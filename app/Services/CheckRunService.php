<?php

namespace App\Services;

use App\Models\CheckItem;
use App\Models\CheckResult;
use App\Models\Question;
use Illuminate\Support\Collection;
use Quesfix\StataLogic\CompiledLogic;
use Quesfix\StataLogic\Exceptions\EvalError;
use Quesfix\StataLogic\LogicEngine;

/**
 * 檢核執行引擎：
 * - run()：對指定樣本（或全部）執行全部檢核條件，產生／同步 check_results
 * - recheckAfterFix()：資料修正後的重新檢核連鎖（規劃書 4.3.4）
 */
class CheckRunService
{
    public function __construct(private readonly EngineCatalogService $catalogService)
    {
    }

    /**
     * 對樣本執行所有檢核條件。
     *
     * @param  string[]|null  $sampleIds  null = 全部樣本
     * @return array{evaluated: int, created: int, eval_errors: array<string>}
     */
    public function run(Question $question, ?array $sampleIds = null): array
    {
        $checkItems = $question->checkItems()->get();
        $catalog = $this->catalogService->catalogFor($question);

        $compiled = $this->parseAll($checkItems);

        $samples = $question->samples()
            ->when($sampleIds !== null, fn ($q) => $q->whereIn('sample_id', $sampleIds))
            ->pluck('sample_id');

        $evaluated = 0;
        $created = 0;
        $evalErrors = [];

        foreach ($samples as $sampleId) {
            try {
                $resolver = $this->catalogService->resolverFor($question, $sampleId, $catalog);
            } catch (EvalError $e) {
                // 單一樣本資料異常（如數值型變數含文字）→ 跳過該樣本並記錄，不中斷整批
                $evalErrors[] = "樣本 {$sampleId}: {$e->getMessage()}";
                continue;
            }

            $existing = CheckResult::where('ques_id', $question->id)
                ->where('sample_id', $sampleId)
                ->get()
                ->keyBy('check_item_id');

            foreach ($checkItems as $item) {
                $logic = $compiled[$item->id] ?? null;
                if ($logic === null) {
                    continue;
                }

                try {
                    $hit = $logic->evaluate($resolver);
                } catch (EvalError $e) {
                    $evalErrors[] = "{$item->item_name} @ {$sampleId}: {$e->getMessage()}";
                    continue;
                }

                $evaluated++;

                if ($hit && ! $existing->has($item->id)) {
                    CheckResult::create([
                        'ques_id' => $question->id,
                        'sample_id' => $sampleId,
                        'check_item_id' => $item->id,
                    ]);
                    $created++;
                }
            }
        }

        return ['evaluated' => $evaluated, 'created' => $created, 'eval_errors' => $evalErrors];
    }

    /**
     * 資料修正後重新確認該樣本的檢核條件（規劃書 4.3.4）。
     * 僅針對「與被修正變數相關」的檢核條件：
     * - 未處理且不再觸發 → 標記 resolved_at（軟性消失）
     * - 已標記 resolved 但再度觸發 → 復原為未處理
     * - 新觸發且無紀錄 → 新增待處理
     * - 已記錄為「接受」但仍觸發 → 改為「重新確認」
     *
     * @param  string[]  $fixedVariables  本次被修正的變數名稱
     * @return array{resolved: int, restored: int, created: int, recheck: int}
     */
    public function recheckAfterFix(Question $question, string $sampleId, array $fixedVariables = []): array
    {
        $checkItems = $question->checkItems()->get();

        if ($fixedVariables !== []) {
            $checkItems = $checkItems->filter(
                fn (CheckItem $item) => $this->logicMentions($item->logic, $fixedVariables),
            )->values();
        }
        $catalog = $this->catalogService->catalogFor($question);
        $compiled = $this->parseAll($checkItems);

        $stats = ['resolved' => 0, 'restored' => 0, 'created' => 0, 'recheck' => 0];

        try {
            $resolver = $this->catalogService->resolverFor($question, $sampleId, $catalog);
        } catch (EvalError $e) {
            // 樣本資料異常時無法重新檢核（修正本身已儲存），回報原因不中斷流程
            return [...$stats, 'error' => $e->getMessage()];
        }

        $existing = CheckResult::where('ques_id', $question->id)
            ->where('sample_id', $sampleId)
            ->get()
            ->keyBy('check_item_id');

        foreach ($checkItems as $item) {
            $logic = $compiled[$item->id] ?? null;
            if ($logic === null) {
                continue;
            }

            try {
                $hit = $logic->evaluate($resolver);
            } catch (EvalError) {
                continue;
            }

            $result = $existing->get($item->id);

            if ($hit) {
                if ($result === null) {
                    CheckResult::create([
                        'ques_id' => $question->id,
                        'sample_id' => $sampleId,
                        'check_item_id' => $item->id,
                    ]);
                    $stats['created']++;
                } elseif ($result->resolved_at !== null) {
                    $result->update(['resolved_at' => null, 'error' => null]);
                    $stats['restored']++;
                } elseif ($result->error === CheckResult::ERROR_ACCEPTED) {
                    $result->update(['error' => CheckResult::ERROR_RECHECK]);
                    $stats['recheck']++;
                }
            } elseif ($result !== null && $result->resolved_at === null && $result->error === null) {
                // 尚未確認的檢核條件不再觸發 → 消失（記錄 resolved_at）
                $result->update(['resolved_at' => now()]);
                $stats['resolved']++;
            }
        }

        return $stats;
    }

    /** 檢核邏輯是否引用任一指定變數（變數名稱區分大小寫、完整比對） */
    private function logicMentions(string $logic, array $variables): bool
    {
        $cleaned = preg_replace('/"[^"]*"/', '', $logic); // 去除字串常數
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $cleaned, $matches);
        $identifiers = array_flip($matches[0]);

        foreach ($variables as $variable) {
            if (isset($identifiers[$variable])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, CompiledLogic|null> check_item_id => 已編譯邏輯 */
    private function parseAll(Collection $checkItems): array
    {
        $compiled = [];

        foreach ($checkItems as $item) {
            try {
                $compiled[$item->id] = LogicEngine::compile($item->logic);
            } catch (\Throwable) {
                $compiled[$item->id] = null; // 匯入時已驗證過，理論上不會發生
            }
        }

        return $compiled;
    }
}

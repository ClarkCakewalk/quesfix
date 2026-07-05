<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Quesfix\StataLogic\LogicEngine;

/**
 * 檢核條件 Excel 匯入：條件代號、條件敘述、關聯題目（逗號分隔題目名稱）、檢核邏輯。
 * 以 stata-logic Validator 驗證邏輯語法、變數存在性與型別；
 * 錯誤 → 拒絕該筆；警告（如混用 &/| 未加括號）→ 匯入但回報。
 */
class CheckImportService
{
    public function __construct(private readonly EngineCatalogService $catalogService)
    {
    }

    /**
     * @return array{imported: int, rejected: string[], warnings: string[]}
     */
    public function import(Question $question, string $filePath): array
    {
        $rows = IOFactory::load($filePath)->getSheet(0)->toArray();
        array_shift($rows);

        $catalog = $this->catalogService->catalogFor($question);
        $itemsByName = $question->items()->pluck('id', 'name');
        // 關聯欄位可填題目名稱或變數名稱（變數自動歸屬其題目）
        $varsToItem = $question->vars()->pluck('item_id', 'variable');

        $imported = 0;
        $rejected = [];
        $warnings = [];

        DB::transaction(function () use ($question, $rows, $catalog, $itemsByName, $varsToItem, &$imported, &$rejected, &$warnings) {
            foreach ($rows as $i => $row) {
                $line = $i + 2;
                [$name, $description, $relatedItems, $logic] =
                    array_pad(array_map(fn ($v) => trim((string) ($v ?? '')), array_slice($row, 0, 4)), 4, '');

                if ($name === '' && $logic === '') {
                    continue;
                }

                if ($name === '' || $logic === '') {
                    $rejected[] = "第 {$line} 列：條件代號或檢核邏輯空白。";
                    continue;
                }

                if ($question->checkItems()->where('item_name', $name)->exists()) {
                    $rejected[] = "第 {$line} 列（{$name}）：條件代號已存在。";
                    continue;
                }

                // 關聯題目：先比對題目名稱，再比對變數名稱（歸屬其題目）；
                // 無法解析者（如 Stata 前處理的衍生變數）以警告回報，不拒絕整列
                $itemIds = [];
                $unresolved = [];
                foreach (array_filter(array_map('trim', explode(',', $relatedItems))) as $token) {
                    if ($itemsByName->has($token)) {
                        $itemIds[] = $itemsByName[$token];
                    } elseif ($varsToItem->has($token)) {
                        $itemIds[] = $varsToItem[$token];
                    } else {
                        $unresolved[] = $token;
                    }
                }
                $itemIds = array_values(array_unique($itemIds));

                if ($unresolved !== []) {
                    $warnings[] = "{$name}：關聯題目/變數無法對應（不影響匯入）：".implode('、', $unresolved);
                }

                // 檢核邏輯驗證
                $result = LogicEngine::validate($logic, $catalog);

                if (! $result->ok()) {
                    $rejected[] = "第 {$line} 列（{$name}）：".implode('；', array_map('strval', $result->errors));
                    continue;
                }

                foreach ($result->warnings as $warning) {
                    $warnings[] = "{$name}：{$warning}";
                }

                $checkItem = $question->checkItems()->create([
                    'item_name' => $name,
                    'description' => $description,
                    'logic' => $logic,
                ]);
                $checkItem->quesItems()->sync($itemIds);
                $imported++;
            }
        });

        return ['imported' => $imported, 'rejected' => $rejected, 'warnings' => $warnings];
    }
}

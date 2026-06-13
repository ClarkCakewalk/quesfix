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

        $imported = 0;
        $rejected = [];
        $warnings = [];

        DB::transaction(function () use ($question, $rows, $catalog, $itemsByName, &$imported, &$rejected, &$warnings) {
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

                // 關聯題目存在性
                $itemIds = [];
                $missingItems = [];
                foreach (array_filter(array_map('trim', explode(',', $relatedItems))) as $itemName) {
                    if ($itemsByName->has($itemName)) {
                        $itemIds[] = $itemsByName[$itemName];
                    } else {
                        $missingItems[] = $itemName;
                    }
                }

                if ($missingItems !== []) {
                    $rejected[] = "第 {$line} 列（{$name}）：關聯題目不存在：".implode('、', $missingItems);
                    continue;
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

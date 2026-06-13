<?php

namespace App\Services;

use App\Models\OptionGroup;
use App\Models\Question;
use App\Models\QuesVar;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * 資料格式 Excel 匯入：包含「數值標籤」與「題項」兩個工作表。
 * 先匯入數值標籤，再匯入題項（規劃書 3.2.3）。
 *
 * 題項工作表欄位：題目名稱、題目標籤、變數名稱、變數標籤、關聯數值標籤、（選用）型別
 * 數值標籤工作表欄位：選項代號、數值、數值說明
 */
class FormatImportService
{
    /**
     * @return array{labels_imported: int, vars_imported: int, warnings: string[]}
     */
    public function import(Question $question, string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);

        $labelSheet = $spreadsheet->getSheetByName('數值標籤') ?? $spreadsheet->getSheet(1);
        $itemSheet = $spreadsheet->getSheetByName('題項') ?? $spreadsheet->getSheet(0);

        $warnings = [];

        return DB::transaction(function () use ($question, $labelSheet, $itemSheet, &$warnings) {
            $labelsImported = $this->importLabels($question, $labelSheet->toArray(), $warnings);
            $varsImported = $this->importItems($question, $itemSheet->toArray(), $warnings);

            return [
                'labels_imported' => $labelsImported,
                'vars_imported' => $varsImported,
                'warnings' => $warnings,
            ];
        });
    }

    /** @param array<int, array> $rows */
    private function importLabels(Question $question, array $rows, array &$warnings): int
    {
        array_shift($rows); // 表頭
        $imported = 0;

        foreach ($rows as $i => $row) {
            $line = $i + 2;
            [$groupName, $value, $label] = array_pad(array_map($this->cell(...), array_slice($row, 0, 3)), 3, '');

            if ($groupName === '' && $value === '') {
                continue;
            }

            if ($groupName === '' || $value === '') {
                $warnings[] = "數值標籤第 {$line} 列：選項代號或數值空白，略過。";
                continue;
            }

            $group = OptionGroup::firstOrCreate(['ques_id' => $question->id, 'name' => $groupName]);

            // 同一選項代號包含相同數值 → 略過（規劃書 3.2.3）
            if ($group->options()->where('value', $value)->exists()) {
                $warnings[] = "數值標籤第 {$line} 列：{$groupName} 已存在數值 {$value}，略過。";
                continue;
            }

            $group->options()->create(['value' => $value, 'label' => $label]);
            $imported++;
        }

        return $imported;
    }

    /** @param array<int, array> $rows */
    private function importItems(Question $question, array $rows, array &$warnings): int
    {
        array_shift($rows);
        $imported = 0;
        $sortBase = (int) $question->items()->max('sort_order');

        foreach ($rows as $i => $row) {
            $line = $i + 2;
            [$itemName, $itemLabel, $variable, $varLabel, $groupName, $typeText] =
                array_pad(array_map($this->cell(...), array_slice($row, 0, 6)), 6, '');

            if ($itemName === '' && $variable === '') {
                continue;
            }

            if ($itemName === '' || $variable === '') {
                $warnings[] = "題項第 {$line} 列：題目名稱或變數名稱空白，略過。";
                continue;
            }

            $item = $question->items()->where('name', $itemName)->first();
            $variableExists = $question->vars()->where('variable', $variable)->exists();

            // 題目名稱及變數名稱都重複 → 略過（規劃書 3.2.3）
            if ($item !== null && $variableExists) {
                $warnings[] = "題項第 {$line} 列：題目 {$itemName} 與變數 {$variable} 皆已存在，略過。";
                continue;
            }

            if ($variableExists) {
                $warnings[] = "題項第 {$line} 列：變數 {$variable} 已存在於其他題目，略過。";
                continue;
            }

            $group = null;
            if ($groupName !== '') {
                $group = $question->optionGroups()->where('name', $groupName)->first();
                if ($group === null) {
                    $warnings[] = "題項第 {$line} 列：關聯數值標籤 {$groupName} 不存在，變數 {$variable} 以無標籤匯入。";
                }
            }

            // 題目名稱重複但變數未重複 → 題目不重複建立，僅新增變數
            $item ??= $question->items()->create([
                'name' => $itemName,
                'label' => $itemLabel,
                'sort_order' => ++$sortBase,
            ]);

            $item->vars()->create([
                'ques_id' => $question->id,
                'variable' => $variable,
                'label' => $varLabel,
                'option_group_id' => $group?->id,
                'var_type' => $this->resolveType($typeText, $group !== null),
            ]);
            $imported++;
        }

        return $imported;
    }

    private function resolveType(string $typeText, bool $hasGroup): int
    {
        return match (mb_strtolower(trim($typeText))) {
            '文字', 'string', 'str' => QuesVar::TYPE_TEXT,
            '選項', 'option' => QuesVar::TYPE_OPTION,
            '數值', 'numeric', 'num' => QuesVar::TYPE_NUMERIC,
            default => $hasGroup ? QuesVar::TYPE_OPTION : QuesVar::TYPE_NUMERIC,
        };
    }

    private function cell(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}

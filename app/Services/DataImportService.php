<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 調查資料 CSV 匯入（UTF-8、含表頭、逗號分隔）。
 * 規格（規劃書 5.1）：
 * - 表頭必須包含 id，否則拒絕整批匯入
 * - 所有表頭變數必須存在於資料格式，否則拒絕整批匯入
 * - id 已存在 → 略過該筆
 */
class DataImportService
{
    /**
     * @return array{imported: int, skipped: string[], warnings: string[]}
     */
    public function import(Question $question, string $filePath): array
    {
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new RuntimeException('無法開啟上傳檔案');
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '');

            if ($header === false) {
                throw new RuntimeException('檔案內容空白');
            }

            // 去除 UTF-8 BOM
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map('trim', $header);

            $idIndex = array_search('id', $header, true);

            if ($idIndex === false) {
                throw new RuntimeException('表頭未包含 id 變數，拒絕匯入。');
            }

            $varIds = $question->vars()->pluck('id', 'variable');
            $unknown = array_diff($header, $varIds->keys()->all());

            if ($unknown !== []) {
                throw new RuntimeException('下列變數不存在於資料格式，拒絕匯入：'.implode('、', $unknown));
            }

            $existingIds = $question->samples()->pluck('sample_id')->flip();

            $imported = 0;
            $skipped = [];
            $warnings = [];
            $line = 1;

            DB::transaction(function () use (
                $handle, $header, $idIndex, $varIds, $existingIds, $question,
                &$imported, &$skipped, &$warnings, &$line
            ) {
                $batch = [];
                $now = now();

                while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                    $line++;

                    if (count($row) === 1 && trim((string) $row[0]) === '') {
                        continue;
                    }

                    if (count($row) !== count($header)) {
                        $warnings[] = "第 {$line} 列：欄位數與表頭不符，略過。";
                        continue;
                    }

                    $sampleId = trim((string) $row[$idIndex]);

                    if ($sampleId === '') {
                        $warnings[] = "第 {$line} 列：id 空白，略過。";
                        continue;
                    }

                    if ($existingIds->has($sampleId)) {
                        $skipped[] = $sampleId;
                        continue;
                    }

                    $existingIds[$sampleId] = true;

                    $question->samples()->create(['sample_id' => $sampleId]);

                    foreach ($header as $col => $variable) {
                        $batch[] = [
                            'ques_id' => $question->id,
                            'sample_id' => $sampleId,
                            'var_id' => $varIds[$variable],
                            'value' => (string) ($row[$col] ?? ''),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if (count($batch) >= 2000) {
                        DB::table('origin_data')->insert($batch);
                        $batch = [];
                    }

                    $imported++;
                }

                if ($batch !== []) {
                    DB::table('origin_data')->insert($batch);
                }
            });

            return ['imported' => $imported, 'skipped' => $skipped, 'warnings' => $warnings];
        } finally {
            fclose($handle);
        }
    }
}

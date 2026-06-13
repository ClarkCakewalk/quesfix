<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Question;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * 圖檔及錄音檔 zip 匯入（規劃書 5.1.2）：
 * - zip 內以樣本 id 分資料夾，同一 id 的所有檔案在同一資料夾
 * - 檔名（不含副檔名）對應題目名稱（ques_items.name）
 * - 檔案或資料夾重複 → 以新檔覆蓋
 * - 檔案存放於 storage/app/private（webroot 之外），經權限驗證後串流
 */
class MediaImportService
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private const AUDIO_EXT = ['mp3', 'wav', 'm4a', 'ogg', 'aac'];

    /**
     * @return array{imported: int, warnings: string[]}
     */
    public function import(Question $question, string $zipPath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('無法開啟 zip 檔案');
        }

        $itemsByName = $question->items()->pluck('id', 'name');
        $itemSort = $question->items()->pluck('sort_order', 'id');
        $sampleIds = $question->samples()->pluck('sample_id')->flip();

        $imported = 0;
        $warnings = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);

                if ($entry === false || str_ends_with($entry, '/')) {
                    continue;
                }

                // 防 zip-slip：拒絕含 .. 或絕對路徑的項目
                if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                    $warnings[] = "{$entry}：路徑不合法，略過。";
                    continue;
                }

                $parts = array_values(array_filter(explode('/', $entry)));

                if (count($parts) < 2) {
                    $warnings[] = "{$entry}：未按「樣本id/檔名」結構存放，略過。";
                    continue;
                }

                $sampleId = $parts[count($parts) - 2];
                $filename = $parts[count($parts) - 1];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $base = pathinfo($filename, PATHINFO_FILENAME);

                $type = match (true) {
                    in_array($ext, self::IMAGE_EXT, true) => Media::TYPE_IMAGE,
                    in_array($ext, self::AUDIO_EXT, true) => Media::TYPE_AUDIO,
                    default => null,
                };

                if ($type === null) {
                    $warnings[] = "{$entry}：不支援的檔案類型，略過。";
                    continue;
                }

                if (! $sampleIds->has($sampleId)) {
                    $warnings[] = "{$entry}：樣本 {$sampleId} 不存在，略過。";
                    continue;
                }

                if (! $itemsByName->has($base)) {
                    $warnings[] = "{$entry}：題目 {$base} 不存在，略過。";
                    continue;
                }

                $itemId = $itemsByName[$base];
                $relativePath = "media/{$question->id}/{$sampleId}/{$filename}";

                Storage::put($relativePath, $zip->getFromIndex($i));

                Media::updateOrCreate(
                    [
                        'ques_id' => $question->id,
                        'sample_id' => $sampleId,
                        'item_id' => $itemId,
                        'type' => $type,
                    ],
                    [
                        'path' => $relativePath,
                        'sort_order' => $itemSort[$itemId] ?? 0,
                    ],
                );

                $imported++;
            }
        } finally {
            $zip->close();
        }

        return ['imported' => $imported, 'warnings' => $warnings];
    }
}

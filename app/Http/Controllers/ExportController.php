<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Services\ExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 匯出（限專案管理者）
 */
class ExportController extends Controller
{
    public function __construct(private readonly ExportService $service)
    {
    }

    /** 訪員錯誤報表（xlsx，可指定週數） */
    public function interviewerErrors(Request $request, Question $question): StreamedResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $weeks = array_filter(array_map('trim', explode(',', (string) $request->query('weeks', ''))));
        $rows = $this->service->interviewerErrorRows($question, $weeks);

        return $this->xlsxResponse(
            ['訪員錯誤報表' => $rows],
            "{$question->code}_訪員錯誤報表_".now()->format('Ymd').'.xlsx',
        );
    }

    /** 檢核結果報表（xlsx，三個 sheet） */
    public function checkResults(Request $request, Question $question): StreamedResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        return $this->xlsxResponse(
            $this->service->checkResultSheets($question),
            "{$question->code}_檢核結果報表_".now()->format('Ymd').'.xlsx',
        );
    }

    /** Stata 資料修正程式（.do） */
    public function fixDo(Request $request, Question $question): StreamedResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $content = $this->service->fixDo($question);
        $filename = "{$question->code}_".now()->format('Ymd').'.do';

        return $this->textResponse($content, $filename);
    }

    /** 修正後資料檔（CSV） */
    public function fixedData(Request $request, Question $question): StreamedResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $content = $this->service->fixedDataCsv($question);
        $filename = "{$question->code}_fixed_".now()->format('Ymd').'.csv';

        return $this->textResponse($content, $filename, 'text/csv');
    }

    /** 修正後資料檔的讀檔程式（.do） */
    public function readDo(Request $request, Question $question): StreamedResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $csvName = "{$question->code}_fixed_".now()->format('Ymd').'.csv';
        $content = $this->service->readDo($question, $csvName);

        return $this->textResponse($content, "{$question->code}_read_".now()->format('Ymd').'.do');
    }

    /** @param array<string, Collection> $sheets sheet 名稱 => 資料列 */
    private function xlsxResponse(array $sheets, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $title => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle(mb_substr($title, 0, 31));

            if ($rows->isEmpty()) {
                $sheet->setCellValue('A1', '（無資料）');
                continue;
            }

            $headers = array_keys($rows->first());
            $sheet->fromArray($headers, null, 'A1');

            $r = 2;
            foreach ($rows as $row) {
                $c = 1;
                foreach ($headers as $h) {
                    $cell = $sheet->getCell([$c, $r]);
                    $cell->setValueExplicit((string) ($row[$h] ?? ''));
                    if (str_contains((string) ($row[$h] ?? ''), "\n")) {
                        $cell->getStyle()->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
                    }
                    $c++;
                }
                $r++;
            }

            foreach (range('A', 'E') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function textResponse(string $content, string $filename, string $mime = 'text/plain'): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print($content),
            $filename,
            ['Content-Type' => "{$mime}; charset=UTF-8"],
        );
    }
}

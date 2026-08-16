<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Services\CheckImportService;
use App\Services\CheckRunService;
use App\Services\DataImportService;
use App\Services\FormatImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 各式批次匯入與檢核執行（限專案管理者）
 */
class ImportController extends Controller
{
    /** 資料匯入頁（CSV 上傳、檢核執行、資料概況） */
    public function page(Request $request, Question $question): Response
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        return Inertia::render('Manage/Data', [
            'question' => $question->only('id', 'code', 'name'),
            'stats' => [
                'samples' => $question->samples()->count(),
                'vars' => $question->vars()->count(),
                'check_items' => $question->checkItems()->count(),
                'pending_results' => $question->checkResults()->pending()->count(),
                'total_results' => $question->checkResults()->active()->count(),
            ],
        ]);
    }

    /** 資料格式 Excel 匯入（數值標籤＋題項兩工作表） */
    public function format(Request $request, Question $question, FormatImportService $service): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480']]);

        try {
            $result = $service->import($question, $request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => '匯入失敗：'.$e->getMessage()]);
        }

        return back()
            ->with('status', "匯入完成：數值標籤 {$result['labels_imported']} 筆、變數 {$result['vars_imported']} 筆。")
            ->with('warnings', $result['warnings']);
    }

    /** 檢核條件 Excel 匯入 */
    public function checks(Request $request, Question $question, CheckImportService $service): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480']]);

        try {
            $result = $service->import($question, $request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => '匯入失敗：'.$e->getMessage()]);
        }

        return back()
            ->with('status', "匯入完成：{$result['imported']} 筆檢核條件。"
                .(count($result['rejected']) ? '拒絕 '.count($result['rejected']).' 筆。' : ''))
            ->with('warnings', array_merge($result['rejected'], $result['warnings']));
    }

    /** 調查資料 CSV 匯入 */
    public function data(Request $request, Question $question, DataImportService $service): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:102400']]);

        try {
            $result = $service->import($question, $request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => '匯入失敗：'.$e->getMessage()]);
        }

        $skippedNote = count($result['skipped'])
            ? '已存在而略過的 id：'.count($result['skipped']).' 筆。'
            : '';

        return back()
            ->with('status', "資料匯入完成：{$result['imported']} 筆樣本。{$skippedNote}")
            ->with('warnings', $result['warnings']);
    }

    /** 補充變數匯入：對既有樣本補上新增變數的值（CSV：id + 新變數欄位） */
    public function supplement(Request $request, Question $question, DataImportService $service): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:102400']]);

        try {
            $result = $service->importSupplement($question, $request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => '匯入失敗：'.$e->getMessage()]);
        }

        $unknownNote = count($result['unknown_ids'])
            ? '不存在於系統而略過的 id：'.count($result['unknown_ids']).' 筆。'
            : '';

        return back()
            ->with('status', "補充匯入完成：更新 {$result['updated_samples']} 個樣本"
                ."（新增 {$result['inserted']} 個值、覆寫 {$result['updated']} 個值）。{$unknownNote}"
                .'如需納入檢核，請重新「執行檢核」。')
            ->with('warnings', array_merge(
                array_map(fn ($id) => "略過不存在的 id：{$id}", array_slice($result['unknown_ids'], 0, 30)),
                $result['warnings'],
            ));
    }

    /** 執行檢核：產生待處理的檢核結果清單 */
    public function runChecks(Request $request, Question $question, CheckRunService $service): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        @set_time_limit(600);

        $result = $service->run($question);

        return back()
            ->with('status', "檢核完成：評估 {$result['evaluated']} 次，新增 {$result['created']} 筆待處理結果。")
            ->with('warnings', array_slice($result['eval_errors'], 0, 50));
    }
}

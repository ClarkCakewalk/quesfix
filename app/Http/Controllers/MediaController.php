<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Question;
use App\Services\MediaImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    /** zip 整批上傳（限專案管理者） */
    public function upload(Request $request, Question $question, MediaImportService $service): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $request->validate(['file' => ['required', 'file', 'mimes:zip', 'max:1048576']]);

        @set_time_limit(600);

        try {
            $result = $service->import($question, $request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => '匯入失敗：'.$e->getMessage()]);
        }

        return back()
            ->with('status', "影音檔匯入完成：{$result['imported']} 個檔案。")
            ->with('warnings', array_slice($result['warnings'], 0, 100));
    }

    /** 影音串流（限專案成員，檔案存於 webroot 之外） */
    public function stream(Request $request, Question $question, Media $media): BinaryFileResponse
    {
        abort_unless(
            $question->isCheckableBy($request->user()) && $media->ques_id === $question->id,
            403,
        );

        $path = Storage::path($media->path);

        abort_unless(is_file($path), 404);

        return response()->file($path);
    }
}

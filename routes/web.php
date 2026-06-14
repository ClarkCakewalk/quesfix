<?php

use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\UserAdminController;
use App\Http\Controllers\CheckItemController;
use App\Http\Controllers\CheckWorkflowController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FormatController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::get('/dashboard', fn () => redirect()->route('questions.index'))
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    // 專案管理
    Route::resource('questions', QuestionController::class)->except(['show']);

    Route::prefix('questions/{question}')->group(function () {
        // 資料格式：題項
        Route::get('formats/items', [FormatController::class, 'items'])->name('formats.items.index');
        Route::post('formats/items', [FormatController::class, 'storeItem'])->name('formats.items.store');
        Route::patch('formats/vars/{var}', [FormatController::class, 'updateVar'])->name('formats.vars.update');
        Route::delete('formats/vars/{var}', [FormatController::class, 'destroyVar'])->name('formats.vars.destroy');

        // 資料格式：數值標籤
        Route::get('formats/labels', [FormatController::class, 'labels'])->name('formats.labels.index');
        Route::post('formats/labels', [FormatController::class, 'storeLabel'])->name('formats.labels.store');
        Route::patch('formats/options/{option}', [FormatController::class, 'updateOption'])->name('formats.options.update');
        Route::delete('formats/options/{option}', [FormatController::class, 'destroyOption'])->name('formats.options.destroy');

        // 檢核條件
        Route::get('checks/items', [CheckItemController::class, 'index'])->name('checks.items.index');
        Route::post('checks/items', [CheckItemController::class, 'store'])->name('checks.items.store');
        Route::patch('checks/items/{checkItem}', [CheckItemController::class, 'update'])->name('checks.items.update');
        Route::delete('checks/items/{checkItem}', [CheckItemController::class, 'destroy'])->name('checks.items.destroy');

        // 匯入與檢核執行
        Route::get('manage/data', [ImportController::class, 'page'])->name('manage.data');
        Route::post('imports/format', [ImportController::class, 'format'])->name('imports.format');
        Route::post('imports/checks', [ImportController::class, 'checks'])->name('imports.checks');
        Route::post('imports/data', [ImportController::class, 'data'])->name('imports.data');
        Route::post('checks/run', [ImportController::class, 'runChecks'])->name('checks.run');

        // 影音檔
        Route::post('imports/media', [MediaController::class, 'upload'])->name('imports.media');
        Route::get('media/{media}', [MediaController::class, 'stream'])->name('media.stream');

        // 匯出
        Route::get('exports/interviewer-errors', [ExportController::class, 'interviewerErrors'])->name('exports.interviewer-errors');
        Route::get('exports/check-results', [ExportController::class, 'checkResults'])->name('exports.check-results');
        Route::get('exports/fix-do', [ExportController::class, 'fixDo'])->name('exports.fix-do');
        Route::get('exports/fixed-data', [ExportController::class, 'fixedData'])->name('exports.fixed-data');
        Route::get('exports/read-do', [ExportController::class, 'readDo'])->name('exports.read-do');

        // 檢核作業
        Route::get('checks', fn (\App\Models\Question $question) => Inertia\Inertia::render('Checks/Modes', [
            'question' => $question->only('id', 'code', 'name'),
        ]))->name('checks.modes');

        Route::get('checks/by-sample', [CheckWorkflowController::class, 'bySample'])->name('checks.by-sample');
        Route::get('checks/by-sample/{sampleId}', [CheckWorkflowController::class, 'sampleConditions'])->name('checks.sample-conditions');
        Route::get('checks/by-logic', [CheckWorkflowController::class, 'byLogic'])->name('checks.by-logic');
        Route::get('checks/by-logic/{checkItem}', [CheckWorkflowController::class, 'logicSamples'])->name('checks.logic-samples');
        Route::get('checks/review/{sampleId}/{checkItem}', [CheckWorkflowController::class, 'review'])->name('checks.review');
        Route::post('checks/review/{sampleId}/{checkItem}/complete', [CheckWorkflowController::class, 'complete'])->name('checks.complete');
        Route::post('checks/heartbeat/{sampleId}', [CheckWorkflowController::class, 'heartbeat'])->name('checks.heartbeat');
        Route::post('checks/unlock/{sampleId}', [CheckWorkflowController::class, 'unlock'])->name('checks.unlock');
        Route::get('checks/locks', [CheckWorkflowController::class, 'locks'])->name('checks.locks');
        Route::post('checks/locks/{sample}/unlock', [CheckWorkflowController::class, 'forceUnlock'])->name('checks.force-unlock');
    });
});

// 使用者管理（限系統管理者）
Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // 使用者邀請
    Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
    Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
    Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

    Route::get('users', [UserAdminController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [UserAdminController::class, 'edit'])->name('users.edit');
    Route::patch('users/{user}/email', [UserAdminController::class, 'updateEmail'])->name('users.email');
    Route::patch('users/{user}/questions/{question}/role', [UserAdminController::class, 'updateProjectRole'])->name('users.project-role');
    Route::delete('users/{user}/questions/{question}', [UserAdminController::class, 'revokeProject'])->name('users.revoke-project');
    Route::post('users/{user}/reset-password', [UserAdminController::class, 'resetPassword'])->name('users.reset-password');
    Route::post('users/{user}/make-admin', [UserAdminController::class, 'makeAdmin'])->name('users.make-admin');
    Route::delete('users/{user}', [UserAdminController::class, 'destroy'])->name('users.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/email', [ProfileController::class, 'updateEmail'])->name('profile.email');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

<?php

namespace App\Http\Controllers;

use App\Models\CheckItem;
use App\Models\CheckResult;
use App\Models\Question;
use App\Models\Sample;
use App\Services\CheckRunService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 資料檢核作業：依樣本／依邏輯列表、檢核介面、樣本鎖定、完成檢核
 */
class CheckWorkflowController extends Controller
{
    public function __construct(
        private readonly \App\Services\EngineCatalogService $catalogService,
        private readonly CheckRunService $checkRunService,
    ) {
    }

    /** 依樣本檢核：列出包含未完成檢核條件的樣本 */
    public function bySample(Request $request, Question $question): Response
    {
        abort_unless($question->isCheckableBy($request->user()), 403);

        $samples = CheckResult::where('ques_id', $question->id)
            ->pending()
            ->select('sample_id', DB::raw('count(*) as pending_count'))
            ->groupBy('sample_id')
            ->orderBy('sample_id')
            ->get();

        $locks = Sample::where('ques_id', $question->id)
            ->whereNotNull('locked_by')
            ->where('lock_expires_at', '>', now())
            ->pluck('locked_by', 'sample_id');

        return Inertia::render('Checks/BySample', [
            'question' => $question->only('id', 'code', 'name'),
            'samples' => $samples->map(fn ($s) => [
                'sample_id' => $s->sample_id,
                'pending_count' => $s->pending_count,
                'locked' => $locks->has($s->sample_id) && $locks[$s->sample_id] !== $request->user()->id,
            ]),
        ]);
    }

    /** 樣本的檢核條件列表（含已完成），未處理在前 */
    public function sampleConditions(Request $request, Question $question, string $sampleId): Response
    {
        abort_unless($question->isCheckableBy($request->user()), 403);

        $results = CheckResult::where('ques_id', $question->id)
            ->where('sample_id', $sampleId)
            ->active()
            ->with('checkItem:id,item_name,description')
            ->get()
            ->map(fn (CheckResult $r) => [
                'id' => $r->id,
                'check_item_id' => $r->check_item_id,
                'item_name' => $r->checkItem->item_name,
                'description' => $r->checkItem->description,
                'error' => $r->error,
                're_survey' => $r->re_survey,
                'pending' => $r->isPending(),
            ])
            ->sortBy([['pending', 'desc'], ['item_name', 'asc']])
            ->values();

        return Inertia::render('Checks/SampleConditions', [
            'question' => $question->only('id', 'code', 'name'),
            'sampleId' => $sampleId,
            'results' => $results,
            'errorLabels' => CheckResult::ERROR_LABELS,
        ]);
    }

    /** 依邏輯檢核：列出包含未完成樣本的檢核條件 */
    public function byLogic(Request $request, Question $question): Response
    {
        abort_unless($question->isCheckableBy($request->user()), 403);

        $items = CheckResult::where('check_results.ques_id', $question->id)
            ->pending()
            ->join('check_items', 'check_items.id', '=', 'check_results.check_item_id')
            ->select(
                'check_items.id',
                'check_items.item_name',
                'check_items.description',
                DB::raw('count(*) as pending_count'),
            )
            ->groupBy('check_items.id', 'check_items.item_name', 'check_items.description')
            ->orderBy('check_items.item_name')
            ->get();

        return Inertia::render('Checks/ByLogic', [
            'question' => $question->only('id', 'code', 'name'),
            'items' => $items,
        ]);
    }

    /** 檢核條件的樣本列表（含已完成），未處理在前 */
    public function logicSamples(Request $request, Question $question, CheckItem $checkItem): Response
    {
        abort_unless($question->isCheckableBy($request->user()) && $checkItem->ques_id === $question->id, 403);

        $results = CheckResult::where('ques_id', $question->id)
            ->where('check_item_id', $checkItem->id)
            ->active()
            ->get()
            ->map(fn (CheckResult $r) => [
                'id' => $r->id,
                'sample_id' => $r->sample_id,
                'error' => $r->error,
                're_survey' => $r->re_survey,
                'pending' => $r->isPending(),
            ])
            ->sortBy([['pending', 'desc'], ['sample_id', 'asc']])
            ->values();

        $locks = Sample::where('ques_id', $question->id)
            ->whereNotNull('locked_by')
            ->where('lock_expires_at', '>', now())
            ->pluck('locked_by', 'sample_id');

        return Inertia::render('Checks/LogicSamples', [
            'question' => $question->only('id', 'code', 'name'),
            'checkItem' => $checkItem->only('id', 'item_name', 'description'),
            'results' => $results->map(fn ($r) => [
                ...$r,
                'locked' => $locks->has($r['sample_id']) && $locks[$r['sample_id']] !== $request->user()->id,
            ]),
            'errorLabels' => CheckResult::ERROR_LABELS,
        ]);
    }

    /** 檢核介面：進入時鎖定樣本 */
    public function review(Request $request, Question $question, string $sampleId, CheckItem $checkItem): Response|RedirectResponse
    {
        abort_unless($question->isCheckableBy($request->user()) && $checkItem->ques_id === $question->id, 403);

        $sample = Sample::where('ques_id', $question->id)->where('sample_id', $sampleId)->firstOrFail();

        // 樣本鎖定
        if ($sample->isLockedByOther($request->user())) {
            return back()->withErrors([
                'lock' => "樣本 {$sampleId} 正由 {$sample->lockedBy?->name} 檢核中，無法開啟。",
            ]);
        }

        $sample->update([
            'locked_by' => $request->user()->id,
            'locked_at' => now(),
            'lock_expires_at' => now()->addMinutes(Sample::LOCK_MINUTES),
        ]);

        // 該樣本所有題目資料（題目 → 變數 → 數值＋標籤＋修正紀錄）
        $values = $this->sampleValues($question, $sampleId);

        $relatedItemIds = $checkItem->quesItems()->pluck('ques_items.id')->flip();

        $result = CheckResult::where('ques_id', $question->id)
            ->where('sample_id', $sampleId)
            ->where('check_item_id', $checkItem->id)
            ->firstOrFail();

        // 該樣本的所有條件代號（右側垂直列表）
        $allConditions = CheckResult::where('ques_id', $question->id)
            ->where('sample_id', $sampleId)
            ->active()
            ->with('checkItem:id,item_name,description')
            ->get()
            ->map(fn (CheckResult $r) => [
                'check_item_id' => $r->check_item_id,
                'item_name' => $r->checkItem->item_name,
                'description' => $r->checkItem->description,
                'pending' => $r->isPending(),
            ])
            ->sortBy('item_name')
            ->values();

        return Inertia::render('Checks/Review', [
            'question' => $question->only('id', 'code', 'name'),
            'sampleId' => $sampleId,
            'checkItem' => $checkItem->only('id', 'item_name', 'description'),
            'relatedItems' => $values->filter(fn ($i) => $relatedItemIds->has($i['item_id']))->values(),
            'allItems' => $values,
            'result' => [
                'id' => $result->id,
                'error' => $result->error,
                're_survey' => $result->re_survey,
                'note' => $result->note,
                'error_note' => $result->error_note,
                're_survey_note' => $result->re_survey_note,
            ],
            'allConditions' => $allConditions,
            'lockMinutes' => Sample::LOCK_MINUTES,
            // 來源：'sample'（依樣本→檢核條件）或 'logic'（依邏輯→樣本列表），供返回連結使用
            'from' => $request->query('from') === 'logic' ? 'logic' : 'sample',
        ]);
    }

    /** 鎖定心跳：檢核介面停留期間定時續鎖 */
    public function heartbeat(Request $request, Question $question, string $sampleId)
    {
        $sample = Sample::where('ques_id', $question->id)->where('sample_id', $sampleId)->firstOrFail();

        if ($sample->locked_by === $request->user()->id) {
            $sample->update(['lock_expires_at' => now()->addMinutes(Sample::LOCK_MINUTES)]);

            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => false], 409);
    }

    /** 離開檢核介面／回到列表時釋放鎖定 */
    public function unlock(Request $request, Question $question, string $sampleId): RedirectResponse
    {
        $sample = Sample::where('ques_id', $question->id)->where('sample_id', $sampleId)->first();

        if ($sample !== null && $sample->locked_by === $request->user()->id) {
            $sample->update(['locked_by' => null, 'locked_at' => null, 'lock_expires_at' => null]);
        }

        return back();
    }

    /** 完成檢核：寫入結果與修正、執行重新檢核連鎖 */
    public function complete(Request $request, Question $question, string $sampleId, CheckItem $checkItem): RedirectResponse
    {
        abort_unless($question->isCheckableBy($request->user()) && $checkItem->ques_id === $question->id, 403);

        $data = $request->validate([
            'error' => ['required', 'integer', 'in:0,1,2'],
            're_survey' => ['required', 'boolean'],
            'note' => ['nullable', 'string'],
            'error_note' => ['nullable', 'string'],
            're_survey_note' => ['nullable', 'string'],
            'fixes' => ['array'],
            'fixes.*.var_id' => ['required', 'integer'],
            'fixes.*.value' => ['nullable', 'string'],
        ]);

        // 規格 4.3.3 的必填連動
        $this->validateNotes($data);

        $sample = Sample::where('ques_id', $question->id)->where('sample_id', $sampleId)->firstOrFail();

        if ($sample->locked_by !== $request->user()->id || ! $sample->isLocked()) {
            throw ValidationException::withMessages([
                'lock' => '樣本鎖定已失效（可能已被強制解鎖），請重新進入檢核介面。',
            ]);
        }

        $fixedVariables = [];

        DB::transaction(function () use ($request, $question, $sampleId, $checkItem, $data, &$fixedVariables) {
            $result = CheckResult::where('ques_id', $question->id)
                ->where('sample_id', $sampleId)
                ->where('check_item_id', $checkItem->id)
                ->lockForUpdate()
                ->firstOrFail();

            $result->update([
                'error' => $data['error'],
                're_survey' => $data['re_survey'],
                'note' => $data['note'] ?? null,
                'error_note' => $data['error_note'] ?? null,
                're_survey_note' => $data['re_survey_note'] ?? null,
                'checked_by' => $request->user()->id,
                'checked_at' => now(),
            ]);

            foreach ($data['fixes'] ?? [] as $fix) {
                $origin = $question->originData()
                    ->where('sample_id', $sampleId)
                    ->where('var_id', $fix['var_id'])
                    ->first();

                if ($origin === null) {
                    throw ValidationException::withMessages([
                        'fixes' => '修正的變數不存在於該樣本的原始資料中。',
                    ]);
                }

                $origin->fixes()->create([
                    'value' => (string) ($fix['value'] ?? ''),
                    'user_id' => $request->user()->id,
                    'check_item_id' => $checkItem->id,
                ]);

                $fixedVariables[] = $origin->var->variable;
            }
        });

        // 規格 4.3.4：有數值修正時，僅重新確認與被修正變數相關的檢核條件
        $stats = $fixedVariables !== []
            ? $this->checkRunService->recheckAfterFix($question, $sampleId, array_unique($fixedVariables))
            : null;

        $message = '檢核結果已儲存。';

        if ($stats !== null) {
            $message .= "重新檢核：消失 {$stats['resolved']}、復原 {$stats['restored']}、新增 {$stats['created']}、重新確認 {$stats['recheck']}。";
        }

        return back()->with('status', $message)->with('completed', true);
    }

    /** 資料修改模式：輸入樣本編號的介面 */
    public function editEntry(Request $request, Question $question): Response
    {
        abort_unless($question->isCheckableBy($request->user()), 403);

        return Inertia::render('Checks/EditEntry', [
            'question' => $question->only('id', 'code', 'name'),
        ]);
    }

    /** 搜尋樣本編號，存在且未被他人鎖定則導向資料修改介面 */
    public function editSearch(Request $request, Question $question): RedirectResponse
    {
        abort_unless($question->isCheckableBy($request->user()), 403);

        $request->validate(['sample_id' => ['required', 'string']], [], ['sample_id' => '樣本編號']);
        $sampleId = trim((string) $request->input('sample_id'));

        $sample = Sample::where('ques_id', $question->id)->where('sample_id', $sampleId)->first();

        if ($sample === null) {
            throw ValidationException::withMessages(['sample_id' => "找不到樣本編號「{$sampleId}」。"]);
        }

        if ($sample->isLockedByOther($request->user())) {
            throw ValidationException::withMessages([
                'sample_id' => "樣本 {$sampleId} 正由 {$sample->lockedBy?->name} 處理中，無法開啟。",
            ]);
        }

        return redirect()->route('checks.edit', [$question->id, $sampleId]);
    }

    /** 資料修改介面：僅「全部題目」，進入時鎖定樣本 */
    public function edit(Request $request, Question $question, string $sampleId): Response|RedirectResponse
    {
        abort_unless($question->isCheckableBy($request->user()), 403);

        $sample = Sample::where('ques_id', $question->id)->where('sample_id', $sampleId)->firstOrFail();

        if ($sample->isLockedByOther($request->user())) {
            return redirect()->route('checks.edit-entry', $question->id)->withErrors([
                'sample_id' => "樣本 {$sampleId} 正由 {$sample->lockedBy?->name} 處理中，無法開啟。",
            ]);
        }

        $sample->update([
            'locked_by' => $request->user()->id,
            'locked_at' => now(),
            'lock_expires_at' => now()->addMinutes(Sample::LOCK_MINUTES),
        ]);

        return Inertia::render('Checks/EditData', [
            'question' => $question->only('id', 'code', 'name'),
            'sampleId' => $sampleId,
            'allItems' => $this->sampleValues($question, $sampleId),
            'lockMinutes' => Sample::LOCK_MINUTES,
        ]);
    }

    /**
     * 完成修訂：儲存修正並執行重新檢核連鎖（同「完成檢核」，但不綁定檢核條件，
     * 也不寫入 check_results）。完成後釋放鎖定。
     */
    public function completeEdit(Request $request, Question $question, string $sampleId): RedirectResponse
    {
        abort_unless($question->isCheckableBy($request->user()), 403);

        $data = $request->validate([
            'fixes' => ['array'],
            'fixes.*.var_id' => ['required', 'integer'],
            'fixes.*.value' => ['nullable', 'string'],
        ]);

        $sample = Sample::where('ques_id', $question->id)->where('sample_id', $sampleId)->firstOrFail();

        if ($sample->locked_by !== $request->user()->id || ! $sample->isLocked()) {
            throw ValidationException::withMessages([
                'lock' => '樣本鎖定已失效（可能已被強制解鎖），請重新進入資料修改介面。',
            ]);
        }

        $fixedVariables = [];

        DB::transaction(function () use ($request, $question, $sampleId, $data, &$fixedVariables) {
            foreach ($data['fixes'] ?? [] as $fix) {
                $origin = $question->originData()
                    ->where('sample_id', $sampleId)
                    ->where('var_id', $fix['var_id'])
                    ->first();

                if ($origin === null) {
                    throw ValidationException::withMessages([
                        'fixes' => '修正的變數不存在於該樣本的原始資料中。',
                    ]);
                }

                $origin->fixes()->create([
                    'value' => (string) ($fix['value'] ?? ''),
                    'user_id' => $request->user()->id,
                    'check_item_id' => null, // 資料修改模式：不綁定特定檢核條件
                ]);

                $fixedVariables[] = $origin->var->variable;
            }
        });

        // 同「完成檢核」：有修正時，重新確認與被修正變數相關的檢核條件
        $stats = $fixedVariables !== []
            ? $this->checkRunService->recheckAfterFix($question, $sampleId, array_unique($fixedVariables))
            : null;

        $sample->update(['locked_by' => null, 'locked_at' => null, 'lock_expires_at' => null]);

        $message = '資料修訂已儲存。';

        if ($stats !== null) {
            $message .= "重新檢核：消失 {$stats['resolved']}、復原 {$stats['restored']}、新增 {$stats['created']}、重新確認 {$stats['recheck']}。";
        }

        return redirect()->route('checks.edit-entry', $question->id)->with('status', $message);
    }

    /** 樣本鎖定列表（專案管理介面）＋強制解鎖 */
    public function locks(Request $request, Question $question): Response
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $locked = Sample::where('ques_id', $question->id)
            ->whereNotNull('locked_by')
            ->with('lockedBy:id,username,name')
            ->orderBy('locked_at')
            ->get()
            ->map(fn (Sample $s) => [
                'id' => $s->id,
                'sample_id' => $s->sample_id,
                'locked_by' => $s->lockedBy?->name,
                'locked_at' => $s->locked_at?->toDateTimeString(),
                'expires_at' => $s->lock_expires_at?->toDateTimeString(),
                'expired' => ! $s->isLocked(),
            ]);

        return Inertia::render('Checks/Locks', [
            'question' => $question->only('id', 'code', 'name'),
            'locked' => $locked,
        ]);
    }

    public function forceUnlock(Request $request, Question $question, Sample $sample): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()) && $sample->ques_id === $question->id, 403);

        $sample->update(['locked_by' => null, 'locked_at' => null, 'lock_expires_at' => null]);

        return back()->with('status', "已強制解除樣本 {$sample->sample_id} 的鎖定。");
    }

    /**
     * 組出樣本的完整題目資料：題目 → 變數 → 最新值＋原始值＋標籤。
     */
    private function sampleValues(Question $question, string $sampleId)
    {
        $rows = DB::table('origin_data')
            ->join('ques_vars', 'ques_vars.id', '=', 'origin_data.var_id')
            ->join('ques_items', 'ques_items.id', '=', 'ques_vars.item_id')
            ->leftJoin('option_groups', 'option_groups.id', '=', 'ques_vars.option_group_id')
            ->leftJoin('fix_data as latest_fix', function ($join) {
                $join->on('latest_fix.data_id', '=', 'origin_data.id')
                    ->whereRaw('latest_fix.id = (select max(f2.id) from fix_data f2 where f2.data_id = origin_data.id)');
            })
            ->where('origin_data.ques_id', $question->id)
            ->where('origin_data.sample_id', $sampleId)
            ->orderBy('ques_items.sort_order')
            ->orderBy('ques_vars.id')
            ->get([
                'origin_data.id as data_id',
                'ques_items.id as item_id',
                'ques_items.name as item_name',
                'ques_items.label as item_label',
                'ques_vars.id as var_id',
                'ques_vars.variable',
                'ques_vars.label as var_label',
                'ques_vars.option_group_id',
                'origin_data.value as origin_value',
                'latest_fix.value as fix_value',
            ]);

        // 標籤對照：option_group_id => [value => label]
        $groupIds = $rows->pluck('option_group_id')->filter()->unique();
        $labels = DB::table('ques_options')
            ->whereIn('option_group_id', $groupIds)
            ->get(['option_group_id', 'value', 'label'])
            ->groupBy('option_group_id')
            ->map(fn ($opts) => $opts->pluck('label', 'value'));

        $media = DB::table('media')
            ->where('ques_id', $question->id)
            ->where('sample_id', $sampleId)
            ->get(['item_id', 'id', 'type', 'sort_order'])
            ->groupBy('item_id');

        return $rows
            ->groupBy('item_id')
            ->map(function ($vars) use ($labels, $media, $question) {
                $first = $vars->first();
                $itemMedia = $media->get($first->item_id, collect());

                return [
                    'item_id' => $first->item_id,
                    'item_name' => $first->item_name,
                    'item_label' => $first->item_label,
                    'has_media' => $itemMedia->isNotEmpty(),
                    'media' => [
                        'image' => $itemMedia->firstWhere('type', \App\Models\Media::TYPE_IMAGE)?->id,
                        'audio' => $itemMedia->firstWhere('type', \App\Models\Media::TYPE_AUDIO)?->id,
                    ],
                    'vars' => $vars->map(function ($v) use ($labels) {
                        $valueLabels = $v->option_group_id !== null
                            ? ($labels[$v->option_group_id] ?? collect())
                            : collect();

                        return [
                            'var_id' => $v->var_id,
                            'variable' => $v->variable,
                            'label' => $v->var_label,
                            'origin_value' => $v->origin_value,
                            'fix_value' => $v->fix_value,
                            'origin_label' => $valueLabels[$v->origin_value] ?? null,
                            'fix_label' => $v->fix_value !== null ? ($valueLabels[$v->fix_value] ?? null) : null,
                            'all_labels' => $valueLabels,
                        ];
                    })->values(),
                ];
            })
            ->values();
    }

    /** 規格 4.3.3：說明欄位與選項的必填連動 */
    private function validateNotes(array $data): void
    {
        $errors = [];

        if ($data['error'] === 1 && trim((string) ($data['error_note'] ?? '')) === '') {
            $errors['error_note'] = '檢核結果為「錯誤且算錯」時，訪員說明必填。';
        }

        if ($data['error'] === 2 && trim((string) ($data['note'] ?? '')) === '') {
            $errors['note'] = '檢核結果為「錯誤不算錯」時，內部註記必填。';
        }

        if ($data['re_survey'] && trim((string) ($data['re_survey_note'] ?? '')) === '') {
            $errors['re_survey_note'] = '選擇補問時，補問說明必填。';
        }

        if (trim((string) ($data['re_survey_note'] ?? '')) !== '' && ! $data['re_survey']) {
            $errors['re_survey'] = '補問說明非空白時，是否補問必須選擇「補問」。';
        }

        if (trim((string) ($data['error_note'] ?? '')) !== '' && $data['error'] !== 1) {
            $errors['error'] = '訪員說明非空白時，檢核結果必須選擇「錯誤且算錯」。';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}

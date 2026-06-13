<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 專案（問卷別）管理
 */
class QuestionController extends Controller
{
    /** 專案列表：系統管理者看全部，一般使用者看自己參與的 */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $questions = Question::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->whereHas(
                'members', fn ($m) => $m->where('users.id', $user->id),
            ))
            ->withCount('members')
            ->orderBy('code')
            ->get()
            ->map(fn (Question $qs) => [
                'id' => $qs->id,
                'code' => $qs->code,
                'name' => $qs->name,
                'members_count' => $qs->members_count,
                'can_manage' => $qs->isManagedBy($user),
            ]);

        return Inertia::render('Questions/Index', [
            'questions' => $questions,
            'canCreate' => $user->isAdmin(),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        return Inertia::render('Questions/Form', [
            'question' => null,
            'allUsers' => $this->allUsers(),
            'reportVars' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $this->validateProject($request);

        $question = DB::transaction(function () use ($data) {
            $question = Question::create(['code' => $data['code'], 'name' => $data['name']]);
            $question->members()->sync($this->memberPivot($data['members']));

            return $question;
        });

        return redirect()->route('questions.index')
            ->with('status', "專案 {$question->code} 已建立。");
    }

    public function edit(Request $request, Question $question): Response
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $question->load('members:id,username,name', 'reportVars');

        return Inertia::render('Questions/Form', [
            'question' => [
                'id' => $question->id,
                'code' => $question->code,
                'name' => $question->name,
                'week_var_id' => $question->week_var_id,
                'interviewer_var_id' => $question->interviewer_var_id,
                'members' => $question->members->map(fn ($m) => [
                    'user_id' => $m->id,
                    'username' => $m->username,
                    'name' => $m->name,
                    'role' => $m->pivot->role,
                ]),
                'report_var_ids' => $question->reportVars->pluck('var_id'),
            ],
            'allUsers' => $this->allUsers(),
            'allVars' => $question->vars()->orderBy('variable')
                ->get(['id', 'variable', 'label'])
                ->map(fn ($v) => ['id' => $v->id, 'variable' => $v->variable, 'label' => $v->label]),
        ]);
    }

    public function update(Request $request, Question $question): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $data = $this->validateProject($request, $question);

        // 規格：不得將自身帳號從成員列表中除權（系統管理者除外）
        $user = $request->user();
        if (! $user->isAdmin() && ! collect($data['members'])->contains('user_id', $user->id)) {
            throw ValidationException::withMessages(['members' => '不得將自身帳號從成員列表中除權。']);
        }

        DB::transaction(function () use ($question, $data) {
            $question->update([
                'code' => $data['code'],
                'name' => $data['name'],
                'week_var_id' => $data['week_var_id'] ?? null,
                'interviewer_var_id' => $data['interviewer_var_id'] ?? null,
            ]);
            $question->members()->sync($this->memberPivot($data['members']));

            $question->reportVars()->delete();
            foreach (array_values($data['report_var_ids'] ?? []) as $i => $varId) {
                $question->reportVars()->create(['var_id' => $varId, 'sort_order' => $i]);
            }
        });

        return redirect()->route('questions.index')->with('status', '專案已更新。');
    }

    /** 刪除專案：連帶清除所有關聯資料（FK cascade） */
    public function destroy(Request $request, Question $question): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $question->delete();

        return redirect()->route('questions.index')->with('status', '專案及其所有資料已刪除。');
    }

    private function validateProject(Request $request, ?Question $question = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'alpha_num:ascii',
                'unique:questions,code'.($question ? ','.$question->id : '')],
            'name' => ['required', 'string', 'max:255'],
            'members' => ['required', 'array', 'min:1'],
            'members.*.user_id' => ['required', 'integer', 'exists:users,id', 'distinct'],
            'members.*.role' => ['required', 'integer', 'in:1,2'],
            'week_var_id' => ['nullable', 'integer', 'exists:ques_vars,id'],
            'interviewer_var_id' => ['nullable', 'integer', 'exists:ques_vars,id'],
            'report_var_ids' => ['nullable', 'array'],
            'report_var_ids.*' => ['integer', 'exists:ques_vars,id'],
        ], [], [
            'code' => '專案代號', 'name' => '專案名稱', 'members' => '人員列表',
        ]);

        if (! collect($data['members'])->contains('role', Question::ROLE_MANAGER)) {
            throw ValidationException::withMessages(['members' => '至少需指定一位專案管理者。']);
        }

        return $data;
    }

    private function memberPivot(array $members): array
    {
        return collect($members)->mapWithKeys(
            fn ($m) => [$m['user_id'] => ['role' => $m['role']]],
        )->all();
    }

    private function allUsers()
    {
        return User::whereNotNull('email_verified_at')
            ->orderBy('username')
            ->get(['id', 'username', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'username' => $u->username, 'name' => $u->name]);
    }
}

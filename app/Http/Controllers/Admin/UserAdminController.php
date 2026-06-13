<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 使用者管理（限系統管理者）
 */
class UserAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('username', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('unit', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%"),
            ))
            ->orderBy('username')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => ['q' => $q],
        ]);
    }

    public function edit(User $user): Response
    {
        $user->load('questions:id,code,name');

        return Inertia::render('Admin/Users/Edit', [
            'editUser' => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                'gender' => $user->gender,
                'unit' => $user->unit,
                'email' => $user->email,
                'role' => $user->role,
                'locked_at' => $user->locked_at,
                'questions' => $user->questions->map(fn ($qs) => [
                    'id' => $qs->id,
                    'code' => $qs->code,
                    'name' => $qs->name,
                    'role' => $qs->pivot->role,
                ]),
            ],
        ]);
    }

    /** Email 修改：比照使用者 Email 更新程序，重新寄信驗證 */
    public function updateEmail(Request $request, User $user): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ], [], ['email' => 'Email']);

        $user->forceFill([
            'email' => $request->email,
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();

        return back()->with('status', 'Email 已更新並寄出驗證信，該使用者完成驗證前無法登入。');
    }

    /** 調整使用者在某專案的角色 */
    public function updateProjectRole(Request $request, User $user, Question $question): RedirectResponse
    {
        $request->validate(['role' => 'required|integer|in:1,2']);

        $this->ensureNotLastManager($question, $user);

        $question->members()->updateExistingPivot($user->id, ['role' => $request->integer('role')]);

        return back()->with('status', '角色已更新。');
    }

    /** 除權：刪除使用者在該專案的所有權限 */
    public function revokeProject(User $user, Question $question): RedirectResponse
    {
        $this->ensureNotLastManager($question, $user);

        $question->members()->detach($user->id);

        return back()->with('status', '已移除該專案權限。');
    }

    /** 重設密碼：寄出重設信，程序比照忘記密碼；同時解除帳戶鎖定 */
    public function resetPassword(User $user): RedirectResponse
    {
        $user->forceFill(['failed_attempts' => 0, 'locked_at' => null])->save();

        Password::sendResetLink(['email' => $user->email]);

        return back()->with('status', '已寄出重設密碼信並解除帳戶鎖定。');
    }

    public function makeAdmin(User $user): RedirectResponse
    {
        $user->forceFill(['role' => User::ROLE_ADMIN])->save();

        return back()->with('status', '已設定為系統管理者。');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->isAdmin()) {
            return back()->withErrors(['user' => '不可刪除系統管理者。']);
        }

        DB::transaction(function () use ($user) {
            $user->questions()->detach();
            $user->delete();
        });

        return redirect()->route('admin.users.index')->with('status', '使用者已刪除。');
    }

    /** 避免把專案唯一的管理者除權/降權，違反「至少一位專案管理者」 */
    private function ensureNotLastManager(Question $question, User $user): void
    {
        $isManager = $question->members()
            ->wherePivot('user_id', $user->id)
            ->wherePivot('role', Question::ROLE_MANAGER)
            ->exists();

        if (! $isManager) {
            return;
        }

        $managerCount = $question->members()->wherePivot('role', Question::ROLE_MANAGER)->count();

        if ($managerCount <= 1) {
            abort(422, '此使用者為該專案唯一的專案管理者，請先指定其他管理者。');
        }
    }
}

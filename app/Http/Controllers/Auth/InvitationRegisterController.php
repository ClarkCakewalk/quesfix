<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 邀請制註冊：使用者憑邀請信中的專屬連結填寫註冊表單。
 * Email 由邀請決定、不可修改（已由收信證明所有權，註冊後直接視為已驗證）。
 */
class InvitationRegisterController extends Controller
{
    public function create(string $token): Response|RedirectResponse
    {
        $invitation = Invitation::where('token', $token)->first();

        if ($invitation === null || ! $invitation->isPending()) {
            return redirect()->route('login')->with('status', $this->invalidMessage($invitation));
        }

        return Inertia::render('Auth/InvitationRegister', [
            'token' => $token,
            'email' => $invitation->email,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = Invitation::where('token', $token)->first();

        if ($invitation === null || ! $invitation->isPending()) {
            return redirect()->route('login')->with('status', $this->invalidMessage($invitation));
        }

        $request->validate([
            'username' => ['required', 'string', 'max:64', 'alpha_num:ascii', 'unique:users,username'],
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'integer', 'in:0,1,2'],
            'unit' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [], [
            'username' => '登入帳號',
            'name' => '姓名',
            'gender' => '性別',
            'unit' => '服務單位',
            'password' => '密碼',
        ]);

        DB::transaction(function () use ($request, $invitation) {
            $user = User::create([
                'username' => $request->username,
                'name' => $request->name,
                'gender' => $request->gender,
                'unit' => $request->unit,
                'email' => $invitation->email,
                'password' => Hash::make($request->password),
            ]);

            // 已透過邀請連結證明 Email 所有權，直接視為已驗證
            // （email_verified_at 不在 fillable，須以 forceFill 設定）
            $user->forceFill(['email_verified_at' => now()])->save();

            $invitation->update(['accepted_at' => now()]);
        });

        return redirect()->route('login')
            ->with('status', '註冊完成，請以您設定的帳號密碼登入。');
    }

    private function invalidMessage(?Invitation $invitation): string
    {
        if ($invitation?->isAccepted()) {
            return '此邀請連結已完成註冊，請直接登入。';
        }

        return '邀請連結無效或已過期，請聯絡系統管理者重新寄送。';
    }
}

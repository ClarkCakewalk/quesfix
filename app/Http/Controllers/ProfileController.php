<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'status' => session('status'),
        ]);
    }

    /**
     * 基本資料更新（姓名、性別、服務單位）。
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated())->save();

        return Redirect::route('profile.edit')->with('status', '基本資料已更新。');
    }

    /**
     * Email 更新：需輸入 Email 與確認欄位。更新後強制登出，
     * 寄出驗證信，完成驗證前禁止登入（LoginRequest 會擋）。
     */
    public function updateEmail(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255', 'confirmed',
                'unique:users,email,'.$request->user()->id,
            ],
        ], [], ['email' => 'Email']);

        $user = $request->user();

        $user->forceFill([
            'email' => $request->email,
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::route('login')
            ->with('status', 'Email 已更新並寄出驗證信，請完成驗證後重新登入。');
    }

    /**
     * 刪除帳號（保留 Breeze 預設功能，僅限本人）。
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}

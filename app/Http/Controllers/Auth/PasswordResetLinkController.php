<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    /**
     * 規格：需輸入帳號及註冊 Email，兩者與註冊資料吻合才寄出忘記密碼信。
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'username' => 'required|string',
            'email' => 'required|email',
        ], [], ['username' => '登入帳號', 'email' => 'Email']);

        $status = Password::sendResetLink(
            $request->only('username', 'email')
        );

        if ($status == Password::RESET_LINK_SENT) {
            return back()->with('status', '重設密碼信已寄出，連結有效時間為 10 分鐘。');
        }

        throw ValidationException::withMessages([
            'email' => ['帳號與 Email 不吻合，或帳號不存在。'],
        ]);
    }
}

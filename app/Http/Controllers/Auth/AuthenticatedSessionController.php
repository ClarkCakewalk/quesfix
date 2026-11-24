<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor)
    {
    }

    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * 帳密驗證通過後：
     * - 受信任裝置（90 天內通過雙重驗證並記住）→ 直接登入
     * - 其餘 → 寄出 Email 驗證碼，轉往雙重驗證頁（規劃書 2.2.2）
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->validateCredentials();

        $device = $this->twoFactor->trustedDevice($user, $request);

        if ($device !== null) {
            $device->forceFill(['last_used_at' => now()])->save();

            Auth::login($user, $request->boolean('remember'));
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard', absolute: false));
        }

        // 尚未登入：只在 session 記住「待雙重驗證」的使用者
        $request->session()->regenerate();
        $request->session()->put(TwoFactorController::SESSION_KEY, [
            'user_id' => $user->id,
            'remember' => $request->boolean('remember'),
        ]);

        try {
            $this->twoFactor->sendCode($user);
        } catch (\Throwable $e) {
            report($e);
            $request->session()->forget(TwoFactorController::SESSION_KEY);

            throw ValidationException::withMessages([
                'username' => '驗證碼信件寄送失敗，請稍後再試；若持續發生請聯絡系統管理者。',
            ]);
        }

        return redirect()->route('two-factor.challenge');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}

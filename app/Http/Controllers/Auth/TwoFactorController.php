<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 雙重驗證頁（規劃書 2.2.2）：輸入 Email 收到的驗證碼完成登入。
 */
class TwoFactorController extends Controller
{
    public const SESSION_KEY = 'login.two_factor';

    public function __construct(private readonly TwoFactorService $twoFactor)
    {
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge', [
            'maskedEmail' => $this->maskEmail($user->email),
            'validMinutes' => TwoFactorService::CODE_VALID_MINUTES,
            'trustDays' => TwoFactorService::TRUST_DAYS,
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'remember_device' => ['boolean'],
        ], [], ['code' => '驗證碼']);

        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login')->with('status', '驗證已逾時，請重新登入。');
        }

        $result = $this->twoFactor->verifyCode($user, (string) $request->input('code'));

        if ($result !== 'ok') {
            throw ValidationException::withMessages(['code' => match ($result) {
                'expired' => '驗證碼已失效，請點選「重新寄送驗證碼」。',
                'too_many' => '驗證碼錯誤次數過多，請點選「重新寄送驗證碼」取得新的驗證碼。',
                default => '驗證碼錯誤，請再確認一次。',
            }]);
        }

        $pending = $request->session()->pull(self::SESSION_KEY);

        Auth::login($user, (bool) ($pending['remember'] ?? false));
        $request->session()->regenerate();

        $response = redirect()->intended(route('dashboard', absolute: false));

        if ($request->boolean('remember_device')) {
            $response->withCookie($this->twoFactor->trustDevice($user, $request));
        }

        return $response;
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login')->with('status', '驗證已逾時，請重新登入。');
        }

        try {
            $this->twoFactor->sendCode($user);
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'code' => '驗證碼信件寄送失敗，請稍後再試；若持續發生請聯絡系統管理者。',
            ]);
        }

        return back()->with('status', '已重新寄送驗證碼，請至信箱查收。');
    }

    /** 取得 session 中「帳密已通過、待雙重驗證」的使用者 */
    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || empty($pending['user_id'])) {
            return null;
        }

        $user = User::find($pending['user_id']);

        // 等待期間帳戶被鎖定或刪除 → 視同無效
        if ($user === null || $user->isLocked()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $user;
    }

    /** 遮蔽 Email：clark.huang@example.com → cl*******@example.com */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('*', max(mb_strlen($local) - mb_strlen($visible), 3)).'@'.$domain;
    }
}

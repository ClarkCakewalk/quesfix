<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** 連續失敗達此次數顯示 reCAPTCHA */
    public const CAPTCHA_THRESHOLD = 3;

    /** 連續失敗達此次數鎖定帳戶 */
    public const LOCK_THRESHOLD = 5;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * 以帳號＋密碼驗證。規格：
     * - 連續 3 次錯誤顯示 reCAPTCHA（前端據 failed_attempts 顯示）
     * - 連續 5 次錯誤鎖定帳戶，需系統管理者重設密碼解鎖
     * - Email 未驗證（含變更 Email 後尚未重新驗證）禁止登入
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = User::where('username', $this->string('username'))->first();

        if ($user !== null && $user->isLocked()) {
            throw ValidationException::withMessages([
                'username' => '此帳戶因連續登入失敗已被鎖定，請聯絡系統管理者重設密碼。',
            ]);
        }

        if ($user === null || ! Hash::check($this->string('password'), $user->password)) {
            RateLimiter::hit($this->throttleKey());

            if ($user !== null) {
                $user->increment('failed_attempts');

                if ($user->failed_attempts >= self::LOCK_THRESHOLD) {
                    $user->forceFill(['locked_at' => now()])->save();

                    throw ValidationException::withMessages([
                        'username' => '連續登入失敗達 5 次，帳戶已鎖定，請聯絡系統管理者重設密碼。',
                    ]);
                }
            }

            throw ValidationException::withMessages([
                'username' => '帳號或密碼錯誤。',
                'failed_attempts' => (string) ($user?->failed_attempts ?? 0),
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'username' => '帳號尚未完成 Email 驗證，請點擊驗證信中的連結開通帳號。',
            ]);
        }

        $user->forceFill(['failed_attempts' => 0])->save();

        Auth::login($user, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
    }

    /** @throws ValidationException */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 10)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('username')).'|'.$this->ip());
    }
}

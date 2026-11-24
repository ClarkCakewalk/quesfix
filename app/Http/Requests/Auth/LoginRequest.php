<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
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
     * 以帳號＋密碼驗證，通過則回傳使用者（尚未登入，是否需雙重驗證由呼叫端決定）。規格：
     * - 連續 5 次錯誤鎖定帳戶，需系統管理者重設密碼解鎖
     * - Email 未驗證（含變更 Email 後尚未重新驗證）禁止登入
     *
     * @throws ValidationException
     */
    public function validateCredentials(): User
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
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'username' => '帳號尚未完成 Email 驗證，請點擊驗證信中的連結開通帳號。',
            ]);
        }

        $user->forceFill(['failed_attempts' => 0])->save();

        RateLimiter::clear($this->throttleKey());

        return $user;
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

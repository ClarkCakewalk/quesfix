<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;

/**
 * 雙重驗證（規劃書 2.2.2）
 *
 * - 驗證方法：Email 寄送 6 位英數字驗證碼，10 分鐘內有效
 * - 驗證時機：未記錄的裝置登入，或已記錄裝置距前次驗證超過 90 天
 * - 「記住此裝置」：勾選後 90 天內同一裝置免重複驗證
 */
class TwoFactorService
{
    public const CODE_LENGTH = 6;

    public const CODE_VALID_MINUTES = 10;

    public const TRUST_DAYS = 90;

    /** 同一組驗證碼最多可嘗試的次數，超過須重新寄送 */
    public const MAX_ATTEMPTS = 5;

    /** 受信任裝置 Cookie（加 quesfix_ 前綴，避免與同網域其他系統衝突） */
    public const COOKIE = 'quesfix_trusted_device';

    /** 排除易混淆字元（0/O、1/I/L）的英數字集 */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** 此請求的裝置是否為該使用者 90 天內驗證過的受信任裝置 */
    public function trustedDevice(User $user, Request $request): ?TrustedDevice
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return null;
        }

        return TrustedDevice::where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('verified_at', '>=', now()->subDays(self::TRUST_DAYS))
            ->first();
    }

    /** 產生並寄出新的驗證碼（覆蓋舊碼、重設嘗試次數） */
    public function sendCode(User $user): void
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $user->forceFill([
            'two_factor_code' => $this->hashCode($code),
            'two_factor_expires_at' => now()->addMinutes(self::CODE_VALID_MINUTES),
            'two_factor_attempts' => 0,
        ])->save();

        $user->notify(new TwoFactorCodeNotification($code, self::CODE_VALID_MINUTES));
    }

    /**
     * 驗證使用者輸入的驗證碼。
     *
     * @return 'ok'|'invalid'|'expired'|'too_many'
     */
    public function verifyCode(User $user, string $input): string
    {
        if ($user->two_factor_code === null || $user->two_factor_expires_at === null
            || $user->two_factor_expires_at->isPast()) {
            return 'expired';
        }

        if ($user->two_factor_attempts >= self::MAX_ATTEMPTS) {
            return 'too_many';
        }

        // 不分大小寫、忽略使用者誤輸入的空白
        $normalized = strtoupper(preg_replace('/\s+/', '', $input));

        if (! hash_equals($user->two_factor_code, $this->hashCode($normalized))) {
            $user->increment('two_factor_attempts');

            return $user->two_factor_attempts >= self::MAX_ATTEMPTS ? 'too_many' : 'invalid';
        }

        $user->forceFill([
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
            'two_factor_attempts' => 0,
        ])->save();

        return 'ok';
    }

    /**
     * 將目前裝置登記為受信任裝置，回傳要寫入瀏覽器的 Cookie。
     * 若此裝置已有舊紀錄（例如超過 90 天），先移除再重新登記。
     */
    public function trustDevice(User $user, Request $request): HttpCookie
    {
        $old = $request->cookie(self::COOKIE);
        if (is_string($old) && $old !== '') {
            TrustedDevice::where('user_id', $user->id)->where('token_hash', hash('sha256', $old))->delete();
        }

        // 順手清掉該使用者已逾期的裝置紀錄
        TrustedDevice::where('user_id', $user->id)
            ->where('verified_at', '<', now()->subDays(self::TRUST_DAYS))
            ->delete();

        $token = Str::random(64);

        TrustedDevice::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'ip_address' => $request->ip(),
            'verified_at' => now(),
            'last_used_at' => now(),
        ]);

        return Cookie::make(
            self::COOKIE,
            $token,
            self::TRUST_DAYS * 24 * 60,
            config('session.path'),
            config('session.domain'),
            config('session.secure') ?? $request->isSecure(),
            true,  // httpOnly
            false,
            config('session.same_site', 'lax'),
        );
    }

    /** 撤銷使用者全部受信任裝置（密碼變更／重設時呼叫） */
    public function revokeDevices(User $user): void
    {
        TrustedDevice::where('user_id', $user->id)->delete();
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 通過雙重驗證並勾選「記住此裝置」的裝置。
 * 瀏覽器 Cookie 存隨機憑證明文，資料庫只存其 SHA-256 雜湊。
 */
class TrustedDevice extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'user_agent', 'ip_address', 'verified_at', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

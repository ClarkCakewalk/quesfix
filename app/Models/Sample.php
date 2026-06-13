<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 樣本索引與鎖定
 */
class Sample extends Model
{
    /** 鎖定有效時間（分鐘），前端 heartbeat 續鎖 */
    public const LOCK_MINUTES = 10;

    protected $fillable = ['ques_id', 'sample_id', 'locked_by', 'locked_at', 'lock_expires_at'];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'lock_expires_at' => 'datetime',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'ques_id');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isLocked(): bool
    {
        return $this->locked_by !== null
            && $this->lock_expires_at !== null
            && $this->lock_expires_at->isFuture();
    }

    public function isLockedByOther(User $user): bool
    {
        return $this->isLocked() && $this->locked_by !== $user->id;
    }
}

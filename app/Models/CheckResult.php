<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 檢核結果
 *
 * error 狀態：null=未處理, 0=接受, 1=錯誤且算錯, 2=錯誤不算錯, 3=重新確認
 * resolved_at 非 null 表示資料修正後條件已不再觸發（不列入待辦）
 */
class CheckResult extends Model
{
    public const ERROR_ACCEPTED = 0;

    public const ERROR_COUNTED = 1;

    public const ERROR_NOT_COUNTED = 2;

    public const ERROR_RECHECK = 3;

    public const ERROR_LABELS = [
        self::ERROR_ACCEPTED => '接受',
        self::ERROR_COUNTED => '錯誤且算錯',
        self::ERROR_NOT_COUNTED => '錯誤不算錯',
        self::ERROR_RECHECK => '重新確認',
    ];

    protected $fillable = [
        'ques_id', 'sample_id', 'check_item_id', 'error', 're_survey',
        're_survey_note', 'error_note', 'note', 'checked_by', 'checked_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'error' => 'integer',
            're_survey' => 'boolean',
            'checked_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function checkItem(): BelongsTo
    {
        return $this->belongsTo(CheckItem::class, 'check_item_id');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    /** 待處理：未處理或重新確認，且未因修正而失效 */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')
            ->where(fn (Builder $q) => $q->whereNull('error')->orWhere('error', self::ERROR_RECHECK));
    }

    /** 已處理（接受/錯誤且算錯/錯誤不算錯） */
    public function scopeDone(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')
            ->whereIn('error', [self::ERROR_ACCEPTED, self::ERROR_COUNTED, self::ERROR_NOT_COUNTED]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function isPending(): bool
    {
        return $this->resolved_at === null
            && ($this->error === null || $this->error === self::ERROR_RECHECK);
    }
}

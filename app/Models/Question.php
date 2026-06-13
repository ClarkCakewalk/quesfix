<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 問卷別（專案）
 */
class Question extends Model
{
    public const ROLE_MANAGER = 1;

    public const ROLE_CHECKER = 2;

    protected $fillable = ['code', 'name', 'week_var_id', 'interviewer_var_id'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ques_authes', 'ques_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuesItem::class, 'ques_id');
    }

    public function vars(): HasMany
    {
        return $this->hasMany(QuesVar::class, 'ques_id');
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(OptionGroup::class, 'ques_id');
    }

    public function checkItems(): HasMany
    {
        return $this->hasMany(CheckItem::class, 'ques_id');
    }

    public function checkResults(): HasMany
    {
        return $this->hasMany(CheckResult::class, 'ques_id');
    }

    public function samples(): HasMany
    {
        return $this->hasMany(Sample::class, 'ques_id');
    }

    public function originData(): HasMany
    {
        return $this->hasMany(OriginData::class, 'ques_id');
    }

    public function reportVars(): HasMany
    {
        return $this->hasMany(QuesReportVar::class, 'ques_id')->orderBy('sort_order');
    }

    public function weekVar(): BelongsTo
    {
        return $this->belongsTo(QuesVar::class, 'week_var_id');
    }

    public function interviewerVar(): BelongsTo
    {
        return $this->belongsTo(QuesVar::class, 'interviewer_var_id');
    }

    public function roleOf(User $user): ?int
    {
        $member = $this->members->firstWhere('id', $user->id);

        return $member?->pivot->role;
    }

    /** 系統管理者視同具備專案管理者權限（規劃書 1.2.1.1） */
    public function isManagedBy(User $user): bool
    {
        return $user->isAdmin() || $this->roleOf($user) === self::ROLE_MANAGER;
    }

    public function isCheckableBy(User $user): bool
    {
        return $user->isAdmin() || $this->roleOf($user) !== null;
    }
}

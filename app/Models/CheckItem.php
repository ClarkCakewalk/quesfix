<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 檢核條件
 */
class CheckItem extends Model
{
    protected $fillable = ['ques_id', 'item_name', 'description', 'logic'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'ques_id');
    }

    /** 關聯題目 */
    public function quesItems(): BelongsToMany
    {
        return $this->belongsToMany(QuesItem::class, 'check_item_vars', 'check_item_id', 'ques_item_id')
            ->withTimestamps();
    }

    public function results(): HasMany
    {
        return $this->hasMany(CheckResult::class, 'check_item_id');
    }

    public function fixData(): HasMany
    {
        return $this->hasMany(FixData::class, 'check_item_id');
    }
}

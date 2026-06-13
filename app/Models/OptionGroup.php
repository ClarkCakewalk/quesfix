<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 選項群組（數值標籤代號）
 */
class OptionGroup extends Model
{
    protected $fillable = ['ques_id', 'name', 'description'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'ques_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuesOption::class, 'option_group_id');
    }

    public function vars(): HasMany
    {
        return $this->hasMany(QuesVar::class, 'option_group_id');
    }
}

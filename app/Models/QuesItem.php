<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 題目
 */
class QuesItem extends Model
{
    protected $fillable = ['ques_id', 'name', 'label', 'sort_order'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'ques_id');
    }

    public function vars(): HasMany
    {
        return $this->hasMany(QuesVar::class, 'item_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'item_id');
    }
}

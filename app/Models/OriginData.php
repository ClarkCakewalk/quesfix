<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 原始數據（長格式）
 */
class OriginData extends Model
{
    protected $table = 'origin_data';

    protected $fillable = ['ques_id', 'sample_id', 'var_id', 'value'];

    public function var(): BelongsTo
    {
        return $this->belongsTo(QuesVar::class, 'var_id');
    }

    public function fixes(): HasMany
    {
        return $this->hasMany(FixData::class, 'data_id');
    }

    public function latestFix()
    {
        return $this->hasOne(FixData::class, 'data_id')->latest('id');
    }

    /** 套用修正後的最新值 */
    public function currentValue(): ?string
    {
        $fix = $this->relationLoaded('latestFix') ? $this->latestFix : $this->fixes()->latest('id')->first();

        return $fix?->value ?? $this->value;
    }
}

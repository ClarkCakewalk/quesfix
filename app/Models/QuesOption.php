<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 選項內容（數值標籤）
 */
class QuesOption extends Model
{
    protected $fillable = ['option_group_id', 'value', 'label'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }
}

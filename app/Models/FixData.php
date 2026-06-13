<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 數據修正紀錄（append-only：只新增不更新）
 */
class FixData extends Model
{
    protected $table = 'fix_data';

    protected $fillable = ['data_id', 'value', 'note', 'user_id', 'check_item_id'];

    public function data(): BelongsTo
    {
        return $this->belongsTo(OriginData::class, 'data_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function checkItem(): BelongsTo
    {
        return $this->belongsTo(CheckItem::class, 'check_item_id');
    }
}

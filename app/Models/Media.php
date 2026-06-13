<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 題目關聯影音（截圖/錄音）
 */
class Media extends Model
{
    public const TYPE_IMAGE = 1;

    public const TYPE_AUDIO = 2;

    protected $table = 'media';

    protected $fillable = ['ques_id', 'sample_id', 'item_id', 'type', 'path', 'sort_order'];

    protected function casts(): array
    {
        return ['type' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(QuesItem::class, 'item_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 訪員錯誤報表的訪問相關訊息變數
 */
class QuesReportVar extends Model
{
    protected $fillable = ['ques_id', 'var_id', 'sort_order'];

    public function var(): BelongsTo
    {
        return $this->belongsTo(QuesVar::class, 'var_id');
    }
}

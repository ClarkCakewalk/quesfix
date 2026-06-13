<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Quesfix\StataLogic\VariableCatalog;

/**
 * 變數
 */
class QuesVar extends Model
{
    public const TYPE_OPTION = 1;

    public const TYPE_NUMERIC = 2;

    public const TYPE_TEXT = 3;

    protected $fillable = ['ques_id', 'item_id', 'variable', 'label', 'option_group_id', 'var_type'];

    protected function casts(): array
    {
        return ['var_type' => 'integer'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'ques_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(QuesItem::class, 'item_id');
    }

    public function optionGroup(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }

    /** stata-logic 引擎使用的型別 */
    public function engineType(): string
    {
        return $this->var_type === self::TYPE_TEXT
            ? VariableCatalog::TYPE_STRING
            : VariableCatalog::TYPE_NUMERIC;
    }
}

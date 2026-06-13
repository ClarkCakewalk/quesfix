<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Quesfix\StataLogic\ArrayResolver;
use Quesfix\StataLogic\VariableCatalog;

/**
 * stata-logic 引擎與資料庫的橋接：
 * - 由 ques_vars 建立 VariableCatalog（變數名稱 → 型別）
 * - 由 origin_data + fix_data 最新修訂組出單一樣本的求值資料列
 */
class EngineCatalogService
{
    public function catalogFor(Question $question): VariableCatalog
    {
        $types = $question->vars()
            ->get(['variable', 'var_type'])
            ->mapWithKeys(fn ($v) => [
                $v->variable => $v->var_type === \App\Models\QuesVar::TYPE_TEXT
                    ? VariableCatalog::TYPE_STRING
                    : VariableCatalog::TYPE_NUMERIC,
            ])
            ->all();

        return new VariableCatalog($types);
    }

    /**
     * 取得單一樣本「套用最新修訂後」的變數值（變數名稱 => 字串值）。
     *
     * @return array<string, string|null>
     */
    public function rowFor(Question $question, string $sampleId): array
    {
        $rows = DB::table('origin_data')
            ->join('ques_vars', 'ques_vars.id', '=', 'origin_data.var_id')
            ->leftJoin('fix_data as latest_fix', function ($join) {
                $join->on('latest_fix.data_id', '=', 'origin_data.id')
                    ->whereRaw('latest_fix.id = (select max(f2.id) from fix_data f2 where f2.data_id = origin_data.id)');
            })
            ->where('origin_data.ques_id', $question->id)
            ->where('origin_data.sample_id', $sampleId)
            ->get([
                'ques_vars.variable',
                'origin_data.value as origin_value',
                'latest_fix.value as fix_value',
            ]);

        $values = [];
        foreach ($rows as $row) {
            $values[$row->variable] = $row->fix_value ?? $row->origin_value;
        }

        return $values;
    }

    public function resolverFor(Question $question, string $sampleId, ?VariableCatalog $catalog = null): ArrayResolver
    {
        return ArrayResolver::fromStrings(
            $this->rowFor($question, $sampleId),
            $catalog ?? $this->catalogFor($question),
        );
    }
}

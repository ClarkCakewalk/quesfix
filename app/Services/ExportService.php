<?php

namespace App\Services;

use App\Models\CheckResult;
use App\Models\Question;
use App\Models\QuesVar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 報表與資料匯出（規劃書 5.2）
 */
class ExportService
{
    /**
     * 訪員錯誤報表資料列（錯誤且算錯）。
     *
     * @param  string[]  $weeks  週數篩選（空陣列＝不篩選）
     * @return Collection<int, array>
     */
    public function interviewerErrorRows(Question $question, array $weeks = []): Collection
    {
        return $this->reportRows(
            $question,
            CheckResult::where('ques_id', $question->id)
                ->active()
                ->where('error', CheckResult::ERROR_COUNTED)
                ->with('checkItem.quesItems.vars'),
            $weeks,
        );
    }

    /**
     * 檢核結果報表：無誤／需修正／需補問 三組資料列。
     *
     * @return array{無誤: Collection, 需修正: Collection, 需補問: Collection}
     */
    public function checkResultSheets(Question $question): array
    {
        $base = fn () => CheckResult::where('ques_id', $question->id)
            ->active()
            ->with('checkItem.quesItems.vars');

        return [
            '無誤' => $this->reportRows($question, $base()->where('error', CheckResult::ERROR_ACCEPTED)),
            '需修正' => $this->reportRows($question, $base()
                ->whereIn('error', [CheckResult::ERROR_COUNTED, CheckResult::ERROR_NOT_COUNTED])
                ->where('re_survey', false)),
            '需補問' => $this->reportRows($question, $base()->where('re_survey', true)),
        ];
    }

    /**
     * Stata 資料修正程式（.do）。
     */
    public function fixDo(Question $question): string
    {
        $idIsString = $this->idIsString($question);

        $fixes = DB::table('fix_data')
            ->join('origin_data', 'origin_data.id', '=', 'fix_data.data_id')
            ->join('ques_vars', 'ques_vars.id', '=', 'origin_data.var_id')
            ->join('check_items', 'check_items.id', '=', 'fix_data.check_item_id')
            ->join('users', 'users.id', '=', 'fix_data.user_id')
            ->where('origin_data.ques_id', $question->id)
            ->orderBy('origin_data.sample_id')
            ->orderBy('fix_data.created_at')
            ->orderBy('fix_data.id')
            ->get([
                'origin_data.sample_id',
                'ques_vars.variable',
                'ques_vars.var_type',
                'fix_data.value',
                'check_items.item_name',
                'users.name as fixer',
            ]);

        $lines = [
            '// '.$question->name.' 資料修正程式',
            '// 產生時間：'.now()->toDateTimeString(),
            '',
        ];

        foreach ($fixes as $fix) {
            $value = $this->stataValue($fix->value, (int) $fix->var_type);
            $id = $idIsString ? '"'.$fix->sample_id.'"' : $fix->sample_id;

            $lines[] = "replace {$fix->variable}={$value} if id=={$id} //修正邏輯：{$fix->item_name}，修正者：{$fix->fixer}";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * 修正後資料檔（CSV 內容，UTF-8、逗號分隔）。
     */
    public function fixedDataCsv(Question $question): string
    {
        $vars = $question->vars()
            ->join('ques_items', 'ques_items.id', '=', 'ques_vars.item_id')
            ->orderBy('ques_items.sort_order')
            ->orderBy('ques_vars.id')
            ->get(['ques_vars.id', 'ques_vars.variable']);

        $rows = DB::table('origin_data')
            ->leftJoin('fix_data as latest_fix', function ($join) {
                $join->on('latest_fix.data_id', '=', 'origin_data.id')
                    ->whereRaw('latest_fix.id = (select max(f2.id) from fix_data f2 where f2.data_id = origin_data.id)');
            })
            ->where('origin_data.ques_id', $question->id)
            ->get([
                'origin_data.sample_id',
                'origin_data.var_id',
                DB::raw('coalesce(latest_fix.value, origin_data.value) as value'),
            ])
            ->groupBy('sample_id');

        $out = fopen('php://temp', 'r+');
        fputcsv($out, $vars->pluck('variable')->all(), ',', '"', '');

        foreach ($rows->sortKeys() as $values) {
            $byVar = $values->pluck('value', 'var_id');
            fputcsv($out, $vars->map(fn ($v) => $byVar[$v->id] ?? '')->all(), ',', '"', '');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * 修正後資料檔的 Stata 讀檔程式。
     */
    public function readDo(Question $question, string $csvFileName): string
    {
        $stringVars = $question->vars()
            ->where('var_type', QuesVar::TYPE_TEXT)
            ->pluck('variable');

        $lines = [
            '// '.$question->name.' 讀檔程式',
            'clear',
            "import delimited \"{$csvFileName}\", encoding(utf-8) stringcols(_all) case(preserve)",
        ];

        $lines[] = '// 數值變數轉型';
        foreach ($question->vars()->where('var_type', '!=', QuesVar::TYPE_TEXT)->pluck('variable') as $variable) {
            if ($variable === 'id' && $stringVars->contains('id')) {
                continue;
            }
            $lines[] = "destring {$variable}, replace force";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * 報表共用列組裝：訪員代號、樣本編號、邏輯相關答案、訪問相關訊息、檢核條件說明、回覆訊息。
     */
    private function reportRows(Question $question, $resultsQuery, array $weeks = []): Collection
    {
        $results = $resultsQuery->orderBy('sample_id')->get();
        $sampleIds = $results->pluck('sample_id')->unique()->values();

        // 一次撈出所有樣本的原始值（var_id => value，每樣本）
        $values = DB::table('origin_data')
            ->where('ques_id', $question->id)
            ->whereIn('sample_id', $sampleIds)
            ->get(['sample_id', 'var_id', 'value'])
            ->groupBy('sample_id')
            ->map(fn ($rows) => $rows->pluck('value', 'var_id'));

        // 標籤對照
        $labels = DB::table('ques_options')
            ->join('option_groups', 'option_groups.id', '=', 'ques_options.option_group_id')
            ->where('option_groups.ques_id', $question->id)
            ->get(['ques_options.option_group_id', 'ques_options.value', 'ques_options.label'])
            ->groupBy('option_group_id')
            ->map(fn ($opts) => $opts->pluck('label', 'value'));

        $varsById = $question->vars()->get()->keyBy('id');

        $weekVarId = $question->week_var_id;
        $interviewerVarId = $question->interviewer_var_id;
        $reportVars = $question->reportVars()->with('var')->get();

        return $results
            ->filter(function (CheckResult $r) use ($weeks, $weekVarId, $values) {
                if ($weeks === [] || $weekVarId === null) {
                    return true;
                }

                return in_array((string) ($values[$r->sample_id][$weekVarId] ?? ''), $weeks, true);
            })
            ->map(function (CheckResult $r) use ($values, $labels, $varsById, $interviewerVarId, $reportVars) {
                $sampleValues = $values[$r->sample_id] ?? collect();

                $applyLabel = function (?int $varId) use ($sampleValues, $labels, $varsById) {
                    if ($varId === null) {
                        return '';
                    }
                    $value = (string) ($sampleValues[$varId] ?? '');
                    $var = $varsById[$varId] ?? null;
                    $label = $var?->option_group_id !== null
                        ? ($labels[$var->option_group_id][$value] ?? null)
                        : null;

                    return $label !== null ? "{$value} {$label}" : $value;
                };

                // 邏輯相關答案：關聯題目的所有變數
                $qLines = [];
                $vLines = [];
                $aLines = [];
                foreach ($r->checkItem->quesItems as $item) {
                    foreach ($item->vars as $var) {
                        $qLines[] = "{$item->name} {$item->label}";
                        $vLines[] = "{$var->variable} {$var->label}";
                        $aLines[] = $applyLabel($var->id);
                    }
                }

                $row = [
                    '訪員代號' => $applyLabel($interviewerVarId),
                    '樣本編號' => $r->sample_id,
                    '題目' => implode("\n", $qLines),
                    '變數' => implode("\n", $vLines),
                    '答案' => implode("\n", $aLines),
                ];

                foreach ($reportVars as $rv) {
                    $row[$rv->var->variable] = $applyLabel($rv->var_id);
                }

                $row['檢核條件說明'] = $r->checkItem->description;
                $row['計畫回覆訊息'] = $r->error_note ?? '';
                $row['補問訊息'] = $r->re_survey_note ?? '';
                $row['檢核結果說明'] = $r->note ?? '';

                return $row;
            })
            ->values();
    }

    private function stataValue(string $value, int $varType): string
    {
        if ($varType === QuesVar::TYPE_TEXT) {
            return '"'.str_replace('"', '', $value).'"';
        }

        return trim($value) === '' ? '.' : trim($value);
    }

    private function idIsString(Question $question): bool
    {
        $idVar = $question->vars()->where('variable', 'id')->first();

        return $idVar === null || $idVar->var_type === QuesVar::TYPE_TEXT;
    }
}

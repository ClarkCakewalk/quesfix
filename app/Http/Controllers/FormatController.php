<?php

namespace App\Http\Controllers;

use App\Models\OptionGroup;
use App\Models\Question;
use App\Models\QuesOption;
use App\Models\QuesVar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 資料格式管理：題項（題目＋變數）與數值標籤（限專案管理者）
 */
class FormatController extends Controller
{
    /** 題項列表 */
    public function items(Request $request, Question $question): Response
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $q = trim((string) $request->query('q', ''));

        $vars = QuesVar::query()
            ->where('ques_vars.ques_id', $question->id)
            ->join('ques_items', 'ques_items.id', '=', 'ques_vars.item_id')
            ->leftJoin('option_groups', 'option_groups.id', '=', 'ques_vars.option_group_id')
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('ques_items.name', 'like', "%{$q}%")
                    ->orWhere('ques_items.label', 'like', "%{$q}%")
                    ->orWhere('ques_vars.variable', 'like', "%{$q}%")
                    ->orWhere('ques_vars.label', 'like', "%{$q}%")
                    ->orWhere('option_groups.name', 'like', "%{$q}%"),
            ))
            ->select([
                'ques_vars.id', 'ques_vars.item_id', 'ques_vars.variable',
                'ques_vars.label as var_label', 'ques_vars.var_type',
                'ques_items.name as item_name', 'ques_items.label as item_label',
                'option_groups.name as group_name',
            ])
            ->orderBy('ques_items.sort_order')
            ->orderBy('ques_vars.id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn ($v) => [
                'id' => $v->id,
                'item_id' => $v->item_id,
                'item_name' => $v->item_name,
                'item_label' => $v->item_label,
                'variable' => $v->variable,
                'label' => $v->var_label,
                'group_name' => $v->group_name,
                'var_type' => $v->var_type,
            ]);

        return Inertia::render('Formats/Items', [
            'question' => $question->only('id', 'code', 'name'),
            'vars' => $vars,
            'filters' => ['q' => $q],
            'groups' => $question->optionGroups()->orderBy('name')->pluck('name'),
        ]);
    }

    /** 題項新增（題目名稱重複但變數未重複 → 只新增變數） */
    public function storeItem(Request $request, Question $question): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $data = $this->validateItem($request, $question);

        $exists = $question->vars()->where('variable', $data['variable'])->exists();
        $item = $question->items()->where('name', $data['item_name'])->first();

        if ($exists) {
            throw ValidationException::withMessages(['variable' => '變數名稱已存在。']);
        }

        DB::transaction(function () use ($question, $item, $data) {
            $item ??= $question->items()->create([
                'name' => $data['item_name'],
                'label' => $data['item_label'],
                'sort_order' => (int) $question->items()->max('sort_order') + 1,
            ]);

            $item->vars()->create([
                'ques_id' => $question->id,
                'variable' => $data['variable'],
                'label' => $data['label'],
                'option_group_id' => $data['group_id'],
                'var_type' => $data['var_type'],
            ]);
        });

        return back()->with('status', '題項已新增。');
    }

    /** 題項修改：修正題目名稱/標籤時，該題目所有變數連動變更 */
    public function updateVar(Request $request, Question $question, QuesVar $var): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()) && $var->ques_id === $question->id, 403);

        $data = $this->validateItem($request, $question);

        $duplicate = $question->vars()
            ->where('variable', $data['variable'])
            ->where('id', '!=', $var->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['variable' => '變數名稱已存在。']);
        }

        DB::transaction(function () use ($question, $var, $data) {
            // 題目名稱/標籤更新（同題目所有變數共用同一筆 ques_items）
            $item = $var->item;

            if ($item->name !== $data['item_name']) {
                $other = $question->items()->where('name', $data['item_name'])->where('id', '!=', $item->id)->first();

                if ($other !== null) {
                    // 改為既有題目 → 變數移掛該題目
                    $oldItem = $item;
                    $var->item()->associate($other)->save();

                    if ($oldItem->vars()->count() === 0) {
                        $oldItem->delete();
                    }

                    $item = $other;
                } else {
                    $item->update(['name' => $data['item_name'], 'label' => $data['item_label']]);
                }
            } else {
                $item->update(['label' => $data['item_label']]);
            }

            $var->update([
                'variable' => $data['variable'],
                'label' => $data['label'],
                'option_group_id' => $data['group_id'],
                'var_type' => $data['var_type'],
            ]);
        });

        return back()->with('status', '題項已更新。');
    }

    /** 刪除變數；若上層題目已無變數，一併刪除題目 */
    public function destroyVar(Request $request, Question $question, QuesVar $var): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()) && $var->ques_id === $question->id, 403);

        DB::transaction(function () use ($var) {
            $item = $var->item;
            $var->delete();

            if ($item->vars()->count() === 0) {
                $item->delete();
            }
        });

        return back()->with('status', '變數已刪除。');
    }

    /** 數值標籤列表 */
    public function labels(Request $request, Question $question): Response
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $q = trim((string) $request->query('q', ''));

        $options = QuesOption::query()
            ->join('option_groups', 'option_groups.id', '=', 'ques_options.option_group_id')
            ->where('option_groups.ques_id', $question->id)
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('option_groups.name', 'like', "%{$q}%")
                    ->orWhere('ques_options.label', 'like', "%{$q}%"),
            ))
            ->select([
                'ques_options.id', 'ques_options.value',
                'ques_options.label as option_label',
                'option_groups.name as group_name',
            ])
            ->orderBy('option_groups.name')
            ->orderByRaw('ques_options.value + 0')
            ->paginate(50)
            ->withQueryString()
            ->through(fn ($o) => [
                'id' => $o->id,
                'group_name' => $o->group_name,
                'value' => $o->value,
                'label' => $o->option_label,
            ]);

        return Inertia::render('Formats/Labels', [
            'question' => $question->only('id', 'code', 'name'),
            'options' => $options,
            'filters' => ['q' => $q],
        ]);
    }

    /** 新增數值標籤：代號存在且數值重複 → 拒絕 */
    public function storeLabel(Request $request, Question $question): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $data = $request->validate([
            'group_name' => ['required', 'string', 'max:128'],
            'value' => ['required', 'string', 'max:128'],
            'label' => ['required', 'string', 'max:1024'],
        ], [], ['group_name' => '標籤代號', 'value' => '數值', 'label' => '數值說明']);

        $group = OptionGroup::firstOrCreate(['ques_id' => $question->id, 'name' => $data['group_name']]);

        if ($group->options()->where('value', $data['value'])->exists()) {
            throw ValidationException::withMessages(['value' => '此代號已存在相同數值，拒絕新增。']);
        }

        $group->options()->create(['value' => $data['value'], 'label' => $data['label']]);

        return back()->with('status', '數值標籤已新增。');
    }

    /** 修改數值標籤；修改代號時所有相同代號一併修改（重新命名群組） */
    public function updateOption(Request $request, Question $question, QuesOption $option): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()) && $option->group->ques_id === $question->id, 403);

        $data = $request->validate([
            'group_name' => ['required', 'string', 'max:128'],
            'value' => ['required', 'string', 'max:128'],
            'label' => ['required', 'string', 'max:1024'],
        ], [], ['group_name' => '標籤代號', 'value' => '數值', 'label' => '數值說明']);

        DB::transaction(function () use ($question, $option, $data) {
            $group = $option->group;

            if ($group->name !== $data['group_name']) {
                $existing = $question->optionGroups()->where('name', $data['group_name'])->first();

                if ($existing !== null) {
                    throw ValidationException::withMessages([
                        'group_name' => "代號 {$data['group_name']} 已存在，請改用該代號管理或先刪除。",
                    ]);
                }

                $group->update(['name' => $data['group_name']]);
            }

            $duplicate = $group->options()
                ->where('value', $data['value'])
                ->where('id', '!=', $option->id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['value' => '此代號已存在相同數值。']);
            }

            $option->update(['value' => $data['value'], 'label' => $data['label']]);
        });

        return back()->with('status', '數值標籤已更新。');
    }

    public function destroyOption(Request $request, Question $question, QuesOption $option): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()) && $option->group->ques_id === $question->id, 403);

        DB::transaction(function () use ($option) {
            $group = $option->group;
            $option->delete();

            if ($group->options()->count() === 0) {
                $group->delete();
            }
        });

        return back()->with('status', '數值標籤已刪除。');
    }

    private function validateItem(Request $request, Question $question): array
    {
        $data = $request->validate([
            'item_name' => ['required', 'string', 'max:255'],
            'item_label' => ['required', 'string', 'max:1024'],
            'variable' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:1024'],
            'group_name' => ['nullable', 'string', 'max:128'],
            'var_type' => ['required', 'integer', 'in:1,2,3'],
        ], [
            'variable.regex' => '變數名稱須以英文字母或底線開頭，僅含英數與底線。',
        ], [
            'item_name' => '題目名稱', 'item_label' => '題目標籤',
            'variable' => '變數名稱', 'label' => '變數標籤', 'group_name' => '關聯數值標籤',
        ]);

        $data['group_id'] = null;

        if (($data['group_name'] ?? '') !== '' && $data['group_name'] !== null) {
            $group = $question->optionGroups()->where('name', $data['group_name'])->first();

            if ($group === null) {
                throw ValidationException::withMessages(['group_name' => '關聯數值標籤不存在。']);
            }

            $data['group_id'] = $group->id;
        }

        return $data;
    }
}

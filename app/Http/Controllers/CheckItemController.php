<?php

namespace App\Http\Controllers;

use App\Models\CheckItem;
use App\Models\Question;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Quesfix\StataLogic\LogicEngine;

/**
 * 檢核條件管理（限專案管理者）
 */
class CheckItemController extends Controller
{
    public function __construct(private readonly \App\Services\EngineCatalogService $catalogService)
    {
    }

    public function index(Request $request, Question $question): Response
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $q = trim((string) $request->query('q', ''));

        $items = $question->checkItems()
            ->with('quesItems:id,name')
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('item_name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%")
                    ->orWhere('logic', 'like', "%{$q}%"),
            ))
            ->orderBy('item_name')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (CheckItem $item) => [
                'id' => $item->id,
                'item_name' => $item->item_name,
                'description' => $item->description,
                'logic' => $item->logic,
                'related_items' => $item->quesItems->pluck('name')->implode(','),
            ]);

        return Inertia::render('Checks/Items', [
            'question' => $question->only('id', 'code', 'name'),
            'items' => $items,
            'filters' => ['q' => $q],
        ]);
    }

    public function store(Request $request, Question $question): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()), 403);

        $data = $this->validateCheckItem($request, $question);

        if ($question->checkItems()->where('item_name', $data['item_name'])->exists()) {
            throw ValidationException::withMessages(['item_name' => '條件代號已存在。']);
        }

        DB::transaction(function () use ($question, $data) {
            $item = $question->checkItems()->create([
                'item_name' => $data['item_name'],
                'description' => $data['description'],
                'logic' => $data['logic'],
            ]);
            $item->quesItems()->sync($data['item_ids']);
        });

        return back()->with('status', '檢核條件已新增。')->with('warnings', $data['warnings']);
    }

    public function update(Request $request, Question $question, CheckItem $checkItem): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()) && $checkItem->ques_id === $question->id, 403);

        // 條件代號不可編輯（規劃書 3.3.2）
        $data = $this->validateCheckItem($request, $question, requireName: false);

        DB::transaction(function () use ($checkItem, $data) {
            $checkItem->update([
                'description' => $data['description'],
                'logic' => $data['logic'],
            ]);
            $checkItem->quesItems()->sync($data['item_ids']);
        });

        return back()->with('status', '檢核條件已更新。')->with('warnings', $data['warnings']);
    }

    /** 刪除檢核條件：連帶刪除關聯的檢核結果與修正紀錄（FK cascade） */
    public function destroy(Request $request, Question $question, CheckItem $checkItem): RedirectResponse
    {
        abort_unless($question->isManagedBy($request->user()) && $checkItem->ques_id === $question->id, 403);

        $checkItem->delete();

        return back()->with('status', '檢核條件及其關聯紀錄已刪除。');
    }

    private function validateCheckItem(Request $request, Question $question, bool $requireName = true): array
    {
        $data = $request->validate([
            'item_name' => $requireName ? ['required', 'string', 'max:128'] : ['nullable'],
            'description' => ['required', 'string'],
            'related_items' => ['nullable', 'string'],
            'logic' => ['required', 'string'],
        ], [], [
            'item_name' => '條件代號', 'description' => '條件敘述',
            'related_items' => '關聯題目', 'logic' => '檢核邏輯',
        ]);

        // 關聯題目（逗號分隔題目名稱）存在性
        $itemsByName = $question->items()->pluck('id', 'name');
        $itemIds = [];
        $missing = [];

        foreach (array_filter(array_map('trim', explode(',', $data['related_items'] ?? ''))) as $name) {
            if ($itemsByName->has($name)) {
                $itemIds[] = $itemsByName[$name];
            } else {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'related_items' => '關聯題目不存在：'.implode('、', $missing),
            ]);
        }

        // 檢核邏輯驗證（語法、變數、型別）
        $result = LogicEngine::validate($data['logic'], $this->catalogService->catalogFor($question));

        if (! $result->ok()) {
            throw ValidationException::withMessages([
                'logic' => implode('；', array_map('strval', $result->errors)),
            ]);
        }

        $data['item_ids'] = $itemIds;
        $data['warnings'] = array_map('strval', $result->warnings);

        return $data;
    }
}

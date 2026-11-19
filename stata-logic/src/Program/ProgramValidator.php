<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Program;

use Quesfix\StataLogic\Exceptions\SyntaxError;
use Quesfix\StataLogic\ValidationIssue;
use Quesfix\StataLogic\ValidationResult;
use Quesfix\StataLogic\Validator;
use Quesfix\StataLogic\VariableCatalog;

/**
 * 程式格式（gen check = ...）的匯入驗證：
 * 1. 程式可否解析（含 forvalues/foreach 展開、if 區塊）
 * 2. 依語句順序追蹤變數與型別：資料格式變數 ∪ 程式衍生變數
 *    （gen 依運算式決定型別；split 產生字串片段；destring 轉為數值；rename 沿用原型別）
 * 3. 各運算式引用的變數是否存在、型別是否一致
 * 4. 是否產生 check 變數
 * 5. check 運算式同層混用 &/| 未加括號 → 警告
 *
 * cap 前綴的語句：引用不存在的變數改報警告（Stata 執行時亦會略過該句）。
 * if 區塊內各分支皆照常驗證（變數須存在於資料格式）。
 */
final class ProgramValidator
{
    /** @var ValidationIssue[] */
    private array $errors = [];

    /** @var ValidationIssue[] */
    private array $warnings = [];

    private bool $hasCheck = false;

    public function __construct(private readonly VariableCatalog $catalog)
    {
    }

    public function validate(string $logic): ValidationResult
    {
        try {
            $program = new StataProgram($logic, $this->catalog->names());
        } catch (SyntaxError $e) {
            return new ValidationResult([new ValidationIssue($e->detail, $e->pos)]);
        }

        $this->errors = [];
        $this->warnings = [];
        $this->hasCheck = false;

        $this->walk($program->statements(), $this->catalog);

        if (! $this->hasCheck) {
            $this->errors[] = new ValidationIssue('檢核邏輯未產生 check 變數（應以 gen check = (...) 或 egen check = ... 結尾）。');
        }

        return new ValidationResult($this->unique($this->errors), $this->unique($this->warnings));
    }

    /**
     * 迴圈展開或多分支可能產生完全相同的訊息，只保留一筆。
     *
     * @param  ValidationIssue[]  $issues
     * @return ValidationIssue[]
     */
    private function unique(array $issues): array
    {
        $seen = [];

        return array_values(array_filter($issues, function (ValidationIssue $issue) use (&$seen) {
            $key = (string) $issue;
            if (isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));
    }

    private function walk(array $statements, VariableCatalog $catalog): VariableCatalog
    {
        foreach ($statements as $stmt) {
            $catalog = $this->validateStatement($stmt, $catalog);
        }

        return $catalog;
    }

    private function validateStatement(array $stmt, VariableCatalog $catalog): VariableCatalog
    {
        $lenient = $stmt['cap'];

        switch ($stmt['kind']) {
            case 'assign':
                $type = $this->expr($stmt['expr'], $catalog, $stmt['var'] === 'check', $lenient);
                if ($stmt['cond'] !== null) {
                    $this->expr($stmt['cond'], $catalog, false, $lenient);
                }

                if ($stmt['cmd'] === 'replace' && $catalog->has($stmt['var']) && $catalog->typeOf($stmt['var']) !== $type) {
                    $this->errors[] = new ValidationIssue(sprintf(
                        "replace %s：變數為%s，不能指定為%s",
                        $stmt['var'],
                        $this->typeName($catalog->typeOf($stmt['var'])),
                        $this->typeName($type),
                    ));
                }

                return $this->define($catalog, $stmt['var'], $type);

            case 'egen':
                return $this->define($catalog, $stmt['var'], VariableCatalog::TYPE_NUMERIC);

            case 'recode':
                if ($stmt['cond'] !== null) {
                    $this->expr($stmt['cond'], $catalog, false, $lenient);
                }
                foreach ($stmt['vars'] as $i => $name) {
                    if ($this->requireVar($catalog, $name, 'recode', $lenient)
                        && $catalog->typeOf($name) !== VariableCatalog::TYPE_NUMERIC) {
                        $this->errors[] = new ValidationIssue("recode：變數 '{$name}' 為字串，無法重新編碼");
                    }
                    $catalog = $this->define($catalog, $stmt['gen'][$i] ?? $name, VariableCatalog::TYPE_NUMERIC);
                }

                return $catalog;

            case 'split':
                if ($stmt['cond'] !== null) {
                    $this->expr($stmt['cond'], $catalog, false, $lenient);
                }
                if ($this->requireVar($catalog, $stmt['var'], 'split', $lenient)
                    && $catalog->typeOf($stmt['var']) !== VariableCatalog::TYPE_STRING) {
                    $this->errors[] = new ValidationIssue(
                        "split：變數 '{$stmt['var']}' 在資料格式中不是字串（文字）變數，請確認資料格式的型別設定",
                    );
                }

                return $catalog->withStub(
                    $stmt['stub'],
                    $stmt['destring'] ? VariableCatalog::TYPE_NUMERIC : VariableCatalog::TYPE_STRING,
                );

            case 'destring':
                foreach ($stmt['vars'] as $i => $name) {
                    $this->requireVar($catalog, $name, 'destring', $lenient);
                    $catalog = $this->define($catalog, $stmt['gen'][$i] ?? $name, VariableCatalog::TYPE_NUMERIC);
                }

                return $catalog;

            case 'rename':
                foreach ($stmt['pairs'] as [$old, $new]) {
                    $type = $this->requireVar($catalog, $old, 'rename', $lenient)
                        ? $catalog->typeOf($old)
                        : VariableCatalog::TYPE_NUMERIC;
                    $catalog = $this->define($catalog, $new, $type);
                }

                return $catalog;

            case 'if':
                $merged = $catalog;
                foreach ($stmt['branches'] as $branch) {
                    if ($branch['cond'] !== null) {
                        $this->expr($branch['cond'], $catalog, false, $lenient);
                    }
                    $merged = $merged->merge($this->walk($branch['body'], $catalog));
                }

                return $merged;

            default: // drop
                return $catalog;
        }
    }

    /** 驗證運算式並回傳其型別 */
    private function expr(\Quesfix\StataLogic\Ast\Node $node, VariableCatalog $catalog, bool $isCheck, bool $lenient): string
    {
        $validator = new Validator($catalog);
        $result = $validator->validateAst($node, warnMixedAndOr: $isCheck, unknownAsWarning: $lenient);

        $this->errors = [...$this->errors, ...$result->errors];
        $this->warnings = [...$this->warnings, ...$result->warnings];

        return $validator->lastType();
    }

    private function requireVar(VariableCatalog $catalog, string $name, string $cmd, bool $lenient): bool
    {
        if ($catalog->has($name)) {
            return true;
        }

        if (! $lenient) {
            $this->errors[] = new ValidationIssue("{$cmd}：檢核邏輯包含不存在的變數 '{$name}'");
        }

        return false;
    }

    private function define(VariableCatalog $catalog, string $name, string $type): VariableCatalog
    {
        if ($name === 'check') {
            $this->hasCheck = true;
        }

        return $catalog->withType($name, $type);
    }

    private function typeName(string $type): string
    {
        return $type === VariableCatalog::TYPE_STRING ? '字串' : '數值';
    }
}

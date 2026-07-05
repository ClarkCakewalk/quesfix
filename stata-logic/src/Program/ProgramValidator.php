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
 * 1. 程式可否解析（含 forvalues 展開）
 * 2. 是否含不支援的指令（rename/split/destring）→ 錯誤，需 Stata 前處理
 * 3. 是否產生 check 變數
 * 4. 各運算式引用的變數是否存在（資料格式變數 ∪ 程式衍生變數）、型別是否一致
 * 5. check 運算式同層混用 &/| 未加括號 → 警告
 */
final class ProgramValidator
{
    public function __construct(private readonly VariableCatalog $catalog)
    {
    }

    public function validate(string $logic): ValidationResult
    {
        try {
            $program = new StataProgram($logic);
        } catch (SyntaxError $e) {
            return new ValidationResult([new ValidationIssue($e->getMessage(), $e->pos)]);
        }

        $analysis = $program->analyze();
        $errors = [];
        $warnings = [];

        foreach ($analysis['unsupported'] as $cmd) {
            $errors[] = new ValidationIssue(
                "包含不支援的指令「{$cmd}」：字串解析（rename/split/destring）需於 Stata 前處理完成後，"
                ."將衍生變數一併匯入，並改以該變數撰寫檢核邏輯。",
            );
        }

        if (! $analysis['hasCheck']) {
            $errors[] = new ValidationIssue('檢核邏輯未產生 check 變數（應以 gen check = (...) 或 egen check = ... 結尾）。');
        }

        // 衍生變數視為數值型，加入目錄後對各運算式做變數存在性與型別檢查
        $augmented = $this->catalog->withNumeric($analysis['defined']);
        $validator = new Validator($augmented);

        foreach ($analysis['exprs'] as $expr) {
            $result = $validator->validateAst($expr['node'], warnMixedAndOr: $expr['isCheck']);
            $errors = [...$errors, ...$result->errors];
            $warnings = [...$warnings, ...$result->warnings];
        }

        return new ValidationResult($errors, $warnings);
    }
}

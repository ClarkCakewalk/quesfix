<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

use Quesfix\StataLogic\Ast\Node;
use Quesfix\StataLogic\Program\ProgramValidator;
use Quesfix\StataLogic\Program\StataProgram;

/**
 * 對外的單一入口。
 *
 * 檢核邏輯有兩種形式，皆自動偵測：
 *   (a) 單一布林運算式：例 (a01 != Sex)
 *   (b) Stata 子集程式：以 gen check = (...) 或 egen check = ... 結尾，
 *       前可含 gen/replace/egen 衍生變數與 forvalues 迴圈。
 *
 * 匯入驗證：
 *   $result = LogicEngine::validate($logic, $catalog);
 *   if (! $result->ok()) { ... 拒絕匯入，顯示 $result->errors ... }
 *   foreach ($result->warnings as $w) { ... 顯示警告請使用者確認 ... }
 *
 * 檢核求值（每樣本一列）：
 *   $hit = LogicEngine::evaluate($logic, ArrayResolver::fromStrings($row, $catalog));
 */
final class LogicEngine
{
    public static function isProgram(string $logic): bool
    {
        return StataProgram::looksLikeProgram($logic);
    }

    public static function parse(string $logic): Node
    {
        return (new Parser($logic))->parse();
    }

    /**
     * 編譯檢核邏輯（運算式或程式）為可重複求值的物件，供批次檢核快取使用。
     */
    public static function compile(string $logic): CompiledLogic
    {
        return new CompiledLogic(
            self::isProgram($logic) ? new StataProgram($logic) : self::parse($logic),
        );
    }

    public static function validate(string $logic, VariableCatalog $catalog): ValidationResult
    {
        return self::isProgram($logic)
            ? (new ProgramValidator($catalog))->validate($logic)
            : (new Validator($catalog))->validate($logic);
    }

    /**
     * @param array<string, float|int|string|null>|VariableResolverInterface $row
     */
    public static function evaluate(Node|string $logic, array|VariableResolverInterface $row): bool
    {
        $resolver = is_array($row) ? new ArrayResolver($row) : $row;

        if (is_string($logic) && self::isProgram($logic)) {
            return (new StataProgram($logic))->run($resolver);
        }

        $ast = is_string($logic) ? self::parse($logic) : $logic;

        return (new Evaluator($resolver))->evaluateBool($ast);
    }
}

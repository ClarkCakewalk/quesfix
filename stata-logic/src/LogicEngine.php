<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

use Quesfix\StataLogic\Ast\Node;

/**
 * 對外的單一入口。
 *
 * 匯入驗證：
 *   $result = LogicEngine::validate($logic, $catalog);
 *   if (! $result->ok()) { ... 拒絕匯入，顯示 $result->errors ... }
 *   foreach ($result->warnings as $w) { ... 顯示警告請使用者確認 ... }
 *
 * 檢核求值（每樣本一列）：
 *   $hit = LogicEngine::evaluate($ast, ArrayResolver::fromStrings($row, $catalog));
 */
final class LogicEngine
{
    public static function parse(string $logic): Node
    {
        return (new Parser($logic))->parse();
    }

    public static function validate(string $logic, VariableCatalog $catalog): ValidationResult
    {
        return (new Validator($catalog))->validate($logic);
    }

    /**
     * @param array<string, float|int|string|null>|VariableResolverInterface $row
     */
    public static function evaluate(Node|string $logic, array|VariableResolverInterface $row): bool
    {
        $ast = is_string($logic) ? self::parse($logic) : $logic;
        $resolver = is_array($row) ? new ArrayResolver($row) : $row;

        return (new Evaluator($resolver))->evaluateBool($ast);
    }
}

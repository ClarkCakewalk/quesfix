<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

use Quesfix\StataLogic\Ast\AbsCall;
use Quesfix\StataLogic\Ast\AnyMatch;
use Quesfix\StataLogic\Ast\BinaryOp;
use Quesfix\StataLogic\Ast\InList;
use Quesfix\StataLogic\Ast\InRange;
use Quesfix\StataLogic\Ast\MissingLiteral;
use Quesfix\StataLogic\Ast\Node;
use Quesfix\StataLogic\Ast\NumberLiteral;
use Quesfix\StataLogic\Ast\StringLiteral;
use Quesfix\StataLogic\Ast\UnaryOp;
use Quesfix\StataLogic\Ast\Variable;
use Quesfix\StataLogic\Exceptions\EvalError;

/**
 * AST 求值器，遵循 Stata 語意：
 *
 * - 缺失值（null）在比較中視為正無窮大：x < . 即「x 非缺失」。
 * - 算術運算任一邊為缺失值，結果為缺失值；除以零結果為缺失值。
 * - 比較與邏輯運算一律回傳 1.0 / 0.0，不會產生缺失值。
 * - 邏輯運算（& | !）中，缺失值視為真；非零即真。
 * - 字串僅能與字串比較（== != > < >= <=，依 byte 順序）。
 */
final class Evaluator
{
    public function __construct(private readonly VariableResolverInterface $vars)
    {
    }

    /**
     * 條件是否成立（比照 Stata 的 if：缺失值視為真）。
     */
    public function evaluateBool(Node $node): bool
    {
        return $this->truthy($this->evaluate($node));
    }

    public function evaluate(Node $node): float|string|null
    {
        return match (true) {
            $node instanceof NumberLiteral => $node->value,
            $node instanceof StringLiteral => $node->value,
            $node instanceof MissingLiteral => null,
            $node instanceof Variable => $this->variable($node),
            $node instanceof UnaryOp => $this->unary($node),
            $node instanceof BinaryOp => $this->binary($node),
            $node instanceof InList => $this->inList($node),
            $node instanceof InRange => $this->inRange($node),
            $node instanceof AbsCall => $this->absCall($node),
            $node instanceof AnyMatch => $this->anyMatch($node),
            default => throw new \LogicException('未知的 AST 節點：' . $node::class),
        };
    }

    private function variable(Variable $node): float|string|null
    {
        if (! $this->vars->has($node->name)) {
            throw new EvalError(sprintf("變數 '%s' 不存在於資料中", $node->name));
        }

        return $this->vars->value($node->name);
    }

    private function unary(UnaryOp $node): float|null
    {
        $value = $this->evaluate($node->operand);

        if ($node->op === '!') {
            return $this->truthy($value) ? 0.0 : 1.0;
        }

        if (is_string($value)) {
            throw new EvalError("'-' 運算不適用於字串");
        }

        return $value === null ? null : -$value;
    }

    private function binary(BinaryOp $node): float|null
    {
        if ($node->op === '&' || $node->op === '|') {
            $left = $this->truthy($this->evaluate($node->left));
            $right = $this->truthy($this->evaluate($node->right));

            $result = $node->op === '&' ? ($left && $right) : ($left || $right);

            return $result ? 1.0 : 0.0;
        }

        $left = $this->evaluate($node->left);
        $right = $this->evaluate($node->right);

        if (in_array($node->op, BinaryOp::COMPARISON, true)) {
            return $this->compare($node->op, $left, $right);
        }

        return $this->arithmetic($node->op, $left, $right);
    }

    private function compare(string $op, float|string|null $left, float|string|null $right): float
    {
        if (is_string($left) && is_string($right)) {
            $cmp = strcmp($left, $right);
        } elseif (is_string($left) || is_string($right)) {
            throw new EvalError(sprintf("比較運算 '%s' 的兩側型別不一致（字串與數值不能互相比較）", $op));
        } else {
            // Stata 語意：缺失值大於任何數值
            $cmp = ($left ?? INF) <=> ($right ?? INF);
        }

        $result = match ($op) {
            '==' => $cmp === 0,
            '!=' => $cmp !== 0,
            '>' => $cmp > 0,
            '<' => $cmp < 0,
            '>=' => $cmp >= 0,
            '<=' => $cmp <= 0,
        };

        return $result ? 1.0 : 0.0;
    }

    private function arithmetic(string $op, float|string|null $left, float|string|null $right): float|null
    {
        if (is_string($left) || is_string($right)) {
            throw new EvalError(sprintf("算術運算 '%s' 不適用於字串", $op));
        }

        if ($left === null || $right === null) {
            return null;
        }

        if ($op === '/' && $right == 0.0) {
            return null; // Stata：除以零結果為缺失值
        }

        $result = match ($op) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $left / $right,
            '^' => $left ** $right,
        };

        return is_finite($result) ? $result : null;
    }

    private function inList(InList $node): float
    {
        $value = $this->evaluate($node->expr);

        foreach ($node->values as $candidate) {
            if ($this->equals($value, $candidate)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    private function inRange(InRange $node): float
    {
        $value = $this->evaluate($node->expr);

        $inRange = $this->compare('>=', $value, $node->low) === 1.0
            && $this->compare('<=', $value, $node->high) === 1.0;

        return $inRange ? 1.0 : 0.0;
    }

    private function absCall(AbsCall $node): float|null
    {
        $value = $this->evaluate($node->expr);

        if (is_string($value)) {
            throw new EvalError('abs() 不適用於字串');
        }

        return $value === null ? null : abs($value);
    }

    private function anyMatch(AnyMatch $node): float
    {
        $matched = Wildcard::match($node->pattern, $this->vars->names());

        if ($matched === []) {
            throw new EvalError(sprintf("anymatch 樣式 '%s' 未匹配到任何變數", $node->pattern));
        }

        foreach ($matched as $name) {
            if ($this->equals($this->vars->value($name), $node->value)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    private function equals(float|string|null $value, float|string $candidate): bool
    {
        if (is_string($value) && is_string($candidate)) {
            return $value === $candidate;
        }

        if (is_string($value) || is_string($candidate)) {
            throw new EvalError('比對值與變數的型別不一致（字串與數值不能互相比較）');
        }

        return ($value ?? INF) == $candidate;
    }

    private function truthy(float|string|null $value): bool
    {
        if ($value === null) {
            return true; // Stata 語意：缺失值在邏輯判斷中視為真
        }

        if (is_string($value)) {
            throw new EvalError('字串不能直接作為邏輯條件使用');
        }

        return $value != 0.0;
    }
}

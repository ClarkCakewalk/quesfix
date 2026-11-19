<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

use Quesfix\StataLogic\Ast\AbsCall;
use Quesfix\StataLogic\Ast\AnyMatch;
use Quesfix\StataLogic\Ast\BinaryOp;
use Quesfix\StataLogic\Ast\FuncCall;
use Quesfix\StataLogic\Ast\InList;
use Quesfix\StataLogic\Ast\InRange;
use Quesfix\StataLogic\Ast\MissingLiteral;
use Quesfix\StataLogic\Ast\Node;
use Quesfix\StataLogic\Ast\NumberLiteral;
use Quesfix\StataLogic\Ast\StringLiteral;
use Quesfix\StataLogic\Ast\UnaryOp;
use Quesfix\StataLogic\Ast\Variable;
use Quesfix\StataLogic\Exceptions\SyntaxError;

/**
 * 匯入時的檢核邏輯驗證：
 *
 * 1. 語法剖析是否成功（失敗 → 錯誤）
 * 2. 變數是否存在於資料格式（不存在 → 錯誤）
 * 3. 型別檢查：字串/數值不得混用（混用 → 錯誤）
 * 4. 同層混用 & 與 | 且未加括號（→ 警告，提示 Stata 解讀方式）
 */
final class Validator
{
    /** @var ValidationIssue[] */
    private array $errors = [];

    /** @var ValidationIssue[] */
    private array $warnings = [];

    /** 最近一次 validateAst 的運算式結果型別 */
    private string $lastType = VariableCatalog::TYPE_NUMERIC;

    /** 不存在的變數改報警告（cap 語句：執行時該句會被略過） */
    private bool $unknownAsWarning = false;

    public function __construct(private readonly VariableCatalog $catalog)
    {
    }

    public function validate(string $logic): ValidationResult
    {
        try {
            $ast = (new Parser($logic))->parse();
        } catch (SyntaxError $e) {
            return new ValidationResult([new ValidationIssue($e->detail, $e->pos)]);
        }

        return $this->validateAst($ast);
    }

    /**
     * 驗證一個已解析的運算式 AST（供程式格式逐式驗證使用）。
     */
    public function validateAst(Node $ast, bool $warnMixedAndOr = true, bool $unknownAsWarning = false): ValidationResult
    {
        $this->errors = [];
        $this->warnings = [];
        $this->unknownAsWarning = $unknownAsWarning;

        $this->lastType = $this->typeOf($ast);

        if ($warnMixedAndOr) {
            $this->checkMixedAndOr($ast);
        }

        return new ValidationResult($this->errors, $this->warnings);
    }

    /** @return VariableCatalog::TYPE_* 最近一次 validateAst 的運算式結果型別 */
    public function lastType(): string
    {
        return $this->lastType;
    }

    /** @return VariableCatalog::TYPE_* */
    private function typeOf(Node $node): string
    {
        if ($node instanceof NumberLiteral || $node instanceof MissingLiteral) {
            return VariableCatalog::TYPE_NUMERIC;
        }

        if ($node instanceof StringLiteral) {
            return VariableCatalog::TYPE_STRING;
        }

        if ($node instanceof Variable) {
            if (! $this->catalog->has($node->name)) {
                if ($this->unknownAsWarning) {
                    $this->warnings[] = new ValidationIssue(
                        sprintf("引用不存在的變數 '%s'（位於 cap 語句內，不阻擋匯入；執行時該句會被略過）", $node->name),
                        $node->pos,
                    );
                } else {
                    $this->error(sprintf("檢核邏輯包含不存在的變數 '%s'", $node->name), $node->pos);
                }

                return VariableCatalog::TYPE_NUMERIC;
            }

            return $this->catalog->typeOf($node->name);
        }

        if ($node instanceof UnaryOp) {
            if ($this->typeOf($node->operand) === VariableCatalog::TYPE_STRING) {
                $this->error(sprintf("'%s' 運算不適用於字串", $node->op), $node->pos);
            }

            return VariableCatalog::TYPE_NUMERIC;
        }

        if ($node instanceof BinaryOp) {
            return $this->typeOfBinary($node);
        }

        if ($node instanceof AbsCall) {
            if ($this->typeOf($node->expr) === VariableCatalog::TYPE_STRING) {
                $this->error('abs() 不適用於字串', $node->pos);
            }

            return VariableCatalog::TYPE_NUMERIC;
        }

        if ($node instanceof InList) {
            $exprType = $this->typeOf($node->expr);

            foreach ($node->values as $value) {
                if ($this->constType($value) !== $exprType) {
                    $this->error('inlist 的比對值與變數型別不一致', $node->pos);
                    break;
                }
            }

            return VariableCatalog::TYPE_NUMERIC;
        }

        if ($node instanceof InRange) {
            $exprType = $this->typeOf($node->expr);

            if ($this->constType($node->low) !== $exprType || $this->constType($node->high) !== $exprType) {
                $this->error('inrange 的範圍值與變數型別不一致', $node->pos);
            }

            return VariableCatalog::TYPE_NUMERIC;
        }

        if ($node instanceof AnyMatch) {
            $this->typeOfAnyMatch($node);

            return VariableCatalog::TYPE_NUMERIC;
        }

        if ($node instanceof FuncCall) {
            return $this->typeOfFuncCall($node);
        }

        throw new \LogicException('未知的 AST 節點：' . $node::class);
    }

    private function typeOfBinary(BinaryOp $node): string
    {
        $left = $this->typeOf($node->left);
        $right = $this->typeOf($node->right);

        if (in_array($node->op, BinaryOp::COMPARISON, true)) {
            if ($left !== $right) {
                $this->error(sprintf("比較運算 '%s' 兩側型別不一致（字串與數值不能互相比較）", $node->op), $node->pos);
            }

            return VariableCatalog::TYPE_NUMERIC;
        }

        if (in_array($node->op, BinaryOp::ARITHMETIC, true)) {
            if ($left === VariableCatalog::TYPE_STRING || $right === VariableCatalog::TYPE_STRING) {
                $this->error(sprintf("算術運算 '%s' 不適用於字串", $node->op), $node->pos);
            }

            return VariableCatalog::TYPE_NUMERIC;
        }

        // & 或 |
        if ($left === VariableCatalog::TYPE_STRING || $right === VariableCatalog::TYPE_STRING) {
            $this->error(sprintf("邏輯運算 '%s' 的運算元不能是字串", $node->op), $node->pos);
        }

        return VariableCatalog::TYPE_NUMERIC;
    }

    private function typeOfFuncCall(FuncCall $node): string
    {
        $types = array_map($this->typeOf(...), $node->args);

        foreach ($types as $i => $type) {
            $expected = Functions::argType($node->name, $i);

            if ($expected !== Functions::ANY && $expected !== $type) {
                $this->error(sprintf(
                    '%s() 的第 %d 個參數應為%s',
                    $node->name,
                    $i + 1,
                    $expected === VariableCatalog::TYPE_STRING ? '字串' : '數值',
                ), $node->pos);
            }
        }

        $returns = Functions::SIGNATURES[$node->name][3];

        if ($returns !== 'cond') {
            return $returns;
        }

        // cond(x, a, b[, c])：a、b（、c）型別須一致，回傳該型別
        $branchTypes = array_unique(array_slice($types, 1));

        if (count($branchTypes) > 1) {
            $this->error('cond() 各結果值的型別須一致（字串與數值不能混用）', $node->pos);
        }

        return $types[1];
    }

    private function typeOfAnyMatch(AnyMatch $node): void
    {
        $matched = $this->catalog->matching($node->pattern);

        if ($matched === []) {
            $this->error(sprintf("anymatch 樣式 '%s' 未匹配到任何變數", $node->pattern), $node->pos);

            return;
        }

        $valueType = $this->constType($node->value);

        foreach ($matched as $name) {
            if ($this->catalog->typeOf($name) !== $valueType) {
                $this->error(
                    sprintf("anymatch 匹配到的變數 '%s' 與比對值型別不一致", $name),
                    $node->pos,
                );
            }
        }
    }

    /**
     * 同一層混用 & 與 | 且未以括號分組時提出警告——
     * 本系統將其解讀為 (A | B) & C，與 Stata 原生的 A | (B & C) 不同，故提醒確認。
     */
    private function checkMixedAndOr(Node $node): void
    {
        if ($node instanceof BinaryOp) {
            if ($node->op === '&') {
                foreach ([$node->left, $node->right] as $child) {
                    if ($child instanceof BinaryOp && $child->op === '|' && ! $child->parenthesized) {
                        $this->warnings[] = new ValidationIssue(
                            "運算式同層混用 '&' 與 '|' 且未以括號分組：本系統將 A | B & C 解讀為 (A | B) & C（Stata 原生為 A | (B & C)），請確認是否符合原意",
                            $child->pos,
                        );
                    }
                }
            }

            $this->checkMixedAndOr($node->left);
            $this->checkMixedAndOr($node->right);

            return;
        }

        if ($node instanceof UnaryOp) {
            $this->checkMixedAndOr($node->operand);
        } elseif ($node instanceof AbsCall || $node instanceof InRange) {
            $this->checkMixedAndOr($node->expr);
        } elseif ($node instanceof InList) {
            $this->checkMixedAndOr($node->expr);
        } elseif ($node instanceof FuncCall) {
            foreach ($node->args as $arg) {
                $this->checkMixedAndOr($arg);
            }
        }
    }

    /** @return VariableCatalog::TYPE_* */
    private function constType(float|string $value): string
    {
        return is_string($value) ? VariableCatalog::TYPE_STRING : VariableCatalog::TYPE_NUMERIC;
    }

    private function error(string $message, int $pos): void
    {
        $this->errors[] = new ValidationIssue($message, $pos);
    }
}

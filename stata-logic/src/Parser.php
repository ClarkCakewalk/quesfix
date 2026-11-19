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
 * 遞迴下降剖析器。優先順序（低至高）：
 *
 *   &  →  |  →  比較（== != > < >= <=，左結合可串接）
 *   →  + -  →  * /  →  單元負號  →  ^  →  !
 */
final class Parser
{
    private const FUNCTIONS = ['inlist', 'inrange', 'abs', 'anymatch'];

    /** @var Token[] */
    private array $tokens;

    private int $idx = 0;

    public function __construct(private readonly string $src)
    {
        $this->tokens = (new Lexer($src))->tokenize();
    }

    public function parse(): Node
    {
        $node = $this->parseExpression();

        if ($this->peek()->type === TokenType::Question) {
            throw new SyntaxError("意外的 '?'（萬用字元僅能用於 anymatch 的變數樣式中）", $this->peek()->pos);
        }

        $this->expect(TokenType::Eof, '運算式結尾有多餘的內容');

        return $node;
    }

    /**
     * 本系統規則：同層未加括號混用 & 與 | 時，| 先結合（與 Stata 原生相反）。
     * 例：a | b & c ＝ (a | b) & c；c & a | b ＝ c & (a | b)。
     * 有括號者依括號；未混用時兩種規則結果相同。
     */
    private function parseExpression(): Node
    {
        $left = $this->parseOr();

        while ($this->peek()->type === TokenType::And) {
            $op = $this->next();
            $left = new BinaryOp('&', $left, $this->parseOr(), $op->pos);
        }

        return $left;
    }

    private function parseOr(): Node
    {
        $left = $this->parseComparison();

        while ($this->peek()->type === TokenType::Or) {
            $op = $this->next();
            $left = new BinaryOp('|', $left, $this->parseComparison(), $op->pos);
        }

        return $left;
    }

    private function parseComparison(): Node
    {
        $left = $this->parseAddSub();

        while (true) {
            $opText = match ($this->peek()->type) {
                TokenType::Eq => '==',
                TokenType::Ne => '!=',
                TokenType::Gt => '>',
                TokenType::Lt => '<',
                TokenType::Ge => '>=',
                TokenType::Le => '<=',
                default => null,
            };

            if ($opText === null) {
                return $left;
            }

            $op = $this->next();
            $left = new BinaryOp($opText, $left, $this->parseAddSub(), $op->pos);
        }
    }

    private function parseAddSub(): Node
    {
        $left = $this->parseMulDiv();

        while (true) {
            $type = $this->peek()->type;

            if ($type !== TokenType::Plus && $type !== TokenType::Minus) {
                return $left;
            }

            $op = $this->next();
            $left = new BinaryOp($op->text, $left, $this->parseMulDiv(), $op->pos);
        }
    }

    private function parseMulDiv(): Node
    {
        $left = $this->parseNegate();

        while (true) {
            $type = $this->peek()->type;

            if ($type !== TokenType::Star && $type !== TokenType::Slash) {
                return $left;
            }

            $op = $this->next();
            $left = new BinaryOp($op->text, $left, $this->parseNegate(), $op->pos);
        }
    }

    private function parseNegate(): Node
    {
        if ($this->peek()->type === TokenType::Minus) {
            $op = $this->next();

            return new UnaryOp('-', $this->parseNegate(), $op->pos);
        }

        return $this->parsePower();
    }

    private function parsePower(): Node
    {
        $left = $this->parseNot();

        while ($this->peek()->type === TokenType::Caret) {
            $op = $this->next();
            $left = new BinaryOp('^', $left, $this->parseNot(), $op->pos);
        }

        return $left;
    }

    private function parseNot(): Node
    {
        if ($this->peek()->type === TokenType::Not) {
            $op = $this->next();

            return new UnaryOp('!', $this->parseNot(), $op->pos);
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): Node
    {
        $token = $this->next();

        switch ($token->type) {
            case TokenType::Number:
                return new NumberLiteral((float) $token->text, $token->pos);

            case TokenType::String:
                return new StringLiteral(substr($token->text, 1, -1), $token->pos);

            case TokenType::Missing:
                return new MissingLiteral($token->pos);

            case TokenType::LParen:
                $node = $this->parseExpression();
                $this->expect(TokenType::RParen, "缺少右括號 ')'");
                $node->parenthesized = true;

                return $node;

            case TokenType::Ident:
                if ($this->peek()->type === TokenType::LParen) {
                    return $this->parseFunction($token);
                }

                return new Variable($token->text, $token->pos);

            case TokenType::Question:
                throw new SyntaxError("意外的 '?'（萬用字元僅能用於 anymatch 的變數樣式中）", $token->pos);

            default:
                throw new SyntaxError(
                    $token->type === TokenType::Eof
                        ? '運算式不完整，未預期地結束'
                        : sprintf("意外的符號 '%s'", $token->text),
                    $token->pos,
                );
        }
    }

    private function parseFunction(Token $name): Node
    {
        if (Functions::exists($name->text)) {
            return $this->parseGenericFunction($name);
        }

        if (! in_array($name->text, self::FUNCTIONS, true)) {
            throw new SyntaxError(
                sprintf(
                    "未知的函數 '%s'（支援：%s）",
                    $name->text,
                    implode(', ', [...self::FUNCTIONS, ...array_keys(Functions::SIGNATURES)]),
                ),
                $name->pos,
            );
        }

        $this->expect(TokenType::LParen, "函數後需接 '('");

        $node = match ($name->text) {
            'abs' => new AbsCall($this->parseExpression(), $name->pos),
            'inlist' => $this->parseInList($name),
            'inrange' => new InRange($this->parseExpression(), $this->expectComma()->parseConstant(), $this->expectComma()->parseConstant(), $name->pos),
            'anymatch' => $this->parseAnyMatch($name),
        };

        $this->expect(TokenType::RParen, sprintf("%s 缺少右括號 ')'", $name->text));

        return $node;
    }

    private function parseGenericFunction(Token $name): FuncCall
    {
        $this->expect(TokenType::LParen, "函數後需接 '('");
        $args = [];

        if ($this->peek()->type !== TokenType::RParen) {
            $args[] = $this->parseExpression();

            while ($this->peek()->type === TokenType::Comma) {
                $this->next();
                $args[] = $this->parseExpression();
            }
        }

        $this->expect(TokenType::RParen, sprintf("%s 缺少右括號 ')'", $name->text));

        [$min, $max] = Functions::SIGNATURES[$name->text];
        $count = count($args);

        if ($count < $min || ($max !== -1 && $count > $max)) {
            $expected = $max === -1 ? "至少 {$min}" : ($min === $max ? (string) $min : "{$min}～{$max}");

            throw new SyntaxError(
                sprintf('%s() 需要 %s 個參數，實際為 %d 個', $name->text, $expected, $count),
                $name->pos,
            );
        }

        return new FuncCall($name->text, $args, $name->pos);
    }

    private function parseInList(Token $name): InList
    {
        $expr = $this->parseExpression();
        $values = [];

        $this->expect(TokenType::Comma, 'inlist 至少需要一個比對值');
        $values[] = $this->parseConstant();

        while ($this->peek()->type === TokenType::Comma) {
            $this->next();
            $values[] = $this->parseConstant();
        }

        return new InList($expr, $values, $name->pos);
    }

    private function parseAnyMatch(Token $name): AnyMatch
    {
        $pattern = $this->parseWildcardPattern();
        $this->expect(TokenType::Comma, 'anymatch 需要第二個參數（比對值），格式：anymatch(變數樣式, 值)');
        $value = $this->parseConstant();

        return new AnyMatch($pattern, $value, $name->pos);
    }

    /**
     * 收集 anymatch 的變數樣式。樣式可含識別字、? 與 *，
     * 各 token 必須緊鄰（中間不得有空白）。
     */
    private function parseWildcardPattern(): string
    {
        $allowed = [TokenType::Ident, TokenType::Question, TokenType::Star, TokenType::Number];
        $first = null;
        $last = null;

        while (in_array($this->peek()->type, $allowed, true)) {
            $token = $this->peek();

            if ($last !== null && $token->pos !== $last->end()) {
                break;
            }

            $this->next();
            $first ??= $token;
            $last = $token;
        }

        if ($first === null) {
            throw new SyntaxError('anymatch 缺少變數樣式', $this->peek()->pos);
        }

        $pattern = substr($this->src, $first->pos, $last->end() - $first->pos);

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_?*]*$/', $pattern) !== 1) {
            throw new SyntaxError(sprintf("anymatch 的變數樣式 '%s' 格式不正確", $pattern), $first->pos);
        }

        return $pattern;
    }

    private function parseConstant(): float|string
    {
        $token = $this->next();

        if ($token->type === TokenType::Minus) {
            $num = $this->expect(TokenType::Number, '負號後需接數值');

            return -((float) $num->text);
        }

        if ($token->type === TokenType::Number) {
            return (float) $token->text;
        }

        if ($token->type === TokenType::String) {
            return substr($token->text, 1, -1);
        }

        throw new SyntaxError('此處需要數值或字串常數', $token->pos);
    }

    private function expectComma(): self
    {
        $this->expect(TokenType::Comma, "此處需要 ','");

        return $this;
    }

    private function peek(): Token
    {
        return $this->tokens[$this->idx];
    }

    private function next(): Token
    {
        return $this->tokens[$this->idx++];
    }

    private function expect(TokenType $type, string $message): Token
    {
        $token = $this->next();

        if ($token->type !== $type) {
            throw new SyntaxError($message, $token->pos);
        }

        return $token;
    }
}

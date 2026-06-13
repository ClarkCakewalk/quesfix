<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Tests;

use PHPUnit\Framework\TestCase;
use Quesfix\StataLogic\Exceptions\SyntaxError;
use Quesfix\StataLogic\Lexer;
use Quesfix\StataLogic\TokenType;

final class LexerTest extends TestCase
{
    /** @return TokenType[] */
    private function types(string $src): array
    {
        return array_map(fn ($t) => $t->type, (new Lexer($src))->tokenize());
    }

    public function testBasicOperators(): void
    {
        $this->assertSame(
            [
                TokenType::Ident, TokenType::Eq, TokenType::Number,
                TokenType::And, TokenType::Ident, TokenType::Ne, TokenType::Number,
                TokenType::Eof,
            ],
            $this->types('a01 == 1 & b02 != 2'),
        );
    }

    public function testMissingValueVersusDecimalNumber(): void
    {
        $this->assertSame([TokenType::Missing, TokenType::Eof], $this->types('.'));
        $this->assertSame([TokenType::Number, TokenType::Eof], $this->types('0.25'));
        $this->assertSame([TokenType::Number, TokenType::Eof], $this->types('.25'));
        $this->assertSame([TokenType::Ident, TokenType::Lt, TokenType::Missing, TokenType::Eof], $this->types('x < .'));
    }

    public function testExtendedMissing(): void
    {
        $tokens = (new Lexer('x == .a'))->tokenize();
        $this->assertSame(TokenType::Missing, $tokens[2]->type);
        $this->assertSame('.a', $tokens[2]->text);
    }

    public function testChineseStringLiteral(): void
    {
        $tokens = (new Lexer('Marry != "從未結婚"'))->tokenize();
        $this->assertSame(TokenType::String, $tokens[2]->type);
        $this->assertSame('"從未結婚"', $tokens[2]->text);
    }

    public function testNotVersusNotEqual(): void
    {
        $this->assertSame(
            [TokenType::Not, TokenType::Ident, TokenType::Eof],
            $this->types('!flag'),
        );
        $this->assertSame(
            [TokenType::Ident, TokenType::Ne, TokenType::Number, TokenType::Eof],
            $this->types('flag != 1'),
        );
    }

    public function testTildeAliases(): void
    {
        $this->assertSame(
            [TokenType::Ident, TokenType::Ne, TokenType::Number, TokenType::Eof],
            $this->types('x ~= 1'),
        );
    }

    public function testSingleEqualsThrowsWithHint(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessageMatches("/=='/u");
        (new Lexer('x = 1'))->tokenize();
    }

    public function testUnterminatedStringThrows(): void
    {
        $this->expectException(SyntaxError::class);
        (new Lexer('Marry == "已婚'))->tokenize();
    }

    public function testLineCommentIsIgnored(): void
    {
        // 範例檔 same_lg_282 規則行尾即帶有 Stata 註解
        $this->assertSame(
            [TokenType::Ident, TokenType::Gt, TokenType::Number, TokenType::Eof],
            $this->types('x > 1 //表格題組至多僅記錄5位手足'),
        );
        // 註解不影響除法
        $this->assertSame(
            [TokenType::Ident, TokenType::Slash, TokenType::Number, TokenType::Eof],
            $this->types('x / 2'),
        );
    }

    public function testTokenPositions(): void
    {
        $tokens = (new Lexer('ab >= 10'))->tokenize();
        $this->assertSame(0, $tokens[0]->pos);
        $this->assertSame(3, $tokens[1]->pos);
        $this->assertSame(6, $tokens[2]->pos);
    }
}

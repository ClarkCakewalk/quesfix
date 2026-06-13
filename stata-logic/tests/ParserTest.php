<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Tests;

use PHPUnit\Framework\TestCase;
use Quesfix\StataLogic\Ast\AnyMatch;
use Quesfix\StataLogic\Ast\BinaryOp;
use Quesfix\StataLogic\Ast\InList;
use Quesfix\StataLogic\Ast\UnaryOp;
use Quesfix\StataLogic\Exceptions\SyntaxError;
use Quesfix\StataLogic\LogicEngine;

final class ParserTest extends TestCase
{
    public function testAndBindsTighterThanOr(): void
    {
        // A | B & C → A | (B & C)
        $ast = LogicEngine::parse('a == 1 | b == 2 & c == 3');

        $this->assertInstanceOf(BinaryOp::class, $ast);
        $this->assertSame('|', $ast->op);
        $this->assertInstanceOf(BinaryOp::class, $ast->right);
        $this->assertSame('&', $ast->right->op);
    }

    public function testParenthesizedFlagTracksExplicitGrouping(): void
    {
        $ast = LogicEngine::parse('a == 1 | (b == 2 & c == 3)');
        $this->assertInstanceOf(BinaryOp::class, $ast);
        $this->assertTrue($ast->right->parenthesized);

        $ast = LogicEngine::parse('a == 1 | b == 2 & c == 3');
        $this->assertInstanceOf(BinaryOp::class, $ast);
        $this->assertFalse($ast->right->parenthesized);
    }

    public function testComparisonChainsLeftAssociative(): void
    {
        // 3 > 2 > 1 → (3 > 2) > 1，比照 Stata 由左至右
        $ast = LogicEngine::parse('3 > 2 > 1');

        $this->assertInstanceOf(BinaryOp::class, $ast);
        $this->assertSame('>', $ast->op);
        $this->assertInstanceOf(BinaryOp::class, $ast->left);
        $this->assertSame('>', $ast->left->op);
    }

    public function testUnaryMinusBindsLooserThanPower(): void
    {
        // -2^2 → -(2^2)，比照 Stata 優先順序
        $ast = LogicEngine::parse('-2^2');

        $this->assertInstanceOf(UnaryOp::class, $ast);
        $this->assertSame('-', $ast->op);
        $this->assertInstanceOf(BinaryOp::class, $ast->operand);
        $this->assertSame('^', $ast->operand->op);
    }

    public function testInListSingleValue(): void
    {
        $ast = LogicEngine::parse('inlist(d02b,1)');

        $this->assertInstanceOf(InList::class, $ast);
        $this->assertSame([1.0], $ast->values);
    }

    public function testInListManyValuesIncludingNegative(): void
    {
        $ast = LogicEngine::parse('inlist(d24,1,2,3,-4,94)');

        $this->assertInstanceOf(InList::class, $ast);
        $this->assertSame([1.0, 2.0, 3.0, -4.0, 94.0], $ast->values);
    }

    public function testAnyMatchPattern(): void
    {
        $ast = LogicEngine::parse('anymatch(sibs_e?, 1)');

        $this->assertInstanceOf(AnyMatch::class, $ast);
        $this->assertSame('sibs_e?', $ast->pattern);
        $this->assertSame(1.0, $ast->value);
    }

    public function testAnyMatchStarPattern(): void
    {
        $ast = LogicEngine::parse('anymatch(j15errs_s*, 1)');

        $this->assertInstanceOf(AnyMatch::class, $ast);
        $this->assertSame('j15errs_s*', $ast->pattern);
    }

    public function testLegacyAnyMatchSyntaxRejected(): void
    {
        // 舊記法 anymatch(p), value(1) 不再支援，需改寫為 anymatch(p, 1)
        $this->expectException(SyntaxError::class);
        LogicEngine::parse('anymatch(sibs_e?), value(1)');
    }

    public function testUnknownFunctionThrows(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessageMatches('/未知的函數/u');
        LogicEngine::parse('regexm(x, "a")');
    }

    public function testWildcardOutsideAnyMatchThrows(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessageMatches('/anymatch/u');
        LogicEngine::parse('sibs_e? == 1');
    }

    public function testMissingClosingParenThrows(): void
    {
        $this->expectException(SyntaxError::class);
        LogicEngine::parse('(a == 1');
    }

    public function testTrailingGarbageThrows(): void
    {
        $this->expectException(SyntaxError::class);
        LogicEngine::parse('a == 1 b');
    }
}

<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Tests;

use PHPUnit\Framework\TestCase;
use Quesfix\StataLogic\ArrayResolver;
use Quesfix\StataLogic\Exceptions\EvalError;
use Quesfix\StataLogic\LogicEngine;
use Quesfix\StataLogic\VariableCatalog;

final class EvaluatorTest extends TestCase
{
    // ---- Stata 缺失值語意 ----

    public function testMissingIsGreaterThanAnyNumber(): void
    {
        // x > 100 在 x 缺失時為真（缺失值 = 正無窮大）
        $this->assertTrue(LogicEngine::evaluate('x > 100', ['x' => null]));
        $this->assertFalse(LogicEngine::evaluate('x > 100', ['x' => 50]));
    }

    public function testNotMissingGuardIdiom(): void
    {
        // 慣用防呆寫法：x < . 即「x 非缺失」
        $this->assertTrue(LogicEngine::evaluate('x < .', ['x' => 5]));
        $this->assertFalse(LogicEngine::evaluate('x < .', ['x' => null]));
    }

    public function testMissingEqualsMissing(): void
    {
        $this->assertTrue(LogicEngine::evaluate('x == .', ['x' => null]));
        $this->assertFalse(LogicEngine::evaluate('x == .', ['x' => 0]));
    }

    public function testArithmeticWithMissingPropagates(): void
    {
        // (x - y) < . ：任一邊缺失 → 運算結果缺失 → 比較為假
        $row = ['x' => null, 'y' => 3];
        $this->assertFalse(LogicEngine::evaluate('(x - y) < .', $row));

        $row = ['x' => 10, 'y' => 3];
        $this->assertTrue(LogicEngine::evaluate('(x - y) < .', $row));
    }

    public function testDivisionByZeroIsMissing(): void
    {
        $this->assertTrue(LogicEngine::evaluate('(1 / x) == .', ['x' => 0]));
    }

    public function testMissingIsTrueInLogicalContext(): void
    {
        // Stata：邏輯運算中缺失值視為真（非零）
        $this->assertTrue(LogicEngine::evaluate('x & 1', ['x' => null]));
        $this->assertTrue(LogicEngine::evaluate('x | 0', ['x' => null]));
        $this->assertFalse(LogicEngine::evaluate('!x', ['x' => null]));
    }

    // ---- 優先順序 ----

    public function testAndBindsTighterThanOr(): void
    {
        $this->assertTrue(LogicEngine::evaluate('1 | 0 & 0', []));   // 1 | (0 & 0)
        $this->assertFalse(LogicEngine::evaluate('(1 | 0) & 0', []));
    }

    public function testPower(): void
    {
        $this->assertTrue(LogicEngine::evaluate('2^3 == 8', []));
        $this->assertTrue(LogicEngine::evaluate('(0 - 2)^2 == 4', []));
        $this->assertTrue(LogicEngine::evaluate('-2^2 == -4', [])); // 單元負號優先序低於 ^
    }

    public function testChainedComparisonIsLeftAssociative(): void
    {
        // (3 > 2) > 1 → 1 > 1 → 假，比照 Stata
        $this->assertFalse(LogicEngine::evaluate('3 > 2 > 1', []));
    }

    // ---- 字串 ----

    public function testChineseStringComparison(): void
    {
        $this->assertTrue(LogicEngine::evaluate('Marry != "從未結婚"', ['Marry' => '已婚']));
        $this->assertFalse(LogicEngine::evaluate('Marry != "已婚"', ['Marry' => '已婚']));
    }

    public function testStringNumberComparisonThrows(): void
    {
        $this->expectException(EvalError::class);
        LogicEngine::evaluate('Marry == 1', ['Marry' => '已婚']);
    }

    public function testStringAsConditionThrows(): void
    {
        $this->expectException(EvalError::class);
        LogicEngine::evaluate('Marry & 1', ['Marry' => '已婚']);
    }

    // ---- 函數 ----

    public function testInListNumeric(): void
    {
        $this->assertTrue(LogicEngine::evaluate('inlist(c02r1,1,3)', ['c02r1' => 3]));
        $this->assertFalse(LogicEngine::evaluate('inlist(c02r1,1,3)', ['c02r1' => 2]));
        $this->assertFalse(LogicEngine::evaluate('inlist(c02r1,1,3)', ['c02r1' => null]));
    }

    public function testInListString(): void
    {
        $this->assertTrue(LogicEngine::evaluate('inlist(Marry,"已婚","分居")', ['Marry' => '分居']));
        $this->assertFalse(LogicEngine::evaluate('inlist(Marry,"已婚","分居")', ['Marry' => '離婚']));
    }

    public function testInRangeBoundariesAreInclusive(): void
    {
        $this->assertTrue(LogicEngine::evaluate('inrange(x,2,24)', ['x' => 2]));
        $this->assertTrue(LogicEngine::evaluate('inrange(x,2,24)', ['x' => 24]));
        $this->assertFalse(LogicEngine::evaluate('inrange(x,2,24)', ['x' => 25]));
        $this->assertFalse(LogicEngine::evaluate('inrange(x,2,24)', ['x' => null]));
    }

    public function testAbs(): void
    {
        $this->assertTrue(LogicEngine::evaluate('abs(age2 - age) >= 25', ['age2' => 60, 'age' => 30]));
        $this->assertTrue(LogicEngine::evaluate('abs(age2 - age) >= 25', ['age2' => 30, 'age' => 60]));
        $this->assertFalse(LogicEngine::evaluate('abs(age2 - age) >= 25', ['age2' => 40, 'age' => 30]));
    }

    public function testAnyMatch(): void
    {
        $row = ['sibs_e1' => 0, 'sibs_e2' => 1, 'sibs_e3' => 0];
        $this->assertTrue(LogicEngine::evaluate('anymatch(sibs_e?, 1)', $row));

        $row = ['sibs_e1' => 0, 'sibs_e2' => 0, 'sibs_e3' => 0];
        $this->assertFalse(LogicEngine::evaluate('anymatch(sibs_e?, 1)', $row));
    }

    public function testAnyMatchNoVariableThrows(): void
    {
        $this->expectException(EvalError::class);
        $this->expectExceptionMessageMatches('/未匹配到任何變數/u');
        LogicEngine::evaluate('anymatch(zzz_?, 1)', ['a' => 1]);
    }

    public function testUnknownVariableThrows(): void
    {
        $this->expectException(EvalError::class);
        $this->expectExceptionMessageMatches('/不存在於資料中/u');
        LogicEngine::evaluate('ghost == 1', ['a' => 1]);
    }

    // ---- 真實規則案例 ----

    public function testBmiRuleHandlesDivisionByZeroGracefully(): void
    {
        $logic = '((a27 / ((a26 / 100)^2)) < 10 & (a26 < 991 & a27 < 991))';

        // 身高 170、體重 20 → BMI 6.9 → 過低，觸發
        $this->assertTrue(LogicEngine::evaluate($logic, ['a26' => 170, 'a27' => 20]));

        // 身高 0 → 除以零 → 缺失 → 缺失 < 10 為假 → 不觸發、不噴錯
        $this->assertFalse(LogicEngine::evaluate($logic, ['a26' => 0, 'a27' => 20]));

        // 正常 BMI 不觸發
        $this->assertFalse(LogicEngine::evaluate($logic, ['a26' => 170, 'a27' => 65]));
    }

    public function testSameLg2DocumentsThePrecedencePitfall(): void
    {
        // same_lg_2 原始邏輯：A | B & C → 防呆 (a02z01 < 991) 只保護到後半段。
        // a02z01 = 995（不知道）時，前半段 Birth_2 != a02z01 仍會觸發——
        // 此測試固定住這個行為，作為「混用 &/| 須加括號」警告的依據。
        $logic = '((Birth_2 != a02z01) | (Birth_3 != a02z02) & (a02z01 < 991))';
        $row = ['Birth_2' => 80, 'a02z01' => 995, 'Birth_3' => 5, 'a02z02' => 5];

        $this->assertTrue(LogicEngine::evaluate($logic, $row));

        // 若原意是 (A | B) & C，加上括號後同一筆資料不觸發
        $fixed = '(((Birth_2 != a02z01) | (Birth_3 != a02z02)) & (a02z01 < 991))';
        $this->assertFalse(LogicEngine::evaluate($fixed, $row));
    }

    // ---- ArrayResolver::fromStrings（模擬 DB 字串值） ----

    public function testResolverFromDbStrings(): void
    {
        $catalog = new VariableCatalog([
            'a01' => VariableCatalog::TYPE_NUMERIC,
            'Marry' => VariableCatalog::TYPE_STRING,
        ]);

        $resolver = ArrayResolver::fromStrings(['a01' => '2', 'Marry' => '已婚'], $catalog);
        $this->assertTrue(LogicEngine::evaluate('a01 == 2 & Marry == "已婚"', $resolver));

        // 空字串與 '.' 都是數值缺失
        $resolver = ArrayResolver::fromStrings(['a01' => '', 'Marry' => ''], $catalog);
        $this->assertTrue(LogicEngine::evaluate('a01 == .', $resolver));

        $resolver = ArrayResolver::fromStrings(['a01' => '.', 'Marry' => ''], $catalog);
        $this->assertTrue(LogicEngine::evaluate('a01 == .', $resolver));
    }

    public function testResolverRejectsGarbageNumericValue(): void
    {
        $catalog = new VariableCatalog(['a01' => VariableCatalog::TYPE_NUMERIC]);

        $this->expectException(EvalError::class);
        $this->expectExceptionMessageMatches('/不是有效數值/u');
        ArrayResolver::fromStrings(['a01' => 'abc'], $catalog);
    }
}

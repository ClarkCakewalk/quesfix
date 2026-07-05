<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Tests;

use PHPUnit\Framework\TestCase;
use Quesfix\StataLogic\ArrayResolver;
use Quesfix\StataLogic\LogicEngine;
use Quesfix\StataLogic\VariableCatalog;

final class ProgramTest extends TestCase
{
    // ---- gen check 外殼 ----

    public function testGenCheckWrapper(): void
    {
        $this->assertTrue(LogicEngine::evaluate('gen check = (a01 != Sex)', ['a01' => 1, 'Sex' => 2]));
        $this->assertFalse(LogicEngine::evaluate('gen check = (a01 != Sex)', ['a01' => 2, 'Sex' => 2]));
    }

    public function testIsProgramDetection(): void
    {
        $this->assertTrue(LogicEngine::isProgram('gen check = (a==1)'));
        $this->assertFalse(LogicEngine::isProgram('(a == 1)'));
    }

    public function testBareExpressionStillWorks(): void
    {
        // 向後相容：舊格式單一運算式仍可求值
        $this->assertTrue(LogicEngine::evaluate('(a01 != Sex)', ['a01' => 1, 'Sex' => 2]));
    }

    // ---- 多行衍生變數 + if 限定 ----

    public function testDerivedVariablesWithIfQualifier(): void
    {
        $logic = <<<'STATA'
        gen enter_time = (a19z10 + (a19z11/12)) if (a19z10 > 0 & a19z10 < 991) & (a19z11 > 0 & a19z11 < 13)
        gen leave_time = (a19z12 + (a19z13/12)) if (a19z12 > 0 & a19z12 < 991) & (a19z13 > 0 & a19z13 < 13)
        gen check = (((leave_time - enter_time < 0.25) & (leave_time - enter_time) < . ) & (a19z01==2) & (a02z01 >= 83 & a02z01 < 94))
        STATA;

        // 進出時間差 0.1667 < 0.25、a19z01==2、a02z01=85 → 觸發
        $this->assertTrue(LogicEngine::evaluate($logic, [
            'a19z10' => 100, 'a19z11' => 6, 'a19z12' => 100, 'a19z13' => 8,
            'a19z01' => 2, 'a02z01' => 85,
        ]));

        // a19z01=1 → 不觸發
        $this->assertFalse(LogicEngine::evaluate($logic, [
            'a19z10' => 100, 'a19z11' => 6, 'a19z12' => 100, 'a19z13' => 8,
            'a19z01' => 1, 'a02z01' => 85,
        ]));
    }

    public function testIfQualifierFalseMakesDerivedMissing(): void
    {
        // a19z10 缺失 → enter_time 缺失 → (leave_time - enter_time) 缺失 → 不觸發
        $logic = <<<'STATA'
        gen enter_time = (a19z10 + (a19z11/12)) if (a19z10 > 0 & a19z10 < 991) & (a19z11 > 0 & a19z11 < 13)
        gen leave_time = (a19z12 + (a19z13/12)) if (a19z12 > 0 & a19z12 < 991) & (a19z13 > 0 & a19z13 < 13)
        gen check = (((leave_time - enter_time < 0.25) & (leave_time - enter_time) < . ) & (a19z01==2))
        STATA;

        $this->assertFalse(LogicEngine::evaluate($logic, [
            'a19z10' => null, 'a19z11' => 6, 'a19z12' => 100, 'a19z13' => 8, 'a19z01' => 2,
        ]));
    }

    public function testReplaceUpdatesConditionally(): void
    {
        $logic = <<<'STATA'
        gen x = .
        replace x = a if a > 0 & a < 991
        gen check = (x == 50)
        STATA;

        $this->assertTrue(LogicEngine::evaluate($logic, ['a' => 50]));
        $this->assertFalse(LogicEngine::evaluate($logic, ['a' => 999])); // 條件不符 → x 維持缺失
    }

    // ---- egen ----

    public function testEgenRowtotalTreatsMissingAsZero(): void
    {
        $logic = <<<'STATA'
        egen total = rowtotal(c04z01 c04z02 c04z03)
        gen check = (total > 0)
        STATA;

        $this->assertTrue(LogicEngine::evaluate($logic, ['c04z01' => 0, 'c04z02' => 3, 'c04z03' => null]));
        $this->assertFalse(LogicEngine::evaluate($logic, ['c04z01' => 0, 'c04z02' => 0, 'c04z03' => null]));
    }

    public function testEgenAnycountWithNumlistRange(): void
    {
        // anycount(...), value(1/3) → 計入值為 1、2、3 的變數個數
        $logic = <<<'STATA'
        egen cnt = anycount(q1 q2 q3 q4), value(1/3)
        gen check = (cnt >= 2)
        STATA;

        $this->assertTrue(LogicEngine::evaluate($logic, ['q1' => 1, 'q2' => 3, 'q3' => 5, 'q4' => null]));
        $this->assertFalse(LogicEngine::evaluate($logic, ['q1' => 1, 'q2' => 5, 'q3' => 9, 'q4' => null]));
    }

    public function testEgenAnymatch(): void
    {
        $logic = <<<'STATA'
        egen hit = anymatch(a b c), value(95 99)
        gen check = (hit == 1)
        STATA;

        $this->assertTrue(LogicEngine::evaluate($logic, ['a' => 1, 'b' => 99, 'c' => 2]));
        $this->assertFalse(LogicEngine::evaluate($logic, ['a' => 1, 'b' => 2, 'c' => 3]));
    }

    // ---- forvalues 迴圈 + local 巨集 ----

    public function testForvaluesLoopWithLocalMacro(): void
    {
        // 兄弟姊妹出生年順序檢查：任一 j03s[a] <= j03s[a-1]（且皆有效）即觸發
        $logic = <<<'STATA'
        forvalue a = 2/5 {
        local s = `a'-1
        gen errors_`a' = ((j03s`a' <= j03s`s') & (j03s`a' > 0 & j03s`a' < 96) & (j03s`s' > 0 & j03s`s' < 96))
        }
        egen check_sum = rowtotal(errors_?)
        gen check = (check_sum > 0)
        STATA;

        // j03s2(25) <= j03s1(30) 且皆有效 → 觸發
        $this->assertTrue(LogicEngine::evaluate($logic, [
            'j03s1' => 30, 'j03s2' => 25, 'j03s3' => null, 'j03s4' => null, 'j03s5' => null,
        ]));

        // 遞增（30,35,40）→ 不觸發
        $this->assertFalse(LogicEngine::evaluate($logic, [
            'j03s1' => 30, 'j03s2' => 35, 'j03s3' => 40, 'j03s4' => null, 'j03s5' => null,
        ]));
    }

    public function testForvaluesWithEgenAnymatchCheck(): void
    {
        $logic = <<<'STATA'
        forvalue a = 1/3 {
        gen flag_`a' = (x`a' == 1)
        }
        egen check = anymatch(flag_?), value(1)
        STATA;

        $this->assertTrue(LogicEngine::evaluate($logic, ['x1' => 0, 'x2' => 1, 'x3' => 0]));
        $this->assertFalse(LogicEngine::evaluate($logic, ['x1' => 0, 'x2' => 0, 'x3' => 0]));
    }

    // ---- 驗證 ----

    private function catalog(): VariableCatalog
    {
        return new VariableCatalog([
            'a01' => VariableCatalog::TYPE_NUMERIC,
            'Sex' => VariableCatalog::TYPE_NUMERIC,
            'Marry' => VariableCatalog::TYPE_STRING,
            'a19z10' => VariableCatalog::TYPE_NUMERIC,
            'a19z11' => VariableCatalog::TYPE_NUMERIC,
        ]);
    }

    public function testValidateProgramOk(): void
    {
        $result = LogicEngine::validate('gen check = (a01 != Sex)', $this->catalog());
        $this->assertTrue($result->ok());
    }

    public function testValidateProgramDerivedVarNotFlagged(): void
    {
        $logic = <<<'STATA'
        gen t = (a19z10 + a19z11) if a19z10 < 991
        gen check = (t > 100)
        STATA;
        $result = LogicEngine::validate($logic, $this->catalog());
        $this->assertTrue($result->ok(), implode('；', array_map('strval', $result->errors)));
    }

    public function testValidateProgramUnknownVariable(): void
    {
        $result = LogicEngine::validate('gen check = (ghost == 1)', $this->catalog());
        $this->assertFalse($result->ok());
        $this->assertStringContainsString('ghost', $result->errors[0]->message);
    }

    public function testValidateProgramMissingCheck(): void
    {
        $result = LogicEngine::validate('gen foo = (a01 == 1)', $this->catalog());
        $this->assertFalse($result->ok());
        $this->assertStringContainsString('check', $result->errors[0]->message);
    }

    public function testValidateProgramRejectsUnsupportedCommand(): void
    {
        $logic = <<<'STATA'
        split Birth, p("年" "月")
        gen check = (a01 == 1)
        STATA;
        $result = LogicEngine::validate($logic, $this->catalog());
        $this->assertFalse($result->ok());
        $this->assertStringContainsString('split', $result->errors[0]->message);
    }

    public function testValidateProgramMixedAndOrWarns(): void
    {
        $result = LogicEngine::validate('gen check = (a01 == 1 | Sex == 2 & a19z10 == 3)', $this->catalog());
        $this->assertTrue($result->ok());
        $this->assertCount(1, $result->warnings);
    }

    public function testValidateProgramTypeMismatch(): void
    {
        // Marry 是字串，與數值比較 → 錯誤
        $result = LogicEngine::validate('gen check = (Marry == 1)', $this->catalog());
        $this->assertFalse($result->ok());
    }
}

<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Tests;

use PHPUnit\Framework\TestCase;
use Quesfix\StataLogic\ArrayResolver;
use Quesfix\StataLogic\LogicEngine;
use Quesfix\StataLogic\VariableCatalog;

/**
 * 擴充語法：split/destring/rename、recode、if 區塊、foreach、cond/real/strtrim/strmatch 等函數。
 * 範例取自 2026 正式訪問的實際檢核條件。
 */
final class ProgramExtensionsTest extends TestCase
{
    private const LASTM = <<<'STATA'
    cap split Lastm, p("民國" "年" "月")
    cap drop Lastm1    //此欄位為空值
    cap destring Lastm2 Lastm3, replace
    cap rename Lastm2 Lastm_y
    cap rename Lastm3 Lastm_m
    gen check = ((a19b01 < Lastm_y & a19b01 < 991) | (a19b01 <= Lastm_y & a19b02 < Lastm_m & a19b01 < 991 & a19b02 < 991))
    STATA;

    private function catalog(): VariableCatalog
    {
        return new VariableCatalog([
            'Lastm' => VariableCatalog::TYPE_STRING,
            'C1_name' => VariableCatalog::TYPE_STRING,
            'a19b01' => VariableCatalog::TYPE_NUMERIC,
            'a19b02' => VariableCatalog::TYPE_NUMERIC,
            'c18r1' => VariableCatalog::TYPE_NUMERIC,
            'Mode' => VariableCatalog::TYPE_NUMERIC,
            'k03a' => VariableCatalog::TYPE_NUMERIC,
            'k03b' => VariableCatalog::TYPE_NUMERIC,
            'k03c' => VariableCatalog::TYPE_NUMERIC,
        ]);
    }

    private function eval(string $logic, array $row): bool
    {
        return LogicEngine::evaluate($logic, ArrayResolver::fromStrings($row, $this->catalog()));
    }

    // ---- split / destring / rename ----

    public function testSplitDestringRenameParsesRocDate(): void
    {
        // 上次訪問 民國112年5月；本次填答 111 年 → 早於上次 → 觸發
        $this->assertTrue($this->eval(self::LASTM, ['Lastm' => '民國112年5月', 'a19b01' => '111', 'a19b02' => '1']));
        // 同年但月份較早 → 觸發
        $this->assertTrue($this->eval(self::LASTM, ['Lastm' => '民國112年5月', 'a19b01' => '112', 'a19b02' => '3']));
        // 同年較晚月份 → 不觸發
        $this->assertFalse($this->eval(self::LASTM, ['Lastm' => '民國112年5月', 'a19b01' => '112', 'a19b02' => '8']));
    }

    public function testSplitOnEmptyStringGivesMissingPieces(): void
    {
        // Lastm 空白 → Lastm_y、Lastm_m 缺失（大於任何數）→ a19b01 < Lastm_y 成立
        $this->assertTrue($this->eval(self::LASTM, ['Lastm' => '', 'a19b01' => '100', 'a19b02' => '1']));
        // a19b01 為 991（不適用）→ 不觸發
        $this->assertFalse($this->eval(self::LASTM, ['Lastm' => '', 'a19b01' => '991', 'a19b02' => '1']));
    }

    public function testSplitIfQualifierAndDestringOption(): void
    {
        $logic = <<<'STATA'
        split Lastm if a19b01 == 1, p("年") gen(part) destring
        gen check = (part1 == 112)
        STATA;
        $this->assertTrue($this->eval($logic, ['Lastm' => '112年5月', 'a19b01' => '1']));
        $this->assertFalse($this->eval($logic, ['Lastm' => '112年5月', 'a19b01' => '2'])); // if 不成立 → 片段為空
    }

    public function testDestringNonNumericFailsUnlessForce(): void
    {
        $catalog = $this->catalog();
        $row = ArrayResolver::fromStrings(['Lastm' => 'abc'], $catalog);

        $this->assertTrue(LogicEngine::evaluate("destring Lastm, gen(n) force\ngen check = (n == .)", $row));

        $this->expectException(\Quesfix\StataLogic\Exceptions\EvalError::class);
        LogicEngine::evaluate("destring Lastm, gen(n)\ngen check = (n == .)", $row);
    }

    // ---- recode ----

    public function testRecodeWithGenAndRanges(): void
    {
        $logic = <<<'STATA'
        recode c18r1 (1=0)(2=5)(3=15)(22=250)(96/99=0), gen(c18r1_recode)
        gen check = (c18r1_recode == X)
        STATA;

        foreach (['1' => 0, '2' => 5, '3' => 15, '22' => 250, '96' => 0, '97' => 0, '99' => 0, '50' => 50] as $in => $out) {
            $this->assertTrue($this->eval(str_replace('X', (string) $out, $logic), ['c18r1' => (string) $in]), "c18r1={$in}");
        }

        // 原變數不變
        $this->assertTrue($this->eval("recode c18r1 (1=0), gen(r)\ngen check = (c18r1 == 1 & r == 0)", ['c18r1' => '1']));
    }

    public function testRecodeFirstMatchingRuleWins(): void
    {
        $this->assertTrue($this->eval("recode c18r1 (7=750)(7=850), gen(r)\ngen check = (r == 750)", ['c18r1' => '7']));
    }

    public function testRecodeInPlaceKeepsUnmatchedFractions(): void
    {
        $logic = "gen agep = c18r1 + 0.5\nrecode agep (1/18=1)(19/64=2)(65/99=3)\ngen check = (agep == X)";
        $this->assertTrue($this->eval(str_replace('X', '1', $logic), ['c18r1' => '10']));   // 10.5 → 1
        $this->assertTrue($this->eval(str_replace('X', '18.5', $logic), ['c18r1' => '18'])); // 18.5 不在任何區間 → 不變
    }

    public function testRecodeMissingAndElse(): void
    {
        $logic = "recode c18r1 (missing=-1)(1=10)(else=0), gen(r)\ngen check = (r == X)";
        $this->assertTrue($this->eval(str_replace('X', '-1', $logic), ['c18r1' => '']));
        $this->assertTrue($this->eval(str_replace('X', '10', $logic), ['c18r1' => '1']));
        $this->assertTrue($this->eval(str_replace('X', '0', $logic), ['c18r1' => '5']));
    }

    // ---- if 區塊 ----

    public function testIfElseBlockUsesSampleValue(): void
    {
        $logic = <<<'STATA'
        if Mode==2 {
        gen check = (a19b01 == 1)
        }
        else {
        gen check = (a19b01 == 2)
        }
        STATA;
        $this->assertTrue($this->eval($logic, ['Mode' => '2', 'a19b01' => '1']));
        $this->assertFalse($this->eval($logic, ['Mode' => '2', 'a19b01' => '2']));
        $this->assertTrue($this->eval($logic, ['Mode' => '1', 'a19b01' => '2']));
    }

    public function testElseIfOnSameLineAsClosingBrace(): void
    {
        $logic = "if Mode==1 {\ngen check = 1\n} else if Mode==2 {\ngen check = 2\n} else {\ngen check = 0\n}";
        $this->assertTrue($this->eval($logic, ['Mode' => '1']));
        $this->assertTrue($this->eval($logic, ['Mode' => '2']));
        $this->assertFalse($this->eval($logic, ['Mode' => '3']));
    }

    // ---- foreach ----

    public function testForeachVarlistWildcard(): void
    {
        $logic = <<<'STATA'
        foreach var of varlist k03* {
        gen p1_`var' = (`var'==4)
        }
        egen check = anymatch(p1_k03*), value(1)
        STATA;
        $this->assertTrue($this->eval($logic, ['k03a' => '1', 'k03b' => '4', 'k03c' => '1']));
        $this->assertFalse($this->eval($logic, ['k03a' => '1', 'k03b' => '2', 'k03c' => '1']));

        // 匯入驗證時以資料格式展開 k03*
        $this->assertTrue(LogicEngine::validate($logic, $this->catalog())->ok());
    }

    public function testForeachNumlistWithLocalAndNestedLoop(): void
    {
        $logic = <<<'STATA'
        gen total = 0
        foreach a of numlist 2 3 {
            local s = `a'-1
            forvalues k = 1/2 {
                replace total = total + `s' * `k'
            }
        }
        gen check = (total == 9)
        STATA;
        // (1*1 + 1*2) + (2*1 + 2*2) = 3 + 6 = 9
        $this->assertTrue($this->eval($logic, []));
    }

    public function testForeachVarlistWithoutMatchIsRejected(): void
    {
        $logic = "foreach v of varlist zz* {\ngen x_`v' = 1\n}\ngen check = 1";
        $result = LogicEngine::validate($logic, $this->catalog());
        $this->assertFalse($result->ok());
        $this->assertStringContainsString('未匹配到任何變數', $result->errors[0]->message);
    }

    // ---- 函數 ----

    public function testCondFunction(): void
    {
        $this->assertTrue(LogicEngine::evaluate('cond(x <= 0, 1, 0) == 1', ['x' => -2]));
        $this->assertTrue(LogicEngine::evaluate('cond(x <= 0, 1, 0) == 0', ['x' => 3]));
        $this->assertTrue(LogicEngine::evaluate('cond(x, 1, 0, 9) == 9', ['x' => null]));  // 第 4 參數：缺失時
        $this->assertTrue(LogicEngine::evaluate('cond(x, 1, 0) == 1', ['x' => null]));     // 無第 4 參數：缺失視為真
    }

    public function testRealAndStrtrim(): void
    {
        $this->assertTrue(LogicEngine::evaluate('real(s) == 12', ['s' => ' 12 ']));
        $this->assertTrue(LogicEngine::evaluate('real(s) == . & strtrim(s) != ""', ['s' => '十二']));
        $this->assertFalse(LogicEngine::evaluate('real(s) == . & strtrim(s) != ""', ['s' => '   ']));
    }

    public function testStrmatchWithChinese(): void
    {
        $this->assertTrue(LogicEngine::evaluate('strmatch(n, "*兒子*")', ['n' => '大兒子']));
        $this->assertFalse(LogicEngine::evaluate('strmatch(n, "*兒子*")', ['n' => '女兒']));
        $this->assertTrue(LogicEngine::evaluate('strmatch(n, "?兒")', ['n' => '女兒']));
    }

    public function testMinMaxMissingSubstr(): void
    {
        $this->assertTrue(LogicEngine::evaluate('max(a, b) == 5 & min(a, b) == 5', ['a' => 5, 'b' => null]));
        $this->assertTrue(LogicEngine::evaluate('missing(a, b)', ['a' => 1, 'b' => null]));
        $this->assertTrue(LogicEngine::evaluate('usubstr(s, 3, 3) == "112"', ['s' => '民國112年']));
        $this->assertTrue(LogicEngine::evaluate('mod(7, 3) == 1 & round(2.5) == 3 & int(-2.7) == -2', []));
    }

    public function testRealRuleWithForvaluesAndStringFunctions(): void
    {
        $logic = <<<'STATA'
        forvalue a = 1/2 {
        gen g17c`a'_err = 0
        replace  g17c`a'_err = 1 if real(g17z02c`a') == . & strtrim(g17z02c`a') != ""
        }
        egen check = anymatch(g17c*_err ), value(1)
        STATA;
        $this->assertTrue(LogicEngine::evaluate($logic, ['g17z02c1' => '30', 'g17z02c2' => '約三十']));
        $this->assertFalse(LogicEngine::evaluate($logic, ['g17z02c1' => '30', 'g17z02c2' => '']));
    }

    // ---- 匯入驗證的型別追蹤 ----

    public function testValidatorTracksDerivedTypes(): void
    {
        $catalog = $this->catalog();

        $this->assertTrue(LogicEngine::validate(self::LASTM, $catalog)->ok());
        // split 數值變數 → 錯誤
        $this->assertFalse(LogicEngine::validate("split c18r1, p(\"年\")\ngen check = 1", $catalog)->ok());
        // gen 字串衍生變數後與數值比較 → 錯誤
        $this->assertFalse(LogicEngine::validate("gen s = strtrim(C1_name)\ngen check = (s == 1)", $catalog)->ok());
        $this->assertTrue(LogicEngine::validate("gen s = strtrim(C1_name)\ngen check = (s == \"x\")", $catalog)->ok());
        // destring 未指定 replace/gen → 錯誤
        $this->assertFalse(LogicEngine::validate("destring Lastm\ngen check = 1", $catalog)->ok());
        // cond 結果型別不一致 → 錯誤
        $this->assertFalse(LogicEngine::validate('gen check = cond(c18r1 > 1, 1, "a") == 1', $catalog)->ok());
        // real() 參數須為字串
        $this->assertFalse(LogicEngine::validate('gen check = real(c18r1) == 1', $catalog)->ok());
    }

    public function testIfBlockBranchesAreValidated(): void
    {
        $logic = "if Mode==2 {\ngen check = (ghost == 1)\n}\nelse {\ngen check = 0\n}";
        $result = LogicEngine::validate($logic, $this->catalog());
        $this->assertFalse($result->ok());
        $this->assertStringContainsString('ghost', $result->errors[0]->message);
    }
}

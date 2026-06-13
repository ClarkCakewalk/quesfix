<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Tests;

use PHPUnit\Framework\TestCase;
use Quesfix\StataLogic\LogicEngine;
use Quesfix\StataLogic\VariableCatalog;

final class ValidatorTest extends TestCase
{
    private VariableCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new VariableCatalog([
            'a01' => VariableCatalog::TYPE_NUMERIC,
            'a02z01' => VariableCatalog::TYPE_NUMERIC,
            'd02b' => VariableCatalog::TYPE_NUMERIC,
            'Marry' => VariableCatalog::TYPE_STRING,
            'sibs_e1' => VariableCatalog::TYPE_NUMERIC,
            'sibs_e2' => VariableCatalog::TYPE_NUMERIC,
        ]);
    }

    public function testValidLogicPasses(): void
    {
        $result = LogicEngine::validate('(a01 == 1 & inlist(d02b,1,2)) | Marry == "已婚"', $this->catalog);

        $this->assertTrue($result->ok());
        $this->assertSame([], $result->warnings);
    }

    public function testUnknownVariableIsError(): void
    {
        $result = LogicEngine::validate('ghost == 1', $this->catalog);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString("不存在的變數 'ghost'", $result->errors[0]->message);
    }

    public function testSyntaxErrorIsReported(): void
    {
        $result = LogicEngine::validate('a01 == ', $this->catalog);

        $this->assertFalse($result->ok());
    }

    public function testStringNumericComparisonIsError(): void
    {
        $result = LogicEngine::validate('Marry == 1', $this->catalog);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('型別不一致', $result->errors[0]->message);
    }

    public function testStringInArithmeticIsError(): void
    {
        $result = LogicEngine::validate('Marry + 1 == 2', $this->catalog);

        $this->assertFalse($result->ok());
    }

    public function testInListTypeMismatchIsError(): void
    {
        $result = LogicEngine::validate('inlist(a01,"已婚")', $this->catalog);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('inlist', $result->errors[0]->message);
    }

    public function testAnyMatchWithNoMatchingVariableIsError(): void
    {
        $result = LogicEngine::validate('anymatch(zzz_?, 1)', $this->catalog);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('未匹配到任何變數', $result->errors[0]->message);
    }

    public function testAnyMatchWithMatchingVariablesPasses(): void
    {
        $result = LogicEngine::validate('anymatch(sibs_e?, 1)', $this->catalog);

        $this->assertTrue($result->ok());
    }

    public function testMixedAndOrWithoutParensWarns(): void
    {
        $result = LogicEngine::validate('a01 == 1 | a02z01 == 2 & d02b == 3', $this->catalog);

        $this->assertTrue($result->ok());
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString("混用 '&' 與 '|'", $result->warnings[0]->message);
    }

    public function testMixedAndOrWithParensDoesNotWarn(): void
    {
        $result = LogicEngine::validate('a01 == 1 | (a02z01 == 2 & d02b == 3)', $this->catalog);

        $this->assertTrue($result->ok());
        $this->assertSame([], $result->warnings);
    }

    public function testRealRuleSameLg2TriggersTheWarning(): void
    {
        // 範例檔中實際發現的疑似邏輯錯誤模式
        $catalog = new VariableCatalog([
            'Birth_2' => VariableCatalog::TYPE_NUMERIC,
            'Birth_3' => VariableCatalog::TYPE_NUMERIC,
            'a02z01' => VariableCatalog::TYPE_NUMERIC,
            'a02z02' => VariableCatalog::TYPE_NUMERIC,
        ]);

        $result = LogicEngine::validate(
            '((Birth_2 != a02z01) | (Birth_3 != a02z02) & (a02z01 < 991))',
            $catalog,
        );

        $this->assertTrue($result->ok());
        $this->assertCount(1, $result->warnings);
    }
}

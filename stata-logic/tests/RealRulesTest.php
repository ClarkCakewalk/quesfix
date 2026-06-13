<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Tests;

use PHPUnit\Framework\TestCase;
use Quesfix\StataLogic\Exceptions\SyntaxError;
use Quesfix\StataLogic\LogicEngine;

/**
 * 以實際調查專案的 387 條檢核條件（tests/fixtures/real_rules.json，
 * 自「檢核系統開發-邏輯項目匯入.xlsx」產生；3 條 anymatch 已改寫為
 * anymatch(樣式, 值) 標準格式）整批驗證剖析器的涵蓋率。
 */
final class RealRulesTest extends TestCase
{
    /**
     * 範例檔中已確認括號不平衡的規則（多一個右括號），
     * 屬於來源資料錯誤，引擎應正確拒絕——修正 Excel 後可自此清單移除。
     */
    private const KNOWN_INVALID = ['same_lg_312', 'c_lg_57', 'c_lg_58'];

    /** @return array<array{name: string, logic: string}> */
    private function rules(): array
    {
        $json = file_get_contents(__DIR__ . '/fixtures/real_rules.json');

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function testAllRealRulesParse(): void
    {
        $rules = $this->rules();
        $this->assertGreaterThanOrEqual(380, count($rules));

        $failures = [];
        $invalidThatParsed = [];

        foreach ($rules as $rule) {
            $knownInvalid = in_array($rule['name'], self::KNOWN_INVALID, true);

            try {
                LogicEngine::parse($rule['logic']);

                if ($knownInvalid) {
                    $invalidThatParsed[] = $rule['name'];
                }
            } catch (SyntaxError $e) {
                if (! $knownInvalid) {
                    $failures[] = sprintf('%s: %s — %s', $rule['name'], $e->getMessage(), $rule['logic']);
                }
            }
        }

        $this->assertSame([], $failures, sprintf("%d 條規則剖析失敗：\n%s", count($failures), implode("\n", $failures)));
        $this->assertSame([], $invalidThatParsed, '以下已知錯誤規則竟可剖析，請更新 KNOWN_INVALID 清單');
    }
}

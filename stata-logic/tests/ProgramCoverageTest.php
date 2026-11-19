<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Tests;

use PHPUnit\Framework\TestCase;
use Quesfix\StataLogic\Program\StataProgram;
use Quesfix\StataLogic\VariableResolverInterface;

/**
 * 以「邏輯確認.xlsx」的 379 條實際檢核程式測量解析與執行涵蓋率。
 * 每條以「全部變數皆缺失」的 resolver 執行一次——目的是驗證程式能完整
 * 解析並跑完（不丟例外），而非驗證觸發結果正確性（後者由單元測試負責）。
 */
final class ProgramCoverageTest extends TestCase
{
    /** @return array<array{code: string, logic: string}> */
    private function rules(): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/fixtures/program_rules.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** 偵測被與字串常數比較的變數（視為字串型），其餘視為數值型 */
    private function stringVars(array $rules): array
    {
        $set = [];
        foreach ($rules as $rule) {
            if (preg_match_all('/([A-Za-z_]\w*)\s*(?:!=|==)\s*"/', $rule['logic'], $m)) {
                foreach ($m[1] as $v) {
                    $set[$v] = true;
                }
            }
            if (preg_match_all('/inlist\(\s*([A-Za-z_]\w*)\s*,\s*"/', $rule['logic'], $m)) {
                foreach ($m[1] as $v) {
                    $set[$v] = true;
                }
            }
        }

        return $set;
    }

    public function testProgramsParseAndRun(): void
    {
        $rules = $this->rules();
        $this->assertGreaterThanOrEqual(379, count($rules));

        // 任何變數都視為「存在」：字串型回空字串、其餘回數值缺失，確保執行路徑能跑完
        $stringVars = $this->stringVars($rules);
        $resolver = new class($stringVars) implements VariableResolverInterface
        {
            public function __construct(private array $stringVars)
            {
            }

            public function has(string $name): bool
            {
                return true;
            }

            public function value(string $name): float|string|null
            {
                return isset($this->stringVars[$name]) ? '' : null;
            }

            public function names(): array
            {
                return [];
            }
        };

        $parseFail = [];
        $runFail = [];
        $ok = 0;

        foreach ($rules as $rule) {
            try {
                $program = new StataProgram($rule['logic']);
            } catch (\Throwable $e) {
                $parseFail[] = "{$rule['code']}: {$e->getMessage()}";

                continue;
            }

            try {
                $program->run($resolver);
                $ok++;
            } catch (\Throwable $e) {
                $runFail[] = "{$rule['code']}: {$e->getMessage()}";
            }
        }

        fwrite(STDERR, sprintf(
            "\n[程式涵蓋率] 可執行 %d／%d；解析失敗 %d；執行失敗 %d\n",
            $ok, count($rules), count($parseFail), count($runFail),
        ));
        if ($parseFail) {
            fwrite(STDERR, "解析失敗:\n  " . implode("\n  ", array_slice($parseFail, 0, 30)) . "\n");
        }
        if ($runFail) {
            fwrite(STDERR, "執行失敗:\n  " . implode("\n  ", array_slice($runFail, 0, 30)) . "\n");
        }

        // 解析/執行失敗應為 0
        $this->assertSame([], $parseFail, '有程式無法解析');
        $this->assertSame([], $runFail, '有程式執行時丟出例外');
    }
}

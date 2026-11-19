<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Program;

use Quesfix\StataLogic\Exceptions\SyntaxError;

/**
 * Stata recode 規則：「(舊值… = 新值)」。
 *
 * 舊值可為：數值、a/b 閉區間（端點可用 min/max）、.（含 .a～.z）、
 * missing（所有缺失）、nonmissing（所有非缺失）、else 或 *（其餘全部，含缺失）。
 * 新值為數值或缺失（.），之後可接 "標籤"（忽略）。
 * 依 Stata 規則由左至右比對，第一個符合的規則生效；都不符合者保留原值。
 */
final class Recode
{
    /**
     * @return array{matchers: list<array>, to: ?float}
     */
    public static function parseRule(string $rule): array
    {
        $eq = strpos($rule, '=');

        if ($eq === false) {
            throw new SyntaxError("recode 規則「({$rule})」缺少 '='", 0);
        }

        $lhs = trim(substr($rule, 0, $eq));
        $rhs = trim(preg_replace('/"[^"]*"/', '', substr($rule, $eq + 1)));

        if ($lhs === '') {
            throw new SyntaxError("recode 規則「({$rule})」缺少舊值", 0);
        }

        $matchers = [];
        foreach (preg_split('/\s+/', $lhs) as $token) {
            $matchers[] = self::parseMatcher($token, $rule);
        }

        return ['matchers' => $matchers, 'to' => self::parseValue($rhs, $rule)];
    }

    /** @param list<array{matchers: list<array>, to: ?float}> $rules */
    public static function apply(array $rules, ?float $value): ?float
    {
        foreach ($rules as $rule) {
            foreach ($rule['matchers'] as $m) {
                if (self::matches($m, $value)) {
                    return $rule['to'];
                }
            }
        }

        return $value;
    }

    private static function matches(array $m, ?float $value): bool
    {
        return match ($m['type']) {
            'value' => $value === null ? $m['v'] === null : ($m['v'] !== null && abs($value - $m['v']) < 1e-9),
            'range' => $value !== null && $value >= $m['lo'] && $value <= $m['hi'],
            'missing' => $value === null,
            'nonmissing' => $value !== null,
            'else' => true,
        };
    }

    private static function parseMatcher(string $token, string $rule): array
    {
        $lower = strtolower($token);

        if ($lower === 'else' || $token === '*') {
            return ['type' => 'else'];
        }

        if ($lower === 'missing' || $lower === 'miss') {
            return ['type' => 'missing'];
        }

        if ($lower === 'nonmissing' || $lower === 'nonmiss') {
            return ['type' => 'nonmissing'];
        }

        if (str_contains($token, '/')) {
            [$lo, $hi] = explode('/', $token, 2);
            $lo = strtolower($lo) === 'min' ? -INF : self::parseNumber($lo, $rule);
            $hi = strtolower($hi) === 'max' ? INF : self::parseNumber($hi, $rule);

            if ($lo > $hi) {
                throw new SyntaxError("recode 規則「({$rule})」的範圍 {$token} 起點大於終點", 0);
            }

            return ['type' => 'range', 'lo' => $lo, 'hi' => $hi];
        }

        return ['type' => 'value', 'v' => self::parseValue($token, $rule)];
    }

    private static function parseValue(string $token, string $rule): ?float
    {
        if (preg_match('/^\.[a-z]?$/', $token) === 1) {
            return null;
        }

        return self::parseNumber($token, $rule);
    }

    private static function parseNumber(string $token, string $rule): float
    {
        if (! is_numeric($token)) {
            throw new SyntaxError("recode 規則「({$rule})」中無法解析的值「{$token}」", 0);
        }

        return (float) $token;
    }
}

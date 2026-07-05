<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Program;

use Quesfix\StataLogic\Wildcard;

/**
 * Stata varlist 解析（用於 egen 的變數清單）。
 * 支援：明確變數名、? 與 * 萬用字元、以及 a- b 連續範圍（依資料中變數順序展開）。
 * 例：「cb23z03 cb23z04 cb23z05」「marbro_*」「j15errs_s?」「k26z01- k26z03 k27z01- k27z94」
 */
final class Varlist
{
    /**
     * @param  string[]  $available  資料中可用的變數名稱（依定義順序）
     * @return string[] 展開後實際匹配到的變數名稱（依 available 順序、去重）
     */
    public static function expand(string $spec, array $available): array
    {
        // 將範圍運算子周圍空白收斂：「k26z01- k26z03」→「k26z01-k26z03」
        $spec = preg_replace('/\s*-\s*/', '-', trim($spec));

        $result = [];
        $order = array_flip($available);

        foreach (preg_split('/\s+/', $spec, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (str_contains($token, '-')) {
                [$start, $end] = explode('-', $token, 2);
                $result = [...$result, ...self::range($start, $end, $available, $order)];
            } elseif (str_contains($token, '?') || str_contains($token, '*')) {
                $result = [...$result, ...Wildcard::match($token, $available)];
            } else {
                $result[] = $token;
            }
        }

        // 依 available 順序排序並去重
        $unique = array_values(array_unique($result));
        usort($unique, fn ($a, $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        return $unique;
    }

    /**
     * @param  array<string, int>  $order
     * @return string[]
     */
    private static function range(string $start, string $end, array $available, array $order): array
    {
        if (! isset($order[$start], $order[$end])) {
            return [$start, $end]; // 端點不存在 → 原樣帶回，交由驗證階段報未知變數
        }

        $from = $order[$start];
        $to = $order[$end];

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return array_slice($available, $from, $to - $from + 1);
    }
}

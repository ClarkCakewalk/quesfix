<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Program;

use Quesfix\StataLogic\Exceptions\SyntaxError;

/**
 * Stata numlist 解析（用於 egen anycount/anymatch 的 value() 選項）。
 * 支援：單一整數、a/b 連續範圍（含端點）、以空白分隔的多項。
 * 例：「1/90 97 98 99」「0 995」「50/113 997 998 999」
 */
final class Numlist
{
    /** @return float[] 展開後的數值集合 */
    public static function parse(string $spec): array
    {
        $values = [];

        foreach (preg_split('/\s+/', trim($spec), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (preg_match('/^(-?\d+(?:\.\d+)?)\/(-?\d+(?:\.\d+)?)$/', $token, $m) === 1) {
                $from = (int) $m[1];
                $to = (int) $m[2];

                if ($to < $from) {
                    throw new SyntaxError("numlist 範圍 {$token} 起點大於終點", 0);
                }

                for ($i = $from; $i <= $to; $i++) {
                    $values[] = (float) $i;
                }
            } elseif (is_numeric($token)) {
                $values[] = (float) $token;
            } else {
                throw new SyntaxError("無法解析的 numlist 項目「{$token}」", 0);
            }
        }

        if ($values === []) {
            throw new SyntaxError('numlist 為空', 0);
        }

        return $values;
    }
}

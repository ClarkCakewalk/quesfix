<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

/**
 * anymatch 變數樣式的萬用字元工具：? 匹配單一字元，* 匹配任意長度。
 */
final class Wildcard
{
    /**
     * @param string[] $names
     * @return string[]
     */
    public static function match(string $pattern, array $names): array
    {
        $regex = self::toRegex($pattern);

        return array_values(array_filter($names, fn (string $n) => preg_match($regex, $n) === 1));
    }

    public static function toRegex(string $pattern): string
    {
        $quoted = preg_quote($pattern, '/');
        $quoted = str_replace(['\?', '\*'], ['[A-Za-z0-9_]', '[A-Za-z0-9_]*'], $quoted);

        return '/^' . $quoted . '$/';
    }
}

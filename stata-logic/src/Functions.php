<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

use Quesfix\StataLogic\Exceptions\EvalError;

/**
 * 一般函數（FuncCall）的型別簽章與求值，語意比照 Stata：
 *
 * - 數值缺失以 null 表示；字串的「缺失」為空字串 ""。
 * - 字串函數依 Stata 14+ 規則：strlen/strpos/substr 以 byte 計，ustrlen/ustrpos/usubstr 以字元計。
 * - cond(x, a, b[, c])：x 非零（含缺失）→ a，x 為零 → b；有第 4 參數時 x 缺失 → c。
 * - min()/max() 忽略缺失值；全部缺失時回傳缺失。
 */
final class Functions
{
    public const N = VariableCatalog::TYPE_NUMERIC;

    public const S = VariableCatalog::TYPE_STRING;

    public const ANY = 'any';

    /**
     * 函數名稱 => [最少參數數, 最多參數數（-1 不限）, 各參數型別（最後一項可重複）, 回傳型別]
     * 回傳型別 'cond' 表示與第 2 參數相同。
     */
    public const SIGNATURES = [
        'cond' => [3, 4, [self::N, self::ANY], 'cond'],
        'missing' => [1, -1, [self::ANY], self::N],
        'min' => [1, -1, [self::N], self::N],
        'max' => [1, -1, [self::N], self::N],
        'int' => [1, 1, [self::N], self::N],
        'floor' => [1, 1, [self::N], self::N],
        'ceil' => [1, 1, [self::N], self::N],
        'round' => [1, 2, [self::N], self::N],
        'mod' => [2, 2, [self::N], self::N],
        'sqrt' => [1, 1, [self::N], self::N],
        'exp' => [1, 1, [self::N], self::N],
        'ln' => [1, 1, [self::N], self::N],
        'log' => [1, 1, [self::N], self::N],
        'real' => [1, 1, [self::S], self::N],
        'string' => [1, 2, [self::N, self::S], self::S],
        'strtrim' => [1, 1, [self::S], self::S],
        'trim' => [1, 1, [self::S], self::S],
        'strltrim' => [1, 1, [self::S], self::S],
        'ltrim' => [1, 1, [self::S], self::S],
        'strrtrim' => [1, 1, [self::S], self::S],
        'rtrim' => [1, 1, [self::S], self::S],
        'stritrim' => [1, 1, [self::S], self::S],
        'itrim' => [1, 1, [self::S], self::S],
        'ustrtrim' => [1, 1, [self::S], self::S],
        'strupper' => [1, 1, [self::S], self::S],
        'upper' => [1, 1, [self::S], self::S],
        'ustrupper' => [1, 1, [self::S], self::S],
        'strlower' => [1, 1, [self::S], self::S],
        'lower' => [1, 1, [self::S], self::S],
        'ustrlower' => [1, 1, [self::S], self::S],
        'strlen' => [1, 1, [self::S], self::N],
        'ustrlen' => [1, 1, [self::S], self::N],
        'strpos' => [2, 2, [self::S], self::N],
        'ustrpos' => [2, 2, [self::S], self::N],
        'substr' => [3, 3, [self::S, self::N, self::N], self::S],
        'usubstr' => [3, 3, [self::S, self::N, self::N], self::S],
        'strmatch' => [2, 2, [self::S], self::N],
        'regexm' => [2, 2, [self::S], self::N],
        'ustrregexm' => [2, 2, [self::S], self::N],
    ];

    public static function exists(string $name): bool
    {
        return isset(self::SIGNATURES[$name]);
    }

    /** 第 $i 個參數（0 起算）的預期型別 */
    public static function argType(string $name, int $i): string
    {
        $types = self::SIGNATURES[$name][2];

        return $types[min($i, count($types) - 1)];
    }

    /**
     * @param  list<float|string|null>  $args  已求值的參數（cond 除外）
     */
    public static function call(string $name, array $args): float|string|null
    {
        foreach ($args as $i => $arg) {
            $expected = self::argType($name, $i);
            if ($expected === self::N && is_string($arg)) {
                throw new EvalError(sprintf('%s() 的第 %d 個參數應為數值，不能是字串', $name, $i + 1));
            }
            if ($expected === self::S && ! is_string($arg)) {
                throw new EvalError(sprintf('%s() 的第 %d 個參數應為字串，不能是數值', $name, $i + 1));
            }
        }

        return match ($name) {
            'missing' => self::anyMissing($args) ? 1.0 : 0.0,
            'min', 'max' => self::minMax($name, $args),
            'int' => self::num($args[0], fn ($x) => $x < 0 ? ceil($x) : floor($x)),
            'floor' => self::num($args[0], fn ($x) => floor($x)),
            'ceil' => self::num($args[0], fn ($x) => ceil($x)),
            'round' => self::round($args[0], $args[1] ?? 1.0),
            'mod' => self::mod($args[0], $args[1]),
            'sqrt' => self::num($args[0], fn ($x) => $x < 0 ? null : sqrt($x)),
            'exp' => self::num($args[0], fn ($x) => exp($x)),
            'ln', 'log' => self::num($args[0], fn ($x) => $x <= 0 ? null : log($x)),
            'real' => self::real($args[0]),
            'string' => self::toString($args[0]),
            'strtrim', 'trim' => trim($args[0], ' '),
            'strltrim', 'ltrim' => ltrim($args[0], ' '),
            'strrtrim', 'rtrim' => rtrim($args[0], ' '),
            'stritrim', 'itrim' => preg_replace('/ {2,}/', ' ', $args[0]),
            'ustrtrim' => preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $args[0]) ?? $args[0],
            'strupper', 'upper' => strtoupper($args[0]),
            'ustrupper' => mb_strtoupper($args[0], 'UTF-8'),
            'strlower', 'lower' => strtolower($args[0]),
            'ustrlower' => mb_strtolower($args[0], 'UTF-8'),
            'strlen' => (float) strlen($args[0]),
            'ustrlen' => (float) mb_strlen($args[0], 'UTF-8'),
            'strpos' => self::strpos($args[0], $args[1], false),
            'ustrpos' => self::strpos($args[0], $args[1], true),
            'substr' => self::substr($args[0], $args[1], $args[2], false),
            'usubstr' => self::substr($args[0], $args[1], $args[2], true),
            'strmatch' => self::strmatch($args[0], $args[1]) ? 1.0 : 0.0,
            'regexm', 'ustrregexm' => self::regexm($args[0], $args[1]) ? 1.0 : 0.0,
            default => throw new EvalError(sprintf("未知的函數 '%s'", $name)),
        };
    }

    private static function num(?float $x, callable $fn): ?float
    {
        if ($x === null) {
            return null;
        }

        $result = $fn($x);

        return $result === null || ! is_finite($result) ? null : (float) $result;
    }

    private static function anyMissing(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg === null || $arg === '') {
                return true;
            }
        }

        return false;
    }

    private static function minMax(string $name, array $args): ?float
    {
        $values = array_filter($args, fn ($a) => $a !== null);

        if ($values === []) {
            return null;
        }

        return (float) ($name === 'min' ? min($values) : max($values));
    }

    private static function round(?float $x, ?float $unit): ?float
    {
        if ($x === null || $unit === null || $unit == 0.0) {
            return null;
        }

        // Stata：round(x, y) = y * floor(x/y + .5)
        return $unit * floor($x / $unit + 0.5);
    }

    private static function mod(?float $x, ?float $y): ?float
    {
        if ($x === null || $y === null || $y == 0.0) {
            return null;
        }

        return $x - $y * floor($x / $y);
    }

    private static function real(string $s): ?float
    {
        $t = trim($s);

        if ($t === '' || $t === '.' || ! is_numeric($t)) {
            return null;
        }

        return (float) $t;
    }

    private static function toString(?float $x): string
    {
        if ($x === null) {
            return '.';
        }

        if ($x == floor($x) && abs($x) < 1e15) {
            return (string) (int) $x;
        }

        return rtrim(rtrim(sprintf('%.7F', $x), '0'), '.');
    }

    private static function strpos(string $haystack, string $needle, bool $unicode): float
    {
        if ($needle === '') {
            return 1.0;
        }

        $pos = $unicode ? mb_strpos($haystack, $needle, 0, 'UTF-8') : strpos($haystack, $needle);

        return $pos === false ? 0.0 : (float) ($pos + 1);
    }

    private static function substr(string $s, ?float $start, ?float $length, bool $unicode): string
    {
        $len = $unicode ? mb_strlen($s, 'UTF-8') : strlen($s);

        if ($start === null) {
            return '';
        }

        $n1 = (int) $start;
        $begin = $n1 < 0 ? $len + $n1 : $n1 - 1;

        if ($n1 === 0 || $begin < 0 || $begin >= $len) {
            return '';
        }

        // 長度缺失 → 取到結尾
        $count = $length === null ? $len - $begin : max(0, (int) $length);

        return $unicode ? mb_substr($s, $begin, $count, 'UTF-8') : substr($s, $begin, $count);
    }

    /** Stata strmatch：整串比對，* 匹配任意長度、? 匹配單一字元 */
    private static function strmatch(string $s, string $pattern): bool
    {
        $regex = '';
        foreach (mb_str_split($pattern, 1, 'UTF-8') as $ch) {
            $regex .= match ($ch) {
                '*' => '.*',
                '?' => '.',
                default => preg_quote($ch, '/'),
            };
        }

        return preg_match('/^' . $regex . '$/su', $s) === 1;
    }

    private static function regexm(string $s, string $pattern): bool
    {
        $result = @preg_match('/' . str_replace('/', '\/', $pattern) . '/u', $s);

        if ($result === false) {
            throw new EvalError(sprintf("regexm 的正規表示式 '%s' 無效", $pattern));
        }

        return $result === 1;
    }
}

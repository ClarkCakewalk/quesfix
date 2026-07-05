<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Program;

use Quesfix\StataLogic\Ast\Node;
use Quesfix\StataLogic\Evaluator;
use Quesfix\StataLogic\Exceptions\EvalError;
use Quesfix\StataLogic\Exceptions\SyntaxError;
use Quesfix\StataLogic\MutableResolver;
use Quesfix\StataLogic\Parser;
use Quesfix\StataLogic\VariableResolverInterface;

/**
 * Stata 子集程式：一段以 gen check = (...)（或 egen check = ...）結尾的小程式。
 *
 * 支援指令：
 *   gen|generate VAR = EXPR [if COND]
 *   replace VAR = EXPR [if COND]
 *   egen VAR = rowtotal(VARLIST)
 *   egen VAR = anycount(VARLIST), value(NUMLIST)
 *   egen VAR = anymatch(VARLIST), value(NUMLIST)
 *   forvalues X = A/B { ... }   （解析期展開）
 *   local NAME = 整數運算       （解析期計算，供迴圈索引使用）
 *   drop ...                    （忽略）
 *   cap/capture 前綴            （執行失敗時忽略該句）
 *
 * 不支援（需於 Stata 前處理階段完成後匯入）：rename / split / destring
 */
final class StataProgram
{
    public const UNSUPPORTED = ['rename', 'split', 'destring'];

    /** @var list<array> 展開後的扁平語句（typed） */
    private array $statements;

    public function __construct(string $source)
    {
        $flat = $this->unroll($source);
        $this->statements = array_map($this->parseStatement(...), $flat);
    }

    public static function looksLikeProgram(string $source): bool
    {
        if (str_contains($source, "\n")) {
            return true;
        }

        // 以 Stata 指令動詞開頭（單一布林運算式則以變數/數字/括號開頭）
        return (bool) preg_match(
            '/^\s*(cap(?:ture)?\s+)?(gen(?:erate)?|egen|replace|rename|split|destring|drop|forvalues?|local)\b/',
            $source,
        );
    }

    /** 執行程式，回傳此檢核條件是否觸發（check 為非缺失且非零） */
    public function run(VariableResolverInterface $base): bool
    {
        $scope = new MutableResolver($base);

        foreach ($this->statements as $stmt) {
            try {
                $this->execute($stmt, $scope);
            } catch (EvalError $e) {
                if (! ($stmt['cap'] ?? false)) {
                    throw $e;
                }
            }
        }

        if (! $scope->has('check')) {
            throw new EvalError('程式未產生 check 變數');
        }

        $value = $scope->value('check');

        return $value !== null && (float) $value != 0.0;
    }

    /**
     * 供驗證使用：回傳程式分析結果。
     *
     * @return array{defined: string[], unsupported: string[], exprs: array<array{node: Node, isCheck: bool}>, hasCheck: bool}
     */
    public function analyze(): array
    {
        $defined = [];
        $unsupported = [];
        $exprs = [];
        $hasCheck = false;

        foreach ($this->statements as $stmt) {
            switch ($stmt['kind']) {
                case 'assign':
                    $exprs[] = ['node' => $stmt['expr'], 'isCheck' => $stmt['var'] === 'check'];
                    if ($stmt['cond'] !== null) {
                        $exprs[] = ['node' => $stmt['cond'], 'isCheck' => false];
                    }
                    $defined[] = $stmt['var'];
                    $hasCheck = $hasCheck || $stmt['var'] === 'check';
                    break;
                case 'egen':
                    $defined[] = $stmt['var'];
                    $hasCheck = $hasCheck || $stmt['var'] === 'check';
                    break;
                case 'unsupported':
                    $unsupported[] = $stmt['cmd'];
                    break;
            }
        }

        return [
            'defined' => array_values(array_unique($defined)),
            'unsupported' => array_values(array_unique($unsupported)),
            'exprs' => $exprs,
            'hasCheck' => $hasCheck,
        ];
    }

    // ---- 執行 ----

    private function execute(array $stmt, MutableResolver $scope): void
    {
        switch ($stmt['kind']) {
            case 'assign':
                $this->executeAssign($stmt, $scope);
                break;
            case 'egen':
                $this->executeEgen($stmt, $scope);
                break;
            case 'drop':
                break; // 忽略
            case 'unsupported':
                throw new EvalError(sprintf("不支援的指令 '%s'（需於 Stata 前處理完成）", $stmt['cmd']));
        }
    }

    private function executeAssign(array $stmt, MutableResolver $scope): void
    {
        $evaluator = new Evaluator($scope);

        if ($stmt['cond'] !== null) {
            $cond = $evaluator->evaluate($stmt['cond']);
            $condTrue = $cond !== null && (float) $cond != 0.0; // if 限定：缺失視為假

            if (! $condTrue) {
                // gen：未符合 → 缺失；replace：未符合 → 保留原值（不存在則缺失）
                if ($stmt['cmd'] === 'gen' || ! $scope->has($stmt['var'])) {
                    $scope->set($stmt['var'], null);
                }

                return;
            }
        }

        $scope->set($stmt['var'], $evaluator->evaluate($stmt['expr']));
    }

    private function executeEgen(array $stmt, MutableResolver $scope): void
    {
        $vars = Varlist::expand($stmt['varlist'], $scope->names());

        if ($stmt['func'] === 'rowtotal') {
            $sum = 0.0;
            foreach ($vars as $name) {
                $v = $scope->has($name) ? $scope->value($name) : null;
                if (is_numeric($v)) {
                    $sum += (float) $v;
                }
            }
            $scope->set($stmt['var'], $sum);

            return;
        }

        // anycount / anymatch：依 value() 的 numlist 計數/比對
        $numset = Numlist::parse($stmt['numlist']);
        $count = 0;

        foreach ($vars as $name) {
            $v = $scope->has($name) ? $scope->value($name) : null;
            if (is_numeric($v) && $this->inNumset((float) $v, $numset)) {
                $count++;
            }
        }

        $scope->set($stmt['var'], $stmt['func'] === 'anymatch' ? ($count > 0 ? 1.0 : 0.0) : (float) $count);
    }

    /** @param float[] $numset */
    private function inNumset(float $value, array $numset): bool
    {
        foreach ($numset as $n) {
            if (abs($value - $n) < 1e-9) {
                return true;
            }
        }

        return false;
    }

    // ---- 解析 ----

    /** 將原始片段展開（迴圈 unroll + 巨集替換）為扁平的語句字串清單 */
    private function unroll(string $source): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $source) as $raw) {
            $line = trim(preg_replace('/\/\/.*$/', '', $raw));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $out = [];
        $macros = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $this->substituteMacros($lines[$i], $macros);

            if (preg_match('/^forvalues?\s+(\w+)\s*=\s*(\d+)\/(\d+)\s*\{?\s*$/', $line, $m) === 1) {
                [$body, $next] = $this->collectLoopBody($lines, $i, $line);
                $loopVar = $m[1];

                for ($v = (int) $m[2]; $v <= (int) $m[3]; $v++) {
                    $loopMacros = $macros;
                    $loopMacros[$loopVar] = (string) $v;

                    foreach ($body as $bodyLine) {
                        $bl = $this->substituteMacros($bodyLine, $loopMacros);

                        if (preg_match('/^local\s+(\w+)\s*=\s*(.+)$/', $bl, $lm) === 1) {
                            $loopMacros[$lm[1]] = $this->evalInt($lm[2]);

                            continue;
                        }

                        $out[] = $bl;
                    }
                }

                $i = $next;

                continue;
            }

            if (preg_match('/^local\s+(\w+)\s*=\s*(.+)$/', $line, $lm) === 1) {
                $macros[$lm[1]] = $this->evalInt($lm[2]);
                $i++;

                continue;
            }

            $out[] = $line;
            $i++;
        }

        return $out;
    }

    /**
     * 收集 forvalues { ... } 的迴圈體。
     *
     * @return array{0: string[], 1: int} 迴圈體行、迴圈結束後的索引
     */
    private function collectLoopBody(array $lines, int $headerIdx, string $headerLine): array
    {
        $depth = substr_count($headerLine, '{');
        $body = [];
        $i = $headerIdx + 1;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];
            $depth += substr_count($line, '{');
            $depth -= substr_count($line, '}');

            if ($depth <= 0) {
                $remainder = trim(str_replace('}', '', $line));
                if ($remainder !== '') {
                    $body[] = $remainder;
                }

                return [$body, $i + 1];
            }

            $body[] = $line;
            $i++;
        }

        throw new SyntaxError('forvalues 缺少結尾的 }', 0);
    }

    private function substituteMacros(string $line, array $macros): string
    {
        return preg_replace_callback(
            "/`([A-Za-z_][A-Za-z0-9_]*)'/",
            fn ($m) => $macros[$m[1]] ?? $m[0],
            $line,
        );
    }

    private function evalInt(string $expr): string
    {
        $ast = (new Parser($expr))->parse();
        $value = (new Evaluator(new MutableResolver(new \Quesfix\StataLogic\ArrayResolver([]))))->evaluate($ast);

        return (string) (int) round((float) $value);
    }

    /** 將單一扁平語句字串解析為 typed 結構 */
    private function parseStatement(string $line): array
    {
        $cap = false;
        if (preg_match('/^cap(?:ture)?\s+(.*)$/s', $line, $m) === 1) {
            $cap = true;
            $line = trim($m[1]);
        }

        // egen
        if (preg_match('/^egen\s+(\w+)\s*=\s*(rowtotal|anycount|anymatch)\s*\((.*?)\)\s*(?:,\s*values?\s*\((.*?)\)\s*)?$/s', $line, $m) === 1) {
            return [
                'kind' => 'egen', 'cap' => $cap, 'var' => $m[1], 'func' => $m[2],
                'varlist' => trim($m[3]), 'numlist' => isset($m[4]) ? trim($m[4]) : null,
            ];
        }

        // gen / replace
        if (preg_match('/^(gen(?:erate)?|replace)\s+(\w+)\s*=\s*(.*)$/s', $line, $m) === 1) {
            [$exprSrc, $condSrc] = $this->splitOnIf($m[3]);

            return [
                'kind' => 'assign',
                'cap' => $cap,
                'cmd' => str_starts_with($m[1], 'gen') ? 'gen' : 'replace',
                'var' => $m[2],
                'expr' => (new Parser($exprSrc))->parse(),
                'cond' => $condSrc !== null ? (new Parser($condSrc))->parse() : null,
            ];
        }

        if (preg_match('/^drop\b/', $line) === 1) {
            return ['kind' => 'drop', 'cap' => $cap];
        }

        if (preg_match('/^([a-zA-Z]+)\b/', $line, $m) === 1 && in_array($m[1], self::UNSUPPORTED, true)) {
            return ['kind' => 'unsupported', 'cap' => $cap, 'cmd' => $m[1]];
        }

        throw new SyntaxError(sprintf('無法解析的語句：%s', mb_substr($line, 0, 60)), 0);
    }

    /**
     * 以頂層（括號外）的 " if " 切分 gen/replace 的賦值式與條件式。
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitOnIf(string $rhs): array
    {
        $depth = 0;
        $len = strlen($rhs);

        for ($i = 0; $i < $len; $i++) {
            $c = $rhs[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($depth === 0 && $c === 'i' && $i + 1 < $len && $rhs[$i + 1] === 'f') {
                $before = $i === 0 ? ' ' : $rhs[$i - 1];
                $after = $i + 2 < $len ? $rhs[$i + 2] : ' ';
                if (! ctype_alnum($before) && $before !== '_' && ! ctype_alnum($after) && $after !== '_') {
                    return [trim(substr($rhs, 0, $i)), trim(substr($rhs, $i + 2))];
                }
            }
        }

        return [trim($rhs), null];
    }
}

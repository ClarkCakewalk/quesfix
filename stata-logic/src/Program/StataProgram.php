<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Program;

use Quesfix\StataLogic\ArrayResolver;
use Quesfix\StataLogic\Evaluator;
use Quesfix\StataLogic\Exceptions\EvalError;
use Quesfix\StataLogic\Exceptions\SyntaxError;
use Quesfix\StataLogic\MutableResolver;
use Quesfix\StataLogic\Parser;
use Quesfix\StataLogic\VariableResolverInterface;

/**
 * Stata 子集程式：一段以 gen check = (...)（或 egen check = ...）產生 check 變數的小程式。
 * 每次對「一個樣本」執行。
 *
 * 支援指令：
 *   gen|generate [TYPE] VAR = EXPR [if COND]、replace VAR = EXPR [if COND]
 *   egen VAR = rowtotal(VARLIST) / anycount(VARLIST), value(NUMLIST) / anymatch(VARLIST), value(NUMLIST)
 *   recode VARLIST (RULE) (RULE) ... [, gen(NEWVARLIST)]
 *   split VAR [, parse("…" "…") gen(STUB) limit(#) destring notrim]
 *   destring VARLIST, replace|gen(NEWVARLIST) [force ignore("…")]
 *   rename OLD NEW、rename (OLD…) (NEW…)
 *   drop ...                                       （忽略）
 *   if COND { ... } [else if COND { ... }] [else { ... }]   （依「本樣本」的值判斷）
 *   forvalues X = A/B | A(D)B { ... }               （解析期展開）
 *   foreach X of varlist|numlist|local … { ... }、foreach X in … { ... }   （解析期展開）
 *   local NAME = 運算式、local NAME 文字             （解析期巨集）
 *   cap/capture、qui/quietly、noi/noisily 前綴      （cap：執行失敗時略過該句）
 */
final class StataProgram
{
    /** @var list<array> 語句樹（if 區塊含巢狀語句） */
    private array $statements;

    /** @var string[] 解析期可用的變數名稱（資料格式＋程式已衍生），供 foreach … of varlist 展開 */
    private array $available;

    /**
     * @param  string[]  $knownNames  資料格式中的變數名稱（依題序），供 foreach varlist 的萬用字元/範圍展開
     */
    public function __construct(string $source, array $knownNames = [])
    {
        $this->available = $knownNames;
        $macros = [];
        $this->statements = $this->parseLines($this->cleanLines($source), $macros);
    }

    public static function looksLikeProgram(string $source): bool
    {
        if (str_contains(trim($source), "\n")) {
            return true;
        }

        // 以 Stata 指令動詞開頭（單一布林運算式則以變數/數字/括號開頭）
        return (bool) preg_match(
            '/^\s*((cap(?:ture)?|qui(?:etly)?)\s+)?(gen(?:erate)?|egen|replace|recode|rename|ren|split|destring|drop|forv[a-z]*|foreach|local|if)\b/',
            $source,
        );
    }

    /** @return list<array> 語句樹，供 ProgramValidator 逐句驗證 */
    public function statements(): array
    {
        return $this->statements;
    }

    /** 執行程式，回傳此檢核條件是否觸發（check 為非缺失且非零） */
    public function run(VariableResolverInterface $base): bool
    {
        $scope = new MutableResolver($base);

        $this->executeAll($this->statements, $scope);

        if (! $scope->has('check')) {
            throw new EvalError('程式未產生 check 變數');
        }

        $value = $scope->value('check');

        return $value !== null && (is_string($value) ? $value !== '' : (float) $value != 0.0);
    }

    // =====================================================================
    // 執行
    // =====================================================================

    private function executeAll(array $statements, MutableResolver $scope): void
    {
        foreach ($statements as $stmt) {
            try {
                $this->execute($stmt, $scope);
            } catch (EvalError $e) {
                if (! ($stmt['cap'] ?? false)) {
                    throw $e;
                }
            }
        }
    }

    private function execute(array $stmt, MutableResolver $scope): void
    {
        match ($stmt['kind']) {
            'assign' => $this->executeAssign($stmt, $scope),
            'egen' => $this->executeEgen($stmt, $scope),
            'recode' => $this->executeRecode($stmt, $scope),
            'split' => $this->executeSplit($stmt, $scope),
            'destring' => $this->executeDestring($stmt, $scope),
            'rename' => $this->executeRename($stmt, $scope),
            'if' => $this->executeIf($stmt, $scope),
            'drop' => null, // 忽略
        };
    }

    private function executeAssign(array $stmt, MutableResolver $scope): void
    {
        $evaluator = new Evaluator($scope);

        if ($stmt['cond'] !== null) {
            $cond = $evaluator->evaluate($stmt['cond']);
            $condTrue = $cond !== null && (float) $cond != 0.0; // if 限定：缺失視為假

            if (! $condTrue) {
                // gen：未符合 → 缺失（字串變數為 ""）；replace：未符合 → 保留原值
                if ($stmt['cmd'] === 'gen' || ! $scope->has($stmt['var'])) {
                    $scope->set($stmt['var'], $this->missingLike($stmt['expr'], $evaluator));
                }

                return;
            }
        }

        $scope->set($stmt['var'], $evaluator->evaluate($stmt['expr']));
    }

    /** gen 的 if 不成立時，依運算式型別給缺失值：數值 → .、字串 → "" */
    private function missingLike(\Quesfix\StataLogic\Ast\Node $expr, Evaluator $evaluator): ?string
    {
        try {
            return is_string($evaluator->evaluate($expr)) ? '' : null;
        } catch (EvalError) {
            return null;
        }
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
        $numset = Numlist::parse((string) $stmt['numlist']);
        $count = 0;

        foreach ($vars as $name) {
            $v = $scope->has($name) ? $scope->value($name) : null;
            if (is_numeric($v) && $this->inNumset((float) $v, $numset)) {
                $count++;
            }
        }

        $scope->set($stmt['var'], $stmt['func'] === 'anymatch' ? ($count > 0 ? 1.0 : 0.0) : (float) $count);
    }

    /** 指令的 if 限定是否成立（無限定 → 成立；缺失視為假） */
    private function qualifies(?\Quesfix\StataLogic\Ast\Node $cond, MutableResolver $scope): bool
    {
        if ($cond === null) {
            return true;
        }

        $value = (new Evaluator($scope))->evaluate($cond);

        return $value !== null && ! is_string($value) && (float) $value != 0.0;
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

    private function executeRecode(array $stmt, MutableResolver $scope): void
    {
        $selected = $this->qualifies($stmt['cond'], $scope);

        foreach ($stmt['vars'] as $i => $name) {
            if (! $scope->has($name)) {
                throw new EvalError(sprintf("recode：變數 '%s' 不存在", $name));
            }

            $value = $scope->value($name);

            if (is_string($value)) {
                throw new EvalError(sprintf("recode：變數 '%s' 為字串，無法重新編碼", $name));
            }

            if ($selected) {
                $scope->set($stmt['gen'][$i] ?? $name, Recode::apply($stmt['rules'], $value));
            } elseif (isset($stmt['gen'][$i])) {
                $scope->set($stmt['gen'][$i], null); // if 不成立：gen() 的新變數為缺失，原變數不變
            }
        }
    }

    private function executeSplit(array $stmt, MutableResolver $scope): void
    {
        if (! $scope->has($stmt['var'])) {
            throw new EvalError(sprintf("split：變數 '%s' 不存在", $stmt['var']));
        }

        $value = $scope->value($stmt['var']);

        if ($value !== null && ! is_string($value)) {
            throw new EvalError(sprintf("split：變數 '%s' 不是字串變數", $stmt['var']));
        }

        $text = (string) $value;
        if (! $stmt['notrim']) {
            $text = trim($text, ' ');
        }

        $pieces = $text === '' || ! $this->qualifies($stmt['cond'], $scope) ? [] : $this->splitText($text, $stmt['parse']);

        if ($stmt['limit'] !== null) {
            $pieces = array_slice($pieces, 0, $stmt['limit']);
        }

        $scope->addStub($stmt['stub'], $stmt['destring']);

        foreach ($pieces as $i => $piece) {
            if ($stmt['destring']) {
                $t = trim($piece);
                $piece = $t === '' ? null : (is_numeric($t) ? (float) $t : $piece);
            }

            $scope->set($stmt['stub'] . ($i + 1), $piece);
        }
    }

    /**
     * 依分隔字串切割（任一分隔字串皆可，取最早出現者；保留空片段，與 Stata split 相同）。
     *
     * @param  string[]  $separators
     * @return string[]
     */
    private function splitText(string $text, array $separators): array
    {
        if ($separators === [' ']) {
            return preg_split('/ +/', $text);
        }

        usort($separators, fn ($a, $b) => strlen($b) <=> strlen($a)); // 同位置時優先較長者
        $pattern = '/' . implode('|', array_map(fn ($s) => preg_quote($s, '/'), $separators)) . '/u';

        return preg_split($pattern, $text);
    }

    private function executeDestring(array $stmt, MutableResolver $scope): void
    {
        foreach ($stmt['vars'] as $i => $name) {
            if (! $scope->has($name)) {
                throw new EvalError(sprintf("destring：變數 '%s' 不存在", $name));
            }

            $value = $scope->value($name);

            if (is_string($value)) {
                $t = trim($stmt['ignore'] === '' ? $value : str_replace(mb_str_split($stmt['ignore']), '', $value));

                if ($t === '' || $t === '.') {
                    $value = null;
                } elseif (is_numeric($t)) {
                    $value = (float) $t;
                } elseif ($stmt['force']) {
                    $value = null;
                } else {
                    throw new EvalError(sprintf("destring：變數 '%s' 的值「%s」不是數字（可加 force 選項轉為缺失值）", $name, $value));
                }
            }

            $scope->set($stmt['gen'][$i] ?? $name, $value);
        }
    }

    private function executeRename(array $stmt, MutableResolver $scope): void
    {
        foreach ($stmt['pairs'] as [$old, $new]) {
            if (! $scope->has($old)) {
                throw new EvalError(sprintf("rename：變數 '%s' 不存在", $old));
            }

            $value = $scope->value($old);
            $scope->remove($old);
            $scope->set($new, $value);
        }
    }

    private function executeIf(array $stmt, MutableResolver $scope): void
    {
        foreach ($stmt['branches'] as $branch) {
            if ($branch['cond'] === null || (new Evaluator($scope))->evaluateBool($branch['cond'])) {
                $this->executeAll($branch['body'], $scope);

                return;
            }
        }
    }

    // =====================================================================
    // 解析
    // =====================================================================

    /** 切行、去除註解（// 、/* *\/、行首 *）、合併 /// 續行 */
    private function cleanLines(string $source): array
    {
        $source = preg_replace('#/\*.*?\*/#s', '', $source);
        $source = preg_replace('#[ \t]*///[^\n]*\r?\n#', ' ', $source);

        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $source) as $raw) {
            $line = trim(preg_replace('#(^|\s)//.*$#', '', $raw));
            if ($line !== '' && ! str_starts_with($line, '*')) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * 解析一段程式行（巨集替換、迴圈展開、if 區塊），回傳語句樹。
     *
     * @param  string[]  $lines
     * @param  array<string, string>  $macros
     */
    private function parseLines(array $lines, array &$macros): array
    {
        $out = [];
        $i = 0;

        while ($i < count($lines)) {
            $line = $this->substituteMacros($lines[$i], $macros);
            $i++;

            // local NAME = 運算式 ／ local NAME 文字
            if (preg_match('/^local\s+(\w+)\s*(=)?\s*(.*)$/', $line, $m) === 1) {
                $macros[$m[1]] = $m[2] === '=' ? $this->evalMacro($m[3]) : trim($m[3], " \"");

                continue;
            }

            // forvalues X = range {
            if (preg_match('/^forv[a-z]*\s+(\w+)\s*=\s*(.+?)\s*\{(.*)$/', $line, $m) === 1) {
                [$body, $i, $rest] = $this->collectBlock($lines, $i, $m[3], 'forvalues');
                $out = [...$out, ...$this->unrollLoop($m[1], $this->forvaluesRange($m[2]), $body, $macros)];
                $lines = $this->pushBack($lines, $i, $rest);

                continue;
            }

            // foreach X of varlist|numlist|local … {   ／  foreach X in … {
            if (preg_match('/^foreach\s+(\w+)\s+(?:of\s+(varlist|numlist|local|global)|in)\s+(.*?)\s*\{(.*)$/', $line, $m) === 1) {
                [$body, $i, $rest] = $this->collectBlock($lines, $i, $m[4], 'foreach');
                $values = $this->foreachValues($m[2], $m[3], $macros);
                $out = [...$out, ...$this->unrollLoop($m[1], $values, $body, $macros)];
                $lines = $this->pushBack($lines, $i, $rest);

                continue;
            }

            // if COND { ... } [else if COND { ... }] [else { ... }]
            if (preg_match('/^if\s+(.+?)\s*\{(.*)$/', $line, $m) === 1) {
                [$stmt, $lines, $i] = $this->parseIfBlock($m[1], $m[2], $lines, $i, $macros);
                $out[] = $stmt;

                continue;
            }

            if (preg_match('/^else\b/', $line) === 1) {
                throw new SyntaxError('else 之前缺少對應的 if { … } 區塊', 0);
            }

            if (str_starts_with($line, '}')) {
                throw new SyntaxError("多出的 '}'（缺少對應的 forvalues/foreach/if 區塊開頭）", 0);
            }

            $out[] = $this->parseStatement($line);
        }

        return $out;
    }

    /**
     * @param  string[]  $values
     * @param  string[]  $body
     */
    private function unrollLoop(string $loopVar, array $values, array $body, array $macros): array
    {
        $out = [];

        foreach ($values as $value) {
            $loopMacros = $macros;
            $loopMacros[$loopVar] = $value;
            $out = [...$out, ...$this->parseLines($body, $loopMacros)];
        }

        return $out;
    }

    /** 將區塊結尾 } 之後同一行的剩餘內容（如「} else {」的 else {）放回待處理行 */
    private function pushBack(array $lines, int $i, string $rest): array
    {
        if ($rest !== '') {
            array_splice($lines, $i, 0, [$rest]);
        }

        return $lines;
    }

    /** @return array{0: array, 1: string[], 2: int} if 語句、更新後的行、下一行索引 */
    private function parseIfBlock(string $condSrc, string $afterBrace, array $lines, int $i, array &$macros): array
    {
        $branches = [];

        while (true) {
            [$body, $i, $rest] = $this->collectBlock($lines, $i, $afterBrace, 'if');
            $branches[] = [
                'cond' => $condSrc === '' ? null : $this->parseExpr($condSrc, 'if'),
                'body' => $this->parseLines($body, $macros),
            ];
            $lines = $this->pushBack($lines, $i, $rest);

            if ($condSrc === '' || ! isset($lines[$i])) {
                break;
            }

            $next = $this->substituteMacros($lines[$i], $macros);

            if (preg_match('/^else\s+if\s+(.+?)\s*\{(.*)$/', $next, $m) === 1) {
                [$condSrc, $afterBrace] = [$m[1], $m[2]];
            } elseif (preg_match('/^else\s*\{(.*)$/', $next, $m) === 1) {
                [$condSrc, $afterBrace] = ['', $m[1]];
            } else {
                break;
            }

            $i++;
        }

        return [['kind' => 'if', 'cap' => false, 'branches' => $branches], $lines, $i];
    }

    /**
     * 收集 { ... } 區塊內容（支援巢狀、同行開合與「} else {」）。
     *
     * @param  int  $i  區塊標頭的下一行索引
     * @param  string  $first  標頭行 { 之後的內容
     * @return array{0: string[], 1: int, 2: string} 區塊內各行、結尾行的下一行索引、結尾 } 之後的剩餘內容
     */
    private function collectBlock(array $lines, int $i, string $first, string $what): array
    {
        $depth = 1;
        $body = [];
        $idx = $i - 1;
        $text = $first;

        while (true) {
            $inString = false;
            $len = strlen($text);

            for ($k = 0; $k < $len; $k++) {
                $c = $text[$k];

                if ($c === '"') {
                    $inString = ! $inString;
                } elseif (! $inString && $c === '{') {
                    $depth++;
                } elseif (! $inString && $c === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $before = trim(substr($text, 0, $k));
                        if ($before !== '') {
                            $body[] = $before;
                        }

                        return [$body, $idx + 1, trim(substr($text, $k + 1))];
                    }
                }
            }

            if (trim($text) !== '') {
                $body[] = trim($text);
            }

            $idx++;

            if ($idx >= count($lines)) {
                throw new SyntaxError("{$what} 區塊缺少結尾的 }", 0);
            }

            $text = $lines[$idx];
        }
    }

    /** @return string[] */
    private function forvaluesRange(string $spec): array
    {
        $spec = trim($spec);

        if (preg_match('/^(-?\d+)\s*\/\s*(-?\d+)$/', $spec, $m) === 1) {
            [$from, $to, $step] = [(int) $m[1], (int) $m[2], 1];
        } elseif (preg_match('/^(-?\d+)\s*\(\s*(-?\d+)\s*\)\s*(-?\d+)$/', $spec, $m) === 1) {
            [$from, $step, $to] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            throw new SyntaxError("無法解析的 forvalues 範圍「{$spec}」（支援 a/b 或 a(d)b）", 0);
        }

        if ($step === 0) {
            throw new SyntaxError('forvalues 的間距不能為 0', 0);
        }

        $values = [];
        for ($v = $from; $step > 0 ? $v <= $to : $v >= $to; $v += $step) {
            $values[] = (string) $v;
        }

        return $values;
    }

    /** @return string[] */
    private function foreachValues(string $kind, string $spec, array $macros): array
    {
        $spec = trim($spec);

        switch ($kind) {
            case 'varlist':
                $vars = Varlist::expand($spec, $this->available);
                if ($vars === []) {
                    throw new SyntaxError("foreach … of varlist {$spec}：未匹配到任何變數", 0);
                }

                return $vars;

            case 'numlist':
                return array_map(
                    fn (float $v) => $v == floor($v) ? (string) (int) $v : (string) $v,
                    Numlist::parse($spec),
                );

            case 'local':
            case 'global':
                $spec = $macros[$spec] ?? '';
                // no break

            default: // in
                preg_match_all('/"([^"]*)"|(\S+)/', $spec, $m, PREG_SET_ORDER);

                return array_map(fn ($x) => $x[2] ?? $x[1], $m);
        }
    }

    private function substituteMacros(string $line, array $macros): string
    {
        return preg_replace_callback(
            "/`([A-Za-z_][A-Za-z0-9_]*)'/",
            fn ($m) => $macros[$m[1]] ?? $m[0],
            $line,
        );
    }

    private function evalMacro(string $expr): string
    {
        $value = (new Evaluator(new MutableResolver(new ArrayResolver([]))))->evaluate($this->parseExpr($expr, 'local'));

        if (is_string($value)) {
            return $value;
        }

        if ($value === null) {
            return '.';
        }

        return $value == floor($value) ? (string) (int) $value : (string) $value;
    }

    /** 將單一扁平語句字串解析為 typed 結構 */
    private function parseStatement(string $line): array
    {
        $cap = false;

        while (preg_match('/^(cap(?:t|tu|tur|ture)?|qui(?:e|et|etl|etly)?|noi(?:s|si|sil|sily)?)\s+(.*)$/s', $line, $m) === 1) {
            $cap = $cap || str_starts_with($m[1], 'cap');
            $line = trim($m[2]);
        }

        if (str_contains($line, '`')) {
            throw new SyntaxError(sprintf('未定義的巨集：%s', mb_substr($line, 0, 60)), 0);
        }

        // egen
        if (preg_match('/^egen\s+(?:\w+\s+)?(\w+)\s*=\s*(rowtotal|anycount|anymatch)\s*\((.*?)\)\s*(?:,\s*values?\s*\((.*?)\)\s*)?$/s', $line, $m) === 1) {
            $this->available[] = $m[1];

            return [
                'kind' => 'egen', 'cap' => $cap, 'var' => $m[1], 'func' => $m[2],
                'varlist' => trim($m[3]), 'numlist' => isset($m[4]) ? trim($m[4]) : null,
            ];
        }

        // gen / replace（gen 可指定儲存型別，如 gen byte x = …、gen str20 s = …）
        if (preg_match('/^(gen(?:erate)?|replace)\s+(?:(?:byte|int|long|float|double|str\d+|strL)\s+)?(\w+)\s*=\s*(.*)$/s', $line, $m) === 1) {
            [$exprSrc, $condSrc] = $this->splitOnIf($m[3]);
            $this->available[] = $m[2];

            return [
                'kind' => 'assign',
                'cap' => $cap,
                'cmd' => str_starts_with($m[1], 'gen') ? 'gen' : 'replace',
                'var' => $m[2],
                'expr' => $this->parseExpr($exprSrc, $m[1]),
                'cond' => $condSrc !== null ? $this->parseExpr($condSrc, 'if') : null,
            ];
        }

        if (preg_match('/^recode\s+(.*)$/s', $line, $m) === 1) {
            return $this->parseRecode($m[1], $cap);
        }

        if (preg_match('/^split\s+(\w+)\b(.*)$/s', $line, $m) === 1) {
            [$ifSrc, $optionText] = $this->splitQualifier($m[2], 'split');

            return $this->parseSplit($m[1], $optionText, $ifSrc, $cap);
        }

        if (preg_match('/^destring\s+([^,]+?)\s*(?:,(.*))?$/s', $line, $m) === 1) {
            return $this->parseDestring($m[1], $m[2] ?? '', $cap);
        }

        if (preg_match('/^ren(?:a|am|ame)?\s+(.*)$/s', $line, $m) === 1) {
            return $this->parseRename($m[1], $cap);
        }

        if (preg_match('/^drop\b/', $line) === 1) {
            return ['kind' => 'drop', 'cap' => $cap];
        }

        throw new SyntaxError(sprintf(
            '無法解析的語句：%s（支援 gen/replace/egen/recode/split/destring/rename/drop/if/forvalues/foreach/local）',
            mb_substr($line, 0, 60),
        ), 0);
    }

    private function parseExpr(string $src, string $context): \Quesfix\StataLogic\Ast\Node
    {
        try {
            return (new Parser($src))->parse();
        } catch (SyntaxError $e) {
            throw new SyntaxError(sprintf('%s「%s」：%s', $context, mb_substr($src, 0, 60), $e->detail), $e->pos);
        }
    }

    private function parseRecode(string $rest, bool $cap): array
    {
        $open = strpos($rest, '(');

        if ($open === false) {
            throw new SyntaxError('recode 規則需以括號表示，例如 recode x (1=0)(2/5=1)', 0);
        }

        $vars = Varlist::expand(substr($rest, 0, $open), $this->available);
        $rules = [];
        $pos = $open;
        $len = strlen($rest);

        while ($pos < $len) {
            while ($pos < $len && ctype_space($rest[$pos])) {
                $pos++;
            }

            if ($pos >= $len || $rest[$pos] !== '(') {
                break;
            }

            $close = strpos($rest, ')', $pos);
            if ($close === false) {
                throw new SyntaxError('recode 規則缺少右括號', 0);
            }

            $rules[] = Recode::parseRule(substr($rest, $pos + 1, $close - $pos - 1));
            $pos = $close + 1;
        }

        [$ifSrc, $optionText] = $this->splitQualifier(substr($rest, $pos), 'recode');
        $gen = [];

        if (trim($optionText) !== '') {
            $options = $this->parseOptions($optionText);
            $genSpec = $options['generate'] ?? $options['gen'] ?? null;

            if ($genSpec !== null) {
                $gen = preg_split('/\s+/', trim($genSpec), -1, PREG_SPLIT_NO_EMPTY);

                if (count($gen) !== count($vars)) {
                    throw new SyntaxError('recode 的 gen() 變數數量須與重新編碼的變數數量相同', 0);
                }
            }
        }

        $this->available = [...$this->available, ...$gen];

        return [
            'kind' => 'recode', 'cap' => $cap, 'vars' => $vars, 'rules' => $rules, 'gen' => $gen,
            'cond' => $ifSrc !== null ? $this->parseExpr($ifSrc, 'if') : null,
        ];
    }

    private function parseSplit(string $var, string $optionText, ?string $ifSrc, bool $cap): array
    {
        $options = $this->parseOptions($optionText);
        $parseSpec = $options['parse'] ?? $options['p'] ?? null;
        $separators = [' '];

        if ($parseSpec !== null) {
            preg_match_all('/"([^"]*)"|(\S+)/', $parseSpec, $m, PREG_SET_ORDER);
            $separators = array_values(array_filter(array_map(fn ($x) => $x[2] ?? $x[1], $m), fn ($s) => $s !== ''));
            if ($separators === []) {
                $separators = [' '];
            }
        }

        $stub = trim($options['generate'] ?? $options['gen'] ?? $var);

        return [
            'kind' => 'split',
            'cap' => $cap,
            'var' => $var,
            'cond' => $ifSrc !== null ? $this->parseExpr($ifSrc, 'if') : null,
            'parse' => $separators,
            'stub' => $stub,
            'limit' => isset($options['limit']) ? (int) $options['limit'] : null,
            'destring' => array_key_exists('destring', $options),
            'notrim' => array_key_exists('notrim', $options),
        ];
    }

    private function parseDestring(string $varSpec, string $optionText, bool $cap): array
    {
        $options = $this->parseOptions($optionText);
        $vars = Varlist::expand($varSpec, $this->available);
        $genSpec = $options['generate'] ?? $options['gen'] ?? null;
        $gen = [];

        if ($genSpec !== null) {
            $gen = preg_split('/\s+/', trim($genSpec), -1, PREG_SPLIT_NO_EMPTY);
            if (count($gen) !== count($vars)) {
                throw new SyntaxError('destring 的 gen() 變數數量須與轉換的變數數量相同', 0);
            }
        } elseif (! array_key_exists('replace', $options)) {
            throw new SyntaxError('destring 須指定 replace 或 gen() 選項', 0);
        }

        $ignore = $options['ignore'] ?? '';
        if (preg_match('/^\s*"(.*)"\s*$/s', $ignore, $m) === 1) {
            $ignore = $m[1];
        }

        $this->available = [...$this->available, ...$gen];

        return [
            'kind' => 'destring',
            'cap' => $cap,
            'vars' => $vars,
            'gen' => $gen,
            'force' => array_key_exists('force', $options),
            'ignore' => $ignore,
        ];
    }

    private function parseRename(string $spec, bool $cap): array
    {
        $spec = trim($spec);

        if (preg_match('/^(\w+)\s+(\w+)$/', $spec, $m) === 1) {
            $pairs = [[$m[1], $m[2]]];
        } elseif (preg_match('/^\(([^)]*)\)\s*\(([^)]*)\)$/', $spec, $m) === 1) {
            $old = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
            $new = preg_split('/\s+/', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY);

            if (count($old) !== count($new) || $old === []) {
                throw new SyntaxError('rename (舊…) (新…) 前後變數數量須相同', 0);
            }

            $pairs = array_map(null, $old, $new);
        } else {
            throw new SyntaxError("無法解析的 rename 語句「rename {$spec}」", 0);
        }

        foreach ($pairs as [, $new]) {
            $this->available[] = $new;
        }

        return ['kind' => 'rename', 'cap' => $cap, 'pairs' => $pairs];
    }

    /**
     * 拆解指令尾端的「[if COND] [, 選項]」。
     *
     * @return array{0: ?string, 1: string} if 條件原始碼、選項文字
     */
    private function splitQualifier(string $tail, string $cmd): array
    {
        $tail = trim($tail);
        $depth = 0;
        $inString = false;
        $comma = null;

        for ($i = 0, $len = strlen($tail); $i < $len; $i++) {
            $c = $tail[$i];
            if ($c === '"') {
                $inString = ! $inString;
            } elseif (! $inString && $c === '(') {
                $depth++;
            } elseif (! $inString && $c === ')') {
                $depth--;
            } elseif (! $inString && $depth === 0 && $c === ',') {
                $comma = $i;
                break;
            }
        }

        $head = trim($comma === null ? $tail : substr($tail, 0, $comma));
        $options = $comma === null ? '' : substr($tail, $comma + 1);

        if ($head === '') {
            return [null, $options];
        }

        if (preg_match('/^if\s+(.+)$/s', $head, $m) === 1) {
            return [trim($m[1]), $options];
        }

        throw new SyntaxError("{$cmd} 之後無法解析的內容「{$head}」", 0);
    }

    /**
     * 解析指令選項：「p("民國" "年") gen(x) force」→ ['p' => '"民國" "年"', 'gen' => 'x', 'force' => null]
     *
     * @return array<string, ?string>
     */
    private function parseOptions(string $text): array
    {
        $options = [];
        $len = strlen($text);
        $pos = 0;

        while ($pos < $len) {
            if (ctype_space($text[$pos])) {
                $pos++;

                continue;
            }

            if (preg_match('/\G([A-Za-z_]\w*)/', $text, $m, 0, $pos) !== 1) {
                throw new SyntaxError(sprintf('無法解析的選項「%s」', trim(substr($text, $pos))), 0);
            }

            $name = $m[1];
            $pos += strlen($name);

            while ($pos < $len && ctype_space($text[$pos])) {
                $pos++;
            }

            if ($pos < $len && $text[$pos] === '(') {
                $depth = 0;
                $inString = false;
                $start = $pos + 1;

                for (; $pos < $len; $pos++) {
                    $c = $text[$pos];
                    if ($c === '"') {
                        $inString = ! $inString;
                    } elseif (! $inString && $c === '(') {
                        $depth++;
                    } elseif (! $inString && $c === ')' && --$depth === 0) {
                        break;
                    }
                }

                if ($pos >= $len) {
                    throw new SyntaxError("選項 {$name}( 缺少右括號", 0);
                }

                $options[$name] = substr($text, $start, $pos - $start);
                $pos++;
            } else {
                $options[$name] = null;
            }
        }

        return $options;
    }

    /**
     * 以頂層（括號、字串外）的 " if " 切分 gen/replace 的賦值式與條件式。
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitOnIf(string $rhs): array
    {
        $depth = 0;
        $inString = false;
        $len = strlen($rhs);

        for ($i = 0; $i < $len; $i++) {
            $c = $rhs[$i];
            if ($c === '"') {
                $inString = ! $inString;
            } elseif ($inString) {
                continue;
            } elseif ($c === '(') {
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

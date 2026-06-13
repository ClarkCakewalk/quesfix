<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

use Quesfix\StataLogic\Exceptions\SyntaxError;

/**
 * 將 Stata 檢核邏輯字串切割為 token 串。
 *
 * 位置（pos）以 byte offset 計算；字串常數內容可包含 UTF-8 中文。
 */
final class Lexer
{
    private int $pos = 0;

    private readonly int $len;

    public function __construct(private readonly string $src)
    {
        $this->len = strlen($src);
    }

    /** @return Token[] */
    public function tokenize(): array
    {
        $tokens = [];

        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];

            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $this->pos++;
                continue;
            }

            // Stata 行註解：// 之後到行尾全部忽略
            if ($c === '/' && $this->pos + 1 < $this->len && $this->src[$this->pos + 1] === '/') {
                while ($this->pos < $this->len && $this->src[$this->pos] !== "\n") {
                    $this->pos++;
                }
                continue;
            }

            $tokens[] = $this->nextToken($c);
        }

        $tokens[] = new Token(TokenType::Eof, '', $this->len);

        return $tokens;
    }

    private function nextToken(string $c): Token
    {
        $start = $this->pos;

        if (ctype_digit($c) || ($c === '.' && $this->pos + 1 < $this->len && ctype_digit($this->src[$this->pos + 1]))) {
            return $this->number($start);
        }

        if ($c === '.') {
            return $this->missing($start);
        }

        if ($c === '"') {
            return $this->string($start);
        }

        if (ctype_alpha($c) || $c === '_') {
            while ($this->pos < $this->len && (ctype_alnum($this->src[$this->pos]) || $this->src[$this->pos] === '_')) {
                $this->pos++;
            }

            return new Token(TokenType::Ident, substr($this->src, $start, $this->pos - $start), $start);
        }

        return $this->operator($c, $start);
    }

    private function number(int $start): Token
    {
        while ($this->pos < $this->len && ctype_digit($this->src[$this->pos])) {
            $this->pos++;
        }

        if ($this->pos < $this->len && $this->src[$this->pos] === '.'
            && $this->pos + 1 < $this->len && ctype_digit($this->src[$this->pos + 1])) {
            $this->pos++;
            while ($this->pos < $this->len && ctype_digit($this->src[$this->pos])) {
                $this->pos++;
            }
        }

        return new Token(TokenType::Number, substr($this->src, $start, $this->pos - $start), $start);
    }

    /**
     * 缺失值：'.' 或 Stata 擴充缺失值 '.a' ~ '.z'。
     */
    private function missing(int $start): Token
    {
        $this->pos++;

        if ($this->pos < $this->len && ctype_lower($this->src[$this->pos])) {
            $after = $this->pos + 1;
            $afterIsIdent = $after < $this->len
                && (ctype_alnum($this->src[$after]) || $this->src[$after] === '_');

            if (! $afterIsIdent) {
                $this->pos++;
            } else {
                throw new SyntaxError(
                    sprintf("無法解析的缺失值記號 '%s'", substr($this->src, $start, $after + 1 - $start)),
                    $start,
                );
            }
        }

        return new Token(TokenType::Missing, substr($this->src, $start, $this->pos - $start), $start);
    }

    private function string(int $start): Token
    {
        $this->pos++;

        while ($this->pos < $this->len && $this->src[$this->pos] !== '"') {
            $this->pos++;
        }

        if ($this->pos >= $this->len) {
            throw new SyntaxError('字串常數缺少結尾的雙引號', $start);
        }

        $this->pos++;

        return new Token(TokenType::String, substr($this->src, $start, $this->pos - $start), $start);
    }

    private function operator(string $c, int $start): Token
    {
        $two = substr($this->src, $this->pos, 2);

        $type = match (true) {
            $two === '==' => TokenType::Eq,
            $two === '!=', $two === '~=' => TokenType::Ne,
            $two === '>=' => TokenType::Ge,
            $two === '<=' => TokenType::Le,
            default => null,
        };

        if ($type !== null) {
            $this->pos += 2;

            return new Token($type, $two, $start);
        }

        $type = match ($c) {
            '(' => TokenType::LParen,
            ')' => TokenType::RParen,
            ',' => TokenType::Comma,
            '+' => TokenType::Plus,
            '-' => TokenType::Minus,
            '*' => TokenType::Star,
            '/' => TokenType::Slash,
            '^' => TokenType::Caret,
            '!', '~' => TokenType::Not,
            '&' => TokenType::And,
            '|' => TokenType::Or,
            '>' => TokenType::Gt,
            '<' => TokenType::Lt,
            '?' => TokenType::Question,
            default => null,
        };

        if ($type === null) {
            if ($c === '=') {
                throw new SyntaxError("意外的 '='，比較是否相等請使用 '=='", $start);
            }

            throw new SyntaxError(sprintf("無法解析的字元 '%s'", $this->utf8CharAt($start)), $start);
        }

        $this->pos++;

        return new Token($type, $c, $start);
    }

    private function utf8CharAt(int $pos): string
    {
        $byte = ord($this->src[$pos]);
        $bytes = match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };

        return substr($this->src, $pos, $bytes);
    }
}

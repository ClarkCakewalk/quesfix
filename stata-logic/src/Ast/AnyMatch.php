<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Ast;

/**
 * anymatch(樣式, 常數)：樣式以 ? 匹配單一字元、* 匹配任意長度，
 * 凡匹配到的任一變數值等於常數即成立。
 */
final class AnyMatch extends Node
{
    public function __construct(
        public readonly string $pattern,
        public readonly float|string $value,
        int $pos,
    ) {
        parent::__construct($pos);
    }
}

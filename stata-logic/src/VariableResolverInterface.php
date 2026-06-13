<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

/**
 * 求值時的變數來源。數值型缺失值以 null 表示。
 */
interface VariableResolverInterface
{
    public function has(string $name): bool;

    public function value(string $name): float|string|null;

    /** @return string[] 全部變數名稱（供 anymatch 萬用字元展開） */
    public function names(): array;
}

<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Ast;

abstract class Node
{
    /** 此節點是否曾被括號明確包裹（供混用 &/| 的警告判斷使用） */
    public bool $parenthesized = false;

    public function __construct(public readonly int $pos)
    {
    }
}

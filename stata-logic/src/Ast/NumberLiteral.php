<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Ast;

final class NumberLiteral extends Node
{
    public function __construct(public readonly float $value, int $pos)
    {
        parent::__construct($pos);
    }
}

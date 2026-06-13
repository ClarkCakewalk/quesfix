<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Ast;

final class StringLiteral extends Node
{
    public function __construct(public readonly string $value, int $pos)
    {
        parent::__construct($pos);
    }
}

<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Ast;

final class Variable extends Node
{
    public function __construct(public readonly string $name, int $pos)
    {
        parent::__construct($pos);
    }
}

<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Ast;

final class AbsCall extends Node
{
    public function __construct(public readonly Node $expr, int $pos)
    {
        parent::__construct($pos);
    }
}

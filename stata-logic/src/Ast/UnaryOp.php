<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Ast;

final class UnaryOp extends Node
{
    /** @param '-'|'!' $op */
    public function __construct(
        public readonly string $op,
        public readonly Node $operand,
        int $pos,
    ) {
        parent::__construct($pos);
    }
}

<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Ast;

final class BinaryOp extends Node
{
    public const ARITHMETIC = ['+', '-', '*', '/', '^'];

    public const COMPARISON = ['==', '!=', '>', '<', '>=', '<='];

    /** @param '+'|'-'|'*'|'/'|'^'|'=='|'!='|'>'|'<'|'>='|'<='|'&'|'|' $op */
    public function __construct(
        public readonly string $op,
        public readonly Node $left,
        public readonly Node $right,
        int $pos,
    ) {
        parent::__construct($pos);
    }
}

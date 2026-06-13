<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Ast;

final class InRange extends Node
{
    public function __construct(
        public readonly Node $expr,
        public readonly float|string $low,
        public readonly float|string $high,
        int $pos,
    ) {
        parent::__construct($pos);
    }
}

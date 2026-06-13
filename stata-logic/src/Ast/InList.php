<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Ast;

final class InList extends Node
{
    /** @param list<float|string> $values */
    public function __construct(
        public readonly Node $expr,
        public readonly array $values,
        int $pos,
    ) {
        parent::__construct($pos);
    }
}

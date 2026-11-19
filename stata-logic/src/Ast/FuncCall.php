<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Ast;

/**
 * 一般函數呼叫（cond、real、strtrim、strmatch、min、max…），
 * 支援清單與型別簽章見 Quesfix\StataLogic\Functions。
 */
final class FuncCall extends Node
{
    /** @param list<Node> $args */
    public function __construct(
        public readonly string $name,
        public readonly array $args,
        int $pos,
    ) {
        parent::__construct($pos);
    }
}

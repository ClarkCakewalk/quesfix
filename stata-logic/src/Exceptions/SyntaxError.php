<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Exceptions;

final class SyntaxError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $pos)
    {
        parent::__construct(sprintf('%s（位置 %d）', $message, $pos));
    }
}

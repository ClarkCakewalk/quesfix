<?php

declare(strict_types=1);

namespace Quesfix\StataLogic\Exceptions;

final class SyntaxError extends \RuntimeException
{
    /** @param string $detail 不含位置的錯誤說明 */
    public function __construct(public readonly string $detail, public readonly int $pos)
    {
        parent::__construct(sprintf('%s（位置 %d）', $detail, $pos));
    }
}

<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

final class ValidationIssue
{
    public function __construct(
        public readonly string $message,
        public readonly ?int $pos = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->pos === null ? $this->message : sprintf('%s（位置 %d）', $this->message, $this->pos);
    }
}

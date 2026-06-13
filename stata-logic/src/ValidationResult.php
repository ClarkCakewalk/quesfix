<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

final class ValidationResult
{
    /**
     * @param ValidationIssue[] $errors 錯誤：應拒絕匯入
     * @param ValidationIssue[] $warnings 警告：可匯入，但需請使用者確認
     */
    public function __construct(
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }
}

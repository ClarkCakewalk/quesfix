<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

/**
 * 資料格式中的變數目錄（名稱 → 型別），供 Validator 與 ArrayResolver 使用。
 * 對應系統的 ques_vars 資料表。
 */
final class VariableCatalog
{
    public const TYPE_NUMERIC = 'numeric';

    public const TYPE_STRING = 'string';

    /** @param array<string, self::TYPE_*> $types */
    public function __construct(private readonly array $types)
    {
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->types);
    }

    /** @return self::TYPE_* */
    public function typeOf(string $name): string
    {
        return $this->types[$name];
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->types);
    }

    /** @return string[] */
    public function matching(string $pattern): array
    {
        return Wildcard::match($pattern, $this->names());
    }
}

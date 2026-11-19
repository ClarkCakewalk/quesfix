<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

/**
 * 資料格式中的變數目錄（名稱 → 型別），供 Validator 與 ArrayResolver 使用。
 * 對應系統的 ques_vars 資料表。
 *
 * 驗證程式格式時，目錄會隨語句逐步衍生（gen/egen/recode/destring/rename 新增變數；
 * split 產生 STUB1、STUB2…，數量依資料而定，故以「stub」登記，凡 STUB+數字 皆視為存在的變數）。
 */
final class VariableCatalog
{
    public const TYPE_NUMERIC = 'numeric';

    public const TYPE_STRING = 'string';

    /**
     * @param  array<string, self::TYPE_*>  $types
     * @param  array<string, self::TYPE_*>  $stubs  split 產生的變數前綴 => 片段型別
     */
    public function __construct(
        private readonly array $types,
        private readonly array $stubs = [],
    ) {
    }

    /**
     * 衍生一份目錄，額外加入指定的數值型變數（程式產生的衍生變數）。
     *
     * @param  string[]  $names
     */
    public function withNumeric(array $names): self
    {
        $types = $this->types;

        foreach ($names as $name) {
            $types[$name] = self::TYPE_NUMERIC;
        }

        return new self($types, $this->stubs);
    }

    /** @param self::TYPE_* $type */
    public function withType(string $name, string $type): self
    {
        $types = $this->types;
        $types[$name] = $type;

        return new self($types, $this->stubs);
    }

    /**
     * 登記 split 的變數前綴：STUB1、STUB2… 皆視為該型別的變數
     *
     * @param  self::TYPE_*  $type  一般為字串；split 加 destring 選項時為數值
     */
    public function withStub(string $stub, string $type = self::TYPE_STRING): self
    {
        return new self($this->types, [...$this->stubs, $stub => $type]);
    }

    /** 合併兩份目錄（條件分支各自衍生的變數取聯集） */
    public function merge(self $other): self
    {
        return new self([...$this->types, ...$other->types], [...$this->stubs, ...$other->stubs]);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->types) || $this->stubType($name) !== null;
    }

    /** @return self::TYPE_* */
    public function typeOf(string $name): string
    {
        return $this->types[$name] ?? $this->stubType($name) ?? self::TYPE_NUMERIC;
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

    /** @return self::TYPE_*|null 屬於 split 片段時回傳片段型別，否則 null */
    private function stubType(string $name): ?string
    {
        foreach ($this->stubs as $stub => $type) {
            if (preg_match('/^' . preg_quote((string) $stub, '/') . '[1-9][0-9]*$/', $name) === 1) {
                return $type;
            }
        }

        return null;
    }
}

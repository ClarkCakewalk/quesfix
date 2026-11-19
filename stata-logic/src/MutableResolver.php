<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

/**
 * 可變動的變數來源：以基底 resolver（樣本原始/修訂值）為底，
 * 疊加程式執行過程中產生的衍生變數（gen / egen / replace / recode / destring / rename / split）。
 */
final class MutableResolver implements VariableResolverInterface
{
    /** @var array<string, float|string|null> 衍生變數（覆蓋層） */
    private array $derived = [];

    /** @var array<string, true> 已被 rename 移除的變數 */
    private array $removed = [];

    /** @var array<string, bool> split 產生的變數前綴 => 片段是否已轉為數值（destring 選項） */
    private array $stubs = [];

    public function __construct(private readonly VariableResolverInterface $base)
    {
    }

    public function has(string $name): bool
    {
        if (array_key_exists($name, $this->derived)) {
            return true;
        }

        if (isset($this->removed[$name])) {
            return false;
        }

        return $this->base->has($name) || $this->stubPiece($name) !== null;
    }

    public function value(string $name): float|string|null
    {
        if (array_key_exists($name, $this->derived)) {
            return $this->derived[$name];
        }

        if (! isset($this->removed[$name]) && $this->base->has($name)) {
            return $this->base->value($name);
        }

        // split 片段：Stata 依全體樣本中最多的片段數建立變數，本樣本片段較少時為空字串（destring 後為缺失）
        return $this->stubPiece($name) === true ? null : '';
    }

    public function set(string $name, float|string|null $value): void
    {
        $this->derived[$name] = $value;
        unset($this->removed[$name]);
    }

    public function remove(string $name): void
    {
        unset($this->derived[$name]);
        $this->removed[$name] = true;
    }

    public function addStub(string $stub, bool $numeric = false): void
    {
        $this->stubs[$stub] = $numeric;
    }

    public function isDerived(string $name): bool
    {
        return array_key_exists($name, $this->derived);
    }

    /** @return string[] 基底＋衍生的全部變數名稱 */
    public function names(): array
    {
        $names = array_unique([...$this->base->names(), ...array_keys($this->derived)]);

        return array_values(array_filter($names, fn ($n) => ! isset($this->removed[$n])));
    }

    /** @return bool|null 屬於 split 片段時回傳是否為數值片段，否則 null */
    private function stubPiece(string $name): ?bool
    {
        foreach ($this->stubs as $stub => $numeric) {
            if (preg_match('/^' . preg_quote((string) $stub, '/') . '[1-9][0-9]*$/', $name) === 1) {
                return $numeric;
            }
        }

        return null;
    }
}

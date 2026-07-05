<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

/**
 * 可變動的變數來源：以基底 resolver（樣本原始/修訂值）為底，
 * 疊加程式執行過程中產生的衍生變數（gen / egen / replace）。
 */
final class MutableResolver implements VariableResolverInterface
{
    /** @var array<string, float|string|null> 衍生變數（覆蓋層） */
    private array $derived = [];

    public function __construct(private readonly VariableResolverInterface $base)
    {
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->derived) || $this->base->has($name);
    }

    public function value(string $name): float|string|null
    {
        if (array_key_exists($name, $this->derived)) {
            return $this->derived[$name];
        }

        return $this->base->value($name);
    }

    public function set(string $name, float|string|null $value): void
    {
        $this->derived[$name] = $value;
    }

    public function isDerived(string $name): bool
    {
        return array_key_exists($name, $this->derived);
    }

    /** @return string[] 基底＋衍生的全部變數名稱 */
    public function names(): array
    {
        return array_values(array_unique([...$this->base->names(), ...array_keys($this->derived)]));
    }
}

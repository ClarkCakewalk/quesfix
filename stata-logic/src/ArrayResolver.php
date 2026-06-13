<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

use Quesfix\StataLogic\Exceptions\EvalError;

final class ArrayResolver implements VariableResolverInterface
{
    /** @var array<string, float|string|null> */
    private array $values = [];

    /** @param array<string, float|int|string|null> $values 已轉型的值；數值缺失以 null 表示 */
    public function __construct(array $values)
    {
        foreach ($values as $name => $value) {
            $this->values[$name] = is_int($value) ? (float) $value : $value;
        }
    }

    /**
     * 從資料庫取出的字串值建立 resolver。
     * 數值型變數：空字串、'.' 視為缺失值（null），其餘須為合法數字。
     * 字串型變數：原樣保留。
     *
     * @param array<string, string|null> $row
     */
    public static function fromStrings(array $row, VariableCatalog $catalog): self
    {
        $values = [];

        foreach ($row as $name => $raw) {
            if (! $catalog->has($name)) {
                throw new EvalError(sprintf("變數 '%s' 未定義於資料格式中", $name));
            }

            if ($catalog->typeOf($name) === VariableCatalog::TYPE_STRING) {
                $values[$name] = $raw ?? '';
                continue;
            }

            $raw = trim((string) $raw);

            if ($raw === '' || $raw === '.') {
                $values[$name] = null;
                continue;
            }

            if (! is_numeric($raw)) {
                throw new EvalError(sprintf("變數 '%s' 的值 '%s' 不是有效數值", $name, $raw));
            }

            $values[$name] = (float) $raw;
        }

        return new self($values);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function value(string $name): float|string|null
    {
        return $this->values[$name];
    }

    public function names(): array
    {
        return array_keys($this->values);
    }
}

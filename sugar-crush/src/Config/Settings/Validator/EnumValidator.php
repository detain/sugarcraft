<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Validator;

use SugarCraft\Crush\Config\Settings\SettingValidator;

/** One of a fixed vocabulary, compared exactly (case and all). */
final class EnumValidator implements SettingValidator
{
    /** @param list<string> $values */
    private function __construct(public readonly array $values)
    {
    }

    /** @param list<string> $values */
    public static function new(array $values): self
    {
        if ($values === []) {
            throw new \InvalidArgumentException('an enum validator needs at least one value');
        }

        return new self(array_values($values));
    }

    public function validate(mixed $value, array $all = []): ?string
    {
        if ($value === null || (\is_string($value) && \in_array($value, $this->values, true))) {
            return null;
        }

        return 'must be one of: ' . implode(', ', $this->values);
    }
}

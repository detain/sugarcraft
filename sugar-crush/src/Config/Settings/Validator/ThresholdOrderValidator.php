<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Validator;

use SugarCraft\Crush\Config\Settings\SettingValidator;

/**
 * A cross-field check: the named keys, where set, must be strictly ascending.
 *
 * Built for thresholds that only mean something in order — the compaction
 * design's `reminder < auto < block` — where each value is in range on its own
 * and the pair is still nonsense. Keys absent from `$all` (or null) are
 * skipped, so a partial save is judged against what it actually sets.
 */
final class ThresholdOrderValidator implements SettingValidator
{
    /** @param list<string> $keys lowest first */
    private function __construct(public readonly array $keys)
    {
    }

    /** @param list<string> $keys lowest first */
    public static function new(array $keys): self
    {
        if (\count($keys) < 2) {
            throw new \InvalidArgumentException('an order check needs at least two keys');
        }

        return new self(array_values($keys));
    }

    public function validate(mixed $value, array $all = []): ?string
    {
        $previousKey = null;
        $previous = null;
        foreach ($this->keys as $key) {
            $current = $all[$key] ?? null;
            if (!\is_int($current) && !\is_float($current)) {
                continue;
            }

            if ($previous !== null && $current <= $previous) {
                return "{$key} must be greater than {$previousKey}";
            }

            $previousKey = $key;
            $previous = $current;
        }

        return null;
    }
}

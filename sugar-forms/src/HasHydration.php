<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

/**
 * Stateless snapshot-parsing helpers for {@see Hydratable} fields
 * (SF-23, sugar-forms-025).
 *
 * Every method here takes the untrusted persisted datum and either
 * returns it in the shape the field's own wither accepts or throws the
 * `form.hydrate_type` refusal the closed `hydrateSetter` match threw
 * before the hook existed — byte-identical message, `key` read from the
 * field itself (locateField has already proven map key == field key by
 * the time a setter runs). The bools-are-refused rule of
 * stringable coercion survives verbatim: silently turning `true` into
 * `'1'` in a name field is how persistence bugs are born.
 */
trait HasHydration
{
    /**
     * Stringable coercion: strings, numbers, stringable objects and
     * BackedEnum cases (a Select in enum mode round-trips its own
     * value()). Bools are refused.
     */
    protected function snapshotString(mixed $raw): string
    {
        if (is_string($raw)) {
            return $raw;
        }
        if ($raw instanceof \BackedEnum) {
            return (string) $raw->value;
        }
        if (is_int($raw) || is_float($raw)) {
            return (string) $raw;
        }
        if (is_object($raw) && method_exists($raw, '__toString')) {
            return (string) $raw;
        }
        throw $this->snapshotMismatch('string', $raw);
    }

    /**
     * A bool stays a bool — Confirm answers `yes`/`no` to exactly `true`/`false`.
     */
    protected function snapshotBool(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        throw $this->snapshotMismatch('bool', $raw);
    }

    /**
     * int|float stays int|float — Slider's range arithmetic has no
     * string story to decode.
     */
    protected function snapshotNumber(mixed $raw): int|float
    {
        if (is_int($raw) || is_float($raw)) {
            return $raw;
        }
        throw $this->snapshotMismatch('int|float', $raw);
    }

    /**
     * null passes through (the genuinely-empty exports: Date with no
     * date, FilePicker with no selection, RadioPills with nothing under
     * the cursor); anything else takes the stringable path.
     */
    protected function snapshotNullableString(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw)) {
            return $raw;
        }
        throw $this->snapshotMismatch('string|null', $raw);
    }

    /**
     * A list of stringables (MultiSelect's picked-option keys).
     *
     * @return list<string>
     */
    protected function snapshotStringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw $this->snapshotMismatch('list<string>', $raw);
        }
        return array_map(fn ($o): string => $this->snapshotString($o), array_values($raw));
    }

    /**
     * Build (do not throw) the shared shape refusal, so call sites read
     * `throw $this->snapshotMismatch(...)` and the message stays the one
     * byte-identical catalogue string.
     */
    protected function snapshotMismatch(string $expected, mixed $raw): \InvalidArgumentException
    {
        return new \InvalidArgumentException(Lang::t('form.hydrate_type', [
            'key'      => $this->key(),
            'expected' => $expected,
            'actual'   => get_debug_type($raw),
        ]));
    }
}

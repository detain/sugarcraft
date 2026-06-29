<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

use SugarCraft\Core\Msg;

/**
 * One field in a {@see Form}.
 *
 * Implementations are expected to be immutable: every state-changing
 * method returns a new instance. The {@see Form} forwards each {@see Msg}
 * to the focused field via {@see update()}; whatever Cmd that returns is
 * scheduled on the loop.
 *
 * Fields that should be skipped during navigation (notes, separators)
 * should report {@see skippable()} as `true`.
 */
interface Field
{
    /** Stable identifier used by the form to key {@see Form::values()}. */
    public function key(): string;

    /** Current value (string / bool / array / etc., field-specific). */
    public function value(): mixed;

    /**
     * Take focus. Returns the new (focused) field plus an optional
     * Cmd the runtime should schedule (cursor-blink, autocomplete-fetch,
     * etc.).
     *
     * @return array{0:Field, 1:?\Closure}
     */
    public function focus(): array;

    /** Release focus. Returns a new (unfocused) field. */
    public function blur(): Field;

    /**
     * Apply a Bubble-Tea message and return `[$next, $cmd]`. Mirrors the
     * upstream `Update(msg)` shape.
     *
     * @return array{0:Field, 1:?\Closure}
     */
    public function update(Msg $msg): array;

    /** Render the field as a multi-line ANSI string. */
    public function view(): string;

    /** True while this field currently holds keyboard focus. */
    public function isFocused(): bool;

    /** Static title (the `*Func` form is resolved separately at view time). */
    public function getTitle(): string;

    /** Static description, same shape as {@see getTitle()}. */
    public function getDescription(): string;

    /** Latest validator error, or null when the value passes validation. */
    public function getError(): ?string;

    /** Notes / separators / hidden fields skip Tab navigation. */
    public function skippable(): bool;

    /**
     * True if the field is in a state where the given Msg has internal
     * meaning that should override Form-level handling. The Form checks
     * this for keys it would otherwise capture (Enter / Escape) so that
     * inner widgets can consume them — e.g. an `ItemList` in filter mode
     * uses Enter to leave filter mode and Escape to clear it.
     */
    public function consumes(Msg $msg): bool;

    /**
     * Runtime visibility predicate. The form skips fields whose
     * `isHidden(values)` returns true; both navigation and the values
     * collector treat them as if they didn't exist.
     *
     * Default implementations return false; concrete fields opt in via
     * `withHideFunc(\Closure(array $values): bool)`.
     *
     * @param array<string,mixed> $values  values collected so far,
     *                                     keyed by field key
     */
    public function isHidden(array $values): bool;

    /**
     * Re-run validators/constraints against the current value and return
     * a new field instance with the recomputed error. Idempotent: if no
     * error exists and the value is unchanged, returns $this. Does not
     * alter focus.
     *
     * This is called by Form::validateAll() and by the submit gate so
     * that untouched-but-required fields surface errors at commit time.
     */
    public function revalidate(): Field;
}
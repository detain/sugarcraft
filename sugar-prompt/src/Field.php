<?php

declare(strict_types=1);

namespace CandyCore\Prompt;

use CandyCore\Core\Msg;

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
    public function key(): string;

    public function value(): mixed;

    /** @return array{0:Field, 1:?\Closure} */
    public function focus(): array;

    public function blur(): Field;

    /** @return array{0:Field, 1:?\Closure} */
    public function update(Msg $msg): array;

    public function view(): string;

    public function isFocused(): bool;

    public function getTitle(): string;

    public function getDescription(): string;

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
}

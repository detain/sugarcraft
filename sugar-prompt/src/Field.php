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
}

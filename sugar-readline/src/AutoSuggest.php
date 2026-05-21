<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * Fish-style autosuggestion for the input buffer.
 *
 * Suggests a completion based on history entries that start with the current
 * buffer text. The suggestion is rendered in dim gray style in {@see TextPrompt::view()}.
 *
 * @see https://github.com/sugarcraft/sugar-readline
 */
final readonly class AutoSuggest
{
    public function __construct(
        /** The suggested text to append after the current buffer. */
        private string $suggestion = '',
        /** Whether this suggestion was derived from history. */
        private bool $isFromHistory = false,
    ) {}

    /**
     * No suggestion available.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Create a suggestion from a history entry.
     *
     * @param string $text The remainder of the history entry after the buffer prefix.
     */
    public static function fromHistory(string $text): self
    {
        return new self($text, true);
    }

    /**
     * The suggested text to append after the current buffer.
     */
    public function suggestion(): string
    {
        return $this->suggestion;
    }

    /**
     * True if this suggestion was derived from a history entry.
     */
    public function isFromHistory(): bool
    {
        return $this->isFromHistory;
    }
}

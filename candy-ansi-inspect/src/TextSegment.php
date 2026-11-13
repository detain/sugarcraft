<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect;

/**
 * Visible text between escape sequences. {@see describe()} returns the
 * payload verbatim so it shows up unmodified in inspector reports.
 *
 * Mirrors the plain (unannotated) entries charmbracelet/sequin prints
 * between escape sequences.
 */
final class TextSegment extends Segment
{
    /**
     * @param ?int $offset absolute byte offset of this text's first input byte
     *                     in the parsed stream (null = hand-constructed,
     *                     untracked)
     */
    public function __construct(
        public readonly string $value,
        public readonly ?int $offset = null,
    ) {}

    public function raw(): string      { return $this->value; }
    public function describe(): string { return $this->value; }

    public function byteOffset(): ?int { return $this->offset; }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

/**
 * One styled run produced by {@see AnsiParser::parse()}. Holds the
 * literal text plus the foreground colour, background colour, and
 * attribute flags that were active when those bytes were emitted.
 */
final class Segment
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $fg,
        public readonly bool $bold,
        public readonly bool $italic,
        public readonly bool $underline,
        public readonly ?string $bg = null,
    ) {}

    public function withBg(?string $bg): self
    {
        return new self(
            text:      $this->text,
            fg:        $this->fg,
            bold:      $this->bold,
            italic:    $this->italic,
            underline: $this->underline,
            bg:        $bg,
        );
    }
}

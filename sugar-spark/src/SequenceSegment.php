<?php

declare(strict_types=1);

namespace CandyCore\Spark;

/**
 * One ANSI escape sequence — its raw bytes paired with a decoded label
 * (e.g. `"SGR foreground red"`). The label is what makes SugarSpark
 * useful as a debugging tool: you can pipe styled output in and see
 * exactly which sequence produced what effect.
 */
final class SequenceSegment extends Segment
{
    /** Display form of an ESC byte: `ESC` instead of `\x1b`. */
    public function __construct(
        public readonly string $bytes,
        public readonly string $label,
    ) {}

    public function raw(): string { return $this->bytes; }

    public function describe(): string
    {
        $printable = self::printable($this->bytes);
        return $printable . '  ' . $this->label;
    }

    /** Render bytes with `\x1b` swapped for the display token `ESC`. */
    public static function printable(string $bytes): string
    {
        return str_replace("\x1b", 'ESC', $bytes);
    }
}

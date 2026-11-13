<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Codec;

/**
 * Key-name to wire-byte encoder: the twin of the parser's decode side.
 *
 * Values are the REAL bytes a keyboard produces (arrows are `ESC [ C`, never
 * a glyph) because a driven program only believes what a terminal sends;
 * literal glyph bytes ride the paste/IME path and exercise a different parser.
 * Names are exact and case-sensitive: `Enter`, `Esc`, `Up`, `PageDown`, `C-a`..`C-z`.
 */
final class KeySequence
{
    public const TABLE = [
        'Enter' => "\r",
        'Esc' => "\x1b",
        'Tab' => "\t",
        'Backspace' => "\x7f",
        'Up' => "\x1b[A",
        'Down' => "\x1b[B",
        'Right' => "\x1b[C",
        'Left' => "\x1b[D",
        'Home' => "\x1b[H",
        'End' => "\x1b[F",
        'PageUp' => "\x1b[5~",
        'PageDown' => "\x1b[6~",
        'Delete' => "\x1b[3~",
        'Insert' => "\x1b[2~",
        'C-a' => "\x01",
        'C-b' => "\x02",
        'C-c' => "\x03",
        'C-d' => "\x04",
        'C-e' => "\x05",
        'C-f' => "\x06",
        'C-g' => "\x07",
        'C-h' => "\x08",
        'C-i' => "\x09",
        'C-j' => "\x0a",
        'C-k' => "\x0b",
        'C-l' => "\x0c",
        'C-m' => "\x0d",
        'C-n' => "\x0e",
        'C-o' => "\x0f",
        'C-p' => "\x10",
        'C-q' => "\x11",
        'C-r' => "\x12",
        'C-s' => "\x13",
        'C-t' => "\x14",
        'C-u' => "\x15",
        'C-v' => "\x16",
        'C-w' => "\x17",
        'C-x' => "\x18",
        'C-y' => "\x19",
        'C-z' => "\x1a",
    ];

    /** The wire bytes for a named key, or null when the name is unknown. */
    public static function encode(string $name): ?string
    {
        return self::TABLE[$name] ?? null;
    }

    /** @return list<string> every encodable key name, in table order */
    public static function names(): array
    {
        return \array_keys(self::TABLE);
    }
}

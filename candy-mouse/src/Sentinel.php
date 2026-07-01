<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * Shared sentinel constants for zone markup.
 *
 * Both Mark (encoder) and Scan (decoder) must agree on these values.
 * U+E000 / U+E001 are in the Unicode Private Use Area and guaranteed
 * not to appear in ANSI escape sequences (CSI starts ESC [, OSC starts
 * ESC]) or regular text.
 *
 * UTF-8 byte encoding:
 *   U+E000 = EE 80 80  (open sentinel)
 *   U+E001 = EE 80 81  (close sentinel)
 *
 * Mirrors bubblezone's sentinel values.
 */
final class Sentinel
{
    /** Open sentinel — marks the start of a zone. UTF-8: \xEE\x80\x80 */
    public const OPEN = "\xEE\x80\x80";

    /** Close sentinel — marks the end of a zone. UTF-8: \xEE\x80\x81 */
    public const CLOSE = "\xEE\x80\x81";
}

<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

use SugarCraft\Core\Util\Sanitize;

/**
 * Shared sentinel constants for zone markup.
 *
 * Both Mark (encoder) and Scan (decoder) must agree on these values.
 * U+E000 / U+E001 are in the Unicode Private Use Area and guaranteed
 * not to appear in ANSI escape sequences (CSI starts ESC [, OSC starts
 * ESC]) or regular text.
 *
 * The values are anchored to {@see Sanitize::ZONE_SENTINEL_OPEN} /
 * {@see Sanitize::ZONE_SENTINEL_CLOSE} — candy-core owns the codepoint
 * reservation, so a sanitizer sweep and the markup it defends against can
 * never drift apart by a re-typed byte literal.
 *
 * Trust boundary: these codepoints are public constants, not secrets — the
 * sentinel DELIMITS our markup but authenticates nothing, so neutralising
 * foreign-sentinel bytes inside untrusted content is the CONSUMER's duty
 * (sugar-crush sweeps pasted/foreign frames through
 * {@see Sanitize::stripZoneSentinels()} / {@see Sanitize::untrustedForMarkedFrames()}
 * before they can forge a zone boundary; any other host of marked frames
 * owns the same responsibility).
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
    public const OPEN = Sanitize::ZONE_SENTINEL_OPEN;

    /** Close sentinel — marks the end of a zone. UTF-8: \xEE\x80\x81 */
    public const CLOSE = Sanitize::ZONE_SENTINEL_CLOSE;
}

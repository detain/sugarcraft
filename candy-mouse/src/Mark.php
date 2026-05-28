<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

use SugarCraft\Core\Util\Width;

/**
 * Wrap $content with invisible zone markers so {@see Scanner} can later
 * extract bounding boxes without any external Manager wiring.
 *
 * Marker format — three consecutive private-use codepoints that are
 * invisible in the terminal and safe alongside ANSI SGR sequences:
 *
 *   U+E000 <id> U+E001 <content> U+E000 / <id> U+E001
 *
 * The sentinel triple U+E000 + id + U+E001 is the zone opening;
 * U+E000 / + id + U+E001 is the zone close.
 *
 * Mirrors bubblezone's Mark function.
 */
final class Mark
{
    /** Sentinel: opens a zone. */
    private const SENTINEL_OPEN = "\u{E000}";

    /** Sentinel: closes a zone. */
    private const SENTINEL_CLOSE = "\u{E001}";

    /**
     * Wrap $content with start / end sentinels for $id.
     *
     * The sentinels use private-use codepoints (U+E000 / U+E001) so they
     * never collide with visible text or ANSI escape sequences.
     */
    public function wrap(string $id, string $content): string
    {
        return self::SENTINEL_OPEN
            . $id
            . self::SENTINEL_CLOSE
            . $content
            . self::SENTINEL_OPEN
            . '/'
            . $id
            . self::SENTINEL_CLOSE;
    }

    /**
     * Static convenience alias — creates a temporary instance and calls wrap().
     */
    public static function zone(string $id, string $content): string
    {
        return (new self)->wrap($id, $content);
    }
}

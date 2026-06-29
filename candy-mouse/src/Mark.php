<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

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
 * Marking can be globally disabled (e.g. for non-interactive output such
 * as width-only measurement passes).  When {@see $enabled} is false,
 * {@see wrap()} returns $content byte-for-byte with no sentinels.
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
     * @param bool $enabled Whether sentinels are emitted.  Defaults to true.
     *                     Set to false to suppress all sentinel output.
     */
    public function __construct(
        private readonly bool $enabled = true,
    ) {}

    /**
     * Factory — creates a Mark instance with marking disabled.
     */
    public static function disabled(): self
    {
        return new self(false);
    }

    /**
     * Return a new Mark with the given enabled state.
     */
    public function withEnabled(bool $enabled): self
    {
        if ($this->enabled === $enabled) {
            return $this;
        }
        return new self($enabled);
    }

    /**
     * Wrap $content with start / end sentinels for $id.
     *
     * When this instance was constructed with {@see $enabled} = false,
     * returns $content unchanged (no sentinels emitted).
     *
     * The sentinels use private-use codepoints (U+E000 / U+E001) so they
     * never collide with visible text or ANSI escape sequences.
     */
    public function wrap(string $id, string $content): string
    {
        if (!$this->enabled) {
            return $content;
        }

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
     * Uses an enabled instance so sentinel output is always produced.
     */
    public static function zone(string $id, string $content): string
    {
        return (new self(true))->wrap($id, $content);
    }
}

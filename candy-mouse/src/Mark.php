<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

use SugarCraft\Mouse\Sentinel;

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
    /**
     * Allowed id charset — ASCII letters, digits, and the punctuation used by
     * real zone ids (`cell:5:3`, `crumb-2`, `a.b_c`).  Anchored with \A…\z
     * (NOT $, which would also match before a trailing newline) and matched
     * byte-wise (no /u) so any byte >= 0x80 is rejected.  This inherently bars
     * the PUA sentinel bytes U+E000 (\xEE\x80\x80) / U+E001 (\xEE\x80\x81) plus
     * any CSI/OSC/APC/control byte — an id carrying those would desync
     * {@see Scan::parse()} (a zone-marker injection).
     */
    private const ID_PATTERN = '/\A[A-Za-z0-9._:-]+\z/';

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
     *
     * @throws \InvalidArgumentException When $id contains any byte outside
     *         {@see self::ID_PATTERN} — e.g. a raw sentinel byte, a control
     *         byte, or whitespace — which would otherwise let a caller-supplied
     *         id inject spurious zone markers into the scanned stream.
     */
    public function wrap(string $id, string $content): string
    {
        // Validate unconditionally (even when disabled): an out-of-charset id
        // is a caller bug regardless of whether sentinels are emitted, and the
        // same id feeds both the marked render and any measurement pass.
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException(
                'Mark id must match ' . self::ID_PATTERN
                . ' (ASCII letters, digits, and ._:- only); '
                . 'got ' . var_export($id, true)
                . ' — an id with sentinel/control/whitespace bytes would desync zone scanning.'
            );
        }

        if (!$this->enabled) {
            return $content;
        }

        return Sentinel::OPEN
            . $id
            . Sentinel::CLOSE
            . $content
            . Sentinel::OPEN
            . '/'
            . $id
            . Sentinel::CLOSE;
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

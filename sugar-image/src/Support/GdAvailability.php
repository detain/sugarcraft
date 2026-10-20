<?php

declare(strict_types=1);

namespace SugarCraft\Image\Support;

/**
 * Single decision point for "is the GD extension usable right now".
 *
 * F-13: the GD-free encode paths (Codec\RawRgba, Codec\TiffUncompressed
 * and the renderers' refusal branches) must be testable on boxes that DO
 * have gd — the probe is a code-path flag seam, mirroring
 * {@see \SugarCraft\Image\ImageSource::overrideHostResolver()}, not a
 * `php -d disable_functions` trick. Tests override in setUp and MUST
 * reset in tearDown; leaked overrides would hide real GD elsewhere in
 * the suite.
 *
 * ext-gd REMAINS composer-required: image ingress
 * (ImageSource::fromFile/fromString) is still GD-gated, so a genuinely
 * GD-less runtime cannot produce a loaded ImageSource at all — the
 * fallbacks here exist for caller-supplied raw buffers and for the
 * future ingress decoupling, not to advertise GD-optional operation
 * today.
 */
final class GdAvailability
{
    private static ?bool $override = null;

    private function __construct() {}

    /**
     * Force the answer (null = report the real extension state).
     *
     * @internal test seam — production callers never override
     */
    public static function override(?bool $available): void
    {
        self::$override = $available;
    }

    /** @internal */
    public static function reset(): void
    {
        self::$override = null;
    }

    public static function available(): bool
    {
        return self::$override ?? extension_loaded('gd');
    }
}

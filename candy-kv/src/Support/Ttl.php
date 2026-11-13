<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Support;

/**
 * The single documented TTL bound for every write channel.
 *
 * WHY THIS EXISTS (candy-kv-001). `--ttl` always had a ceiling — the
 * 9-digit CLI grammar caps it — but the ceiling lived as a private const
 * in ArgParser, so the library doors that bypass the parser (Store::set,
 * Database::set, both importers, the constructor option maps) accepted any
 * int. A `time() + PHP_INT_MAX` bound overflows to a negative epoch: the
 * row commits permanently invisible while the upsert has already destroyed
 * the previous live value, and a huge-but-representable TTL mints the
 * forever-zombie row the CLI cap exists to prevent. The bound must be ONE
 * fact referenced by all channels, not five drift-prone copies.
 *
 * The value is 999999999 seconds (≈31.7 years) — exactly the largest
 * integer the documented `--ttl` grammar accepts, so CLI and library now
 * refuse the same horizon.
 */
final class Ttl
{
    public const MAX_SECONDS = 999_999_999;

    /** True when $seconds is a storable positive TTL inside the horizon. */
    public static function inRange(int $seconds): bool
    {
        return $seconds >= 1 && $seconds <= self::MAX_SECONDS;
    }

    private function __construct()
    {
    }
}

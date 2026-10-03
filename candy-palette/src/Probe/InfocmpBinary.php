<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Probe;

/**
 * Single source of truth for locating the `infocmp` terminfo binary.
 *
 * Probe, AsyncProbe and TerminalProbe each carried their own copy of the
 * same two-path sniff; they now all delegate here so the answer to
 * "where is infocmp?" cannot drift between call sites.
 *
 * The result is memoised per process — the filesystem layout a probe sees
 * at first call is the layout every later call reports. {@see reset()}
 * clears the memo (test isolation only).
 */
final class InfocmpBinary
{
    /** Candidate absolute paths, checked in order. */
    private const CANDIDATES = ['/usr/bin/infocmp', '/bin/infocmp'];

    private static ?string $path = null;

    /**
     * Absolute path to infocmp, or null when the binary is not present.
     */
    public static function path(): ?string
    {
        if (self::$path !== null) {
            return self::$path === '' ? null : self::$path;
        }

        foreach (self::CANDIDATES as $candidate) {
            if (\is_file($candidate)) {
                self::$path = $candidate;

                return $candidate;
            }
        }

        self::$path = '';

        return null;
    }

    /**
     * Clear the memoised answer (for testing only).
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$path = null;
    }
}

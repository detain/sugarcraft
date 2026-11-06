<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Probe;

/**
 * Single source of truth for locating the `infocmp` terminfo binary.
 *
 * Probe, AsyncProbe and TerminalProbe each carried their own copy of the
 * same two-path sniff; they now all delegate here so the answer to
 * "where is infocmp?" cannot drift between call sites. The admission
 * rule itself — `is_file() && is_executable()` — is shared by design
 * with sugar-image's `Renderer\ChafaBinary` roster gate: both locate
 * the same class of candidate, a fixed roster of
 * absolute paths, so the same two-part gate decides them and neither
 * may loosen toward `is_file()` alone without the other following in
 * the same change.
 *
 * The result is memoized per process — the filesystem layout a probe sees
 * at first call is the layout every later call reports. {@see reset()}
 * clears the memo (test isolation only).
 *
 * The class also owns the name-shape gate every exec site consults before
 * handing a TERM to the binary ({@see isSafeTermName()}): the question
 * "may this TERM reach infocmp at all?" has exactly one answer, so it lives
 * beside "where is infocmp?" rather than tripling across Probe, AsyncProbe
 * and TerminalProbe.
 */
final class InfocmpBinary
{
    /** Candidate absolute paths, checked in order. */
    private const CANDIDATES = ['/usr/bin/infocmp', '/bin/infocmp'];

    /**
     * Name shape a TERM must have to reach infocmp: alnum first byte, then
     * the characters real terminfo entries actually use (letters, digits,
     * `.`, `_`, `+`, `-`).
     */
    private const TERM_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._+-]*$/';

    private static ?string $path = null;

    /**
     * Absolute path to infocmp, or null when no roster candidate is a
     * present AND executable file. The executable leg is what keeps this
     * gate from drifting from sugar-image's ChafaBinary roster gate —
     * see the class docblock's shared-rule note.
     */
    public static function path(): ?string
    {
        if (self::$path !== null) {
            return self::$path === '' ? null : self::$path;
        }

        foreach (self::CANDIDATES as $candidate) {
            if (\is_file($candidate) && \is_executable($candidate)) {
                self::$path = $candidate;

                return $candidate;
            }
        }

        self::$path = '';

        return null;
    }

    /**
     * Is $term shaped like a terminal name rather than an infocmp option?
     *
     * `escapeshellarg()` quotes a leading-dash value but cannot make it a
     * non-option to the program itself: `TERM=-D` measured as infocmp
     * exiting 0 and printing its terminfo search path into the probe's
     * stdout (verified for -e/-x/-0/-C/-F/-D). Gate every exec site on this
     * predicate so a value beginning with `-` never reaches the binary;
     * callers keep the env-anchored chain answer when it refuses.
     */
    public static function isSafeTermName(string $term): bool
    {
        return \preg_match(self::TERM_NAME_PATTERN, $term) === 1;
    }

    /**
     * Clear the memoized answer (for testing only).
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$path = null;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Internal;

use SugarCraft\Cli\AutoWidth;

/**
 * Resolves the width a presenter defaults to when the caller stays silent.
 *
 * Precedence, highest first:
 *
 * 1. an explicit `int` the caller passed — the caller knows best, and this
 *    probe never overrides it;
 * 2. `null` the caller passed — the presenter's own "unbounded" opt-out,
 *    also never overridden;
 * 3. the terminal width, as far as it is resolvable: the exported `COLUMNS`
 *    variable (`getenv()`, then `$_ENV`, then `$_SERVER` for SAPIs where
 *    `getenv()` is disabled); values outside [1, {@see MAX_CREDIBLE}] are
 *    treated as junk — a caller who really knows the width passes it
 *    explicitly (tier 1), and an absurd advertisement otherwise turns a
 *    filler `str_repeat()` into an out-of-memory crash;
 * 4. {@see self::FALLBACK} — the historical 80 cells, kept as the last
 *    resort so behaviour under a discoverable-but-quiet environment stays
 *    byte-for-byte what it was before width resolution existed.
 *
 * WHEN THIS BITES: `COLUMNS` must be *exported* to be seen. bash maintains a
 * private COLUMNS variable but does not export it, and neither does it
 * re-export after a window resize, so under plain bash an omitted width
 * usually lands on the 80 fallback rather than the live terminal. zsh and
 * fish export it; so does `export COLUMNS` in a bash profile. Programs that
 * hold a real measurement (a pty size, an ioctl they already ran) should
 * pass it explicitly — tier 1 exists for exactly that.
 *
 * WHY ENV-ONLY, NOT A `Tty::size()`-style ioctl probe: sugar-core's full TTY
 * size query shells out to `stty` and opens `/dev/tty` as fallbacks. On a
 * shared render path that turns every omitted `width` into a subprocess or a
 * device open, and — worse for output authors — makes rendered bytes depend
 * on whether the process happens to own a controlling terminal. A presenter
 * library must answer "how wide is my output target?" cheaply and
 * deterministically; an exported `COLUMNS` is the portable, non-blocking
 * answer, and callers that hold a better measurement (a renderer, a frame
 * layout, an ioctl they already ran) are exactly what the explicit argument
 * in tier 1 is for.
 *
 * @internal Not part of the public API; may change without notice.
 */
final class WidthProbe
{
    /** Width used when nothing better is known — the historical default. */
    public const int FALLBACK = 80;

    /**
     * Upper sanity bound on an advertised width. Real terminals (and tmux,
     * and the widest multiplexers) stay far under this; a value above it is
     * treated as junk so a hostile or typo'd `COLUMNS` cannot drive a
     * filler `str_repeat()` (see {@see \SugarCraft\Cli\Section::rule()}) past
     * the memory limit. Callers with a genuinely huge canvas pass an
     * explicit integer (tier 1), which is never capped here.
     */
    public const int MAX_CREDIBLE = 10_000;

    private const string ENV_KEY = 'COLUMNS';

    /**
     * Resolve a presenter's `$width` argument to the value its layout logic
     * should use: `AutoWidth::Auto` becomes the probed terminal width (or the
     * fallback), everything else passes through untouched.
     */
    public static function resolve(int|AutoWidth|null $width): int|null
    {
        return $width instanceof AutoWidth ? (self::columns() ?? self::FALLBACK) : $width;
    }

    /**
     * The terminal width as advertised by the environment, or null when it is
     * absent, blank, non-numeric, below one cell, or above
     * {@see self::MAX_CREDIBLE}. Never throws and never touches a device or
     * subprocess — a missing answer is ordinary here.
     */
    public static function columns(): ?int
    {
        $raw = getenv(self::ENV_KEY);
        if ($raw === false || $raw === '') {
            $raw = $_ENV[self::ENV_KEY] ?? $_SERVER[self::ENV_KEY] ?? false;
        }
        if ($raw === false) {
            return null;
        }
        $columns = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAX_CREDIBLE],
        ]);
        return $columns === false ? null : $columns;
    }
}

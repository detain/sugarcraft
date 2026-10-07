<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Internal;

use SugarCraft\Kit\AutoWidth;

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
 *    `getenv()` is disabled);
 * 4. {@see self::FALLBACK} — the historical 80 cells, kept as the last
 *    resort so behaviour under a discoverable-but-quiet environment stays
 *    byte-for-byte what it was before width resolution existed.
 *
 * WHY ENV-ONLY, NOT A `Tty::size()`-style ioctl probe: candy-core's full TTY
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
     * absent, blank, non-numeric, or below one cell. Never throws and never
     * touches a device or subprocess — a missing answer is ordinary here.
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
        $columns = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $columns === false ? null : $columns;
    }
}

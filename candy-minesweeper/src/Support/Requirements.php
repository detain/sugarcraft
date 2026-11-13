<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Support;

/**
 * Boot requirements as data (msw-23, finding candy-minesweeper-018).
 * The app rides sugar-core's cell-width and i18n math, whose own manifest
 * declares ext-intl + ext-mbstring (sugar-core/composer.json:34-35). The
 * composer path enforces them transitively, but the advertised phar channel
 * installs nothing through composer — without a preflight the first frame
 * that measures a glyph would semi-die on an undefined-function Error
 * laundered out of frozen vendor code.
 *
 * The check lives here (injectable, catch-free) so the binary can name the
 * missing extension before any vendor callable runs, and the suite can
 * drive the arm honestly — `php -n` boots the real binary without php.ini,
 * which on shared-module builds strips mbstring/intl entirely (see
 * RequirementsTest) — without uninstalling anything.
 */
final class Requirements
{
    /**
     * Blame order: mbstring first — it backs the cell-width math on every
     * frame, while intl only surfaces in locale-sensitive paths. A host
     * missing both hears about the one that matters most.
     *
     * @var list<string>
     */
    public const EXTENSIONS = ['mbstring', 'intl'];

    /**
     * @param list<string> $loaded extension names as reported by get_loaded_extensions()
     */
    public static function firstMissing(array $loaded): ?string
    {
        foreach (self::EXTENSIONS as $extension) {
            if (!\in_array($extension, $loaded, true)) {
                return $extension;
            }
        }

        return null;
    }

    public static function refuseMessage(string $extension): string
    {
        return 'candy-minesweeper requires ext-' . $extension;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Flip\AnsiArt;
use SugarCraft\Flip\ArtLibrary;

/**
 * The animated/logo alternative to the block-letter {@see Banner}: ANSI art
 * from candy-flip, picked once per process and painted by the main, options
 * and help menus in place of the banner.
 *
 * Selection (env CANDY_TOP_LOGO):
 *  - `off`            → null, every caller keeps painting the legacy banner
 *                       (the byte-identical golden-fallback contract);
 *  - a slug name      → that art, deterministic across runs;
 *  - unset / `random` → a random slug at the depth the colour profile maps
 *                       to via {@see ArtLibrary::profileTag()}.
 *
 * CANDY_TOP_LOGO_SEED (an integer) reseeds mt_srand before the random pick,
 * so two runs with the same seed land on the same logo. Honesty note: the
 * reseed is process-global (mt_srand has no narrower form) — the logo pick
 * is currently the app's only mt_rand consumer, so nothing else is
 * observably affected, but a future feature drawing mt_rand would share the
 * seeded stream.
 *
 * The library is memoised process-wide (parsing ~430 files is file I/O that
 * must happen exactly once); a pick is memoised per (depth, choice) because
 * a tty_mode/truecolor flip re-picks at a new depth on every toggle and the
 * parse itself walks a multi-frame .ansi file. Any failure — missing logo
 * dir, unparsable corpus, unknown slug — resolves to null, i.e. the banner:
 * a broken optional decoration must never stop the monitor from drawing.
 */
final class Logo
{
    /** Env switch: `off` disables, a slug pins, unset/`random` picks randomly. */
    public const CHOICE_ENV = 'CANDY_TOP_LOGO';

    /** Env int reseeding mt_srand for the random pick (logo choice only). */
    public const SEED_ENV = 'CANDY_TOP_LOGO_SEED';

    /** The corpus ships inside the package so an install is self-contained. */
    public const DIR = __DIR__ . '/../../logo';

    private static ?ArtLibrary $library = null;

    /** @var array<string, AnsiArt|false> memoised picks; false = resolved to null */
    private static array $picks = [];

    private function __construct()
    {
    }

    /**
     * The logo for `$profile`'s depth tag, or null when the caller must paint
     * the legacy banner (switched off, corpus missing, nothing resolvable).
     */
    public static function pick(ColorProfile $profile): ?AnsiArt
    {
        $choice = strtolower(trim((string) (getenv(self::CHOICE_ENV) ?: '')));
        if ($choice === 'off') {
            return null;
        }
        $library = self::library();
        if ($library === null) {
            return null;
        }
        $tag = ArtLibrary::profileTag($profile);
        $memo = $tag . '|' . $choice;
        if (isset(self::$picks[$memo])) {
            return self::$picks[$memo] === false ? null : self::$picks[$memo];
        }
        $art = self::resolve($library, $tag, $choice);
        // Memoise the miss too: a missing directory must not re-litigate on
        // every tty_mode flip.
        self::$picks[$memo] = $art ?? false;

        return $art;
    }

    /**
     * Blit frame `$frameIndex` of `$art` centred with its top row at 0-based
     * `$y`, the same geometry Banner::paint uses (centre minus the one-cell
     * btop offset). Cells past the right edge are skipped by the surface's
     * own clip; a row taller than the surface simply never lands.
     *
     * `$ink` is not consulted: art cells carry their own SGR (the corpus is
     * pre-rendered per depth tag); an empty cell SGR inherits the surface
     * base, exactly the unstyled-cell idiom Surface implements.
     */
    public static function paint(Surface $surface, int $y, AnsiArt $art, int $frameIndex, Ink $ink): void
    {
        unset($ink);
        $frame = $art->frameCells(max(0, min($frameIndex, $art->frameCount() - 1)));
        $x = max(0, intdiv($surface->width, 2) - intdiv($art->width(), 2) - 1);
        foreach ($frame as $row => $cells) {
            $col = $x;
            foreach ($cells as $cell) {
                if ($col >= $surface->width) {
                    break;
                }
                // btop's Mv::r(1) idiom (Banner): an unstyled space leaves the
                // backdrop intact; a styled space is deliberate fill.
                if ($cell->glyph !== ' ' || $cell->sgr !== '') {
                    $surface->put($col, $y + $row, $cell->glyph, $cell->sgr);
                }
                $col++;
            }
        }
    }

    /**
     * Fit gate shared by the three banner-family menus' logoDelta(): a
     * delta-shifted block spans the logo's top row at 0-based `$topRow`
     * through the box's bottom border at 1-based `$bottomLine`, and every
     * row of it must land on-screen. The clamped/centred anchors plus the
     * content-driven height terms mean a simple interior-floor check can
     * pass while the bottom border rides past the last row — the true
     * extents are cheap to compute at the call site, so compute them and
     * ask here. Failing the gate means falling back to delta 0 (the legacy
     * banner): a clipped border is worse than no logo.
     */
    public static function blockFits(int $topRow, int $bottomLine, int $rows): bool
    {
        return $topRow >= 0 && $bottomLine <= $rows;
    }

    /** Drop the memoised library and picks (test seams around env changes). */
    public static function resetForTesting(): void
    {
        self::$library = null;
        self::$picks = [];
    }

    private static function library(): ?ArtLibrary
    {
        if (self::$library !== null) {
            return self::$library;
        }
        try {
            return self::$library = ArtLibrary::fromDir(self::DIR);
        } catch (\RuntimeException) {
            // Missing or unreadable corpus: Logo::pick's contract is null.
            // Stay unset so a directory appearing later is picked up.
            return null;
        }
    }

    private static function resolve(ArtLibrary $library, string $tag, string $choice): ?AnsiArt
    {
        try {
            if ($choice === '' || $choice === 'random') {
                $seed = getenv(self::SEED_ENV);
                if (is_string($seed) && $seed !== '' && is_numeric($seed)) {
                    mt_srand((int) $seed);
                }
                $rng = static fn (int $n): int => mt_rand(0, $n - 1);
                $slug = $library->randomSlug($tag, true, $rng);

                return $slug === null ? null : $library->pick($slug, $tag, true);
            }

            return $library->pick($choice, $tag, true);
        } catch (\RuntimeException | \InvalidArgumentException) {
            return null;
        }
    }
}

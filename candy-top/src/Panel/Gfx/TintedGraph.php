<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gfx;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dashboard\Plot\Braille\DualSampleGraph;
use SugarCraft\Top\Theme\Palette;
use SugarCraft\Top\View\Ink;

/**
 * Renders a sugar-dashboard {@see DualSampleGraph} in the App's theme and colour
 * profile — btop's `Draw::Graph` with a `color_gradient` name.
 *
 * WHY markers: DualSampleGraph colours through a list of `Color`s it
 * expands itself, while candy-top's colours live in an {@see Ink} that is
 * already resolved for the active profile (TrueColor, Ansi256, 16-colour
 * TTY). Handing the graph raw palette colours would re-derive them
 * through `Color::rgb()` and lose the TTY theme's ANSI indices. Instead
 * the graph gets a 101-stop identity ramp of marker colours rgb(i, 0, 0)
 * (Gradient101 maps 101 stops onto themselves) plus an underlay marker
 * rgb(0, 0, 1), renders once in TrueColor, and every marker escape is
 * swapped for `Ink::gradient($name, i)` / `Ink::fg('inactive_fg')`. The
 * glyph and colour-index law stays the library's, the bytes stay the
 * theme's.
 *
 * The swap is a pattern over the library's TrueColor spelling, so the marker
 * colours below are part of the contract: a change there (colon
 * subparameters, a 48;2 underlay, a different marker channel) must redden
 * tests/Panel/Gfx/GfxTest.php, never silently ship 24-bit bytes into a
 * 16-colour or TTY frame.
 */
final class TintedGraph
{
    /** @var list<Color>|null */
    private static ?array $markers = null;

    /**
     * Rendered lines by content signature (graph data + size + family/invert
     * flags + gradient + underlay + theme + profile). Paints at update_ms
     * routinely redraw identical graphs — a clock tick or menu repaint used
     * to re-render and re-tint every graph on screen (27-38 ms of full-frame
     * margin, S-N17). The held Palette pins the entry against a recycled
     * object id: an entry only serves the very theme it was rendered for.
     *
     * @var array<string, array{Palette, list<string>}>
     */
    private static array $cache = [];

    /** Generous for one frame's graph population; eviction drops the oldest half. */
    private const CACHE_MAX = 256;

    private function __construct()
    {
    }

    /**
     * The graph's rows with theme escapes. `$underlay` paints btop's
     * graph_bg glyph in inactive_fg under a height-1 graph's blank cells.
     *
     * @return list<string>
     */
    public static function lines(DualSampleGraph $graph, Ink $ink, string $gradient, bool $underlay = false): array
    {
        $key = self::cacheKey($graph, $ink, $gradient, $underlay);
        $hit = self::$cache[$key] ?? null;
        if ($hit !== null && $hit[0] === $ink->palette()) {
            return $hit[1];
        }

        $g = $graph->withGradient(self::markers());
        if ($underlay) {
            $g = $g->withUnderlay(Color::rgb(0, 0, 1));
        }
        $raw = $g->render(ColorProfile::TrueColor);
        $inactive = $ink->fg('inactive_fg');
        $tinted = preg_replace_callback(
            '/\x1b\[38;2;(\d+);0;([01])m/',
            static fn (array $m): string => $m[2] === '1' ? $inactive : $ink->gradient($gradient, (int) $m[1]),
            $raw,
        ) ?? $raw;

        $lines = explode("\n", $tinted);
        if (count(self::$cache) >= self::CACHE_MAX) {
            self::$cache = array_slice(self::$cache, intdiv(self::CACHE_MAX, 2), null, true);
        }
        unset(self::$cache[$key]);
        self::$cache[$key] = [$ink->palette(), $lines];

        return $lines;
    }

    /**
     * The tint depends on nothing else the graph carries: width/height, the
     * data, the shape flags, and the resolved theme bytes. samples() is a
     * copy — serializing it costs far less than the render and the swap it
     * guards.
     */
    private static function cacheKey(DualSampleGraph $graph, Ink $ink, string $gradient, bool $underlay): string
    {
        return $graph->width() . 'x' . $graph->height() . '|' . $graph->family()
            . ($graph->inverted() ? '|inv' : '') . ($graph->noZero() ? '|nz' : '')
            . '|' . $graph->maxValue() . '|' . ($underlay ? 'u' : '-')
            . '|' . $gradient . '|' . spl_object_id($ink->palette()) . ':' . $ink->profile()->name
            . '|' . md5(serialize($graph->samples()));
    }

    /**
     * The first gradient of `$names` the palette defines — btop #1739's
     * `zswap -> cached -> used` lookup, which avoids the PR's `at()` crash
     * on themes without the newer name.
     *
     * @param list<string> $names
     */
    public static function gradientName(Ink $ink, array $names): string
    {
        foreach ($names as $name) {
            if ($ink->palette()->hasGradient($name)) {
                return $name;
            }
        }

        return 'cpu';
    }

    /** @return list<Color> */
    private static function markers(): array
    {
        return self::$markers ??= array_map(static fn (int $i): Color => Color::rgb($i, 0, 0), range(0, 100));
    }
}

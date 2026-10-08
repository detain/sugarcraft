<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gfx;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Top\View\Ink;

/**
 * Renders a sugar-dash {@see DualSampleGraph} in the App's theme and colour
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
 */
final class TintedGraph
{
    /** @var list<Color>|null */
    private static ?array $markers = null;

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

        return explode("\n", $tinted);
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

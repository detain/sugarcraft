<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Theme\BoxFlow;
use SugarCraft\Top\Theme\ThemeConfig;

/**
 * Gradient box outlines — a candy-top extension over btop's flat
 * `<box>_box` colour (see {@see ThemeConfig::boxFlow()}).
 *
 * A post-pass over the finished frame rather than a change to every border
 * writer: outlines, title junctions, buttons, dividers and sub-boxes are
 * drawn by a dozen views, and all of them already paint in the box's flat
 * line colour (or `div_line`). Within each box rect the pass recolours
 * exactly the box-drawing cells whose style IS that flat colour, by their
 * position on the box's top-left → bottom-right diagonal. So:
 *  - titles, hotkeys, clock and battery text keep their own colours (they
 *    are not styled in the line colour);
 *  - gpu boxes (cpu_box family) and the ctr box (proc_box family) flow
 *    without their views knowing about it;
 *  - inner `div_line` lines (cpu info box, mem/disks split, net stats box,
 *    proc detail, gpu stats) carry a quieter echo of the same flow:
 *    {@see DIV_ECHO} of the way from div_line toward the flow colour.
 *
 * TrueColor only: the 256-colour, 16-colour (lowcolor) and TTY paths keep
 * the flat btop colour, and a theme without `_end` keys is untouched, so
 * every btop theme renders byte-identically.
 */
final class BorderFlow
{
    /** How far an inner div_line moves toward the outline flow (0 = flat div_line, 1 = full flow). */
    public const DIV_ECHO = 0.3;

    /** Memo bound: distinct (width, rect, colours) boxes kept before the memo is dropped. */
    private const MEMO_BOXES = 64;

    /** @var array<string, array<int, string>> box key → Surface::restyleBoxDrawing memo */
    private static array $memo = [];

    private function __construct()
    {
    }

    /** Apply every box's flow to a finished frame (no-op without flows or truecolor). */
    public static function paint(Surface $surface, Layout $layout, Ink $ink, Config $config): void
    {
        if (!self::enabled($ink, $config)) {
            return;
        }
        $palette = $ink->palette();
        if (!$palette instanceof ThemeConfig) {
            return;
        }
        $div = $palette->color('div_line');
        foreach ($layout->ordered() as $name => $rect) {
            $family = self::family($name, $layout);
            $flow = $palette->boxFlow($family);
            if ($flow !== null) {
                self::sweep($surface, $rect, $ink->fg($family . '_box'), $flow, $ink->fg('div_line'), $div, $ink->profile());
            }
        }
    }

    /** Whether flows may be drawn at all: a truecolor session outside tty mode. */
    public static function enabled(Ink $ink, Config $config): bool
    {
        return $ink->profile() === ColorProfile::TrueColor
            && !$config->ttyMode()
            && !$config->bool('lowcolor');
    }

    /**
     * Which `<family>_box` colour a layout box is outlined in: gpu boxes use
     * cpu_box and ctr uses proc_box, as btop/candy-top draw them; the VM
     * dashboard and every card in it use {@see FrameBuilder::VMS_FAMILY}.
     */
    public static function family(string $box, Layout $layout): string
    {
        return match (true) {
            $layout->gpuBox($box) !== null => 'cpu',
            $box === 'ctr' => 'proc',
            $box === VmsMode::BOX => FrameBuilder::VMS_FAMILY,
            default => $box,
        };
    }

    /**
     * Recolour, inside `$rect`, the box-drawing cells styled exactly
     * `$lineSgr` along `$flow`, and (when given) those styled `$divSgr`
     * toward it by {@see DIV_ECHO}. The reusable hook for any box outline.
     *
     * Runs every frame, so the per-cell colours are memoised per (surface
     * width, rect, colours) in {@see $memo}: after the first frame a box
     * costs one cell scan and an array lookup per border cell.
     */
    public static function sweep(
        Surface $surface,
        Rect $rect,
        string $lineSgr,
        BoxFlow $flow,
        string $divSgr = '',
        ?Color $div = null,
        ColorProfile $profile = ColorProfile::TrueColor,
    ): void {
        if ($rect->width <= 0 || $rect->height <= 0 || $lineSgr === '') {
            return;
        }
        $line = Surface::canonical($lineSgr);
        $styles = [$line => 0];
        $inner = $divSgr === '' || $div === null ? null : Surface::canonical($divSgr);
        if ($inner !== null && $inner !== $line) {
            $styles[$inner] = 1;
        }
        $key = implode('|', [
            $surface->width, $rect->x, $rect->y, $rect->width, $rect->height, $line, $inner ?? '',
            self::hex($flow->start), $flow->mid === null ? '' : self::hex($flow->mid), self::hex($flow->end),
            $div === null ? '' : self::hex($div), $profile->name,
        ]);
        if (!isset(self::$memo[$key]) && \count(self::$memo) >= self::MEMO_BOXES) {
            self::$memo = [];
        }
        self::$memo[$key] ??= [];
        $spanX = max(1, $rect->width - 1);
        $spanY = max(1, $rect->height - 1);
        $surface->restyleBoxDrawing(
            $rect,
            $styles,
            self::$memo[$key],
            static function (int $tag, int $x, int $y) use ($rect, $spanX, $spanY, $flow, $div, $profile): string {
                $color = $flow->at((($x - $rect->x) / $spanX + ($y - $rect->y) / $spanY) / 2);
                if ($tag === 1 && $div !== null) {
                    $color = BoxFlow::mix($div, $color, self::DIV_ECHO);
                }

                return $color->toFg($profile);
            },
        );
    }

    private static function hex(Color $c): string
    {
        return sprintf('%02x%02x%02x', $c->r, $c->g, $c->b);
    }
}

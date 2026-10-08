<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Theme\BoxFlow;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\BorderFlow;
use SugarCraft\Top\View\BoxChrome;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

final class BorderFlowTest extends TestCase
{
    private const LINE = "\x1b[38;2;10;10;10m";
    private const DIV = "\x1b[38;2;0;0;0m";

    private static function flow(): BoxFlow
    {
        return BoxFlow::new(Color::rgb(0, 0, 0), Color::rgb(100, 100, 100), Color::rgb(200, 200, 200));
    }

    private static function fg(int $v): string
    {
        return "\x1b[38;2;{$v};{$v};{$v}m";
    }

    public function testSweepFlowsCornerToCornerAndSparesTitles(): void
    {
        $ink = Ink::new(ThemeConfig::new());
        $s = Surface::new(11, 3);
        BoxChrome::paint($s, Rect::new(0, 0, 11, 3), self::LINE, $ink, Border::rounded(), title: 'cpu');
        $s->put(5, 1, '─x', self::LINE); // a box glyph inside the box counts; text does not
        $titleStyle = $s->style(3, 0);

        BorderFlow::sweep($s, Rect::new(0, 0, 11, 3), self::LINE, self::flow());

        $this->assertSame(self::fg(0), $s->style(0, 0), 'top-left = start');
        $this->assertSame(self::fg(200), $s->style(10, 2), 'bottom-right = end');
        $this->assertSame(self::fg(100), $s->style(10, 0), 'top-right = mid');
        $this->assertSame(self::fg(100), $s->style(0, 2), 'bottom-left = mid');
        $this->assertSame(self::fg(20), $s->style(2, 0), 'title junction ┐ flows (t = .1)');
        $this->assertSame($titleStyle, $s->style(3, 0), 'the title keeps its own colour');
        $this->assertSame(self::fg(100), $s->style(5, 1), 'centre: t = .5');
        $this->assertSame(Surface::canonical(self::LINE), $s->style(6, 1), 'non-box text in the line colour is left alone');
        $this->assertSame('╭─┐cpu┌───╮', $s->plainLines()[0], 'glyphs never change');
    }

    public function testInnerDividersEchoTheFlow(): void
    {
        $s = Surface::new(5, 3);
        $s->put(0, 1, '├───┤', self::DIV);
        $s->put(0, 0, '─', "\x1b[38;2;1;2;3m"); // a third colour: untouched

        BorderFlow::sweep($s, Rect::new(0, 0, 5, 3), self::LINE, self::flow(), self::DIV, Color::rgb(0, 0, 0));

        // t at (4,1) = (1 + .5)/2 = .75 → 150; echo = 0 + .3 * 150 = 45.
        $this->assertSame(self::fg(45), $s->style(4, 1));
        $this->assertSame("\x1b[38;2;1;2;3m", $s->style(0, 0));
    }

    public function testSweepIgnoresEmptyLineAndOffSurfaceRects(): void
    {
        $s = Surface::new(4, 2);
        $s->put(0, 0, '────', '');
        BorderFlow::sweep($s, Rect::new(0, 0, 4, 2), '', self::flow());
        BorderFlow::sweep($s, Rect::new(10, 10, 4, 2), self::LINE, self::flow());
        $this->assertSame('', $s->style(0, 0));
    }

    public function testEnabledOnlyForTruecolorOutsideTtyMode(): void
    {
        $true = Ink::new(ThemeConfig::new(), ColorProfile::TrueColor);
        $this->assertTrue(BorderFlow::enabled($true, Config::new()));
        $this->assertFalse(BorderFlow::enabled(Ink::new(ThemeConfig::new(), ColorProfile::Ansi256), Config::new()));
        $this->assertFalse(BorderFlow::enabled(Ink::new(TtyTheme::new(), ColorProfile::Ansi), Config::new()));
        $this->assertFalse(BorderFlow::enabled($true, Config::new()->with('lowcolor', true)));
        $this->assertFalse(BorderFlow::enabled($true, Config::new()->withTtyModeResolved(true, false)));
    }

    public function testFamilyMapsCtrToProc(): void
    {
        $layout = FrameBuilder::layout(120, 40, Config::new(), 8);
        $this->assertSame('proc', BorderFlow::family('ctr', $layout));
        $this->assertSame('mem', BorderFlow::family('mem', $layout));
    }

    public function testPaintIsANoOpWithoutFlowsAndRecoloursWithThem(): void
    {
        $config = Config::new();
        $layout = FrameBuilder::layout(60, 24, $config, 4);
        $paint = static function (ThemeConfig $theme, ColorProfile $profile) use ($config, $layout): Surface {
            $ink = Ink::new($theme, $profile);
            $s = Surface::new(60, 24, $ink->base());
            FrameBuilder::paintChrome($s, $layout, $ink, $config, \SugarCraft\Top\Tests\Support\Harness::host());
            BorderFlow::paint($s, $layout, $ink, $config);

            return $s;
        };
        $flat = ThemeConfig::new();
        $before = Surface::new(60, 24, Ink::new($flat)->base());
        FrameBuilder::paintChrome($before, $layout, Ink::new($flat), $config, \SugarCraft\Top\Tests\Support\Harness::host());
        $this->assertSame($before->render(), $paint($flat, ColorProfile::TrueColor)->render(), 'no _end keys: byte-identical');

        $flowing = ThemeConfig::fromString("theme[cpu_box]=\"#000000\"\ntheme[cpu_box_end]=\"#ffffff\"\n");
        $cpu = $layout->box('cpu');
        $this->assertNotNull($cpu);
        $s = $paint($flowing, ColorProfile::TrueColor);
        $this->assertSame("\x1b[38;2;0;0;0m", $s->style($cpu->x, $cpu->y));
        $this->assertSame("\x1b[38;2;255;255;255m", $s->style($cpu->right() - 1, $cpu->bottom() - 1));
        $this->assertStringNotContainsString('38;2', $paint($flowing, ColorProfile::Ansi256)->render(), '256 colours stay flat');
    }

    /**
     * The memoised Surface fast path is byte-identical to the plain
     * per-cell definition ({@see referenceSweep()}) on real frames with gpu
     * and ctr boxes, cold and warm (memo hit), and across a theme change on
     * the same rects (the memo is keyed by colour).
     */
    public function testFastPathIsByteIdenticalToTheReferencePass(): void
    {
        $tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $text = (string) file_get_contents(\SugarCraft\Top\Theme\ThemeRegistry::bundledDir() . '/pastel.theme');
            $flat = (string) preg_replace('/^theme\[\w+_box_(mid|end)\].*$/m', '', $text);
            // Same outline colours, different flows: the memo must not serve the old ones.
            $other = (string) preg_replace('/^(theme\[\w+_box_(?:mid|end)\])="#[0-9a-f]{6}"/m', '$1="#10ff20"', $text);
            $this->assertNotSame($text, $other);
            foreach ([[160, 50], [200, 60]] as [$w, $h]) {
                $config = Config::new()->with('shown_boxes', 'cpu mem net proc ctr gpu0')->withShownBoxesSettled(2);
                $host = \SugarCraft\Top\Tests\Support\Harness::host();
                $app = \SugarCraft\Top\App::start(
                    $config,
                    ThemeConfig::fromString($flat, 'pastel'),
                    $host,
                    \SugarCraft\Top\Panel\Panels::standard($host, $config, true),
                    static fn (): \SugarCraft\Top\Msg\ClockTickMsg => new \SugarCraft\Top\Msg\ClockTickMsg(\SugarCraft\Top\Tests\Support\Harness::TIME, 3600.0),
                    ColorProfile::TrueColor,
                );
                [$app] = $app->update(new \SugarCraft\Core\Msg\WindowSizeMsg($w, $h));
                [$app] = $app->update(new \SugarCraft\Top\Msg\ClockTickMsg(\SugarCraft\Top\Tests\Support\Harness::TIME, 3600.0));
                foreach (\SugarCraft\Top\Tests\Support\Cmds::run($app->init()) as $msg) {
                    if (!$msg instanceof \SugarCraft\Core\TickRequest) {
                        [$app] = $app->update($msg);
                    }
                }
                $layout = $app->layout;
                $base = $app->surface();
                $this->assertNotNull($layout);
                $this->assertNotNull($base);
                $this->assertNotNull($layout->box('ctr'));
                $this->assertNotNull($layout->gpuBox('gpu0'));
                $this->assertStringContainsString('proc', implode('', $base->plainLines()), 'a painted frame, not the pre-sample blank');
                foreach ([$text, $other, $text] as $theme) {
                    $ink = Ink::new(ThemeConfig::fromString($theme, 'pastel'));
                    $fast = clone $base;
                    BorderFlow::paint($fast, $layout, $ink, $app->config);
                    $slow = clone $base;
                    self::referencePaint($slow, $layout, $ink);
                    $this->assertSame($slow->render(), $fast->render(), "{$w}x{$h}");
                    $this->assertNotSame($base->render(), $fast->render(), 'the pass did something');
                }
            }
        } finally {
            date_default_timezone_set($tz);
        }
    }

    /** The pass as defined: per-cell style()/restyle() with no memo. */
    private static function referencePaint(Surface $s, \SugarCraft\Top\View\Layout $layout, Ink $ink): void
    {
        $palette = $ink->palette();
        self::assertInstanceOf(ThemeConfig::class, $palette);
        $div = $palette->color('div_line');
        self::assertNotNull($div);
        $divSgr = Surface::canonical($ink->fg('div_line'));
        foreach ($layout->ordered() as $name => $rect) {
            $family = BorderFlow::family($name, $layout);
            $flow = $palette->boxFlow($family);
            if ($flow === null) {
                continue;
            }
            $line = Surface::canonical($ink->fg($family . '_box'));
            $clip = $rect->intersect($s->bounds());
            for ($y = $clip->y; $y < $clip->bottom(); $y++) {
                for ($x = $clip->x; $x < $clip->right(); $x++) {
                    $style = $s->style($x, $y);
                    $cp = mb_ord($s->glyph($x, $y) === '' ? ' ' : $s->glyph($x, $y));
                    if (($style !== $line && $style !== $divSgr) || $cp < 0x2500 || $cp > 0x257F) {
                        continue;
                    }
                    $t = (($x - $rect->x) / max(1, $rect->width - 1) + ($y - $rect->y) / max(1, $rect->height - 1)) / 2;
                    $color = $flow->at($t);
                    if ($style !== $line) {
                        $color = BoxFlow::mix($div, $color, BorderFlow::DIV_ECHO);
                    }
                    $s->restyle($x, $y, $color->toFg(ColorProfile::TrueColor));
                }
            }
        }
    }

    public function testRestyleBoxDrawingUsesTheMemoOnAHit(): void
    {
        $s = Surface::new(3, 1);
        $s->put(0, 0, '─x│', self::LINE);
        $memo = [];
        $calls = 0;
        $resolve = static function (int $tag, int $x, int $y) use (&$calls): string {
            $calls++;

            return "\x1b[38;2;{$x};{$y};{$tag}m";
        };
        $s->restyleBoxDrawing(Rect::new(0, 0, 3, 1), [Surface::canonical(self::LINE) => 0], $memo, $resolve);
        $this->assertSame(2, $calls, 'one miss per box-drawing cell; the x is skipped');
        $this->assertSame("\x1b[38;2;2;0;0m", $s->style(2, 0));
        $this->assertSame(Surface::canonical(self::LINE), $s->style(1, 0));

        $again = Surface::new(3, 1);
        $again->put(0, 0, '─x│', self::LINE);
        $again->restyleBoxDrawing(Rect::new(0, 0, 3, 1), [Surface::canonical(self::LINE) => 0], $memo, $resolve);
        $this->assertSame(2, $calls, 'a warm memo does no colour work');
        $this->assertSame($s->render(), $again->render());
    }
}

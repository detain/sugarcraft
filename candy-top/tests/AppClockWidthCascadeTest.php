<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Rect;

/**
 * Finding 151 (S-X19) wiring pin: the chrome cascade must share the ONE
 * clock width the frame itself was laid out with. A container label on the
 * cpu title row is cut to the cells that survive before the drawn clock,
 * so a wide clock_format must visibly squeeze both the mapped zone and the
 * painted label — and a call site that quietly kept the default width
 * (the bug shape this row removed) answers identically for both clocks and
 * fails here.
 */
final class AppClockWidthCascadeTest extends TestCase
{
    /** Long enough to overrun the budget on any sane layout; TZ-stable in width. */
    private const WIDE_FORMAT = 'clock %d %b %Y %H:%M:%S %j %C on /user at /host uptime /uptime x';

    protected function setUp(): void
    {
        T::reset();
    }

    public function testTheEngineLabelCarvesRoomFromTheClockActuallyDrawn(): void
    {
        // Geometry choice: below 100 columns the battery extension of the
        // reserved clock budget stops (budget becomes cpu-66), the wide
        // clock answers 33 cells, room drops to 5, and the 6-char label
        // loses exactly one cell — the observable this pin needs. At 120
        // the extension leaves room 6, which a 6-char label answers the
        // same as any wider room: a defaulted clock width would hide.
        $shown = Config::new()->with('shown_boxes', 'cpu proc');
        $base = self::app($shown);
        $baseZone = $base->chromeButtons()['x'] ?? null;
        self::assertNotNull($baseZone, 'the default %X clock leaves room for the engine label');
        self::assertSame(6, $baseZone[2], 'the full docker label fits beside an eight-cell clock');
        self::assertStringContainsString('docker', $base->surface()?->plainLines()[0] ?? '');

        $wide = self::app($shown->with('clock_format', self::WIDE_FORMAT));
        $wideZone = $wide->chromeButtons()['x'] ?? null;
        self::assertNotNull($wideZone, 'the squeezed label is still worth drawing at this width');
        self::assertSame(5, $wideZone[2], 'a clock at the unextended reserved budget carves the room to 5');
        self::assertLessThan($baseZone[2], $wideZone[2]);
        $row = $wide->surface()?->plainLines()[0] ?? '';
        self::assertStringContainsString('docke', $row, 'the painted label is cut to the same room');
        self::assertStringNotContainsString('docker', $row, 'paint and hit-map agree — neither keeps the full name');
    }

    public function testTheClockBudgetFloorKeepsTheLabelAliveAndTheFloorMathIsOurs(): void
    {
        // The budget cuts the CLOCK to cpuWidth-66 before anyone asks for
        // room, so no format — however absurd — starves the label entirely
        // through the App; the starvation below three cells is the
        // FrameBuilder's own contract and pinned at that level too.
        $format = self::WIDE_FORMAT . ' plus even more text that keeps going and going past every budget';
        $app = self::app(Config::new()->with('clock_format', $format));
        self::assertNotNull(
            $app->chromeButtons()['x'] ?? null,
            'the clock is truncated to the budget, so the label keeps its floor room',
        );

        $cpu = Rect::new(0, 0, 118, 9);
        self::assertSame(
            5,
            Width::string((string) FrameBuilder::engineLabel($cpu, str_repeat('x', 12), 52)),
            'room drops one cell per two clock cells (59 - 26 - 28)',
        );
        self::assertNull(
            FrameBuilder::engineLabel($cpu, 'docker', 200),
            'below three cells the label yields rather than hide under the clock face',
        );
    }

    private static function app(Config $config): App
    {
        $host = HostInfo::new('Ryzen 7 5800X', 8, 'joe', 'box', containerEngine: 'docker');
        $app = App::start(
            $config,
            ThemeConfig::new(),
            $host,
            Panels::standard($host, $config, true),
            static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0),
            ColorProfile::TrueColor,
        );
        [$app] = $app->update(new WindowSizeMsg(99, 30));
        [$app] = $app->update(new ClockTickMsg(Harness::TIME, 3600.0));

        return self::settle($app, $app->init());
    }

    private static function settle(App $app, ?\Closure $cmd): App
    {
        foreach (Cmds::run($cmd) as $m) {
            if (!$m instanceof TickRequest) {
                [$app, $next] = $app->update($m);
                $app = self::settle($app, $next);
            }
        }

        return $app;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\CodeTime\Dashboard;
use SugarCraft\CodeTime\Heartbeat;
use SugarCraft\CodeTime\Stats;
use SugarCraft\CodeTime\Store;
use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase
{
    private string $tmp;
    private string $storeDir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/candy-codetime-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        $this->storeDir = $this->tmp . '/store';
        mkdir($this->storeDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/store/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->storeDir);
        // sqlite-backed tests drop a .db straight into the tmp root
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        rmdir($this->tmp);
    }

    public function testConstructorStoresFields(): void
    {
        $store = new Store($this->storeDir);
        $endDay = new \DateTimeImmutable('2024-06-07');
        $stats = new Stats([], []);

        $d = new Dashboard($store, $endDay, 14, $stats);

        $this->assertSame($store, $d->source);
        $this->assertSame($endDay, $d->endDay);
        $this->assertSame(14, $d->days);
        $this->assertSame($stats, $d->stats);
    }

    public function testInitReturnsNull(): void
    {
        $d = $this->makeDashboard();
        $this->assertNull($d->init());
    }

    public function testStartFactoryCreatesInstanceAndReloads(): void
    {
        $store = new Store($this->storeDir);
        // Append a heartbeat so reload finds data
        $day = new \DateTimeImmutable('2024-06-07');
        $hb = new Heartbeat($day->getTimestamp(), 'demo', 'php', 'a.php', 120);
        $store->append($hb);

        $d = Dashboard::start($store, $day, 3);

        $this->assertInstanceOf(Dashboard::class, $d);
        $this->assertEquals($day, $d->endDay);
        $this->assertSame(3, $d->days);
    }

    public function testStartWithNullEndDayUsesToday(): void
    {
        $store = new Store($this->storeDir);
        $d = Dashboard::start($store, null, 7);

        $this->assertInstanceOf(Dashboard::class, $d);
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);
        $this->assertEquals($today, $d->endDay);
    }

    // ---- update() behaviour tests -----------------------------------------

    public function testUpdateWithNonKeyMsgReturnsSelfNoCommand(): void
    {
        $d = $this->makeDashboard();
        $msg = new class implements \SugarCraft\Core\Msg {
        };

        [$next, $cmd] = $d->update($msg);

        $this->assertSame($d, $next);
        $this->assertNull($cmd);
    }

    public function testUpdateWithEscapeKeyReturnsQuit(): void
    {
        $d = $this->makeDashboard();
        $msg = new KeyMsg(KeyType::Escape);

        [$next, $cmd] = $d->update($msg);

        $this->assertSame($d, $next);
        $this->assertEquals(Cmd::quit(), $cmd);
    }

    public function testUpdateWithQCharReturnsQuit(): void
    {
        $d = $this->makeDashboard();
        $msg = new KeyMsg(KeyType::Char, 'q');

        [$next, $cmd] = $d->update($msg);

        $this->assertSame($d, $next);
        $this->assertEquals(Cmd::quit(), $cmd);
    }

    public function testUpdateWithCtrlCReturnsQuit(): void
    {
        $d = $this->makeDashboard();
        $msg = new KeyMsg(KeyType::Char, 'c', ctrl: true);

        [$next, $cmd] = $d->update($msg);

        $this->assertSame($d, $next);
        $this->assertEquals(Cmd::quit(), $cmd);
    }

    public function testUpdateWithRCharReloadsAndReturnsSelfNoCommand(): void
    {
        $store = new Store($this->storeDir);
        $day = new \DateTimeImmutable('2024-06-07');
        $store->append(new Heartbeat($day->getTimestamp(), 'reload-test', 'php', 'a.php', 60));

        $d = new Dashboard($store, $day, 3, new Stats([], []));

        $msg = new KeyMsg(KeyType::Char, 'r');
        [$next, $cmd] = $d->update($msg);

        $this->assertNotSame($d, $next);
        $this->assertSame($store, $next->source);
        $this->assertEquals($day, $next->endDay);
        $this->assertNull($cmd);
    }

    public function testUpdateWithLeftArrowShiftsBack(): void
    {
        $store = new Store($this->storeDir);
        $day = new \DateTimeImmutable('2024-06-07');
        // The 62 is load-bearing: shifting used to rebuild the Model without
        // it, so the first arrow keypress put the dashboard back on an
        // unknown-width code path and the frame stopped fitting the window.
        $d = new Dashboard($store, $day, 3, new Stats([], []), 62);

        $msg = new KeyMsg(KeyType::Left);
        [$next, $cmd] = $d->update($msg);

        $this->assertNotSame($d, $next);
        $expected = $day->modify('-1 day');
        $this->assertEquals($expected, $next->endDay);
        $this->assertSame(62, $next->width, 'a shift must keep the terminal width');
        $this->assertNull($cmd);
    }

    public function testPlusKeyStepsTheWindowUpTheLadder(): void
    {
        $store = new Store($this->storeDir);
        $day = new \DateTimeImmutable('2024-06-07');
        $d = new Dashboard($store, $day, 7, new Stats([], []), 62);

        [$next, $cmd] = $d->update(new KeyMsg(KeyType::Char, '+'));

        $this->assertNotSame($d, $next);
        $this->assertSame(14, $next->days);
        $this->assertSame(62, $next->width, 'a window step must keep the terminal width');
        $this->assertEquals($day, $next->endDay);
        $this->assertNull($cmd);
    }

    public function testMinusKeyStepsTheWindowDownTheLadder(): void
    {
        $store = new Store($this->storeDir);
        $day = new \DateTimeImmutable('2024-06-07');
        $d = new Dashboard($store, $day, 14, new Stats([], []), 62);

        [$next, $cmd] = $d->update(new KeyMsg(KeyType::Char, '-'));

        $this->assertSame(7, $next->days);
        $this->assertNull($cmd);
    }

    public function testTheWindowLadderEndsAreSticky(): void
    {
        $store = new Store($this->storeDir);
        $day = new \DateTimeImmutable('2024-06-07');
        $top = new Dashboard($store, $day, 90, new Stats([], []));
        $bottom = new Dashboard($store, $day, 7, new Stats([], []));

        [$sameTop, $cmd1] = $top->update(new KeyMsg(KeyType::Char, '+'));
        [$sameBottom, $cmd2] = $bottom->update(new KeyMsg(KeyType::Char, '-'));

        // Past the end rung the Model is returned untouched: no rebuild, no
        // recompute, no command — a keypress at the end is a no-op, not a
        // window that shrinks to one day or runs past a quarter.
        $this->assertSame($top, $sameTop);
        $this->assertNull($cmd1);
        $this->assertSame($bottom, $sameBottom);
        $this->assertNull($cmd2);
    }

    public function testWindowStepsSnapNonLadderWidthsToTheNearestRung(): void
    {
        $this->assertSame(7, Dashboard::nextWindow(3, +1));
        $this->assertSame(14, Dashboard::nextWindow(30, -1));
        $this->assertSame(30, Dashboard::nextWindow(21, +1));
        $this->assertSame(1, Dashboard::nextWindow(1, -1));
        $this->assertSame(90, Dashboard::nextWindow(90, +1));
    }

    public function testUpdateWithRightArrowShiftsForward(): void
    {
        $store = new Store($this->storeDir);
        $day = new \DateTimeImmutable('2024-06-05');
        $d = new Dashboard($store, $day, 3, new Stats([], []));

        $msg = new KeyMsg(KeyType::Right);
        [$next, $cmd] = $d->update($msg);

        $this->assertNotSame($d, $next);
        $expected = $day->modify('+1 day');
        $this->assertEquals($expected, $next->endDay);
        $this->assertNull($cmd);
    }

    public function testUpdateWithRightArrowDoesNotExceedToday(): void
    {
        $store = new Store($this->storeDir);
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);
        $d = new Dashboard($store, $today, 3, new Stats([], []));

        $msg = new KeyMsg(KeyType::Right);
        [$next, $cmd] = $d->update($msg);

        // Should clamp to today, not go into the future
        $this->assertEquals($today, $next->endDay);
        $this->assertNull($cmd);
    }

    public function testUpdateWithOtherCharReturnsSelfNoCommand(): void
    {
        $d = $this->makeDashboard();
        $msg = new KeyMsg(KeyType::Char, 'a');

        [$next, $cmd] = $d->update($msg);

        $this->assertSame($d, $next);
        $this->assertNull($cmd);
    }

    public function testUpdateWithArrowUpReturnsSelfNoCommand(): void
    {
        $d = $this->makeDashboard();
        $msg = new KeyMsg(KeyType::Up);

        [$next, $cmd] = $d->update($msg);

        $this->assertSame($d, $next);
        $this->assertNull($cmd);
    }

    // ---- view() snapshot tests --------------------------------------------

    public function testViewReturnsRenderedOutput(): void
    {
        $d = $this->makeDashboardWithStats();
        $out = $d->view();

        // Snapshot: raw output contains expected structure markers
        $this->assertStringContainsString('CandyCodetime', $out);
        $this->assertStringContainsString('Daily activity', $out);
        $this->assertStringContainsString('no activity', $out);
        $this->assertStringContainsString('prev day', $out);
        $this->assertStringContainsString('next day', $out);
        $this->assertStringContainsString('reload', $out);
        $this->assertStringContainsString('quit', $out);
    }

    public function testViewWithHeartbeatsShowsProjectsAndLanguages(): void
    {
        $store = new Store($this->storeDir);
        $day = new \DateTimeImmutable('2024-06-07');
        $store->append(new Heartbeat($day->getTimestamp(), 'myproject', 'php', 'a.php', 120));
        $store->append(new Heartbeat($day->getTimestamp(), 'myproject', 'js', 'b.js', 60));

        $from = $day->modify('-6 days');
        $stats = Stats::compute($store->loadRange($from, $day), $from, $day);
        $d = new Dashboard($store, $day, 7, $stats);
        $out = $d->view();

        $this->assertStringContainsString('myproject', $out);
        $this->assertStringContainsString('php', $out);
        $this->assertStringContainsString('js', $out);
    }

    // ---- reload() tests ----------------------------------------------------

    public function testReloadLoadsDataFromStore(): void
    {
        $store = new Store($this->storeDir);
        $day = new \DateTimeImmutable('2024-06-07');
        $store->append(new Heartbeat($day->getTimestamp(), 'reload-test', 'php', 'a.php', 300));

        $d = new Dashboard($store, $day, 3, new Stats([], []));
        $reloaded = $d->reload();

        $this->assertNotSame($d, $reloaded);
        $this->assertSame($store, $reloaded->source);
        $this->assertEquals($day, $reloaded->endDay);
        $this->assertNotSame($d->stats, $reloaded->stats);
        $this->assertArrayHasKey('reload-test', $reloaded->stats->perProject());
    }

    public function testReloadWithEmptyStoreReturnsEmptyStats(): void
    {
        $store = new Store($this->storeDir);
        $day = new \DateTimeImmutable('2024-06-07');

        $d = new Dashboard($store, $day, 3, new Stats([], []));
        $reloaded = $d->reload();

        $this->assertSame([], $reloaded->stats->perProject());
        $this->assertSame([], $reloaded->stats->perLanguage());
    }

    // ---- private shift() edge cases via update() --------------------------

    public function testShiftDoesNotGoBeyondToday(): void
    {
        $store = new Store($this->storeDir);
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);
        $d = new Dashboard($store, $today, 7, new Stats([], []));

        // Shift left first
        $msgLeft = new KeyMsg(KeyType::Left);
        [$nextLeft, ] = $d->update($msgLeft);

        // Then shift right multiple times until we hit today
        $msgRight = new KeyMsg(KeyType::Right);
        [$nextRight, ] = $nextLeft->update($msgRight);

        // Should be clamped to today
        $this->assertEquals($today, $nextRight->endDay);
    }

    // ---- helper ------------------------------------------------------------

    private function makeDashboard(int $days = 7): Dashboard
    {
        $store = new Store($this->storeDir);
        $end = new \DateTimeImmutable('2024-06-07');
        return new Dashboard($store, $end, $days, new Stats([], []));
    }

    private function makeDashboardWithStats(int $days = 7): Dashboard
    {
        $store = new Store($this->storeDir);
        $end = new \DateTimeImmutable('2024-06-07');
        $from = $end->modify('-' . ($days - 1) . ' days');
        $stats = Stats::compute([], $from, $end);
        return new Dashboard($store, $end, $days, $stats);
    }

    // ---- cache invalidation via reload() -----------------------------------

    public function testReloadInvalidatesCache(): void
    {
        $store = new Store($this->storeDir);
        $day = new \DateTimeImmutable('2024-06-07');

        // Start dashboard (loads initial data) with 3-day range
        $d = Dashboard::start($store, $day, 3);

        // Append more data to disk within the same date range (2024-06-05 to 2024-06-07)
        $hb2 = new Heartbeat(
            $day->getTimestamp(),  // Same day as endDay (2024-06-07)
            'new-project',
            'go',
            'b.go',
            120,
        );
        $store->append($hb2);

        // After reload, the new data IS visible because reload() invalidates cache
        $reloaded = $d->reload();
        $this->assertArrayHasKey('new-project', $reloaded->stats->perProject());
    }

    public function testWindowSizeMsgStacksPanesWhenNarrow(): void
    {
        $store = new Store($this->storeDir);
        $end = new \DateTimeImmutable('2024-06-07');
        $stats = new Stats([], []);

        // Send a WindowSizeMsg with narrow width (< 56 threshold)
        $d = new Dashboard($store, $end, 7, $stats, 40);

        $msg = new \SugarCraft\Core\Msg\WindowSizeMsg(40, 24);
        [$next, ] = $d->update($msg);

        $this->assertSame(40, $next->width);
        $this->assertSame(24, $next->height, 'WindowSizeMsg->rows must reach the model');
        $out = $next->view();

        // When narrow, the view should stack panes (contains newline between projects and languages)
        $this->assertStringContainsString('Top projects', $out);
        $this->assertStringContainsString('By language', $out);
    }
    public function testDashboardReadsThroughTheSqliteBackendReader(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('sqlite3 extension not loaded');
        }
        $dbPath = $this->tmp . '/dash.db';
        $backend = new \SugarCraft\CodeTime\Storage\SqliteBackend($dbPath);
        $backend->insert(new Heartbeat(
            time: (new \DateTimeImmutable('today'))->setTime(9, 0)->getTimestamp(),
            project: 'from-sqlite', language: 'php', file: 'a.php', duration: 600,
        ));

        $d = Dashboard::start($backend);
        $this->assertSame($backend, $d->source);
        $this->assertStringContainsString('from-sqlite', $d->view());
    }
    public function testAColorProfileMsgLandsOnTheModelAndSurvivesNavigation(): void
    {
        $store = new Store(sys_get_temp_dir());
        $d = new Dashboard($store, new \DateTimeImmutable('2024-06-07'), 3, new Stats([], []), 80, null, 20);
        $this->assertNull($d->colorProfile);

        [$withProfile, $cmd] = $d->update(new \SugarCraft\Core\Msg\ColorProfileMsg(\SugarCraft\Core\Util\ColorProfile::Ascii));
        $this->assertNull($cmd);
        $this->assertInstanceOf(Dashboard::class, $withProfile);
        $this->assertSame(\SugarCraft\Core\Util\ColorProfile::Ascii, $withProfile->colorProfile);
        $this->assertSame(80, $withProfile->width);
        $this->assertSame(20, $withProfile->height, 'the colour leg must not drop the row budget');

        [$shifted, ] = $withProfile->update(new KeyMsg(KeyType::Right));
        $this->assertSame(\SugarCraft\Core\Util\ColorProfile::Ascii, $shifted->colorProfile);
        $this->assertSame(80, $shifted->width);

        [$resized, ] = $withProfile->update(new \SugarCraft\Core\Msg\WindowSizeMsg(60, 24));
        $this->assertSame(\SugarCraft\Core\Util\ColorProfile::Ascii, $resized->colorProfile);
        $this->assertSame(60, $resized->width);
    }
}

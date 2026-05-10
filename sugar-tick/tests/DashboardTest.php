<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Tests;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tick\Dashboard;
use SugarCraft\Tick\Heartbeat;
use SugarCraft\Tick\Stats;
use SugarCraft\Tick\Store;
use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase
{
    private string $tmp;
    private string $storeDir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sugar-tick-' . bin2hex(random_bytes(4));
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
        rmdir($this->tmp);
    }

    public function testConstructorStoresFields(): void
    {
        $store = new Store($this->storeDir);
        $endDay = new \DateTimeImmutable('2024-06-07');
        $stats = new Stats([], []);

        $d = new Dashboard($store, $endDay, 14, $stats);

        $this->assertSame($store, $d->store);
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
        $this->assertSame($store, $next->store);
        $this->assertEquals($day, $next->endDay);
        $this->assertNull($cmd);
    }

    public function testUpdateWithLeftArrowShiftsBack(): void
    {
        $store = new Store($this->storeDir);
        $day = new \DateTimeImmutable('2024-06-07');
        $d = new Dashboard($store, $day, 3, new Stats([], []));

        $msg = new KeyMsg(KeyType::Left);
        [$next, $cmd] = $d->update($msg);

        $this->assertNotSame($d, $next);
        $expected = $day->modify('-1 day');
        $this->assertEquals($expected, $next->endDay);
        $this->assertNull($cmd);
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
        $this->assertStringContainsString('SugarTick', $out);
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
        $this->assertSame($store, $reloaded->store);
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
}

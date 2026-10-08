<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Net;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\FocusGainedMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Dash\Foundation\NetAutoScale;
use SugarCraft\Top\Collect\Net;
use SugarCraft\Top\Collect\NetSnapshot;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Net\NetButtons;
use SugarCraft\Top\Panel\Net\NetPanel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeNet;
use SugarCraft\Top\Tests\Collect\Support\FixtureTree;
use SugarCraft\Top\View\FrameBuilder;

final class NetPanelTest extends TestCase
{
    private static function ctx(?Config $config = null, int $cols = 120, int $rows = 40): PanelContext
    {
        $config ??= Config::new();
        $layout = FrameBuilder::layout($cols, $rows, $config, 8);

        return new PanelContext($config, $layout, $layout->box('net'));
    }

    private static function feed(NetPanel $panel, int $ticks, ?PanelContext $ctx = null): NetPanel
    {
        $ctx ??= self::ctx();
        for ($i = 0; $i < $ticks; $i++) {
            $msg = ($panel->collect($ctx))();
            $next = $panel->update($msg, $ctx)->panel;
            self::assertInstanceOf(NetPanel::class, $next);
            $panel = $next;
        }

        return $panel;
    }

    private static function key(string $rune, bool $ctrl = false, bool $alt = false): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune, $alt, $ctrl);
    }

    private static function threeIfaces(): NetPanel
    {
        return self::feed(NetPanel::new(FakeNet::new(1.0)), 3);
    }

    // ---- sampling ------------------------------------------------------

    public function testCollectSamplesAndUpdateStoresTheSnapshotImmutably(): void
    {
        $panel = NetPanel::new(FakeNet::new());
        $this->assertSame('net', $panel->box());
        $this->assertNull($panel->snapshot());
        $this->assertNull($panel->selected());

        $msg = ($panel->collect(self::ctx()))();
        $this->assertInstanceOf(SampledMsg::class, $msg);
        $this->assertSame('net', $msg->box);
        $result = $panel->update($msg, self::ctx());
        $this->assertNull($result->cmd);
        $this->assertSame([], $result->set);
        $this->assertInstanceOf(NetPanel::class, $result->panel);
        $this->assertSame('eth0', $result->panel->selected());
        $this->assertNull($panel->snapshot(), 'update is immutable');
        $this->assertFalse($panel->modal(self::ctx()));
        $this->assertFalse($panel->capturesKey(self::key('b'), self::ctx()), 'net keys reach the panel through the broadcast');
    }

    public function testForeignSamplesAndOtherMsgsAreIgnored(): void
    {
        $panel = self::threeIfaces();
        [$snap, $next] = FakeNet::new()->sample();
        $this->assertSame($panel, $panel->update(new SampledMsg('mem', $snap, $next), self::ctx())->panel);
        $this->assertSame($panel, $panel->update(new FocusGainedMsg(), self::ctx())->panel);
    }

    public function testHistoryKeepsEveryInterfaceAndIsCapped(): void
    {
        $panel = self::feed(NetPanel::new(FakeNet::new(1.0)), 5);
        $this->assertCount(5, $panel->history(NetPanel::DOWNLOAD));
        $this->assertCount(5, $panel->history(NetPanel::UPLOAD, 'lo'));
        $this->assertSame([0, 0, 0, 0, 0], $panel->history(NetPanel::DOWNLOAD, 'wlan0'));

        $long = self::feed(NetPanel::new(FakeNet::new(1.0)), NetPanel::HISTORY + 3, self::ctx());
        $this->assertCount(NetPanel::HISTORY, $long->history(NetPanel::DOWNLOAD));
    }

    // ---- #1008: keep the last value on UNMEASURED ----------------------

    public function testUnmeasuredRateKeepsTheLastSpeedAndSkipsHistory(): void
    {
        $u = Sentinel::UNMEASURED;
        $source = ScriptedNetSource::of(
            new NetSnapshot(['eth0' => ScriptedNetSource::iface('eth0', $u, $u, 100, 50)], 'eth0'),
            new NetSnapshot(['eth0' => ScriptedNetSource::iface('eth0', 2048.0, 1024.0, 2148, 1074)], 'eth0'),
            new NetSnapshot(['eth0' => ScriptedNetSource::iface('eth0', $u, $u, 2148, 1074)], 'eth0'),
        );
        $panel = self::feed(NetPanel::new($source), 1);
        $this->assertSame([], $panel->history(NetPanel::DOWNLOAD), 'the first, unmeasured rate never reaches the graph');
        $this->assertSame(0, $panel->speed(NetPanel::DOWNLOAD));
        $this->assertTrue($panel->scale()->rescalePending(), 'no sample offered to the scaler');

        $panel = self::feed($panel, 2);
        $this->assertSame([2048], $panel->history(NetPanel::DOWNLOAD));
        $this->assertSame(2048, $panel->speed(NetPanel::DOWNLOAD), 'last measured value survives an UNMEASURED tick');
        $this->assertSame(1024, $panel->speed(NetPanel::UPLOAD));
        $this->assertSame(2148, $panel->total(NetPanel::DOWNLOAD));
    }

    public function testFailedReadKeepsThePreviousSnapshot(): void
    {
        $source = ScriptedNetSource::of(
            new NetSnapshot(['eth0' => ScriptedNetSource::iface('eth0', 10.0, 20.0, 10, 20)], 'eth0'),
            new NetSnapshot([], null),
        );
        $first = self::feed(NetPanel::new($source), 1);
        $after = self::feed($first, 1);
        $this->assertSame($first->snapshot(), $after->snapshot());
        $this->assertSame('eth0', $after->selected());
        $this->assertSame([10], $after->history(NetPanel::DOWNLOAD));

        $empty = self::feed(NetPanel::new(ScriptedNetSource::of(new NetSnapshot([], null))), 2);
        $this->assertNull($empty->snapshot());
        $this->assertNull($empty->selectedInterface());
    }

    // ---- iface pick policy ---------------------------------------------

    public function testPickPolicyWithTheRealCollector(): void
    {
        $tree = FixtureTree::copy();
        try {
            $t = 100.0;
            $net = Net::new($tree->paths(), static function () use (&$t): float {
                return $t += 1.0;
            });
            // wlan0 has the largest total but is down; eth0 (connected) beats lo.
            $panel = self::feed(NetPanel::new(CollectorSource::of($net)), 1);
            $this->assertSame('eth0', $panel->selected());

            // net_iface wins when it exists ...
            $pinned = self::feed(NetPanel::new(CollectorSource::of($net)), 1, self::ctx(Config::new()->with('net_iface', 'lo')));
            $this->assertSame('lo', $pinned->selected());
            // ... and is ignored when it does not.
            $missing = self::feed(NetPanel::new(CollectorSource::of($net)), 1, self::ctx(Config::new()->with('net_iface', 'tun9')));
            $this->assertSame('eth0', $missing->selected());

            // Nothing connected: highest total.
            $tree->remove('sys/class/net');
            $down = self::feed(NetPanel::new(CollectorSource::of(Net::new($tree->paths(), static fn (): float => 1.0))), 1);
            $this->assertSame('wlan0', $down->selected());
        } finally {
            $tree->destroy();
        }
    }

    public function testSelectionIsStickyAndRepicksWhenTheInterfaceVanishes(): void
    {
        $both = new NetSnapshot([
            'eth0' => ScriptedNetSource::iface('eth0', 1.0, 1.0, 10, 10),
            'wlan0' => ScriptedNetSource::iface('wlan0', 1.0, 1.0, 99, 99),
        ], 'eth0');
        $flipped = new NetSnapshot($both->interfaces, 'wlan0');
        $onlyWlan = new NetSnapshot(['wlan0' => $both->interfaces['wlan0']], 'wlan0');
        $panel = self::feed(NetPanel::new(ScriptedNetSource::of($both, $flipped, $onlyWlan, $both)), 2);
        $this->assertSame('eth0', $panel->selected(), 'a valid selection is never re-picked (btop only picks when unset/invalid)');

        $panel = self::feed($panel, 1);
        $this->assertSame('wlan0', $panel->selected());
        $this->assertTrue($panel->scale()->rescalePending() || $panel->scale()->rescaled, 'losing the interface rescales');
        $this->assertSame([], $panel->history(NetPanel::DOWNLOAD, 'eth0'), 'a vanished interface loses its history');

        $panel = self::feed($panel, 1);
        $this->assertSame('wlan0', $panel->selected(), 'the returning interface does not steal the selection');
    }

    public function testRuntimeNetIfaceOnlyAppliesWhenSelectionIsInvalid(): void
    {
        $panel = self::threeIfaces();
        $panel = self::feed($panel, 1, self::ctx(Config::new()->with('net_iface', 'lo')));
        $this->assertSame('eth0', $panel->selected());
    }

    // ---- keys ----------------------------------------------------------

    public function testBAndNCycleWithWrapAndAreNotPersisted(): void
    {
        $panel = self::threeIfaces();
        $this->assertSame('eth0', $panel->selected());

        $n = $panel->update(self::key('n'), self::ctx());
        $this->assertSame([], $n->set, 'btop never writes net_iface from b/n');
        $this->assertNull($n->cmd);
        $this->assertSame('wlan0', $n->panel->selected());
        $this->assertFalse($n->panel->scale()->rescalePending(), 'rescaled at once, not armed for the next tick');
        $this->assertTrue($n->panel->scale()->rescaled);
        $this->assertSame('lo', $n->panel->update(self::key('n'), self::ctx())->panel->selected(), 'wraps forward');

        $b = $panel->update(self::key('b'), self::ctx())->panel;
        $this->assertSame('lo', $b->selected());
        $this->assertSame('wlan0', $b->update(self::key('b'), self::ctx())->panel->selected(), 'wraps backward');
        $this->assertSame('eth0', $panel->selected(), 'immutable');
    }

    public function testZTogglesTheTotalsOffset(): void
    {
        $panel = self::threeIfaces();
        $rx = $panel->total(NetPanel::DOWNLOAD);
        $this->assertGreaterThan(0, $rx);
        $this->assertFalse($panel->zeroed());

        $z = $panel->update(self::key('z'), self::ctx());
        $this->assertSame([], $z->set);
        $zeroed = $z->panel;
        $this->assertTrue($zeroed->zeroed());
        $this->assertSame(0, $zeroed->total(NetPanel::DOWNLOAD));
        $this->assertSame(0, $zeroed->total(NetPanel::UPLOAD));

        $later = self::feed($zeroed, 1);
        $this->assertSame($later->snapshot()?->selected()?->rxTotal - $rx, $later->total(NetPanel::DOWNLOAD), 'counts from the press');
        $this->assertGreaterThan(0, $later->total(NetPanel::DOWNLOAD));

        $restored = $later->update(self::key('z'), self::ctx())->panel;
        $this->assertFalse($restored->zeroed());
        $this->assertSame($later->snapshot()?->selected()?->rxTotal, $restored->total(NetPanel::DOWNLOAD));
    }

    public function testZOffsetDropsWhenTheCounterRestarts(): void
    {
        $source = ScriptedNetSource::of(
            new NetSnapshot(['eth0' => ScriptedNetSource::iface('eth0', 1.0, 1.0, 5000, 4000)], 'eth0'),
            new NetSnapshot(['eth0' => ScriptedNetSource::iface('eth0', 1.0, 1.0, 100, 4500)], 'eth0'),
        );
        $panel = self::feed(NetPanel::new($source), 1);
        $panel = self::feed($panel->update(self::key('z'), self::ctx())->panel, 1);
        $this->assertSame(100, $panel->total(NetPanel::DOWNLOAD), 'offset > total: btop drops that direction\'s offset');
        $this->assertSame(500, $panel->total(NetPanel::UPLOAD));
        $this->assertTrue($panel->zeroed());
    }

    public function testAAndYFlipTheirOptionsSynchronouslyAndRescale(): void
    {
        $panel = self::threeIfaces();
        $a = $panel->update(self::key('a'), self::ctx());
        $this->assertSame(['net_auto' => false], $a->set);
        $this->assertTrue($a->panel->scale()->rescalePending(), 'autoscale now off: the rescale only stays armed');
        $on = $panel->update(self::key('a'), self::ctx(Config::new()->with('net_auto', false)));
        $this->assertSame(['net_auto' => true], $on->set);
        $this->assertFalse($on->panel->scale()->rescalePending(), 'autoscale back on: rescaled in the same update');

        $y = $panel->update(self::key('y'), self::ctx());
        $this->assertSame(['net_sync' => false], $y->set);
        $this->assertFalse($y->panel->scale()->rescalePending());
        $this->assertFalse($y->panel->scale()->sync, 'the rescale uses the flipped net_sync');
        $this->assertSame(['net_sync' => true], $panel->update(self::key('y'), self::ctx(Config::new()->with('net_sync', false)))->set);
    }

    public function testYUnsyncsTheCeilingsOnTheSameFrame(): void
    {
        $snap = new NetSnapshot(['eth0' => ScriptedNetSource::iface('eth0', 1e6, 50000.0, 10, 10)], 'eth0');
        $panel = self::feed(NetPanel::new(ScriptedNetSource::of($snap)), 7);
        $synced = Config::new();
        $this->assertSame(1300000, $panel->graphMax(NetPanel::UPLOAD, $synced), 'net_sync shares the download ceiling');

        $unsynced = $synced->with('net_sync', false);
        $after = $panel->update(self::key('y'), self::ctx())->panel;
        $this->assertInstanceOf(NetPanel::class, $after);
        $this->assertSame(1300000, $after->graphMax(NetPanel::DOWNLOAD, $unsynced));
        $this->assertSame(65000, $after->graphMax(NetPanel::UPLOAD, $unsynced), 'no tick needed');
    }

    /** @return iterable<string, array{KeyMsg}> */
    public static function ignoredKeys(): iterable
    {
        yield 'ctrl+b' => [self::key('b', ctrl: true)];
        yield 'alt+n' => [self::key('n', alt: true)];
        yield 'capital Z' => [self::key('Z')];
        yield 'other letter' => [self::key('x')];
        yield 'enter' => [new KeyMsg(KeyType::Enter)];
    }

    #[DataProvider('ignoredKeys')]
    public function testOtherKeysAreIgnored(KeyMsg $key): void
    {
        $panel = self::threeIfaces();
        $result = $panel->update($key, self::ctx());
        $this->assertSame($panel, $result->panel);
        $this->assertSame([], $result->set);
    }

    public function testKeysAreIgnoredWhileHiddenOrBeforeTheFirstSample(): void
    {
        $panel = self::threeIfaces();
        $hidden = new PanelContext(Config::new());
        foreach (NetPanel::KEYS as $rune) {
            $this->assertSame($panel, $panel->update(self::key($rune), $hidden)->panel, "$rune while hidden");
            $this->assertSame([], $panel->update(self::key($rune), $hidden)->set);
        }
        $fresh = NetPanel::new(FakeNet::new());
        foreach (NetPanel::KEYS as $rune) {
            $this->assertSame($fresh, $fresh->update(self::key($rune), self::ctx())->panel, "$rune before a sample");
        }
    }

    // ---- mouse ---------------------------------------------------------

    private static function click(int $x, int $y, MouseButton $button = MouseButton::Left, bool $shift = false): MouseMsg
    {
        return new MouseMsg($x, $y, $button, MouseAction::Press, $shift);
    }

    public function testClickingEachTitleButtonActsLikeItsKey(): void
    {
        $ctx = self::ctx();
        $box = $ctx->box;
        $this->assertNotNull($box);
        $panel = self::threeIfaces();
        $targets = NetButtons::targets($box->width, NetPanel::ifaceWidth('eth0'));
        $this->assertSame(['b', 'n', 'z', 'a', 'y'], array_keys($targets));

        foreach ($targets as $key => [$at, $cells]) {
            foreach ([$at, $at + $cells - 1] as $col) {
                // 1-based terminal cell of local column $col on the title row.
                $msg = self::click($box->x + $col + 1, $box->y + 1);
                $this->assertSame($key, $panel->clickedButton($msg, $ctx), "column $col");
                $viaMouse = $panel->update($msg, $ctx);
                $viaKey = $panel->update(self::key($key), $ctx);
                $this->assertSame($viaKey->set, $viaMouse->set, $key);
                $this->assertSame($viaKey->panel->selected(), $viaMouse->panel->selected(), $key);
                $this->assertSame($viaKey->panel->zeroed(), $viaMouse->panel->zeroed(), $key);
            }
        }
    }

    public function testClicksOffTheButtonsOrNotBareLeftPressesDoNothing(): void
    {
        $ctx = self::ctx();
        $box = $ctx->box;
        $this->assertNotNull($box);
        $panel = self::threeIfaces();
        [$at] = NetButtons::targets($box->width, 4)['n'];
        $x = $box->x + $at + 1;

        $this->assertNull($panel->clickedButton(self::click($x, $box->y + 2), $ctx), 'row below the title');
        $this->assertNull($panel->clickedButton(self::click($box->x + 3, $box->y + 1), $ctx), 'the net title itself');
        $this->assertNull($panel->clickedButton(self::click($x, $box->y + 1, MouseButton::Right), $ctx));
        $this->assertNull($panel->clickedButton(self::click($x, $box->y + 1, shift: true), $ctx));
        $this->assertNull($panel->clickedButton(new MouseMsg($x, $box->y + 1, MouseButton::Left, MouseAction::Release), $ctx));
        $this->assertNull($panel->clickedButton(self::click($x, $box->y + 1), new PanelContext(Config::new())), 'hidden');
        $this->assertSame($panel, $panel->update(self::click($x, $box->y + 2), $ctx)->panel);
    }

    public function testNarrowBoxesDropTheAutoAndSyncTargets(): void
    {
        $this->assertSame(['b', 'n', 'z'], array_keys(NetButtons::targets(30, 4)));
        $this->assertSame(['b', 'n', 'z', 'a'], array_keys(NetButtons::targets(31, 4)));
        $this->assertSame(['b', 'n', 'z', 'a', 'y'], array_keys(NetButtons::targets(38, 4)));
        $this->assertFalse(NetButtons::ipFits(54, 4, 14));
        $this->assertTrue(NetButtons::ipFits(54, 4, 13));
        $this->assertFalse(NetButtons::ipFits(200, 4, 0));
    }

    // ---- autoscale -----------------------------------------------------

    /**
     * Ceilings after every tick, worked by hand through btop's law
     * (btop_collect.cpp:2994-3009, 3062-3087): a counter reaching 5
     * rescales to max(avg(last 5) x 1.3 fast | x 3.0 slow, 10 KiB); the
     * first offer always rescales.
     *
     * @return iterable<string, array{bool, list<array{0: float, 1: float}>, list<int>, list<int>}>
     */
    public static function hysteresis(): iterable
    {
        $t = static fn (float $d, float $u, int $n): array => array_fill(0, $n, [$d, $u]);
        yield 'first sample rescales, fifth fast sample grows' => [
            false,
            [[100000.0, 0.0], ...$t(200000.0, 0.0, 5)],
            [130000, 130000, 130000, 130000, 130000, 260000],
            array_fill(0, 6, NetAutoScale::FLOOR),
        ];
        yield 'a lull cancels one fast count' => [
            false,
            [[100000.0, 0.0], ...$t(200000.0, 0.0, 4), [1000.0, 0.0], ...$t(200000.0, 0.0, 2)],
            [...array_fill(0, 7, 130000), 208260],
            array_fill(0, 8, NetAutoScale::FLOOR),
        ];
        yield 'a moderate dip never counts' => [
            false,
            [[100000.0, 0.0], ...$t(50000.0, 0.0, 10)],
            array_fill(0, 11, 130000),
            array_fill(0, 11, NetAutoScale::FLOOR),
        ];
        yield 'fifth slow sample shrinks to the floor' => [
            false,
            [[100000.0, 0.0], ...$t(1000.0, 0.0, 5)],
            [130000, 130000, 130000, 130000, 130000, NetAutoScale::FLOOR],
            array_fill(0, 6, NetAutoScale::FLOOR),
        ];
        yield 'slow rescale x3 above the floor' => [
            false,
            [[1000000.0, 0.0], ...$t(50000.0, 0.0, 5)],
            [1300000, 1300000, 1300000, 1300000, 1300000, 150000],
            array_fill(0, 6, NetAutoScale::FLOOR),
        ];
        yield 'the floor never shrinks' => [
            false,
            [[5000.0, 0.0], ...$t(100.0, 0.0, 6)],
            array_fill(0, 7, NetAutoScale::FLOOR),
            array_fill(0, 7, NetAutoScale::FLOOR),
        ];
        yield 'net_sync shares the faster direction\'s ceiling' => [
            true,
            $t(100000.0, 400000.0, 6),
            [130000, 130000, 130000, 130000, 130000, 520000],
            [130000, 130000, 130000, 130000, 130000, 520000],
        ];
    }

    /**
     * @param list<array{0: float, 1: float}> $rates
     * @param list<int> $down
     * @param list<int> $up
     */
    #[DataProvider('hysteresis')]
    public function testAutoscaleHysteresisTable(bool $sync, array $rates, array $down, array $up): void
    {
        $ctx = self::ctx(Config::new()->with('net_sync', $sync));
        $panel = NetPanel::new(ScriptedNetSource::rates($rates));
        foreach ($rates as $i => $_) {
            $panel = self::feed($panel, 1, $ctx);
            $this->assertSame($down[$i], $panel->graphMax(NetPanel::DOWNLOAD, $ctx->config), "download after tick $i");
            $this->assertSame($up[$i], $panel->graphMax(NetPanel::UPLOAD, $ctx->config), "upload after tick $i");
        }
    }

    public function testNetAutoOffUsesTheFixedMebibitCeilingsAndFreezesTheScaler(): void
    {
        $config = Config::new()->with('net_auto', false)->with('net_download', 100)->with('net_upload', 8);
        $panel = self::feed(NetPanel::new(ScriptedNetSource::rates(array_fill(0, 3, [5e6, 5e6]))), 3, self::ctx($config));
        $this->assertSame(100 * 1024 * 1024 / 8, $panel->graphMax(NetPanel::DOWNLOAD, $config));
        $this->assertSame(1024 * 1024, $panel->graphMax(NetPanel::UPLOAD, $config));
        $this->assertTrue($panel->scale()->rescalePending(), 'nothing offered while net_auto is off');
        $this->assertSame(NetAutoScale::FLOOR, $panel->graphMax(NetPanel::DOWNLOAD, Config::new()), 'never below the floor before the first rescale');
    }

    public function testInterfaceSwitchRescalesFromTheNewInterfacesHistory(): void
    {
        $snap = new NetSnapshot([
            'eth0' => ScriptedNetSource::iface('eth0', 1e6, 1e6, 10, 10),
            'lo' => ScriptedNetSource::iface('lo', 50000.0, 20000.0, 5, 5),
        ], 'eth0');
        $panel = self::feed(NetPanel::new(ScriptedNetSource::of($snap)), 7);
        $this->assertSame(1300000, $panel->graphMax(NetPanel::DOWNLOAD, Config::new()));

        $switched = $panel->update(self::key('n'), self::ctx())->panel;
        $this->assertInstanceOf(NetPanel::class, $switched);
        $this->assertSame('lo', $switched->selected());
        // btop runs Net::collect(no_update) right after the key: the NEW
        // interface's deque is averaged (lo's 50000 x 1.3) before any new
        // tick, and net_sync (default on) copies it onto upload.
        $this->assertSame(65000, $switched->graphMax(NetPanel::DOWNLOAD, Config::new()));
        $this->assertSame(65000, $switched->graphMax(NetPanel::UPLOAD, Config::new()));
        $this->assertSame(65000, self::feed($switched, 1)->graphMax(NetPanel::DOWNLOAD, Config::new()), 'and it holds on the next tick');

        $fixed = $panel->update(self::key('n'), self::ctx(Config::new()->with('net_auto', false)))->panel;
        $this->assertInstanceOf(NetPanel::class, $fixed);
        $this->assertTrue($fixed->scale()->rescalePending(), 'net_auto off: nothing to rescale yet');
    }
}

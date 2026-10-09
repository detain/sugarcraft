<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Collect\Ipmi\IpmiReader;
use SugarCraft\Top\Collect\Ipmi\IpmiSnapshot;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\DataTickMsg;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\IpmiPanel;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Source\Fake\FakeIpmi;
use SugarCraft\Top\Source\Fake\FakeIpmiCaptures;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\BorderFlow;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Rect;

use function React\Promise\resolve;

/**
 * The ipmi box end to end through the App: the band under the cpu box
 * (and the gpu grid), beside the VM dashboard, `I` and the title button,
 * sampling only while shown, presets and the size rule.
 */
final class AppIpmiTest extends TestCase
{
    protected function setUp(): void
    {
        T::reset();
    }

    public function testTheBandSitsUnderTheCpuBoxAndTheBoxesBelowKeepTheirMinimums(): void
    {
        $layout = FrameBuilder::layout(120, 40, Config::new()->with('shown_boxes', 'cpu mem net proc ipmi'), 8);
        $cpu = $layout->box('cpu');
        $this->assertNotNull($cpu);
        $this->assertTrue($layout->box('ipmi')?->equals(Rect::new(0, $cpu->bottom(), 120, 11)), '28% of 40 rows');
        $this->assertSame(['cpu', 'ipmi', 'mem', 'net', 'proc'], array_keys($layout->ordered()));
        $this->assertSame($cpu->bottom() + 11, $layout->box('mem')?->y);
        $this->assertSame($cpu->bottom() + 11, $layout->box('proc')?->y);
        $this->assertSame(40, $layout->box('net')?->bottom());
        $this->assertGreaterThanOrEqual(FrameBuilder::MINIMUMS['mem'][1], $layout->box('mem')?->height);
        $this->assertGreaterThanOrEqual(FrameBuilder::MINIMUMS['net'][1], $layout->box('net')?->height);
        $this->assertGreaterThanOrEqual(FrameBuilder::MINIMUMS['proc'][1], $layout->box('proc')?->height);

        $tall = FrameBuilder::layout(200, 60, Config::new()->with('shown_boxes', 'cpu mem net proc ipmi'), 8);
        $this->assertSame(16, $tall->box('ipmi')?->height, 'the ceiling');

        $alone = FrameBuilder::layout(120, 40, Config::new()->with('shown_boxes', 'cpu ipmi'), 8);
        $this->assertSame(40, $alone->box('ipmi')?->bottom(), 'alone with cpu it takes the rest');

        $bottom = FrameBuilder::layout(120, 40, Config::new()->with('shown_boxes', 'cpu proc ipmi')->with('cpu_bottom', true), 8);
        $this->assertSame(0, $bottom->box('ipmi')?->y, 'cpu_bottom: the band on top');
        $this->assertSame($bottom->box('ipmi')?->bottom(), $bottom->box('proc')?->y);

        // The gpu grid stays between the cpu box and the band.
        $app = self::app(config: Config::new()->with('shown_boxes', 'cpu gpu0 ipmi proc'));
        $gpu = $app->layout?->box('gpu0');
        $this->assertNotNull($gpu);
        $this->assertSame($gpu->bottom(), $app->layout?->box('ipmi')?->y);
    }

    public function testTheBandStaysBesideTheVmDashboard(): void
    {
        $layout = FrameBuilder::layout(120, 40, Config::new()->with('shown_boxes', 'cpu mem net proc vms ipmi'), 8);
        $this->assertSame(['cpu', 'ipmi', 'vms'], array_keys($layout->ordered()), 'the takeover hides mem/net/proc, not the band');
        $this->assertSame($layout->box('ipmi')?->bottom(), $layout->box('vms')?->y);
        $this->assertSame(40, $layout->box('vms')?->bottom());
        $this->assertGreaterThanOrEqual(FrameBuilder::MINIMUMS['vms'][1], $layout->box('vms')?->height);
    }

    public function testMinimumSizeAndFamily(): void
    {
        [$w, $h] = FrameBuilder::minSize(['cpu', 'mem', 'net', 'proc']);
        $this->assertSame([$w, $h + 6], FrameBuilder::minSize(['cpu', 'mem', 'net', 'proc', 'ipmi']));
        $this->assertSame([40, 6], FrameBuilder::minSize(['ipmi']));
        $layout = FrameBuilder::layout(120, 40, Config::new()->with('shown_boxes', 'cpu ipmi'), 8);
        $this->assertSame('net', BorderFlow::family('ipmi', $layout));
        $this->assertSame(FrameBuilder::IPMI_FAMILY, BorderFlow::family('ipmi', $layout));
    }

    public function testShiftIToggles(): void
    {
        $app = self::app();
        [$on, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'I'));
        $this->assertContains('ipmi', $on->config->shownBoxes());
        $this->assertNotNull($on->layout?->box('ipmi'));
        $this->assertContains('ipmi', self::boxes($cmd), 'sampled at once');
        [$off] = $on->update(new KeyMsg(KeyType::Char, 'I'));
        $this->assertNotContains('ipmi', $off->config->shownBoxes());
        [$io] = $app->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertNotContains('ipmi', $io->config->shownBoxes(), '`i` stays io_mode');
    }

    public function testSampledOnlyWhileShown(): void
    {
        $this->assertNotContains('ipmi', self::ticked(self::app()), 'hidden: zero cost');
        $shown = self::app(config: Config::new()->with('shown_boxes', 'cpu mem net proc ipmi'));
        $this->assertContains('ipmi', self::ticked($shown));
        $panel = $shown->panelFor('ipmi');
        $this->assertInstanceOf(IpmiPanel::class, $panel);
        $this->assertSame('ESC8000A-E12', $panel->data()->info?->machine());
        $this->assertStringContainsString('ESC8000A-E12', implode("\n", $shown->surface()?->plainLines() ?? []));
    }

    public function testNotActingBehindTheSizeNotice(): void
    {
        $small = self::app(70, 20);
        $this->assertStringContainsString('Terminal size too small', implode("\n", $small->surface()?->plainLines() ?? []));
        [$after] = $small->update(new KeyMsg(KeyType::Char, 'I'));
        $this->assertSame($small->config->shownBoxes(), $after->config->shownBoxes(), 'like `x`, not in the resize loop');
    }

    public function testAToggleThatWouldNotFitOpensTheSizeError(): void
    {
        $app = self::app(80, 26);
        [$refused] = $app->update(new KeyMsg(KeyType::Char, 'I'));
        $this->assertNotContains('ipmi', $refused->config->shownBoxes(), '80x26 fits the four boxes, not the band too');
        $this->assertNotNull($refused->overlay());
    }

    public function testPresetsMayNameTheBox(): void
    {
        $app = self::app(config: Config::new()->with('presets', 'cpu:0:default,ipmi:0:default,proc:0:default'));
        $next = $app;
        for ($i = 0; $i < 2 && $next->config->shownBoxes() !== ['cpu', 'ipmi', 'proc']; $i++) {
            [$next] = $next->update(new KeyMsg(KeyType::Char, 'p'));
        }
        $this->assertSame(['cpu', 'ipmi', 'proc'], $next->config->shownBoxes());
        $this->assertNotNull($next->layout?->box('ipmi'));
    }

    public function testTheTitleButtonOnlyOnABmcHost(): void
    {
        $this->assertArrayNotHasKey('I', self::app()->chromeButtons(), 'no BMC device: no button');
        $app = self::app(host: Harness::host()->withBmcHost(true));
        $zone = $app->chromeButtons()['I'] ?? null;
        $this->assertNotNull($zone);
        $ctr = $app->chromeButtons()['x'];
        $this->assertGreaterThan($ctr[0] + $ctr[2], $zone[0]);
        $row = $app->surface()?->plainLines()[0] ?? '';
        $this->assertSame('IPMI', mb_substr($row, $zone[0], 4));

        [$on] = $app->update(new MouseMsg($zone[0] + 2, $zone[1] + 1, MouseButton::Left, MouseAction::Press));
        $this->assertContains('ipmi', $on->config->shownBoxes(), 'the button toggles the box');
        $this->assertArrayHasKey('I', self::app(config: Config::new()->with('shown_boxes', 'cpu mem net proc ipmi'))->chromeButtons(), 'shown: the button stays');
        $narrow = self::app(80, 40, null, Harness::host()->withBmcHost(true));
        $this->assertArrayNotHasKey('I', $narrow->chromeButtons(), 'no room before the clock');
    }

    public function testHostDetectsTheDeviceNode(): void
    {
        $root = sys_get_temp_dir() . '/candy-top-bmc-' . bin2hex(random_bytes(4));
        mkdir($root . '/dev', 0777, true);
        $this->assertFalse(HostInfo::bmcPresent($root));
        touch($root . '/dev/ipmi0');
        $this->assertTrue(HostInfo::bmcPresent($root));
        unlink($root . '/dev/ipmi0');
        rmdir($root . '/dev');
        rmdir($root);
        $this->assertTrue(Harness::host()->withBmcHost(true)->bmcHost);
        $this->assertTrue(Harness::host()->withBmcHost(true)->withVmHost(true)->bmcHost, 'withVmHost keeps the BMC flag');
    }

    /**
     * Hidden while a round is in flight, then shown again: no second child
     * while hidden (no poll), the late round still lands (SampledMsg to a
     * hidden panel) without harm, and re-showing samples at once and paints
     * the data.
     */
    public function testHiddenMidRoundThenShownAgain(): void
    {
        $deferred = new Deferred();
        $reader = new class ($deferred) implements IpmiReader {
            public int $polls = 0;

            public function __construct(private readonly Deferred $first)
            {
            }

            public function poll(int $slowMs): ?PromiseInterface
            {
                return ++$this->polls === 1 ? $this->first->promise() : resolve(FakeIpmi::fromCaptures(FakeIpmiCaptures::skynet2())->sample()[0]);
            }

            public function sample(): array
            {
                return [IpmiSnapshot::starting(), $this];
            }
        };
        $config = Config::new()->with('shown_boxes', 'cpu mem net proc ipmi');
        $host = Harness::host();
        $panels = ['ipmi' => IpmiPanel::new($reader)] + Panels::standard($host, $config, true);
        $app = App::start($config, ThemeConfig::new(), $host, $panels, static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0), ColorProfile::TrueColor);
        [$app] = $app->update(new WindowSizeMsg(120, 40));
        $pending = array_values(array_filter(Cmds::run($app->init()), static fn ($m): bool => $m instanceof AsyncCmd));
        $this->assertSame(1, $reader->polls);
        $this->assertCount(1, $pending, 'the round is in flight');

        [$hidden] = $app->update(new KeyMsg(KeyType::Char, 'I'));
        $this->assertNull($hidden->layout?->box('ipmi'));
        [$hidden] = $hidden->update(new DataTickMsg($hidden->generation));
        $this->assertSame(1, $reader->polls, 'hidden: never polled');

        $late = null;
        $pending[0]->promise->then(static function ($m) use (&$late): void {
            $late = $m;
        });
        $deferred->resolve(FakeIpmi::fromCaptures(FakeIpmiCaptures::skynet2())->sample()[0]);
        $this->assertInstanceOf(SampledMsg::class, $late);
        [$hidden] = $hidden->update($late);
        $this->assertNull($hidden->layout?->box('ipmi'), 'a late round does not re-show the box');

        [$shown, $cmd] = $hidden->update(new KeyMsg(KeyType::Char, 'I'));
        $shown = self::settle($shown, $cmd);
        $this->assertSame(2, $reader->polls, 're-shown: sampled at once');
        $this->assertStringContainsString('ESC8000A-E12', implode("\n", $shown->surface()?->plainLines() ?? []));
    }

    /** @return list<string> */
    private static function boxes(?\Closure $cmd): array
    {
        $boxes = array_map(static fn (SampledMsg $m): string => $m->box, Cmds::of(SampledMsg::class, $cmd));
        sort($boxes);

        return array_values(array_unique($boxes));
    }

    /** @return list<string> */
    private static function ticked(App $app): array
    {
        [, $cmd] = $app->update(new DataTickMsg($app->generation));

        return self::boxes($cmd);
    }

    private static function app(int $cols = 120, int $rows = 40, ?Config $config = null, ?HostInfo $host = null): App
    {
        $config ??= Config::new();
        $host ??= Harness::host();
        $panels = Panels::standard($host, $config, true);
        $app = App::start($config, ThemeConfig::new(), $host, $panels, static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0), ColorProfile::TrueColor);
        [$app] = $app->update(new WindowSizeMsg($cols, $rows));
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

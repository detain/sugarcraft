<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Top\Collect\FreeBsd\Battery;
use SugarCraft\Top\Collect\FreeBsd\DiskIo;
use SugarCraft\Top\Collect\FreeBsd\Mounts;
use SugarCraft\Top\Collect\FreeBsd\Net;
use SugarCraft\Top\Collect\FreeBsd\ProcList;
use SugarCraft\Top\Collect\FreeBsd\ReplayProbe;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\MemPanel;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Source\Samples;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\DeferredProbe;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\View\FrameBuilder;

/**
 * S-P02 (2/3 and 3/3): the net / disk / mount / proc / battery collectors
 * and their panels on the replay pump: the blocking result, no blocking
 * child wait, and an AsyncCmd instead of a stalled update path.
 */
final class ReplayProbeFamilyTest extends TestCase
{
    private static function await(PromiseInterface $promise): mixed
    {
        $loop = Loop::get();
        $done = false;
        $value = null;
        $promise->then(static function ($v) use (&$done, &$value, $loop): void {
            $done = true;
            $value = $v;
            $loop->stop();
        });
        $guard = $loop->addTimer(5.0, static fn () => $loop->stop());
        if (!$done) {
            $loop->run();
        }
        $loop->cancelTimer($guard);
        self::assertTrue($done, 'settled inside 5 s');

        return $value;
    }

    private static function netProbe(): FixtureProbe
    {
        return new FixtureProbe('', [
            'netstat' => [FixtureProbe::fixture('netstat-ibn.txt'), FixtureProbe::fixture('netstat-ibn-next.synthetic.txt')],
            'ifconfig' => FixtureProbe::fixture('ifconfig-a.synthetic.txt'),
        ], [100.0, 102.0]);
    }

    public function testNetRatesAcrossTwoAsyncSamplesEqualTheBlockingOnes(): void
    {
        $sync = self::netProbe();
        [, $syncNet] = Net::new($sync)->sample();
        $sync->advance();
        [$expected] = $syncNet->sample();

        $slow = new DeferredProbe(self::netProbe());
        [, $net] = self::await(Net::new(ReplayProbe::over($slow))->sampleAsync());
        $slow->advance();
        [$snap] = self::await($net->sampleAsync());

        $this->assertEquals($expected, $snap);
        $this->assertSame(0, $slow->blocked);
        $this->assertSame(['netstat', '-i', '-b', '-n', '-W'], $slow->launched[0]);
        $this->assertSame(['ifconfig', '-a'], $slow->launched[1], 'ifconfig is read once netstat answered: a dependent round');
    }

    public function testMountsAndDiskIoMatchTheirBlockingResults(): void
    {
        $make = static fn (): FixtureProbe => new FixtureProbe('', [
            'mount' => FixtureProbe::fixture('mount-p.txt'),
            'iostat' => FixtureProbe::fixture('iostat-xI.synthetic.txt'),
        ], spaces: ['/' => [942033884 * 1024, 488713596 * 1024], '/dev' => [1024, 1024]]);

        $slow = new DeferredProbe($make());
        [$mounts] = self::await(Mounts::new(ReplayProbe::over($slow))->sampleAsync());
        [$io] = self::await(DiskIo::new(ReplayProbe::over($slow))->sampleAsync());

        $this->assertEquals(Mounts::new($make())->sample()[0], $mounts);
        $this->assertEquals(DiskIo::new($make())->sample()[0], $io);
        $this->assertSame(0, $slow->blocked);
    }

    public function testProcListAndBatteryMatchTheirBlockingResults(): void
    {
        $makeProc = static fn (): FixtureProbe => new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'), ['ps' => FixtureProbe::fixture('ps-root.synthetic.txt')]);
        $slow = new DeferredProbe($makeProc());
        $lookup = static fn (int $uid): ?string => 'u' . $uid;
        [$procs] = self::await(ProcList::new(ReplayProbe::over($slow), false, false, $lookup)->sampleAsync());

        $this->assertInstanceOf(ProcSnapshot::class, $procs);
        $this->assertEquals(ProcList::new($makeProc(), false, false, $lookup)->sample()[0], $procs);
        $this->assertSame(0, $slow->blocked);

        $makeBat = static fn (): FixtureProbe => new FixtureProbe(FixtureProbe::fixture('sysctl-battery-discharging.synthetic.txt'));
        $slowBat = new DeferredProbe($makeBat());
        [$bat] = self::await(Battery::new(ReplayProbe::over($slowBat))->sampleAsync());
        $this->assertEquals(Battery::new($makeBat())->sample()[0], $bat);
        $this->assertSame(0, $slowBat->blocked);
    }

    public function testMemPanelAndProcPanelDeliverThroughAnAsyncCmdOnAReplayProbe(): void
    {
        $slow = new DeferredProbe(new FixtureProbe(
            FixtureProbe::fixture('sysctl-reference.txt'),
            ['swapinfo' => FixtureProbe::fixture('swapinfo-k.txt'), 'ps' => FixtureProbe::fixture('ps-root.synthetic.txt'), 'mount' => FixtureProbe::fixture('mount-p.txt'), 'iostat' => FixtureProbe::fixture('iostat-xI.synthetic.txt')],
            spaces: ['/' => [100, 40]],
        ));
        $platform = Platform::for('FreeBSD', ReplayProbe::over($slow));

        $mem = MemPanel::standard(Config::new(), false, $platform);
        $msg = ($mem->collect(PanelPaint::context('mem', Config::new(), null)))();
        $this->assertInstanceOf(AsyncCmd::class, $msg);
        $sampled = self::await($msg->promise);
        $this->assertInstanceOf(SampledMsg::class, $sampled);
        $this->assertSame('mem', $sampled->box);
        $this->assertInstanceOf(Samples::class, $sampled->snapshot);

        $config = Config::new();
        $layout = FrameBuilder::layout(120, 40, $config, 8);
        $proc = Panels::standard(PanelPaint::host(), $config, false, $platform)['proc'];
        $msg = ($proc->collect(new PanelContext($config, $layout, $layout->box('proc'))))();
        $this->assertInstanceOf(AsyncCmd::class, $msg);
        $sampled = self::await($msg->promise);
        $this->assertInstanceOf(SampledMsg::class, $sampled);
        $this->assertInstanceOf(ProcSnapshot::class, $sampled->snapshot);
        $this->assertSame(0, $slow->blocked, 'no panel waited on a child from collect()');
    }

    public function testNetPanelDeliversThroughAnAsyncCmdOnAReplayProbe(): void
    {
        $slow = new DeferredProbe(self::netProbe());
        $platform = Platform::for('FreeBSD', ReplayProbe::over($slow));
        $net = Panels::standard(PanelPaint::host(), Config::new(), false, $platform)['net'];

        $msg = ($net->collect(PanelPaint::context('net', Config::new(), null)))();

        $this->assertInstanceOf(AsyncCmd::class, $msg);
        $sampled = self::await($msg->promise);
        $this->assertInstanceOf(SampledMsg::class, $sampled);
        $this->assertSame('net', $sampled->box);
        $this->assertSame(0, $slow->blocked);
    }
}

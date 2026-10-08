<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Gpu;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Collect\Gpu\Settled;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Collect\GpuProcess;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\DataTickMsg;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\CpuPanel;
use SugarCraft\Top\Panel\Gpu\GpuFeed;
use SugarCraft\Top\Panel\GpuPanel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelResult;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Panel\Proc\ProcGpuSample;
use SugarCraft\Top\Panel\ProcPanel;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeCpu;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Source\Samples;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\FrameBuilder;

/**
 * The three GPU consumers on the shared feed while nvidia-smi is in
 * flight (loop-driven, so a sample can be pending): the cpu box delivers
 * its cpu part at once and the GPU part later, the gpu boxes resolve when
 * it lands, the proc box never waits unless `g` needs fresh values — and
 * the App samples the accelerators once per tick for all of them.
 */
final class GpuFeedConsumersTest extends TestCase
{
    /** A live-shaped collector whose every sample is held until released. */
    private function held(): object
    {
        return new class () {
            /** @var list<Deferred<null>> */
            public array $pending = [];

            public int $samples = 0;

            public bool $processes = false;

            public function sample(): array
            {
                return [$this->snapshot(), $this];
            }

            public function sampleAsync(): PromiseInterface
            {
                $this->samples++;
                $d = new Deferred();
                $this->pending[] = $d;
                $snap = $this->snapshot();

                return $d->promise()->then(fn (): array => [$snap, $this]);
            }

            public function release(): void
            {
                $now = $this->pending;
                $this->pending = [];
                foreach ($now as $d) {
                    $d->resolve(null);
                }
            }

            private function snapshot(): GpuSnapshot
            {
                return new GpuSnapshot(
                    [new GpuDevice(0, 'Held GPU', 55.0, 1 << 30, 8 << 30, 60.0, 100.0, uuid: 'GPU-h')],
                    [new GpuProcess(5133, 0, 'GPU-h', 1 << 30, 40.0)],
                );
            }
        };
    }

    public function testTheCpuBoxDeliversItsCpuPartWithoutWaitingForTheGpu(): void
    {
        $held = $this->held();
        $panel = CpuPanel::new(FakeCpu::new(4), null, null, CollectorSource::of($held));
        $ctx = new PanelContext(Config::new()->with('show_gpu_info', 'On')->with('check_temp', false)->with('show_cpu_freq', false)->with('show_core_freq', 'off'));

        $msg = ($panel->collect($ctx))();
        $this->assertInstanceOf(BatchMsg::class, $msg, 'nvidia-smi in flight: two deliveries');
        $now = ($msg->cmds[0])();
        $this->assertInstanceOf(SampledMsg::class, $now);
        $this->assertInstanceOf(Samples::class, $now->snapshot);
        $this->assertNotNull($now->snapshot->get('cpu'));
        $this->assertNull($now->snapshot->get('gpu'));
        $later = ($msg->cmds[1])();
        $this->assertInstanceOf(AsyncCmd::class, $later);
        $this->assertFalse(Settled::peek($later->promise)[0]);

        $panel = $panel->update($now, $ctx)->panel;
        $this->assertInstanceOf(CpuPanel::class, $panel);
        $this->assertSame(4, $panel->coreCount());
        $this->assertSame([], $panel->gpus());

        $held->release();
        $gpuMsg = Settled::value($later->promise);
        $this->assertInstanceOf(SampledMsg::class, $gpuMsg);
        $this->assertSame('cpu', $gpuMsg->box);
        $this->assertSame(['gpu'], array_keys($gpuMsg->snapshot->snapshots), 'the GPU part alone');
        $panel = $panel->update($gpuMsg, $ctx)->panel;
        $this->assertInstanceOf(CpuPanel::class, $panel);
        $this->assertSame('Held GPU', $panel->gpus()[0]->name);
        $this->assertSame(4, $panel->coreCount(), 'the cpu part is untouched');

        // Between queries the feed settles at once: one SampledMsg with both parts.
        $msg = ($panel->collect($ctx))();
        $this->assertInstanceOf(BatchMsg::class, $msg, 'this collector holds every sample');
        $held->release();
    }

    public function testTheCpuBoxSamplesBothPartsInOneMessageWhenTheFeedIsSettled(): void
    {
        $panel = CpuPanel::new(FakeCpu::new(4), null, null, \SugarCraft\Top\Source\Fake\FakeGpu::new(1, 0));
        $ctx = new PanelContext(Config::new()->with('show_gpu_info', 'On')->with('check_temp', false));
        $msg = ($panel->collect($ctx))();
        $this->assertInstanceOf(SampledMsg::class, $msg);
        $this->assertNotNull($msg->snapshot->get('cpu'));
        $this->assertInstanceOf(GpuSnapshot::class, $msg->snapshot->get('gpu'));
    }

    public function testTheGpuBoxesResolveWhenTheSampleLands(): void
    {
        $held = $this->held();
        $panel = GpuPanel::new(CollectorSource::of($held));
        $ctx = new PanelContext(Config::new()->with('shown_boxes', 'cpu gpu0'));
        $msg = ($panel->collect($ctx))();
        $this->assertInstanceOf(AsyncCmd::class, $msg, 'the Cmd returns at once; the loop runs on');
        $held->release();
        $sampled = Settled::value($msg->promise);
        $this->assertInstanceOf(SampledMsg::class, $sampled);
        $this->assertSame('gpu', $sampled->box);
        $panel = $panel->update($sampled, $ctx)->panel;
        $this->assertInstanceOf(GpuPanel::class, $panel);
        $this->assertSame('Held GPU', $panel->device(0)?->name);
    }

    public function testTheProcBoxNeverWaitsExceptForAFreshResample(): void
    {
        $config = Config::new()->with('shown_boxes', 'proc');
        $layout = FrameBuilder::layout(180, 30, $config, 8);
        $ctx = new PanelContext($config, $layout, $layout->box('proc'));
        $held = $this->held();
        $panel = ProcPanel::new(FakeProcList::demo(8))->withGpu(CollectorSource::of($held));

        $msg = ($panel->collect($ctx))();
        $this->assertInstanceOf(SampledMsg::class, $msg, 'the routine sample never waits on nvidia-smi');
        $this->assertInstanceOf(ProcGpuSample::class, $msg->snapshot);
        $this->assertNull($msg->snapshot->gpu->processes, 'the previous (none yet) snapshot');
        $this->assertSame(1, $held->samples, 'but the round was started');
        $held->release();

        // `g` while the values are not fresh: the resample waits for per-process rows.
        $panel = $panel->update($msg, $ctx)->panel;
        $this->assertInstanceOf(ProcPanel::class, $panel);
        $this->assertFalse($panel->gpuUsage()->fresh());
        $r = $panel->update(new KeyMsg(KeyType::Char, 'g'), $ctx);
        $this->assertInstanceOf(PanelResult::class, $r);
        $this->assertNotNull($r->cmd);
        $resample = ($r->cmd)();
        $this->assertInstanceOf(SampledMsg::class, $resample, 'the landed round already carries processes: no wait');
        $this->assertNotNull($resample->snapshot->gpu->processes);
    }

    public function testAFreshResampleWaitsForTheSpawn(): void
    {
        $config = Config::new()->with('shown_boxes', 'proc')->with('proc_gpu_only', true);
        $layout = FrameBuilder::layout(180, 30, $config, 8);
        $ctx = new PanelContext($config, $layout, $layout->box('proc'));
        $held = $this->held();
        $panel = ProcPanel::new(FakeProcList::demo(8))->withGpu(CollectorSource::of($held));
        $r = $panel->update(new KeyMsg(KeyType::Char, 'g'), new PanelContext(Config::new()->with('shown_boxes', 'proc'), $layout, $layout->box('proc')));
        $this->assertNotNull($r->cmd);
        $msg = ($r->cmd)();
        $this->assertInstanceOf(AsyncCmd::class, $msg, 'no snapshot yet: wait for the spawn on the loop');
        $held->release();
        $sampled = Settled::value($msg->promise);
        $this->assertInstanceOf(SampledMsg::class, $sampled);
        $this->assertInstanceOf(ProcGpuSample::class, $sampled->snapshot);
        $this->assertInstanceOf(ProcSnapshot::class, $sampled->snapshot->proc);
        $this->assertNotNull($sampled->snapshot->gpu->processes);
        $this->assertTrue($ctx->config->bool('proc_gpu_only'));
    }

    public function testTheAppSamplesTheAcceleratorsOncePerTickForEveryConsumer(): void
    {
        $config = Config::new()->with('shown_boxes', 'cpu proc gpu0 gpu1')->with('show_gpu_info', 'On');
        $panels = Panels::standard(Harness::host(), $config, true);
        $feed = $panels['proc']->gpuSource();
        $this->assertInstanceOf(GpuFeed::class, $feed);
        $app = App::start($config, ThemeConfig::new(), Harness::host(), $panels, static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0), ColorProfile::TrueColor);
        [$app] = $app->update(new WindowSizeMsg(200, 60));
        $app = self::settle($app, $app->init());
        $this->assertSame(1, $feed->rounds(), 'cpu, gpu boxes and proc: one sample');

        for ($tick = 1; $tick <= 3; $tick++) {
            [$app, $cmd] = $app->update(new DataTickMsg($app->generation));
            $app = self::settle($app, $cmd);
            $this->assertSame($tick + 1, $feed->rounds(), "tick $tick: one sample");
        }
        $this->assertNotNull($feed->current()->processes, 'the proc box asked: per-process rows');
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $this->assertTrue($proc->gpuAvailable(), '--fake proc columns come from the same feed');
        $cpu = $app->panel('cpu');
        $this->assertInstanceOf(CpuPanel::class, $cpu);
        $this->assertSame(['NVIDIA GeForce RTX 4090', 'NVIDIA GeForce RTX 4080'], array_map(static fn (GpuDevice $d): string => $d->name, $cpu->gpus()));
        $gpu = $app->panel('gpu');
        $this->assertInstanceOf(GpuPanel::class, $gpu);
        $this->assertEquals($cpu->gpus()[0]->utilization, $gpu->device(0)?->utilization, 'the same snapshot');
    }

    public function testNothingIsSampledWithoutAConsumer(): void
    {
        $config = Config::new()->with('shown_boxes', 'mem net')->with('show_gpu_info', 'On');
        $panels = Panels::standard(Harness::host(), $config, true);
        $feed = $panels['proc']->gpuSource();
        $this->assertInstanceOf(GpuFeed::class, $feed);
        $app = App::start($config, ThemeConfig::new(), Harness::host(), $panels, static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0), ColorProfile::TrueColor);
        [$app] = $app->update(new WindowSizeMsg(200, 60));
        self::settle($app, $app->init());
        $this->assertSame(0, $feed->rounds(), '#1858: no cpu, gpu or proc box, no sample');
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

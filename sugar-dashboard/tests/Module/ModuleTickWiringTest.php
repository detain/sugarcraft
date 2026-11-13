<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Module;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Kind;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Dashboard\Module\BaseModule;
use SugarCraft\Dashboard\Modules\Clock\ClockModule;
use SugarCraft\Dashboard\Modules\Clock\TickMsg as ClockTickMsg;

/**
 * sugar-dashboard-007: first-party modules must tick inside an
 * in-process composition without the host hand-rolling a tick loop.
 *
 * Pins the three legs of the fix:
 *  - BaseModule exposes a core Tick subscription from the module hooks
 *    (memoised so the reconciler sees a stable producer),
 *  - the legacy Cmd::tick self-rearming chain survives untouched for
 *    hosts that only execute returned Cmds (the examples),
 *  - once a Program honours the subscription, the same instance stops
 *    the legacy chain so cadence converges to a single tick source,
 *  - and end to end: a real Program drives a ticking module under a
 *    fake-free loop and the rendered row actually advances.
 */
final class ModuleTickWiringTest extends TestCase
{
    /** Marker owned by the probe lineage below. */
    private static function probeTick(): Msg
    {
        return ProbeTickMsg::instance();
    }

    public function testClockModuleDeclaresStableTickSubscription(): void
    {
        $module = new ClockModule();

        $subs = $module->subscriptions();
        $this->assertInstanceOf(Subscriptions::class, $subs);
        $all = $subs->all();
        $this->assertCount(1, $all);
        $this->assertSame('module.clock.tick', $all[0]->id);
        $this->assertSame(Kind::Tick, $all[0]->kind);
        $this->assertSame(['seconds' => 1.0], $all[0]->params);
        $this->assertInstanceOf(ClockTickMsg::class, ($all[0]->produce)());

        // Memoised: a second consult returns the identical object, and so
        // does a clone's — the signature the core reconciler diffs stays
        // stable across the whole lineage (a rebuilt closure would restart
        // the periodic timer on every cycle and the tick would never fire).
        $again = $module->subscriptions();
        $this->assertSame($subs, $again);
        [$clone,] = $module->update(self::probeTick());
        $cloneSubs = $clone->subscriptions();
        $this->assertInstanceOf(Subscriptions::class, $cloneSubs);
        $this->assertSame($subs->all()[0]->produce, $cloneSubs->all()[0]->produce);
    }

    public function testLegacyChainSurvivesForCmdOnlyHosts(): void
    {
        $module = new ProbeModule();

        // A host that only executes Cmds never consults subscriptions():
        // the self-rearming chain must keep running exactly as before
        // (this is what the shipped examples rely on).
        $init = $module->init();
        $this->assertNotNull($init, 'init() must still arm the legacy tick for cmd-only hosts');
        $this->assertInstanceOf(\SugarCraft\Core\TickRequest::class, $init());

        [$next, $rearm] = $module->update(self::probeTick());
        $this->assertNotNull($rearm, 'update() must keep re-arming while un-consulted');
        $this->assertInstanceOf(\SugarCraft\Core\TickRequest::class, $rearm());
        $this->assertSame('tick:1', $next->view(), 'the marker must advance the state');
    }

    public function testConsultedSubscriptionRetiresTheLegacyChain(): void
    {
        $module = new ProbeModule();

        $this->assertNotNull($module->init(), 'legacy chain is on before any host consults');

        $subs = $module->subscriptions();
        $this->assertInstanceOf(Subscriptions::class, $subs);

        // The host honours subscriptions — the periodic timer is now the
        // single tick source, so the legacy one-shot chain retires.
        $this->assertNull($module->init(), 'init() must not arm a second cadence once consulted');
        [$next, $rearm] = $module->update(self::probeTick());
        $this->assertNull($rearm, 'update() must not re-arm once consulted');
        $this->assertSame('tick:1', $next->view());
    }

    public function testProgramDrivesModuleTickEndToEnd(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $box = new TickViewBox();
        $module = new ProbeModule($box);
        $program = new Program($module, new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            framerate: 60.0,
            input: $in,
            output: $out,
            loop: $loop,
        ));

        $loop->addTimer(0.35, static fn () => $loop->stop());
        $program->run();

        $this->assertNotSame('', $box->view, 'the Program must render at least one frame');
        $this->assertMatchesRegularExpression('/^tick:\d+$/', $box->view);
        $this->assertGreaterThan(
            0,
            (int) explode(':', $box->view)[1],
            'a ticking module must advance its row inside a bare Program (sugar-dashboard-007: the flagship rendered cold forever)'
        );

        fclose($writer);
        fclose($in);
    }

    /** @return array{0:resource, 1:resource, 2:resource} */
    private function pipes(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$reader, $writer] = $sockets;
        $output = fopen('php://memory', 'w+');
        $this->assertNotFalse($output);
        return [$reader, $output, $writer];
    }
}

/**
 * Shared marker for the probe lineage — a top-level class so
 * instanceof works across files.
 */
final class ProbeTickMsg implements Msg
{
    private static ?self $instance = null;

    public static function instance(): Msg
    {
        return self::$instance ??= new self();
    }
}

/**
 * Clone-safe observation box: the module writes every rendered row
 * into it, so assertions see the latest frame across update() clones.
 */
final class TickViewBox
{
    public string $view = '';
}

/**
 * Minimal self-ticking BaseModule: cadence 0.05s, row = "tick:<n>".
 */
final class ProbeModule extends BaseModule
{
    public function __construct(
        private readonly ?TickViewBox $box = null,
    ) {
    }

    public function name(): string
    {
        return 'probe';
    }

    public function init(): ?\Closure
    {
        $this->note($this->view());
        return $this->selfTickCmd();
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof ProbeTickMsg) {
            $next = $this->withState(['n' => (int) ($this->state()['n'] ?? 0) + 1]);
            $this->note($next->view());
            return [$next, $this->selfTickCmd()];
        }
        return [$this, null];
    }

    public function view(): string
    {
        $view = 'tick:' . (string) (int) ($this->state()['n'] ?? 0);
        $this->note($view);
        return $view;
    }

    protected function tickIntervalSeconds(): ?float
    {
        return 0.05;
    }

    protected function tickProducer(): ?\Closure
    {
        static $produce = null;
        return $produce ??= static fn(): Msg => ProbeTickMsg::instance();
    }

    private function note(string $view): void
    {
        if ($this->box !== null) {
            $this->box->view = $view;
        }
    }
}

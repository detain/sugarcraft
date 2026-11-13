<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Driver model: init() returns whatever Cmd the test injects; update()
 * swallows every message (the marker ticks from the shared periodic) and
 * never queues follow-up work.
 */
final class EveryDriverModel implements Model
{
    use SubscriptionCapable;

    public ?\Closure $initCmd = null;

    public function init(): ?\Closure
    {
        return $this->initCmd;
    }

    /**
     * @return array{0:Model, 1:?Cmd}
     */
    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return 'every';
    }
}

/**
 * sc-09 / sugar-core-004: `Cmd::every` must ride ONE wall-clock-aligned
 * periodic chain per distinct interval, fanned out to every registered
 * producer — the old implementation armed a private one-shot re-arm per
 * subscription while the docblock promised shared ticks.
 *
 * Pins: same-cadence fan-out with zero inter-consumer skew, per-producer
 * step-down on null (and chain teardown when the last seat leaves),
 * identity dedupe instead of stacking, interval isolation, and the sc-07
 * vocabulary inside the fan-out loop (a throwing producer is routed to
 * the exception handler and keeps its seat).
 */
final class CmdEveryTest extends TestCase
{
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

    private function makeOptions($in, $out, $loop): ProgramOptions
    {
        return new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
        );
    }

    /**
     * The registry is emptied by releaseRuntime() on the way out of run(),
     * so every registry assertion reads a snapshot taken INSIDE the quit
     * timer (arrays are copied at read time; the snapshot freezes the
     * live seat list at t=quit).
     *
     * @return \Closure callable for loop->addTimer(runSeconds, ...)
     */
    private function quittingSnapshot(Program $program, array &$periodicsAtQuit, float $runSeconds, $loop): void
    {
        $loop->addTimer($runSeconds, static function () use ($program, &$periodicsAtQuit): void {
            $periodicsAtQuit = (new \ReflectionProperty(Program::class, 'sharedPeriodics'))->getValue($program);
            $program->quit();
        });
    }

    public function testTwoConsumersOfTheSameIntervalRideOneChainAtOneCadence(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $tick = new class () implements Msg {};
        $n1 = 0;
        $n2 = 0;
        $p1 = static function (\DateTimeImmutable $t) use (&$n1, $tick): ?Msg {
            $n1++;
            return $tick;
        };
        $p2 = static function (\DateTimeImmutable $t) use (&$n2, $tick): ?Msg {
            $n2++;
            return $tick;
        };

        $model             = new EveryDriverModel();
        $model->initCmd    = Cmd::batch(Cmd::every(0.05, $p1), Cmd::every(0.05, $p2));
        $program           = new Program($model, $this->makeOptions($in, $out, $loop));
        $periodics         = [];
        $this->quittingSnapshot($program, $periodics, 0.40, $loop);
        $program->run();
        $this->assertCount(1, $periodics, 'one 0.05 s interval must equal one shared chain');
        $entry = array_values($periodics)[0];
        $this->assertCount(2, $entry['listeners'], 'both producers hold seats on the same chain');
        $this->assertGreaterThanOrEqual(5, $n1, 'the shared chain must deliver repeatedly');
        $this->assertGreaterThanOrEqual(5, $n2);
        $this->assertLessThanOrEqual(1, abs($n1 - $n2), 'shared fan-out has zero inter-consumer skew');

        if (is_resource($in)) {
            fclose($in);
        }
        rewind($out);
    }

    public function testNullStepDownStopsDeliveryAndTearsDownTheChain(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $tick = new class () implements Msg {};
        $n1 = 0;
        $n2 = 0;
        // p1 steps down after its second delivery; p2 after its fifth.
        $p1 = static function (\DateTimeImmutable $t) use (&$n1, $tick): ?Msg {
            $n1++;
            return $n1 >= 2 ? null : $tick;
        };
        $p2 = static function (\DateTimeImmutable $t) use (&$n2, $tick): ?Msg {
            $n2++;
            return $n2 >= 5 ? null : $tick;
        };

        $model          = new EveryDriverModel();
        $model->initCmd = Cmd::batch(Cmd::every(0.05, $p1), Cmd::every(0.05, $p2));
        $program        = new Program($model, $this->makeOptions($in, $out, $loop));
        $periodics      = [];
        $this->quittingSnapshot($program, $periodics, 0.55, $loop);
        $program->run();

        $this->assertSame(2, $n1, 'a stepped-down producer must receive no further deliveries');
        $this->assertSame(5, $n2);
        $this->assertSame([], $periodics, 'the chain tears down with the last seat (mid-run snapshot)');

        if (is_resource($in)) {
            fclose($in);
        }
        rewind($out);
    }

    public function testReRegisteringTheSameProducerKeepsOneSeat(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $tick = new class () implements Msg {};
        $n = 0;
        $p  = static function (\DateTimeImmutable $t) use (&$n, $tick): ?Msg {
            $n++;
            return $tick;
        };
        $id = spl_object_id($p);

        $model          = new EveryDriverModel();
        $model->initCmd = Cmd::batch(Cmd::every(0.1, $p), Cmd::every(0.1, $p));
        $program        = new Program($model, $this->makeOptions($in, $out, $loop));
        $periodics      = [];
        $this->quittingSnapshot($program, $periodics, 0.35, $loop);
        $program->run();

        $entry = array_values($periodics)[0];
        $this->assertCount(1, $entry['listeners'], 'identity dedupe: the same closure holds ONE seat');
        $this->assertSame($id, spl_object_id($entry['listeners'][0]));
        $this->assertLessThanOrEqual(4, $n, 'no stacked double delivery per tick');

        if (is_resource($in)) {
            fclose($in);
        }
        rewind($out);
    }

    public function testDistinctIntervalsGetDistinctChains(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $tick = new class () implements Msg {};
        $fast = 0;
        $slow = 0;
        $pf = static function (\DateTimeImmutable $t) use (&$fast, $tick): ?Msg {
            $fast++;
            return $tick;
        };
        $ps = static function (\DateTimeImmutable $t) use (&$slow, $tick): ?Msg {
            $slow++;
            return $tick;
        };

        $model          = new EveryDriverModel();
        $model->initCmd = Cmd::batch(Cmd::every(0.05, $pf), Cmd::every(0.1, $ps));
        $program        = new Program($model, $this->makeOptions($in, $out, $loop));
        $periodics      = [];
        $this->quittingSnapshot($program, $periodics, 0.40, $loop);
        $program->run();
        $this->assertArrayHasKey('0.050000', $periodics);
        $this->assertArrayHasKey('0.100000', $periodics);
        $this->assertGreaterThan($slow + 1, $fast, 'the 0.05 s chain ticks about twice as often');

        if (is_resource($in)) {
            fclose($in);
        }
        rewind($out);
    }

    public function testThrowingProducerIsRoutedToTheHandlerAndKeepsItsSeat(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $tick = new class () implements Msg {};
        $n    = 0;
        $p = static function (\DateTimeImmutable $t) use (&$n, $tick): ?Msg {
            $n++;
            if ($n === 1) {
                throw new \RuntimeException('first tick explodes');
            }
            return $tick;
        };
        $thrown = [];

        $model          = new EveryDriverModel();
        $model->initCmd = Cmd::every(0.05, $p);
        $program        = (new Program($model, $this->makeOptions($in, $out, $loop)))
            ->withExceptionHandler(static function (\Throwable $t) use (&$thrown): void {
                $thrown[] = $t;
            });
        $loop->addTimer(0.30, static function () use ($program): void {
            $program->quit();
        });
        $program->run();

        $this->assertCount(1, $thrown, 'the escaping throw is routed once through the handler');
        $this->assertGreaterThanOrEqual(3, $n, 'an absorbed throw must not evict the seat');

        if (is_resource($in)) {
            fclose($in);
        }
        rewind($out);
    }
}

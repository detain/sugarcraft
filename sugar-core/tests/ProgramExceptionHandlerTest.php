<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\TickRequest;

/**
 * Probe model for sugar-core-003 handler-coverage pins. Every user-code
 * seat is triggerable via a public flag; counters prove the program kept
 * running after an absorbed throw.
 */
final class HandlerProbeModel implements Model
{
    use SubscriptionCapable;

    /** @var list<Msg> */
    public array $seen = [];
    public int $viewCalls = 0;
    public int $updateCalls = 0;
    public bool $throwOnKeyX = false;
    public int $throwOnViewCall = PHP_INT_MAX;
    public bool $cmdFromKeyX = false;
    public ?Subscriptions $subs = null;

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        $this->updateCalls++;
        $this->seen[] = $msg;
        if ($this->throwOnKeyX && $msg instanceof KeyMsg && $msg->rune === 'x') {
            throw new \RuntimeException('update-boom');
        }
        if ($this->cmdFromKeyX && $msg instanceof KeyMsg && $msg->rune === 'x') {
            return [$this, static function () {
                throw new \RuntimeException('cmd-boom');
            }];
        }
        return [$this, null];
    }

    public function view(): string
    {
        $this->viewCalls++;
        if ($this->viewCalls === $this->throwOnViewCall) {
            throw new \RuntimeException('view-boom');
        }
        return 'ok';
    }

    public function subscriptions(): ?Subscriptions
    {
        return $this->subs;
    }
}

/**
 * sc-07 / sugar-core-003: withExceptionHandler() promises "any uncaught
 * throwable arising from user code during the loop". Before the fix only
 * the AsyncCmd rejection path honoured it — a throwing update(), produce
 * closure, Cmd callback, filter, view() or signal arm crashed the program
 * with the handler never called. Every seat now routes through the
 * handler; the default handler re-throws, so fail-loud stays intact.
 */
final class ProgramExceptionHandlerTest extends TestCase
{
    /**
     * @return array{0: Program, 1: LoopInterface, 2: resource}
     *         The writer end must stay referenced for the whole test —
     *         dropping it closes the fd and the EOF arm quits instantly.
     */
    private function makeProgram(Model $model, ?\Closure $filter = null): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$in, $writer] = $sockets;
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);
        $loop = new StreamSelectLoop();
        $program = new Program($model, new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
            filter: $filter,
        ));
        return [$program, $loop, $writer];
    }

    /**
     * @param list<string> $caught
     */
    private function absorbingHandler(array &$caught): \Closure
    {
        return static function (\Throwable $t) use (&$caught): void {
            $caught[] = $t->getMessage();
        };
    }

    public function testUpdateThrowHitsHandlerOnceAndProgramSurvives(): void
    {
        $model = new HandlerProbeModel();
        $model->throwOnKeyX = true;
        $caught = [];
        [$program, $loop, $writer] = $this->makeProgram($model);
        $program = $program->withExceptionHandler($this->absorbingHandler($caught));
        $program->send(new KeyMsg(KeyType::Char, 'x'));
        $program->send(new KeyMsg(KeyType::Char, 'y'));
        $program->send(new QuitMsg());
        $loop->addTimer(1.0, static fn () => $loop->stop());
        $program->run();

        $this->assertSame(['update-boom'], $caught, 'handler must see the update() throw exactly once');
        $last = $model->seen[array_key_last($model->seen)];
        $this->assertInstanceOf(
            KeyMsg::class,
            $last,
            'the message after the absorbed throw must still reach the model',
        );
        $this->assertSame('y', $last->rune);
    }

    public function testDefaultHandlerStillCrashesLoudly(): void
    {
        $model = new HandlerProbeModel();
        $model->throwOnKeyX = true;
        [$program, $loop, $writer] = $this->makeProgram($model);
        $program->send(new KeyMsg(KeyType::Char, 'x'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('update-boom');
        $program->run();
    }

    public function testProducerThrowHitsHandlerOnceAndSubscriptionStaysArmed(): void
    {
        $ticks = 0;
        $model = new HandlerProbeModel();
        $model->subs = (new Subscriptions())->withTick(
            'boom',
            0.01,
            static function () use (&$ticks) {
                $ticks++;
                if ($ticks === 1) {
                    throw new \LogicException('produce-boom');
                }
                return null;
            },
        );
        $caught = [];
        [$program, $loop, $writer] = $this->makeProgram($model);
        $program = $program->withExceptionHandler($this->absorbingHandler($caught));
        $loop->addTimer(0.2, static fn () => $loop->stop());
        $program->run();

        $this->assertSame(['produce-boom'], $caught);
        $this->assertGreaterThan(1, $ticks, 'the timer must keep firing after an absorbed producer throw');
    }

    public function testTickRequestProducerThrowHitsHandler(): void
    {
        $model = new HandlerProbeModel();
        $caught = [];
        [$program, $loop, $writer] = $this->makeProgram($model);
        $program = $program->withExceptionHandler($this->absorbingHandler($caught));
        $program->send(new TickRequest(0.01, static fn () => throw new \RuntimeException('tick-boom')));
        $loop->addTimer(0.15, static fn () => $loop->stop());
        $program->run();

        $this->assertSame(['tick-boom'], $caught);
    }

    public function testCmdThrowHitsHandlerOnce(): void
    {
        $model = new HandlerProbeModel();
        $model->cmdFromKeyX = true;
        $caught = [];
        [$program, $loop, $writer] = $this->makeProgram($model);
        $program = $program->withExceptionHandler($this->absorbingHandler($caught));
        $program->send(new KeyMsg(KeyType::Char, 'x'));
        $loop->addTimer(0.15, static fn () => $loop->stop());
        $program->run();

        $this->assertSame(['cmd-boom'], $caught);
        $last = $model->seen[array_key_last($model->seen)];
        $this->assertInstanceOf(KeyMsg::class, $last);
        $this->assertSame('x', $last->rune, 'the throwing cmd must have been produced by the x message');
    }

    public function testViewThrowSkipsFrameAndSurvives(): void
    {
        $model = new HandlerProbeModel();
        $model->throwOnViewCall = 1;
        $caught = [];
        [$program, $loop, $writer] = $this->makeProgram($model);
        $program = $program->withExceptionHandler($this->absorbingHandler($caught));
        // One message after the absorbed frame: an update marks the screen
        // dirty again and the next paint must call view() successfully.
        $loop->addTimer(0.05, static function () use ($program): void {
            $program->send(new KeyMsg(KeyType::Char, 'y'));
        });
        $loop->addTimer(0.2, static fn () => $loop->stop());
        $program->run();

        $this->assertSame(['view-boom'], $caught);
        $this->assertGreaterThanOrEqual(2, $model->viewCalls, 'the paint loop must continue after an absorbed view() throw');
    }

    public function testFilterThrowRoutesOriginalMessage(): void
    {
        $model = new HandlerProbeModel();
        $caught = [];
        [$program, $loop, $writer] = $this->makeProgram($model, static function (Model $m, Msg $msg): Msg {
            if ($msg instanceof KeyMsg && $msg->rune === 'x') {
                throw new \RuntimeException('filter-boom');
            }
            return $msg;
        });
        $program = $program->withExceptionHandler($this->absorbingHandler($caught));
        $program->send(new KeyMsg(KeyType::Char, 'x'));
        $program->send(new QuitMsg());
        $loop->addTimer(1.0, static fn () => $loop->stop());
        $program->run();

        $this->assertSame(['filter-boom'], $caught);
        $sawX = false;
        foreach ($model->seen as $msg) {
            if ($msg instanceof KeyMsg && $msg->rune === 'x') {
                $sawX = true;
            }
        }
        $this->assertTrue($sawX, 'an absorbed filter throw must route the ORIGINAL message to the model');
    }

    public function testSignalArmThrowHitsHandler(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill') || !defined('SIGUSR1')) {
            $this->markTestSkipped('pcntl/posix required for signal-arm coverage');
        }

        $model = new HandlerProbeModel();
        $model->subs = (new Subscriptions())->withSignal(
            'boom',
            SIGUSR1,
            static fn () => throw new \RuntimeException('signal-boom'),
        );
        $caught = [];
        [$program, $loop, $writer] = $this->makeProgram($model);
        $program = $program->withExceptionHandler($this->absorbingHandler($caught));
        $loop->addTimer(0.05, static fn () => posix_kill(getmypid(), SIGUSR1));
        $loop->addTimer(0.2, static fn () => $loop->stop());
        $program->run();

        $this->assertSame(['signal-boom'], $caught);
    }
}

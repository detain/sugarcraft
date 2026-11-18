<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Core\Kind;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Subscription;
use SugarCraft\Core\Tests\Support\CountingModel;
use SugarCraft\Core\Tests\Support\RecordingLoop;

/**
 * The periodic signal pump is a fallback for pcntl delivery that is not
 * asynchronous. It must stay off unless a signal arm needs polling, so an idle
 * program with signal handling off does not wake at the framerate.
 */
final class ProgramFallbackPumpTest extends TestCase
{
    /** @var list<resource> */
    private array $held = [];

    protected function tearDown(): void
    {
        foreach ($this->held as $res) {
            if (is_resource($res)) {
                fclose($res);
            }
        }
        $this->held = [];
    }

    /** @return array{0: resource, 1: resource} */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        $this->held[] = $pair[0];
        $this->held[] = $pair[1];
        return $pair;
    }

    private function program(RecordingLoop $loop): Program
    {
        [$input] = $this->pair();
        [$output] = $this->pair();
        return new Program(new CountingModel(), new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $input,
            output: $output,
            loop: $loop,
            framerate: 60.0,
        ));
    }

    public function testAnIdleProgramWithSignalHandlingOffArmsNoPollingTimer(): void
    {
        if (!function_exists('pcntl_signal_dispatch')) {
            self::markTestSkipped('pcntl required');
        }
        $loop = new RecordingLoop();
        $program = $this->program($loop);
        $loop->addTimer(0.3, static function () use ($program): void {
            $program->quit();
        });

        $program->run();

        self::assertSame(0, $loop->periodicArms, 'nothing needs polling, so the framerate must not wake an idle program');
    }

    public function testASignalArmNeedsThePumpOnlyWhileTheLoopIsLive(): void
    {
        if (!function_exists('pcntl_signal_dispatch')) {
            self::markTestSkipped('pcntl required');
        }
        $loop = new RecordingLoop();
        $program = $this->program($loop);
        $signo = defined('SIGUSR2') ? SIGUSR2 : 12;
        (new ReflectionProperty(Program::class, 'signalSubscriptions'))->setValue($program, [$signo => [
            'prev' => null,
            'subs' => [new Subscription('sig', Kind::Signal, ['signo' => $signo], static fn (): Msg => new Msg\QuitMsg())],
        ]]);
        $live = new ReflectionProperty(Program::class, 'signalPumpLive');
        $arm = new ReflectionMethod(Program::class, 'armSignalPump');

        $arm->invoke($program);
        self::assertSame(0, $loop->periodicArms, 'a signal arm outside run() arms nothing');

        $live->setValue($program, true);
        $arm->invoke($program);
        $arm->invoke($program);
        self::assertSame(1, $loop->periodicArms, 'a live signal arm arms the pump exactly once');
    }
}

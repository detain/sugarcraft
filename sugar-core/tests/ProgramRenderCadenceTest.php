<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Tests\Support\CadenceBump;
use SugarCraft\Core\Tests\Support\CadenceModel;

/**
 * Render cadence: a frame is armed by a dirty state change on a one-shot
 * timer, not by a 60 Hz poll (audit rank 16), and updates that land between
 * frames collapse into one frame (the coalescing half of rank 13).
 */
final class ProgramRenderCadenceTest extends TestCase
{
    public function testIdleProgramArmsNoRenderTimerAndABumpRendersOnce(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $probe = new \stdClass();
        $probe->views = 0;
        $program = new Program(new CadenceModel($probe), $this->options($in, $out, $loop));

        $seen = [];
        $loop->addTimer(0.15, function () use ($program, $probe, &$seen): void {
            $seen['armedWhileIdle'] = $this->renderTimerArmed($program);
            $seen['viewsIdle'] = $probe->views;
            $program->send(new CadenceBump());
        });
        $loop->addTimer(0.35, function () use ($program, $probe, &$seen): void {
            $seen['viewsAfterBump'] = $probe->views;
            $seen['armedAfterFrame'] = $this->renderTimerArmed($program);
            $program->quit();
        });
        $loop->addTimer(3.0, static fn () => $loop->stop());
        $program->run();

        $this->finish($in, $out, $writer);
        $this->assertFalse($seen['armedWhileIdle'], 'no frame is armed while nothing changed');
        $this->assertSame(1, $seen['viewsIdle'], 'only the initial frame painted while idle');
        $this->assertSame($seen['viewsIdle'] + 1, $seen['viewsAfterBump'], 'the bump painted exactly one frame');
        $this->assertFalse($seen['armedAfterFrame'], 'the one-shot timer releases itself after it fires');
    }

    public function testUpdatesBetweenFramesCoalesceIntoOneFrame(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $probe = new \stdClass();
        $probe->views = 0;
        $program = new Program(new CadenceModel($probe), $this->options($in, $out, $loop));

        $before = 0;
        $loop->addTimer(0.15, function () use ($program, $probe, &$before): void {
            $before = $probe->views;
            // Twenty-five updates queued in one callback: the program must render once for all of them.
            for ($i = 0; $i < 25; $i++) {
                $program->send(new CadenceBump());
            }
        });
        $after = null;
        $loop->addTimer(0.35, function () use ($program, $probe, &$after): void {
            $after = $probe->views;
            $program->quit();
        });
        $loop->addTimer(3.0, static fn () => $loop->stop());
        $program->run();

        $this->finish($in, $out, $writer);
        $this->assertSame(1, $after - $before, 'twenty-five updates painted one frame');
        $this->assertSame(25, $program->model() instanceof CadenceModel ? $program->model()->count : -1, 'and every update was applied');
    }

    private function renderTimerArmed(Program $program): bool
    {
        return (new \ReflectionProperty(Program::class, 'renderTimer'))->getValue($program) !== null;
    }

    /** @return array{0: resource, 1: resource, 2: resource} */
    private function pipes(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$in, $writer] = $pair;
        $out = fopen('php://memory', 'w+b');
        $this->assertNotFalse($out);
        stream_set_blocking($in, false);

        return [$in, $out, $writer];
    }

    private function options($in, $out, StreamSelectLoop $loop): ProgramOptions
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

    /** @param resource $in @param resource $out @param resource $writer */
    private function finish($in, $out, $writer): void
    {
        fclose($writer);
        fclose($in);
        fclose($out);
    }
}

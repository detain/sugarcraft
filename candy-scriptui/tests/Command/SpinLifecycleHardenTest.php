<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use SugarCraft\Core\Model;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\ScriptUi\Command\SpinCommand;
use SugarCraft\ScriptUi\Process\FakeProcess;
use SugarCraft\ScriptUi\Process\Process as ProcessContract;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * scriptui-05 (candy-scriptui-035/-037): the child lifecycle is wrapped
 * in a try/finally reap ladder, and the timeout callback is exception-
 * proofed statement by statement — a throw somewhere in the run never
 * leaves the child running, and never mis-reports the deadline verdict.
 */
final class SpinLifecycleHardenTest extends TestCase
{
    /** @var list<resource> keeps injected stream resources referenced */
    private array $streams = [];

    private function command(FakeProcess $fake, ?\Closure $programFactory = null): SpinCommand
    {
        // The socket-pair input reader below needs AF_UNIX — POSIX only
        // (same gate as SpinCommandTest's fake-driving scaffolding).
        if (\DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('stream_socket_pair(STREAM_PF_UNIX, …) is not supported on Windows.');
        }

        return new SpinCommand(
            processFactory: static function (array $argv, bool $so, bool $se) use ($fake): ProcessContract {
                $fake->recordSpawn($argv, $so, $se);
                return $fake;
            },
            programFactory: $programFactory ?? static function (Model $model, LoopInterface $loop): Program {
                $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
                \assert($pair !== false);
                [$reader, $writer] = $pair;
                return new Program($model, new ProgramOptions(
                    useAltScreen:    false,
                    hideCursor:      false,
                    catchInterrupts: false,
                    input:           $reader,
                    output:          \fopen('php://memory', 'w+'),
                    loop:            $loop,
                    windowSize:      ['cols' => 80, 'rows' => 24],
                ));
            },
        );
    }

    public function testTerminateThrowInsideDeadlineStillExits124WithReap(): void
    {
        $fake = new FakeProcess();
        $fake->throwOnTerminate = true;

        $tester = new CommandTester($this->command($fake));
        $code   = $tester->execute(['argv' => ['sleep', '9999'], '--timeout' => '0.1']);

        $this->assertSame(
            124,
            $code,
            'a terminate() exploding inside the deadline callback must not launder the timeout verdict into exit 1/255'
        );
        $this->assertTrue(
            $fake->closed,
            'the finally ladder must reap the child even when the timer callback threw'
        );
    }

    public function testProgramFactoryBlowupStillReapsTheChild(): void
    {
        $fake  = new FakeProcess();
        $throw = static function (): Program {
            throw new \RuntimeException('injected factory failure');
        };

        $tester = new CommandTester($this->command($fake, $throw));
        try {
            $tester->execute(['argv' => ['sleep', '9999'], '--timeout' => '0']);
            $this->fail('the injected factory failure must surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected factory failure', $e->getMessage());
        }

        $this->assertTrue(
            $fake->closed,
            'candy-scriptui-035: any Throwable between spawn and settle must still reap — never unwind with the child running'
        );
        $this->assertTrue(
            $fake->terminated,
            'the ladder signals before it reaps'
        );
    }

    public function testHappyPathStillSkipsSignallingAnExitedChild(): void
    {
        $fake = new FakeProcess();
        $fake->finish(0);

        $tester = new CommandTester($this->command($fake));
        $code   = $tester->execute(['argv' => ['true'], '--timeout' => '0']);

        $this->assertSame(0, $code);
        $this->assertFalse(
            $fake->terminated,
            'the finally-ladder terminate must no-op for an already-exited child (mirrors RealProcess)'
        );
        $this->assertTrue($fake->closed);
    }
}

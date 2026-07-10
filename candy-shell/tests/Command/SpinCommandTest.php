<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Command;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Model;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Forms\Spinner\Style as SpinStyle;
use SugarCraft\Shell\Command\SpinCommand;
use SugarCraft\Shell\Process\FakeProcess;
use SugarCraft\Shell\Process\Process;
use SugarCraft\Shell\Runtime\TimeoutGuard;
use Symfony\Component\Console\Tester\CommandTester;

final class SpinCommandTest extends TestCase
{
    public function testStyleAliases(): void
    {
        foreach (['line','dot','minidot','points','pulse','globe','meter'] as $name) {
            $this->assertInstanceOf(SpinStyle::class, SpinCommand::pickStyle($name));
        }
    }

    public function testStyleCaseInsensitive(): void
    {
        $this->assertInstanceOf(SpinStyle::class, SpinCommand::pickStyle('DOT'));
    }

    public function testUnknownStyleRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SpinCommand::pickStyle('whirl');
    }

    public function testInterruptedExitCodeIs130(): void
    {
        // Conventional SIGINT-exit code: scripts use this to detect a
        // user-cancelled spin run.
        $this->assertSame(130, SpinCommand::EXIT_INTERRUPTED);
    }

    /**
     * A child that never exits under `--timeout 0.1` must be aborted by the
     * deadline: the process is terminated + reaped and the command surfaces
     * the GNU timeout code (124), NOT the interrupted code (130). This drives
     * the whole SpinCommand->Program->TimeoutGuard path headless via a
     * {@see FakeProcess} that reports `exitCode() === null` forever.
     */
    public function testTimeoutAbortsAndTerminatesChild(): void
    {
        $fake    = new FakeProcess(); // exitCode stays null -> never completes
        $command = $this->spinCommandWith($fake);

        // Backstop: if the timeout wiring is broken the FakeProcess would spin
        // the loop forever, so stop it after 2s — a broken run then returns 130
        // and fails the assertions below loudly instead of hanging the suite.
        $loop = new StreamSelectLoop();
        \React\EventLoop\Loop::set($loop);
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $status = (new CommandTester($command))->execute(
            ['argv' => ['sleep', '9999'], '--timeout' => '0.1'],
            ['decorated' => false],
        );

        $this->assertSame(TimeoutGuard::EXIT_TIMEOUT, $status);
        $this->assertSame(124, $status);
        $this->assertTrue($fake->terminated, 'child must be signalled on timeout');
        $this->assertTrue($fake->closed, 'child handle must be reaped on timeout');
    }

    /**
     * `--timeout 0` (the default) leaves the run unbounded: a child that
     * completes normally still yields its own exit code and is never
     * terminated. Guards the timeout wiring against clobbering the happy path.
     */
    public function testZeroTimeoutRunsToCompletion(): void
    {
        $fake = new FakeProcess();
        $fake->finish(0); // child already exited cleanly

        $command = $this->spinCommandWith($fake);

        $loop = new StreamSelectLoop();
        \React\EventLoop\Loop::set($loop);
        $loop->addTimer(2.0, static fn () => $loop->stop());

        $status = (new CommandTester($command))->execute(
            ['argv' => ['true'], '--timeout' => '0'],
            ['decorated' => false],
        );

        $this->assertSame(0, $status);
        $this->assertFalse($fake->terminated, 'a clean exit must not be terminated');
        $this->assertTrue($fake->closed, 'the process handle must still be reaped');
    }

    /**
     * Build a SpinCommand wired to a fake child + a headless Program via its
     * injectable factory closures, so the TEA runtime drives on a socket/memory
     * pair instead of the real TTY. The streams are parked on the test instance
     * so they outlive Program::run(); the timeout timer shares the loop the
     * command pulls from {@see \React\EventLoop\Loop::get()}.
     *
     * @var list<resource> keeps the injected stream resources referenced
     */
    private array $streams = [];

    private function spinCommandWith(FakeProcess $fake): SpinCommand
    {
        // The headless stdin below is a STREAM_PF_UNIX socket pair. The AF_UNIX
        // domain is POSIX-only: on Windows stream_socket_pair() cannot honour it
        // and raises an E_WARNING, which candy-shell's failOnWarning="true" turns
        // into a CI failure. Only these two socket-backed tests need it (the rest
        // of the class is Windows-clean), and the timeout wiring they exercise is
        // already covered on Linux, so skip just this scaffolding on Windows —
        // mirroring RealProcessTest's DIRECTORY_SEPARATOR gate.
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('stream_socket_pair(STREAM_PF_UNIX, …) is not supported on Windows.');
        }

        return new SpinCommand(
            processFactory: static fn (array $argv, bool $so, bool $se): Process => $fake,
            programFactory: function (Model $model, LoopInterface $loop): Program {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                \assert($pair !== false);
                [$reader, $writer] = $pair;
                $out = fopen('php://memory', 'w+');
                $this->streams[] = $reader;
                $this->streams[] = $writer;
                $this->streams[] = $out;

                return new Program($model, new ProgramOptions(
                    useAltScreen:    false,
                    hideCursor:      false,
                    catchInterrupts: false,
                    input:           $reader,
                    output:          $out,
                    loop:            $loop,
                    windowSize:      ['cols' => 80, 'rows' => 24],
                ));
            },
        );
    }
}

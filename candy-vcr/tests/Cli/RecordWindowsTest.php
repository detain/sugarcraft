<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\RecordCommand;
use SugarCraft\Vcr\Cli\WindowsRecordLoop;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Tests\Support\FakeExitingChild;
use SugarCraft\Vcr\Tests\Support\FakeLoopRecorder;
use SugarCraft\Vcr\Tests\Support\FakeWindowsMaster;

/**
 * `record` on native Windows used to hang forever: the child spawned under
 * ConPTY, PosixPump threw on the ConPTY master, and the cleanup's reap
 * skipped the kill (pcntl's SIGTERM is undefined there) before an INFINITE
 * wait on the still-running child. It now records through
 * {@see WindowsRecordLoop}, and the reap kills with numeric signals.
 */
final class RecordWindowsTest extends TestCase
{
    public function testLiveRecordOnWindowsCapturesOutputInputAndExitCode(): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Records through ConPTY; POSIX hosts record through PosixPump (RecordCommandTest).');
        }
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'cv-recwin-' . \bin2hex(\random_bytes(4));
        \mkdir($dir);
        $cassette = $dir . \DIRECTORY_SEPARATOR . 'live.cas';
        $input = $dir . \DIRECTORY_SEPARATOR . 'in.txt';
        \file_put_contents($input, "ping\r\n");
        $stdin = \fopen($input, 'rb');
        $stdout = \fopen('php://memory', 'w+b');
        $stderr = \fopen('php://memory', 'w+b');

        try {
            $rc = (new RecordCommand($stdin))->run(
                ['--output', $cassette, '--', \PHP_BINARY, '-r', '$l = fgets(STDIN); echo "got:", trim((string) $l), "\n"; exit(7);'],
                $stdout,
                $stderr,
            );
            \rewind($stderr);
            $err = (string) \stream_get_contents($stderr);

            self::assertSame(7, $rc, $err);
            $events = (new JsonlFormat())->read($cassette)->events;
            $output = '';
            $typed = '';
            foreach ($events as $event) {
                $output .= $event->kind === EventKind::Output ? (string) ($event->payload['b'] ?? '') : '';
                $typed .= $event->kind === EventKind::Input ? (string) ($event->payload['b'] ?? '') : '';
            }
            self::assertStringContainsString('got:ping', $output);
            self::assertSame("ping\r\n", $typed, 'piped stdin reaches the child and the cassette');
        } finally {
            \fclose($stdin);
            \fclose($stdout);
            \fclose($stderr);
            @\unlink($cassette);
            @\unlink($input);
            @\rmdir($dir);
        }
    }

    public function testTheLoopRelaysOutputInputAndResizeUntilTheChildExits(): void
    {
        $master = new FakeWindowsMaster(["hello ", null, "world"]);
        $child = new FakeExitingChild(exitAfterPolls: 4, code: 9);
        $recorder = new FakeLoopRecorder();
        $inputs = ['', 'k', '', '', '', ''];
        $sizes = [[80, 24], [100, 30]];
        $stdout = \fopen('php://memory', 'w+b');

        $loop = new WindowsRecordLoop(
            static function () use (&$inputs): string {
                return \array_shift($inputs) ?? '';
            },
            static function () use (&$sizes): array {
                return \count($sizes) > 1 ? \array_shift($sizes) : $sizes[0];
            },
        );
        $code = $loop->run($master, $stdout, $child, $recorder, 80, 24);

        \rewind($stdout);
        self::assertSame(9, $code);
        self::assertSame('hello world', \stream_get_contents($stdout));
        self::assertSame('hello world', $recorder->output);
        self::assertSame('k', $recorder->input);
        self::assertSame(['k'], $master->written);
        \fclose($stdout);
    }

    public function testOutputWrittenJustBeforeExitIsStillDrained(): void
    {
        $master = new FakeWindowsMaster([null, 'tail-bytes', null]);
        $child = new FakeExitingChild(exitAfterPolls: 1, code: 0);
        $recorder = new FakeLoopRecorder();
        $stdout = \fopen('php://memory', 'w+b');

        (new WindowsRecordLoop(static fn (): string => ''))->run($master, $stdout, $child, $recorder, 80, 24);

        self::assertSame('tail-bytes', $recorder->output);
        \fclose($stdout);
    }

    public function testHostResizeIsForwardedAndRecorded(): void
    {
        $master = new FakeWindowsMaster(\array_fill(0, 30, null));
        $child = new FakeExitingChild(exitAfterPolls: 25, code: 0);
        $recorder = new FakeLoopRecorder();
        $stdout = \fopen('php://memory', 'w+b');

        (new WindowsRecordLoop(static fn (): string => '', static fn (): array => [120, 40]))
            ->run($master, $stdout, $child, $recorder, 80, 24);

        self::assertSame([[120, 40]], $master->resizes, 'one change, forwarded once');
        self::assertSame([[120, 40]], $recorder->resizes);
        \fclose($stdout);
    }

    public function testStreamInputReadsAFileToEof(): void
    {
        $file = \fopen('php://temp', 'w+b');
        \fwrite($file, 'abc');
        \rewind($file);
        $read = WindowsRecordLoop::streamInput($file);

        self::assertSame('abc', $read() . $read());
        self::assertSame('', $read());
    }

    public function testTheReapKillsWithNumericSignalsAndThenWaits(): void
    {
        $child = new FakeExitingChild(exitAfterPolls: \PHP_INT_MAX, code: 143, exitOnKill: true);

        (new \ReflectionMethod(RecordCommand::class, 'reapBounded'))->invoke(null, $child);

        self::assertSame([15], $child->signals, 'SIGTERM by number, so the kill happens without pcntl too');
        self::assertTrue($child->waited);
    }

    public function testAWedgedChildIsEscalatedToSigkill(): void
    {
        $child = new FakeExitingChild(exitAfterPolls: \PHP_INT_MAX, code: 137, exitOnKillSignal: 9);

        (new \ReflectionMethod(RecordCommand::class, 'reapBounded'))->invoke(null, $child);

        self::assertSame([15, 9], $child->signals);
    }
}

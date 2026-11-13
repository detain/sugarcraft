<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Cli\ReplayCommand;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * Findings 031/039: realtime pacing sleeps on FILE-carried deltas, so the
 * sleep needs a wall-clock belt (FUZZ-05) and must be interruptible in
 * slices (TUI) — and the process-wide async-signals mode the command flips
 * must be handed back the way it was found.
 *
 * The fuzz payload's `{"dt":1e15}` never survives the S01 parse doors any
 * more; the belt covers what does: two individually-legal rows whose gap
 * is the full 24 h of virtual tape a timestamp ceiling allows.
 */
final class ReplayCapTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    private function cassette(array $events): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-cap-');
        assert(is_string($path));
        $this->files[] = $path;
        (new JsonlFormat())->write(new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            $events,
        ), $path);
        return $path;
    }

    public function testMaximalLegalGapIsCappedToTheWallClockBelt(): void
    {
        $path = $this->cassette([
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x']),
            // Both rows are legal at the doors (t <= 86_400); the DELTA is
            // the day-long sleep the belt exists to catch.
            new Event(t: 86_400.0, kind: EventKind::Quit, payload: []),
        ]);

        /** @var list<float> $slept */
        $slept = [];
        $command = new ReplayCommand(
            static fn ($stream): bool => false,
            static function (float $seconds) use (&$slept): void {
                $slept[] = $seconds;
            },
        );

        $exit = $command->run([$path, '--speed=realtime'], Stream::memory('w+'), Stream::memory('w+'));

        $this->assertSame(0, $exit);
        $total = array_sum($slept);
        $this->assertLessThanOrEqual(
            ReplayCommand::MAX_SLEEP_SECONDS + 0.2,
            $total,
            'a file-carried delta must never sleep past the belt clamp',
        );
        $this->assertGreaterThan(ReplayCommand::MAX_SLEEP_SECONDS - 0.2, $total);
        foreach ($slept as $slice) {
            $this->assertLessThanOrEqual(ReplayCommand::SLEEP_SLICE_SECONDS + 1e-9, $slice);
        }
        $this->assertGreaterThan(100, count($slept), 'the capped sleep must still be sliced');
    }

    public function testIdleTrimStillWinsUnderTheBelt(): void
    {
        $path = $this->cassette([
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x']),
            new Event(t: 10.0, kind: EventKind::Quit, payload: []),
        ]);

        /** @var list<float> $slept */
        $slept = [];
        $command = new ReplayCommand(
            static fn ($stream): bool => false,
            static function (float $seconds) use (&$slept): void {
                $slept[] = $seconds;
            },
        );

        $exit = $command->run(
            [$path, '--speed=realtime', '--idle-trim=2'],
            Stream::memory('w+'),
            Stream::memory('w+'),
        );

        $this->assertSame(0, $exit);
        $this->assertSame(20, count($slept), 'user trim is policy; the belt only catches what survives it');
        $this->assertEqualsWithDelta(2.0, array_sum($slept), 1e-9);
    }

    public function testSignalDuringSleepSliceStopsPacingImmediately(): void
    {
        if (!\function_exists('pcntl_async_signals') || !\defined('SIGINT') || !\function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix required for the interruptible-sleep oracle');
        }

        $path = $this->cassette([
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x']),
            new Event(t: 60.0, kind: EventKind::Quit, payload: []),
        ]);

        // The fake sleeper fires a REAL SIGINT at the test process on its
        // first slice: the armed handler flips the command's interrupt
        // flag, and only a slice re-check can end the sleep loop early.
        // (The old single usleep would have slept the remaining 60 s.)
        $fired = false;
        $command = new ReplayCommand(
            static fn ($stream): bool => false,
            static function (float $seconds) use (&$fired): void {
                if (!$fired) {
                    $fired = true;
                    \posix_kill(\posix_getpid(), SIGINT);
                }
            },
        );

        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $start = microtime(true);
        $exit = $command->run([$path, '--speed=realtime'], $stdout, $stderr);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($fired);
        $this->assertSame(128 + SIGINT, $exit, 'the interrupted replay must report the signal exit code');
        $this->assertLessThan(1.0, $elapsed, 'a signal mid-sleep must land within a slice, not a minute');
        rewind($stderr);
        $this->assertStringContainsString('interrupted', (string) stream_get_contents($stderr));
    }

    public function testAsyncSignalsStateIsRestored(): void
    {
        if (!\function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('ext-pcntl required for the async-signals state oracle');
        }

        $path = $this->cassette([
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x']),
        ]);

        $original = pcntl_async_signals();
        try {
            foreach ([false, true] as $prior) {
                pcntl_async_signals($prior);
                $command = new ReplayCommand(
                    static fn ($stream): bool => false,
                    static function (float $seconds): void {
                    },
                );
                $exit = $command->run([$path, '--speed=instant'], Stream::memory('w+'), Stream::memory('w+'));
                $this->assertSame(0, $exit);
                $this->assertSame(
                    $prior,
                    pcntl_async_signals(),
                    'replay must hand back the process async-signals mode exactly as found',
                );
            }
        } finally {
            pcntl_async_signals($original);
        }
    }
}

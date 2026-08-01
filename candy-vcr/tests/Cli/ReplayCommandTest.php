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

/**
 * Tests for ReplayCommand CLI.
 * Covers --speed=realtime and --idle-trim branches.
 */
final class ReplayCommandTest extends TestCase
{
    private function writeCassette(string $path, array $events): string
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            $events,
        );
        (new JsonlFormat())->write($cassette, $path);
        return $path;
    }

    public function testReplayInstantSpeed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-replay-');
        $this->writeCassette($path, [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hello']),
            new Event(t: 0.5, kind: EventKind::Quit, payload: []),
        ]);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new ReplayCommand())->run([$path, '--speed=instant'], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(0, $exit);
            $this->assertSame('hello', $out);
        } finally {
            @unlink($path);
        }
    }

    public function testReplayRealtimeSpeedNoTrim(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-replay-');
        $this->writeCassette($path, [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hi']),
            new Event(t: 0.05, kind: EventKind::Quit, payload: []),
        ]);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $start = microtime(true);
            $exit = (new ReplayCommand())->run([$path, '--speed=realtime'], $stdout, $stderr);
            $elapsed = microtime(true) - $start;
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(0, $exit);
            $this->assertSame('hi', $out);
            // Should have waited at least 0.05 seconds for realtime playback
            $this->assertGreaterThanOrEqual(0.04, $elapsed);
        } finally {
            @unlink($path);
        }
    }

    public function testReplayRealtimeWithIdleTrim(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-replay-');
        $this->writeCassette($path, [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hi']),
            new Event(t: 5.0, kind: EventKind::Quit, payload: []),
        ]);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $start = microtime(true);
            // With --idle-trim=0.1, a 5 second gap should be clamped to 0.1
            $exit = (new ReplayCommand())->run([$path, '--speed=realtime', '--idle-trim=0.1'], $stdout, $stderr);
            $elapsed = microtime(true) - $start;
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(0, $exit);
            $this->assertSame('hi', $out);
            // Should NOT have waited 5 seconds - should be clamped to ~0.1s
            $this->assertLessThan(1.0, $elapsed);
        } finally {
            @unlink($path);
        }
    }

    public function testReplayIdleTrimWithEqualsSyntax(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-replay-');
        $this->writeCassette($path, [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hi']),
            new Event(t: 0.01, kind: EventKind::Quit, payload: []),
        ]);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new ReplayCommand())->run([$path, '--speed=realtime', '--idle-trim=0.5'], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(0, $exit);
            $this->assertSame('hi', $out);
        } finally {
            @unlink($path);
        }
    }

    public function testReplayIdleTrimZeroOrNegative(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-replay-');
        $this->writeCassette($path, [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hi']),
            new Event(t: 0.01, kind: EventKind::Quit, payload: []),
        ]);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new ReplayCommand())->run([$path, '--idle-trim=0'], $stdout, $stderr);
            rewind($stderr);
            $err = (string) stream_get_contents($stderr);

            $this->assertSame(2, $exit);
            $this->assertStringContainsString('--idle-trim must be > 0', $err);
        } finally {
            @unlink($path);
        }
    }

    public function testReplayIdleTrimNegative(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-replay-');
        $this->writeCassette($path, [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hi']),
            new Event(t: 0.01, kind: EventKind::Quit, payload: []),
        ]);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new ReplayCommand())->run([$path, '--idle-trim=-1'], $stdout, $stderr);
            rewind($stderr);
            $err = (string) stream_get_contents($stderr);

            $this->assertSame(2, $exit);
            $this->assertStringContainsString('--idle-trim must be > 0', $err);
        } finally {
            @unlink($path);
        }
    }

    public function testReplayNoTrimWithTRaw(): void
    {
        // Test that --no-trim respects tRaw when present
        $path = tempnam(sys_get_temp_dir(), 'cv-replay-');
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a', 'tRaw' => 0.0]),
                new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => 'b', 'tRaw' => 5.0]), // 5 second gap in raw
                new Event(t: 0.002, kind: EventKind::Quit, payload: []),
            ],
        );
        (new JsonlFormat())->write($cassette, $path);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            // Without --no-trim, tRaw is ignored
            $exit = (new ReplayCommand())->run([$path, '--speed=instant', '--no-trim'], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(0, $exit);
            $this->assertSame('ab', $out);
        } finally {
            @unlink($path);
        }
    }

    public function testReplayMissingPath(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $exit = (new ReplayCommand())->run([], $stdout, $stderr);
        rewind($stderr);
        $err = (string) stream_get_contents($stderr);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('usage:', $err);
    }

    public function testReplayInvalidSpeed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-replay-');
        $this->writeCassette($path, [
            new Event(t: 0.0, kind: EventKind::Quit, payload: []),
        ]);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new ReplayCommand())->run([$path, '--speed=invalid'], $stdout, $stderr);
            rewind($stderr);
            $err = (string) stream_get_contents($stderr);

            $this->assertSame(2, $exit);
            $this->assertStringContainsString('--speed must be', $err);
        } finally {
            @unlink($path);
        }
    }

    public function testReplayNonExistentFile(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $exit = (new ReplayCommand())->run(['/no/such/file.cas'], $stdout, $stderr);
        rewind($stderr);
        $err = (string) stream_get_contents($stderr);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found', $err);
    }

    public function testSummary(): void
    {
        $cmd = new ReplayCommand();
        $this->assertSame("Stream a cassette's recorded output to stdout", $cmd->summary());
    }
}

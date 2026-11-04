<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Cli\ReplayCommand;
use SugarCraft\Vcr\Cli\StatsCommand;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * C2 (terminal-strand guard) + M15 (dead-success tape replay) + M7
 * (tape warnings to stderr) for `candy-vcr replay`/`stats`.
 *
 * The guard bracket is gated on interactivity: piped capture must stay
 * byte-exact, so the interactive path is driven through the documented
 * ttyProbe seam rather than a real controlling terminal.
 */
final class ReplayTerminalGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) sys_get_temp_dir() . '/cv-guard-' . bin2hex(random_bytes(6));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            $this->markTestSkipped("cannot create {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->dir);
    }

    /**
     * @param list<Event> $events
     */
    private function writeCassette(string $name, array $events): string
    {
        $path = $this->dir . '/' . $name;
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

    private function outputEvents(): array
    {
        return [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hello']),
            new Event(t: 0.1, kind: EventKind::Quit, payload: []),
        ];
    }

    public function testPipedReplayCarriesNoTerminalGuardBytes(): void
    {
        $path = $this->writeCassette('piped.cas', $this->outputEvents());
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');

        $exit = (new ReplayCommand())->run([$path], $stdout, $stderr);

        rewind($stdout);
        $out = (string) stream_get_contents($stdout);
        $this->assertSame(0, $exit);
        // A memory stream is never a tty: capture stays exactly the payload.
        $this->assertSame('hello', $out);
        $this->assertStringNotContainsString("\x1b[?1049", $out);
    }

    public function testInteractiveReplayWrapsStreamInSaveAndRestore(): void
    {
        $path = $this->writeCassette('tty.cas', $this->outputEvents());
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');

        $command = new ReplayCommand(static fn ($stream): bool => true);
        $exit = $command->run([$path], $stdout, $stderr);

        rewind($stdout);
        $out = (string) stream_get_contents($stdout);
        $this->assertSame(0, $exit);
        $this->assertSame(
            ReplayCommand::SCREEN_GUARD_SAVE . 'hello' . ReplayCommand::SCREEN_GUARD_RESTORE,
            $out,
        );
        // The restore leg is the anti-strand contract: saved screen, cursor
        // visible again, attributes reset — as separate verifiable constants
        // it must contain all three.
        $this->assertStringContainsString("\x1b[?1049u", ReplayCommand::SCREEN_GUARD_RESTORE);
        $this->assertStringContainsString("\x1b[?25h", ReplayCommand::SCREEN_GUARD_RESTORE);
        $this->assertStringContainsString("\x1b[0m", ReplayCommand::SCREEN_GUARD_RESTORE);
    }

    public function testSignalDuringReplayAbortsRestoresTerminalAndReportsConventionalStatus(): void
    {
        if (!extension_loaded('pcntl') || !\defined('SIGTERM') || !\function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('ext-pcntl required for the signal ladder');
        }

        // 3-second realtime gap: without the abort ladder this test would
        // sleep; the probe raises SIGTERM once handlers are live (arm
        // happens before the probe), so we must return fast with 143.
        $path = $this->writeCassette('signal.cas', [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'first']),
            new Event(t: 3.0, kind: EventKind::Output, payload: ['b' => 'after-gap']),
        ]);
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');

        $command = new ReplayCommand(static function ($stream): bool {
            posix_kill(posix_getpid(), SIGTERM);
            return true;
        });
        $start = microtime(true);
        $exit = $command->run([$path, '--speed=realtime'], $stdout, $stderr);
        $elapsed = microtime(true) - $start;

        rewind($stdout);
        $out = (string) stream_get_contents($stdout);
        $this->assertSame(143, $exit, 'SIGTERM must surface as 128+15');
        $this->assertLessThan(2.0, $elapsed, 'abort must interrupt the realtime schedule');
        // The flag lands before the first iteration: nothing plays, but the
        // terminal still gets the full restore — and the suite process gets
        // its default dispositions back (the run surviving IS the pin).
        $this->assertSame(
            ReplayCommand::SCREEN_GUARD_SAVE . ReplayCommand::SCREEN_GUARD_RESTORE,
            $out,
        );
    }

    public function testReplayingATapeWithNoOutputBytesIsAnnouncedFailureNotDeadSuccess(): void
    {
        $path = $this->dir . '/demo.tape';
        file_put_contents($path, "Type \"ls\"\nEnter\n");
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');

        $exit = (new ReplayCommand())->run([$path], $stdout, $stderr);

        rewind($stderr);
        $err = (string) stream_get_contents($stderr);
        $this->assertSame(1, $exit, 'tape replay with zero output bytes must not exit 0');
        $this->assertStringContainsString('render-tape', $err);
        $this->assertStringContainsString('no recorded output bytes', $err);
    }

    public function testTapeCompileWarningsSurfaceOnReplayStderr(): void
    {
        $path = $this->dir . '/warn.tape';
        file_put_contents($path, "Type \"A\"\nSource missing.tape\nType \"C\"\n");
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');

        $exit = (new ReplayCommand())->run([$path], $stdout, $stderr);

        rewind($stderr);
        $err = (string) stream_get_contents($stderr);
        $this->assertSame(1, $exit); // zero-output tape => M15 rc1 too
        $this->assertStringContainsString('candy-vcr replay: tape warning:', $err);
        $this->assertStringContainsString('Source include', $err);
        $this->assertStringContainsString('missing.tape', $err);
    }

    public function testTapeCompileWarningsSurfaceOnStatsStderr(): void
    {
        $path = $this->dir . '/warn-stats.tape';
        file_put_contents($path, "Type \"A\"\nSource missing.tape\nType \"C\"\n");
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');

        $exit = (new StatsCommand())->run([$path], $stdout, $stderr);

        rewind($stderr);
        $err = (string) stream_get_contents($stderr);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('candy-vcr stats: tape warning:', $err);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\AudioPlayer;

/**
 * E366 teardown pins for {@see AudioPlayer}.
 *
 * The live-audio lifecycle (ffplay present) is already characterised by
 * AudioPlayerTest; what was MISSING is the failure half — a child that
 * neither exits on stdin EOF nor dies on SIGTERM. Before E366, `stop()` was
 * terminate-then-close with no escalation (so it BLOCKED on exactly this
 * child, `proc_close()` waiting forever), and there was no destructor at
 * all (so a player merely dropped out of scope ABANDONED a live ffplay to
 * init, descriptors and all — the second half of E366's measurement).
 *
 * ffplay is not required: `buildCommand()` is the class's own protected
 * seam, and these fixtures substitute a php child with identical signal
 * behaviour, which is what the ladder actually consumes. Watchdog-armed so
 * a regressed escalation reds at 25s instead of wedging the suite.
 */
final class AudioPlayerTeardownTest extends TestCase
{
    private const MARKER = 'sugar-reel-audioplayer-teardown-child';

    /** Ready-file the fixture touches once SIGTERM is actually ignored. */
    private string $readyPath = '';

    /** @var resource|null */
    private $watchdog = null;

    protected function tearDown(): void
    {
        if ($this->readyPath !== '' && \is_file($this->readyPath)) {
            \unlink($this->readyPath);
        }
        $this->readyPath = '';
        StubbornPlayerFixture::$readyPath = '';
        if ($this->watchdog !== null && is_resource($this->watchdog)) {
            \proc_terminate($this->watchdog);
            \proc_close($this->watchdog);
        }
        $this->watchdog = null;
    }

    public function testStopEscalatesPastASigtermIgnoringPlayerChildAndLeavesNoOrphan(): void
    {
        if (!\function_exists('pcntl_signal')) {
            $this->markTestSkipped('the stubborn-child fixture needs ext-pcntl in the child');
        }

        $player = $this->startStubbornPlayer();

        $pid = $this->childPid($player);
        $start = \microtime(true);
        $player->stop();
        $elapsed = \microtime(true) - $start;

        $this->assertLessThan(
            6.0,
            $elapsed,
            'stop() must terminate in bounded time even against a child that ignores EOF and SIGTERM',
        );
        $this->assertFalse($player->isPlaying());
        $this->assertFalse(
            \posix_kill($pid, 0),
            'no player subprocess may survive stop()',
        );
    }

    public function testDestructStopsALivePlayerInsteadOfAbandoningIt(): void
    {
        if (!\function_exists('pcntl_signal')) {
            $this->markTestSkipped('the stubborn-child fixture needs ext-pcntl in the child');
        }

        $player = $this->startStubbornPlayer();

        $pid = $this->childPid($player);
        $start = \microtime(true);
        unset($player);
        $elapsed = \microtime(true) - $start;

        // E366's measured abandonment: without a destructor this returned in
        // microseconds with the child STILL RUNNING. It must now take at
        // least the escalation and leave nothing behind.
        $this->assertGreaterThan(
            0.9,
            $elapsed,
            'the destructor must actually walk the ladder for a SIGTERM-ignoring child',
        );
        $this->assertLessThan(6.0, $elapsed, 'and the ladder is bounded');
        $this->assertFalse(
            \posix_kill($pid, 0),
            'a player that merely goes out of scope must leave no live child behind',
        );
    }

    // ─── harness ────────────────────────────────────────────────────────────

    /**
     * Spawn the stubborn player and return it only once the child has
     * declared its SIGTERM handler installed (ready file) AND is running.
     *
     * proc_open() answers as soon as the KERNEL has the child — microseconds
     * before PHP bootstraps and reaches `pcntl_signal`. A TERM landing inside
     * that window kills a child whose stubbornness was never installed, and
     * the bounded-teardown assertions would pass for a reason they did not
     * earn. MEASURED on this runner: the un-gated fixture raced exactly that
     * way. The ready-file handshake closes the window.
     */
    private function startStubbornPlayer(): StubbornPlayerFixture
    {
        if (!\function_exists('pcntl_signal')) {
            $this->markTestSkipped('the stubborn-child fixture needs ext-pcntl in the child');
        }

        $this->readyPath = \sys_get_temp_dir() . '/df-ap-' . \getmypid() . '.ready';
        StubbornPlayerFixture::$readyPath = $this->readyPath;
        @\unlink($this->readyPath);

        $player = new StubbornPlayerFixture('/none.wav');
        $player->start();
        $this->armWatchdog();

        $deadline = \microtime(true) + 5.0;
        while (!\file_exists($this->readyPath) && \microtime(true) < $deadline) {
            \usleep(10_000);
        }
        $this->assertFileExists($this->readyPath, 'the stubborn fixture never reached its signal-handler line');
        $this->assertTrue($player->isPlaying(), 'the fixture child must be alive (ready file exists ⇒ past pcntl_signal ⇒ looping)');

        return $player;
    }

    private function childPid(AudioPlayer $player): int
    {
        // The declaring class, not the fixture subclass: processHandle is
        // PRIVATE to AudioPlayer, and ReflectionProperty resolves private
        // properties on the declaring class only.
        $handle = (new \ReflectionProperty(AudioPlayer::class, 'processHandle'))->getValue($player);
        $this->assertIsResource($handle);

        return (int) (\proc_get_status($handle)['pid'] ?? -1);
    }

    private function armWatchdog(): void
    {
        $this->watchdog = \proc_open(
            ['sh', '-c', 'sleep 25; pkill -9 -f ' . self::MARKER],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($this->watchdog);
        foreach ($pipes as $pipe) {
            \fclose($pipe);
        }
    }
}

/**
 * The production class's own documented seam (protected buildCommand, used
 * by its tests already) pointed at a php child that ignores SIGTERM and
 * loops forever — the exact shape E366 measured as fatal for the old
 * terminate-then-close pair.
 */
final class StubbornPlayerFixture extends AudioPlayer
{
    /** Set by the test before start(); the child touches it once stubborn. */
    public static string $readyPath = '';

    protected function buildCommand(): ?array
    {
        $rd = self::$readyPath;

        return [
            \PHP_BINARY,
            '-r',
            'if (function_exists("pcntl_signal")) { pcntl_signal(SIGTERM, SIG_IGN); }'
            . " touch('{$rd}'); // sugar-reel-audioplayer-teardown-child\n"
            . 'while (true) { usleep(50000); }',
        ];
    }
}

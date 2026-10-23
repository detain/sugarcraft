<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use SugarCraft\ColorProfile\AsyncProbe;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Probe;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

/**
 * @covers \SugarCraft\ColorProfile\AsyncProbe
 *
 * Note: These tests verify the synchronous fallback path of AsyncProbe.
 * When no event loop is available, AsyncProbe::colorProfile() returns
 * a promise that resolves to Probe::colorProfile() synchronously.
 * Full async testing would require an actual event loop.
 */
final class AsyncProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear environment variables that might affect detection
        $keys = ['CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM', 'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY'];
        foreach ($keys as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    public function testColorProfileReturnsPromiseInterface(): void
    {
        $promise = AsyncProbe::colorProfile();

        $this->assertInstanceOf(PromiseInterface::class, $promise);
    }

    public function testProbeColorProfileReturnsAnsiByDefault(): void
    {
        // This tests the underlying Probe that AsyncProbe falls back to
        // Without TERM set, default should be Ansi
        $profile = Probe::colorProfile();

        $this->assertSame(ColorProfile::Ansi, $profile);
    }

    public function testProbeColorProfileReturnsTrueColorWhenForced(): void
    {
        $_ENV['CLICOLOR_FORCE'] = '1';
        putenv('CLICOLOR_FORCE=1');

        try {
            $profile = Probe::colorProfile();
            $this->assertSame(ColorProfile::TrueColor, $profile);
        } finally {
            unset($_ENV['CLICOLOR_FORCE']);
            putenv('CLICOLOR_FORCE');
        }
    }

    public function testProbeColorProfileReturnsNoTTYWhenDisabled(): void
    {
        $_ENV['NO_COLOR'] = '1';
        putenv('NO_COLOR=1');

        try {
            $profile = Probe::colorProfile();
            $this->assertSame(ColorProfile::NoTTY, $profile);
        } finally {
            unset($_ENV['NO_COLOR']);
            putenv('NO_COLOR');
        }
    }

    public function testProbeColorProfileReturnsANSI256For256ColorTerm(): void
    {
        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');

        try {
            $profile = Probe::colorProfile();
            $this->assertSame(ColorProfile::Ansi256, $profile);
        } finally {
            unset($_ENV['TERM']);
            putenv('TERM');
        }
    }

    public function testProbeColorProfileReturnsANSIForXterm(): void
    {
        $_ENV['TERM'] = 'xterm';
        putenv('TERM=xterm');

        try {
            $profile = Probe::colorProfile();
            $this->assertSame(ColorProfile::Ansi, $profile);
        } finally {
            unset($_ENV['TERM']);
            putenv('TERM');
        }
    }

    public function testProbeIsNoColorWhenNoColorSet(): void
    {
        $_ENV['NO_COLOR'] = '1';
        putenv('NO_COLOR=1');

        try {
            $this->assertTrue(Probe::isNoColor());
        } finally {
            unset($_ENV['NO_COLOR']);
            putenv('NO_COLOR');
        }
    }

    public function testProbeIsNoColorWhenNoColorNotSet(): void
    {
        $this->assertFalse(Probe::isNoColor());
    }

    public function testProbeIsForceColorWhenForced(): void
    {
        $_ENV['CLICOLOR_FORCE'] = '1';
        putenv('CLICOLOR_FORCE=1');

        try {
            $this->assertTrue(Probe::isForceColor());
        } finally {
            unset($_ENV['CLICOLOR_FORCE']);
            putenv('CLICOLOR_FORCE');
        }
    }

    public function testProbeIsForceColorWhenNotForced(): void
    {
        $this->assertFalse(Probe::isForceColor());
    }

    // ─── env gate, no fabricated TERM, bounded concurrency ───────────────

    public function testNoColorResolvesSynchronouslyWithoutSpawning(): void
    {
        $_ENV['NO_COLOR'] = '1';
        putenv('NO_COLOR=1');
        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');

        try {
            $resolved = null;
            AsyncProbe::colorProfile()->then(function (ColorProfile $p) use (&$resolved): void {
                $resolved = $p;
            });

            // The suppression gate returns an ALREADY-resolved promise — a
            // pending child would leave $resolved null until Loop::run().
            $this->assertSame(ColorProfile::NoTTY, $resolved);
        } finally {
            unset($_ENV['NO_COLOR'], $_ENV['TERM']);
            putenv('NO_COLOR');
            putenv('TERM');
        }
    }

    public function testUnsetTermResolvesSyncInsteadOfProbingFabricatedXterm(): void
    {
        // TERM absent: the old code ran `infocmp -1 xterm`, reporting the
        // capabilities of a terminal nobody declared.
        $resolved = null;
        AsyncProbe::colorProfile()->then(function (ColorProfile $p) use (&$resolved): void {
            $resolved = $p;
        });

        $this->assertSame(ColorProfile::Ansi, $resolved, 'must be the sync default, not an async probe');
    }

    public function testFreshSlotsAtCapDegradeToSyncDetection(): void
    {
        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');
        $counter = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $counter->setAccessible(true);

        try {
            $counter->setValue(null, self::fakeSlots(4, \microtime(true) + 10.0));

            $resolved = null;
            AsyncProbe::colorProfile()->then(function (ColorProfile $p) use (&$resolved): void {
                $resolved = $p;
            });

            $this->assertSame(ColorProfile::Ansi256, $resolved, 'at-cap calls must sync-resolve');
            $this->assertCount(4, $counter->getValue(), 'the cap path must neither spawn nor book a slot');
        } finally {
            self::clearSlots($counter);
            unset($_ENV['TERM']);
            putenv('TERM');
        }
    }

    public function testStaleSlotsAreReclaimedAndTheAsyncLegSettles(): void
    {
        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');
        $counter = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $counter->setAccessible(true);

        try {
            // Four slots whose deadline passed: their callers abandoned the
            // loop, so no exit/error event could ever release them. The next
            // call must reclaim them instead of degrading forever.
            $counter->setValue(null, self::fakeSlots(4, \microtime(true) - 10.0));

            $resolved = null;
            AsyncProbe::colorProfile()->then(function (ColorProfile $p) use (&$resolved): void {
                $resolved = $p;
            });

            if ($resolved === null) {
                // The reclaimed capacity went to a real infocmp child; run
                // the loop until the settlement lands, with a wall-clock
                // watchdog so a wedged child cannot hang the suite (the
                // probe's own ceiling timer settles it at 5s+1s regardless).
                $loop = \React\EventLoop\Loop::get();
                $poll = null;
                $watchdog = $loop->addTimer(8.0, static function () use ($loop): void {
                    $loop->stop();
                });
                $poll = $loop->addPeriodicTimer(0.005, static function () use ($loop, &$resolved, &$poll, $watchdog): void {
                    if ($resolved === null) {
                        return;
                    }
                    $loop->cancelTimer($watchdog);
                    if ($poll !== null) {
                        $loop->cancelTimer($poll);
                    }
                    $loop->stop();
                });
                $loop->run();
            }

            $this->assertInstanceOf(ColorProfile::class, $resolved, 'reclaimed capacity must settle a result');
            $this->assertCount(0, $counter->getValue(), 'every settle path releases its slot');
        } finally {
            self::clearSlots($counter);
            unset($_ENV['TERM']);
            putenv('TERM');
        }
    }

    public function testEverySpawnPathBooksABoundedDeadlineSlot(): void
    {
        // Structural pin for the bounded-child ladder: the ceiling timer
        // escalates and settles, the exit handler cancels it, and stale
        // reclamation kills the wedged child — remove any leg and this reddens.
        $src = file_get_contents(__DIR__ . '/../src/AsyncProbe.php');
        $this->assertStringContainsString('addTimer(self::PROBE_TIMEOUT_SECONDS', $src);
        $this->assertStringContainsString('$process->terminate();', $src);
        $this->assertStringContainsString('terminate(defined(\'SIGKILL\') ? \\SIGKILL : 9)', $src);
        $this->assertStringContainsString('cancelTimer($timeoutTimer)', $src);
        $this->assertStringContainsString('reclaimStaleSlots()', $src);
    }

    /**
     * @return array<int, array{process: \\React\\ChildProcess\\Process, deadline: float}>
     */
    private static function fakeSlots(int $count, float $deadline): array
    {
        $slots = [];
        for ($i = 0; $i < $count; $i++) {
            $slots[$i + 1] = ['process' => new \React\ChildProcess\Process('sleep 30'), 'deadline' => $deadline];
        }

        return $slots;
    }

    private static function clearSlots(\ReflectionProperty $counter): void
    {
        foreach ($counter->getValue() as $slot) {
            $slot['process']->terminate(defined('SIGKILL') ? \SIGKILL : 9);
        }
        $counter->setValue(null, []);
    }
}


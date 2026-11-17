<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\ColorProfile\AsyncProbe;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\InfocmpBinary;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\ColorProfile\Probe\TerminalProbe;

/**
 * @covers \SugarCraft\ColorProfile\AsyncProbe::report
 *
 * The async capability report is the replacement for the synchronous
 * TerminalProbe twin. Its contract: the same ProbeReport the sync pipeline
 * composes, with the infocmp child spawned only on the loop the caller hands
 * in, and no spawn at all where the sync pipeline would not ask for a dump.
 */
final class AsyncProbeReportTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $keys = ['CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM', 'FORCE_COLOR', 'TERM_PROGRAM', 'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY'];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
            putenv($key);
        }
        AsyncProbe::resetForTesting();
        InfocmpBinary::reset();
    }

    protected function tearDown(): void
    {
        AsyncProbe::resetForTesting();
        InfocmpBinary::reset();

        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
        parent::tearDown();
    }

    private function setTerm(string $term): void
    {
        $_ENV['TERM'] = $term;
        putenv('TERM=' . $term);
    }

    /**
     * Run $loop until $promise settles, with a watchdog so a wedged child
     * cannot hang the suite (the probe's own 5 s + 1 s ladder settles first).
     */
    private static function settle(PromiseInterface $promise, LoopInterface $loop): mixed
    {
        $result = null;
        $done = false;
        $promise->then(static function (mixed $value) use (&$result, &$done): void {
            $result = $value;
            $done = true;
        });

        if (!$done) {
            $poll = null;
            $watchdog = $loop->addTimer(8.0, static function () use ($loop): void {
                $loop->stop();
            });
            $poll = $loop->addPeriodicTimer(0.005, static function () use ($loop, &$done, &$poll, $watchdog): void {
                if (!$done) {
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

        self::assertTrue($done, 'the report promise must settle');

        return $result;
    }

    /** @return array<string, string> */
    private static function sources(ProbeReport $report): array
    {
        return $report->allWithSource();
    }

    public function testReportWithoutLoopResolvesInPlaceWithTheSyncReport(): void
    {
        $this->setTerm('xterm-256color');

        $resolved = null;
        AsyncProbe::report()->then(static function (ProbeReport $report) use (&$resolved): void {
            $resolved = $report;
        });

        $this->assertInstanceOf(ProbeReport::class, $resolved, 'no loop means an in-place settle, with no loop run');
        $this->assertSame(self::sources(TerminalProbe::run()), self::sources($resolved));
    }

    public function testSuppressionSettlesWithoutSpawningAChild(): void
    {
        $this->setTerm('xterm-256color');
        $_ENV['NO_COLOR'] = '1';
        putenv('NO_COLOR=1');

        $loop = new StreamSelectLoop();
        $report = null;
        AsyncProbe::report($loop)->then(static function (ProbeReport $r) use (&$report): void {
            $report = $r;
        });

        $this->assertInstanceOf(ProbeReport::class, $report, 'NO_COLOR answers before any loop turn');
        $this->assertTrue($report->has(Capability::NoColor));
        $this->assertCount(0, $this->inFlightSlots(), 'the suppression gate keeps the child from ever being booked');

        unset($_ENV['NO_COLOR']);
        putenv('NO_COLOR');
    }

    public function testReportOverARealChildMatchesTheSyncReport(): void
    {
        if (InfocmpBinary::path() === null) {
            $this->markTestSkipped('no infocmp binary on this host');
        }

        foreach (['xterm-256color', 'xterm-kitty', 'screen-256color'] as $term) {
            AsyncProbe::resetForTesting();
            $this->setTerm($term);

            $loop = new StreamSelectLoop();
            $async = self::settle(AsyncProbe::report($loop), $loop);

            $this->assertInstanceOf(ProbeReport::class, $async, $term);
            $this->assertSame(
                self::sources(TerminalProbe::run()),
                self::sources($async),
                "async report must equal the sync composition for TERM={$term}",
            );
        }
    }

    public function testCompletedChildReplaysFromTheDumpMemoWithoutASpawn(): void
    {
        if (InfocmpBinary::path() === null) {
            $this->markTestSkipped('no infocmp binary on this host');
        }

        $this->setTerm('xterm-256color');
        $loop = new StreamSelectLoop();
        $first = self::settle(AsyncProbe::report($loop), $loop);
        $this->assertInstanceOf(ProbeReport::class, $first);

        // The second call must answer from the dump memo: it settles before any
        // loop turn and books no slot.
        $second = null;
        AsyncProbe::report($loop)->then(static function (ProbeReport $r) use (&$second): void {
            $second = $r;
        });

        $this->assertInstanceOf(ProbeReport::class, $second, 'a memoized dump answers in place');
        $this->assertSame(self::sources($first), self::sources($second));
        $this->assertCount(0, $this->inFlightSlots());
    }

    public function testSaturatedCapDegradesToTheEnvironmentReportWithoutSpawning(): void
    {
        if (InfocmpBinary::path() === null) {
            $this->markTestSkipped('no infocmp binary on this host');
        }

        $this->setTerm('xterm-256color');
        $slots = [];
        for ($i = 1; $i <= AsyncProbe::MAX_CONCURRENT_PROBES; $i++) {
            $slots[$i] = ['process' => new \React\ChildProcess\Process('sleep 30'), 'deadline' => \microtime(true) + 60.0];
        }
        $this->setInFlightSlots($slots);

        try {
            $loop = new StreamSelectLoop();
            $report = null;
            AsyncProbe::report($loop)->then(static function (ProbeReport $r) use (&$report): void {
                $report = $r;
            });

            $this->assertInstanceOf(ProbeReport::class, $report, 'a full cap answers in place');
            $this->assertFalse(\in_array('terminfo:Tc|RGB', self::sources($report), true), 'no terminfo leg ran at the cap');
            $this->assertCount(AsyncProbe::MAX_CONCURRENT_PROBES, $this->inFlightSlots(), 'no slot is added at the cap');
        } finally {
            $this->setInFlightSlots([]);
        }
    }

    /** @return array<int, array{process: mixed, deadline: float}> */
    private function inFlightSlots(): array
    {
        $prop = new \ReflectionProperty(AsyncProbe::class, 'inFlight');

        return $prop->getValue();
    }

    /** @param array<int, array{process: mixed, deadline: float}> $slots */
    private function setInFlightSlots(array $slots): void
    {
        $prop = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $prop->setValue(null, $slots);
    }
}

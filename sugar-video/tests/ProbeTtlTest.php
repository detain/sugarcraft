<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Source\Probe;

/**
 * F-17: the binary-presence probe is a memoized lookup with a 60s TTL, not a
 * per-call `command -v` fork — probes now ride every decoder reopen.
 */
final class ProbeTtlTest extends TestCase
{
    protected function tearDown(): void
    {
        Probe::setClockForTesting(null);
        Probe::clearBinaryCacheForTesting();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // F-17: probe memo
    // -------------------------------------------------------------------------

    public function testProbeMemoizesPathLookupsUntilExplicitlyCleared(): void
    {
        // Deterministic memo witness, no sleep involved: resolve ffmpeg via
        // the stub PATH (a real file in a real dir), then DELETE the stub —
        // a fresh lookup would now answer null, but the memo keeps serving
        // the cached path until clearBinaryCacheForTesting() (or the TTL).
        $dir = sys_get_temp_dir() . '/reel-f17-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $stub = $dir . '/ffmpeg';
        file_put_contents($stub, "#!/bin/sh\nexit 0\n");
        chmod($stub, 0755);

        $originalPath = getenv('PATH');
        putenv('PATH=' . $dir);
        try {
            Probe::clearBinaryCacheForTesting();
            self::assertSame($stub, Probe::ffmpeg());

            unlink($stub);
            self::assertSame($stub, Probe::ffmpeg(), 'memo serves the cached answer without re-forking command -v');

            Probe::clearBinaryCacheForTesting();
            self::assertNull(Probe::ffmpeg(), 'after clearing, the lookup re-runs and sees the truth');
        } finally {
            putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);
            @unlink($stub);
            @rmdir($dir);
            Probe::clearBinaryCacheForTesting();
        }
    }

    public function testTtlIsSixtySecondsAndDocumentedInItsOwnConstant(): void
    {
        // F-17's honest shape: a bounded staleness window, not a permanent
        // freeze — an ffmpeg installed mid-session is picked up within a
        // minute. The constant IS the contract; the expiry math is pinned
        // through the SV-M17 clock seam below (cache hit inside TTL,
        // re-lookup outside it).
        self::assertSame(60.0, Probe::CACHE_TTL_SECONDS);
    }

    // -------------------------------------------------------------------------
    // SV-M17: "ran, answered nothing" vs "could not execute"
    // -------------------------------------------------------------------------

    public function testAnAnsweredNoneLookupIsCachedForTheFullTtl(): void
    {
        $t = 1000.0;
        Probe::setClockForTesting(static function () use (&$t): float {
            return $t;
        });
        ProbeShellTape::$tape = [''];            // ran, found nothing
        ProbeShellTape::$execs = 0;
        Probe::clearBinaryCacheForTesting();

        self::assertNull(ProbeShellTape::ffmpeg());
        $t += 30.0;
        self::assertNull(ProbeShellTape::ffmpeg(), 'inside the 60s window the empty answer rides the memo');
        self::assertSame(1, ProbeShellTape::$execs, 'a cached absence must not re-fork');

        $t += 30.5;
        self::assertNull(ProbeShellTape::ffmpeg());
        self::assertSame(2, ProbeShellTape::$execs, 'an answered-miss re-probes once the TTL expires');
    }

    public function testAnExecFailureIsRetriedNotPinnedForSixtySeconds(): void
    {
        self::assertSame(1.0, Probe::EXEC_RETRY_SECONDS, 'the retry grace is the documented tiny window');

        $t = 500.0;
        Probe::setClockForTesting(static function () use (&$t): float {
            return $t;
        });
        ProbeShellTape::$tape = [null, '/usr/bin/ffmpeg']; // one hiccup, then the real answer
        ProbeShellTape::$execs = 0;
        Probe::clearBinaryCacheForTesting();

        self::assertNull(ProbeShellTape::ffmpeg(), 'a fork hiccup still reports missing');
        $t += 20.0; // well inside the OLD 60s pin — under M17 a failure only owns 1s
        self::assertSame('/usr/bin/ffmpeg', ProbeShellTape::ffmpeg(), 'the probe retries past the 1s window instead of pinning missing for a minute');
        self::assertSame(2, ProbeShellTape::$execs);
    }

    public function testSubclassProbeOverridesBypassTheMemoEntirely(): void
    {
        // FakeProbe (existing test double) replaces which() wholesale — the
        // memo lives INSIDE the base which(), so simulation stays exact.
        $fake = new class extends Probe {
            protected static function which(string $cmd): ?string
            {
                return '/entirely/fake/' . $cmd;
            }
        };
        self::assertSame('/entirely/fake/ffmpeg', $fake::ffmpeg());
    }
}

/**
 * SV-M17 fixture: replaces the raw shell seam so tests can script the
 * difference between "ran, answered nothing" (a cached absence) and
 * "could not execute" (a transient failure that must NOT be cached for
 * the full TTL), and count how often the lookup really forked.
 */
final class ProbeShellTape extends Probe
{
    /** @var list<string|null> scripted shell_exec answers ('' = ran/none, null = exec failure) */
    public static array $tape = [];
    public static int $execs = 0;

    protected static function runShellLookup(string $shell): ?string
    {
        self::$execs++;

        return self::$tape === [] ? '' : array_shift(self::$tape);
    }
}

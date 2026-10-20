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
        // minute. The constant IS the contract; the expiry math mirrors the
        // memo witness above (cache hit inside TTL, re-lookup outside it —
        // aging is not injectable without a clock seam, deliberately: the
        // TTL is coarse by design).
        self::assertSame(60.0, Probe::CACHE_TTL_SECONDS);
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

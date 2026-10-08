<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use SugarCraft\Palette\AsyncProbe;
use SugarCraft\Palette\ColorProfile;
use SugarCraft\Palette\Probe;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

/**
 * @covers \SugarCraft\Palette\AsyncProbe
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

    // ─── A3b: env gate, no fabricated TERM, concurrency cap ────────────────

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

    public function testConcurrencyCapDegradesToSyncDetection(): void
    {
        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');
        $counter = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $counter->setAccessible(true);
        $original = $counter->getValue();
        $cap = (new \ReflectionClass(AsyncProbe::class))
            ->getConstant('MAX_CONCURRENT_PROBES');

        try {
            $counter->setValue(null, $cap);

            $resolved = null;
            AsyncProbe::colorProfile()->then(function (ColorProfile $p) use (&$resolved): void {
                $resolved = $p;
            });

            $this->assertSame(ColorProfile::Ansi256, $resolved, 'at-cap calls must sync-resolve');
            $this->assertSame($cap, $counter->getValue(), 'the cap path must neither spawn nor book a slot');
        } finally {
            $counter->setValue(null, $original);
            unset($_ENV['TERM']);
            putenv('TERM');
        }
    }
}

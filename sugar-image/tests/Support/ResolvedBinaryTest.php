<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Support\ResolvedBinary;
use SugarCraft\Image\Support\SttyBinary;
use SugarCraft\Image\Support\TmuxBinary;

/**
 * The shared argv[0]-resolution doctrine: an absolute, gate-passing path
 * or nothing — never the OS PATH search. Mirrors the ChafaBinary grammar
 * the class generalizes; the roster legs are fabricated so both doors
 * (tmux, stty) are pinned machine-portably.
 */
final class ResolvedBinaryTest extends TestCase
{
    private ?string $dir = null;

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['PATH', 'SIBUT_TEST_OVERRIDE', TmuxBinary::ENV_OVERRIDE, SttyBinary::ENV_OVERRIDE] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        $this->dir = sys_get_temp_dir() . '/sib-resolved-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0700, true);
        TmuxBinary::reset();
        SttyBinary::reset();
    }

    protected function tearDown(): void
    {
        TmuxBinary::reset();
        SttyBinary::reset();
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }
        if ($this->dir !== null) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        }
        $this->dir = null;
    }

    private function executable(string $name): string
    {
        $path = (string) $this->dir . '/' . $name;
        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, 0755);

        return $path;
    }

    private function plainFile(string $name): string
    {
        $path = (string) $this->dir . '/' . $name;
        file_put_contents($path, "not executable\n");
        chmod($path, 0644);

        return $path;
    }

    public function testAbsoluteUsableOverrideIsUsedVerbatim(): void
    {
        $fake = $this->executable('tmux');
        putenv('SIBUT_TEST_OVERRIDE=' . $fake);

        $this->assertSame($fake, ResolvedBinary::locate('SIBUT_TEST_OVERRIDE', ['/nonexistent/tool']));
    }

    public function testBrokenAbsoluteOverrideIsRefusedWithoutRosterFallback(): void
    {
        // Doctrine: a misconfiguration must never be masked by whatever
        // the roster happens to hold — refusal is the answer.
        $missing = (string) $this->dir . '/no-such-binary';
        putenv('SIBUT_TEST_OVERRIDE=' . $missing);

        $this->assertNull(ResolvedBinary::locate('SIBUT_TEST_OVERRIDE', [
            $this->executable('first-would-have-won'),
        ]));
    }

    public function testNonExecutableAbsoluteOverrideIsRefused(): void
    {
        putenv('SIBUT_TEST_OVERRIDE=' . $this->plainFile('plain'));

        $this->assertNull(ResolvedBinary::locate('SIBUT_TEST_OVERRIDE', []));
    }

    public function testRelativePathOverrideIsRefused(): void
    {
        // A slash-bearing relative name is exactly the shape the gate exists to reject.
        putenv('SIBUT_TEST_OVERRIDE=./tmux');
        $this->assertNull(ResolvedBinary::locate('SIBUT_TEST_OVERRIDE', []));

        putenv('SIBUT_TEST_OVERRIDE=bin/../tmux');
        $this->assertNull(ResolvedBinary::locate('SIBUT_TEST_OVERRIDE', []));
    }

    public function testBareNameOverrideConsultsOnlyAbsolutePathDirs(): void
    {
        $script = $this->executable('tool');
        $name   = basename($script);
        putenv('SIBUT_TEST_OVERRIDE=' . $name);
        putenv('PATH=' . $this->dir . PATH_SEPARATOR . 'relative/dir' . PATH_SEPARATOR . ':');

        $this->assertSame($script, ResolvedBinary::locate('SIBUT_TEST_OVERRIDE', []));
    }

    public function testBareNameOverrideWithEmptyPathResolvesToNothing(): void
    {
        $name = basename($this->executable('tool'));
        putenv('SIBUT_TEST_OVERRIDE=' . $name);
        putenv('PATH=');

        $this->assertNull(ResolvedBinary::locate('SIBUT_TEST_OVERRIDE', [$this->executable('roster-would-win')]));
    }

    public function testRosterFirstExecutableWinsInOrder(): void
    {
        $first  = $this->executable('first');
        $second = $this->executable('second');

        $this->assertSame($first, ResolvedBinary::locate(null, [$first, $second]));
    }

    public function testRosterSkipsMissingAndNonExecutableEntries(): void
    {
        $second = $this->executable('second');

        $this->assertSame(
            $second,
            ResolvedBinary::locate(null, [(string) $this->dir . '/missing', $this->plainFile('plain'), $second]),
        );
    }

    public function testEmptyOverrideBehavesAsUnset(): void
    {
        putenv('SIBUT_TEST_OVERRIDE=');
        $only = $this->executable('only');

        $this->assertSame($only, ResolvedBinary::locate('SIBUT_TEST_OVERRIDE', [$only]));
    }

    public function testUsableGateRefusesDirectoryAndMissing(): void
    {
        $this->assertFalse(ResolvedBinary::usable((string) $this->dir));
        $this->assertFalse(ResolvedBinary::usable((string) $this->dir . '/nope'));
        $this->assertTrue(ResolvedBinary::usable($this->executable('gate')));
    }

    // ------------------------------------------------- per-tool facade arms

    public function testTmuxBinaryHonoursOverrideAndReReadsItPerCall(): void
    {
        $fakeA = $this->executable('tmux-a');
        $fakeB = $this->executable('tmux-b');

        putenv(TmuxBinary::ENV_OVERRIDE . '=' . $fakeA);
        $this->assertSame($fakeA, TmuxBinary::path());

        // The override leg is never memoised: moving the knob takes
        // effect on the very next call (test-seam law).
        putenv(TmuxBinary::ENV_OVERRIDE . '=' . $fakeB);
        $this->assertSame($fakeB, TmuxBinary::path());

        // A broken override refuses outright — the roster (which on a
        // tmux-equipped runner may resolve) must NOT answer through it.
        putenv(TmuxBinary::ENV_OVERRIDE . '=' . (string) $this->dir . '/gone');
        $this->assertNull(TmuxBinary::path());
    }

    public function testSttyBinaryHonoursOverrideAndRefusesBroken(): void
    {
        $fake = $this->executable('stty');
        putenv(SttyBinary::ENV_OVERRIDE . '=' . $fake);
        $this->assertSame($fake, SttyBinary::path());

        putenv(SttyBinary::ENV_OVERRIDE . '=' . $this->plainFile('plain-stty'));
        $this->assertNull(SttyBinary::path());
    }
}

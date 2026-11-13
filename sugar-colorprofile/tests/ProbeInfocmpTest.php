<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Probe;
use SugarCraft\ColorProfile\Probe\InfocmpBinary;
use SugarCraft\ColorProfile\Probe\TerminfoParser;

/**
 * @covers \SugarCraft\ColorProfile\Probe
 * @group infocmp
 *
 * Hermetic phase-2 pins. Every test answers through a stuffed fake infocmp
 * (the in-suite idiom from tests/Probe/InfocmpEnvPurityTest.php), never the
 * host binary or the real terminfo database: the pre-CP-26 version of this
 * file asserted only that `shell_exec('infocmp -1 xterm 2>/dev/null')` was
 * non-null — a test that cannot fail — while its sibling execed the HOST
 * binary and inherited whatever memo a prior file had written. The Tc
 * promotion at the async face stays pinned where it already lives; the
 * probe leg here is the synchronous ladder answer these names describe.
 */
final class ProbeInfocmpTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Fresh statics per test: before this seam was wired the phase-2
        // memo crossed method and file boundaries.
        Probe::resetForTesting();

        // Preserve original env values. FORCE_COLOR joins the list (CP-26):
        // a developer/CI shell exporting it outranks every TERM rung the
        // suites here assert.
        $keys = ['CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM', 'FORCE_COLOR', 'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY'];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        Probe::resetForTesting();
    }

    private function env(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    /**
     * Stuff a fake infocmp printing one capability per printf line. The
     * `''` sentinel (binary absent) is the OTHER hermetic arm; tests that
     * need a verdict from a dump pass it explicitly.
     *
     * @return string the stuffed path, for unlinking
     */
    private function stuffInfocmpPrinting(string $printfBody): string
    {
        $printer = \tempnam(\sys_get_temp_dir(), 'infocmp');
        $this->assertIsString($printer);
        \file_put_contents($printer, "#!/bin/sh\nprintf '" . $printfBody . "'\n");
        \chmod($printer, 0755);
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);

        return $printer;
    }

    public function testInfocmpTcCapabilityUpgradesAnsiToTrueColor(): void
    {
        // Parser leg — the documented name-position semantics (this is what
        // the matcher exists to decide): a capability name token at the
        // start of an entry counts; the same word inside a description
        // names `des`, not `Tc` — the exact false-match the old whole-output
        // /\bTc\b/ regex could not tell apart.
        self::assertTrue(
            TerminfoParser::hasCapName("xterm|X11 terminal emulator,\n\tcolors#8,\n\tTc,\n", TerminfoParser::TRUECOLOR_NAMES),
            'a name-position Tc declares truecolor',
        );
        self::assertTrue(
            TerminfoParser::hasCapName("xterm|X11 terminal emulator,\n\trgb=\\E[38;2,%p1;%p2;%p3m,\\n", TerminfoParser::TRUECOLOR_NAMES),
            'a name-position rgb declares truecolor',
        );
        self::assertFalse(
            TerminfoParser::hasCapName("xterm|X11 terminal emulator,\n\tdes=xterm with Tc support,\n", TerminfoParser::TRUECOLOR_NAMES),
            'Tc inside a description is not a capability name',
        );
        // CP-27 roster truth: matching is EXACT on the lowercased token —
        // the retired underscore fold minted TrueColor from a spliced name,
        // and the legitimate snake spelling (sixel_screen_mode) now rides
        // the roster by name instead of relying on the fold.
        self::assertFalse(
            TerminfoParser::hasCapName("forged|spliced name,\n\tt_c,\n", TerminfoParser::TRUECOLOR_NAMES),
            't_c is not the Tc capability',
        );
        self::assertTrue(
            TerminfoParser::hasCapName("foot|sixel,\n\tsixel_screen_mode#1,\n", TerminfoParser::SIXEL_NAMES),
            'the rostered snake spelling still declares sixel',
        );

        // Probe leg — the upgrade this test is named for: TERM=xterm answers
        // Ansi on the ladder, and a fake infocmp declaring Tc promotes it to
        // TrueColor through the synchronous phase-2 gate.
        $printer = $this->stuffInfocmpPrinting('\\tTc,\\n');
        $this->env('TERM', 'xterm');

        try {
            $this->assertSame(ColorProfile::TrueColor, Probe::colorProfile());
        } finally {
            @\unlink($printer);
            Probe::resetForTesting();
        }
    }

    /**
     * When infocmp cannot resolve the TERM (exits non-zero, prints nothing —
     * what the real binary does for an unknown entry), the chain answer
     * stands: Ansi, never a promotion, never a crash. Stuffed-path arm so
     * neither the host database nor a foreign file's memo decides the
     * verdict.
     */
    public function testUnresolvableTermFloorsColorless(): void
    {
        $printer = \tempnam(\sys_get_temp_dir(), 'infocmp');
        $this->assertIsString($printer);
        \file_put_contents($printer, "#!/bin/sh\nexit 1\n");
        \chmod($printer, 0755);
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);
        $this->env('TERM', 'this-term-does-not-exist-12345xyz');

        try {
            // The chain rung for an unresolvable TERM is the CP-10 colorless
            // floor: no infocmp answer, no 16-color promise.
            $this->assertSame(ColorProfile::Ascii, Probe::colorProfile());
        } finally {
            @\unlink($printer);
            Probe::resetForTesting();
        }
    }

    // -----------------------------------------------------------------
    // CP-11: the bounded execution door (InfocmpBinary::runBounded)
    // -----------------------------------------------------------------

    public function testBoundedExecNeverOutlivesItsDeadline(): void
    {
        // 012/013: a wedged infocmp used to block the caller forever. The
        // door must fail open (null → chain answer stands) inside its
        // deadline ladder, not when the child eventually feels like
        // exiting. `exec` replaces the shell so TERM/KILL land on the
        // sleeper itself — no orphaned grandchildren outliving the pin.
        $printer = \tempnam(\sys_get_temp_dir(), 'infocmp');
        $this->assertIsString($printer);
        \file_put_contents($printer, "#!/bin/sh\nexec sleep 30\n");
        \chmod($printer, 0755);
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);

        try {
            $started = \microtime(true);
            $answer = InfocmpBinary::runBounded('xterm', 1.0);
            $elapsed = \microtime(true) - $started;
        } finally {
            @\unlink($printer);
            Probe::resetForTesting();
        }

        $this->assertNull($answer, 'a wedged child must fail open, never a partial verdict');
        $this->assertLessThan(
            6.0,
            $elapsed,
            'deadline 1 s + two 1 s grace rungs must close the child long before its 30 s sleep ' . \sprintf('(took %.2f s)', $elapsed),
        );
    }

    public function testBoundedExecKeepsOnlyThePrefix(): void
    {
        // 012: the output cap is a keep-a-prefix read. A Tc line buried
        // past 8 KiB of filler is noise by contract — and the child must
        // still run to clean exit (stderr/stdout drained to EOF), never be
        // killed for talking too much.
        $printer = \tempnam(\sys_get_temp_dir(), 'infocmp');
        $this->assertIsString($printer);
        \file_put_contents(
            $printer,
            "#!/bin/sh\nprintf '\\tFx0," . \str_repeat('x', 16384) . ",\\n'\nprintf '\\tTc,\\n'\n",
        );
        \chmod($printer, 0755);
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);

        try {
            $dump = InfocmpBinary::runBounded('xterm');
        } finally {
            @\unlink($printer);
            Probe::resetForTesting();
        }

        $this->assertIsString($dump, 'a fast-exiting chatty child still answers');
        $this->assertLessThanOrEqual(InfocmpBinary::OUTPUT_CAP_BYTES, \strlen($dump), 'the kept prefix never exceeds the cap');
        $this->assertFalse(
            TerminfoParser::hasCapName($dump, TerminfoParser::TRUECOLOR_NAMES),
            'a capability line beyond the cap must not reach the verdict',
        );
    }

    public function testDumpMemoAnswersRepeatedProbesWithoutAChild(): void
    {
        // 013: every gated re-probe used to re-exec. The shared per-TERM
        // table must serve the second call from itself — the fake counts
        // its own launches in a marker file, so the proof is the byte
        // count of that file, not a reflection peek.
        $counter = (string) \tempnam(\sys_get_temp_dir(), 'cp-exec-count');
        $printer = \tempnam(\sys_get_temp_dir(), 'infocmp');
        $this->assertIsString($printer);
        \file_put_contents(
            $printer,
            "#!/bin/sh\nprintf 'x\\n' >> " . \escapeshellarg($counter) . "\nprintf '\\tTc,\\n'\n",
        );
        \chmod($printer, 0755);
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);

        try {
            $first = InfocmpBinary::runBounded('xterm');
            $second = InfocmpBinary::runBounded('xterm');

            $this->assertSame($first, $second, 'the memo answers identically');
            $this->assertSame("\tTc,\n", $first);
            $this->assertCount(1, \file($counter), 'the child ran exactly once for two calls');

            // resetForTesting() drains the table AND the stuffed path (both statics),
            // so the re-arm leg restuffs the same fake before asking again.
            Probe::resetForTesting();
            (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);
            InfocmpBinary::runBounded('xterm');
            $this->assertCount(2, \file($counter), 'the reset seam re-arms the door');
        } finally {
            @\unlink($printer);
            @\unlink($counter);
            Probe::resetForTesting();
        }
    }

    public function testDumpMemoClearsAtTheCardinalityCeiling(): void
    {
        // 072: the grammar gate bounds a NAME's shape, not the table's
        // cardinality — a process probed across 257 distinct safe-shaped
        // TERMs must not accumulate 257 entries for its whole life. The
        // fake counts every launch, so the pin also proves the fill was
        // real (one child per distinct name, clear-on-full, never silent
        // eviction of an answer that was never asked again).
        $counter = (string) \tempnam(\sys_get_temp_dir(), 'cp-exec-count');
        $printer = \tempnam(\sys_get_temp_dir(), 'infocmp');
        $this->assertIsString($printer);
        \file_put_contents(
            $printer,
            "#!/bin/sh\nprintf 'x\\n' >> " . \escapeshellarg($counter) . "\nprintf '\\tTc,\\n'\n",
        );
        \chmod($printer, 0755);
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);

        try {
            $memo = new \ReflectionProperty(InfocmpBinary::class, 'dumpMemo');
            $terms = 257;
            for ($i = 0; $i < $terms; $i++) {
                // Safe-shaped distinct names (no leading dash, [a-z0-9-]).
                $this->assertNotNull(InfocmpBinary::runBounded('cp12-term-' . $i));
            }

            $live = $memo->getValue(null);
            $this->assertIsArray($live);
            $this->assertLessThanOrEqual(256, count($live), 'the memo grew past its ceiling');
            // Deterministic shape: the 257th write found a full 256-entry
            // table, cleared it, and stood alone.
            $this->assertCount(1, $live, 'full-memo reset did not leave exactly the newest entry');
            $this->assertArrayHasKey('cp12-term-256', $live, 'the survivor must be the newest verdict');
            $this->assertCount($terms, \file($counter), 'every distinct TERM minted exactly one child');
        } finally {
            @\unlink($printer);
            @\unlink($counter);
            Probe::resetForTesting();
        }
    }
}

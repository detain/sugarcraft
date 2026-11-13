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
        Probe::_reset();

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

        Probe::_reset();
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

        // Probe leg — the upgrade this test is named for: TERM=xterm answers
        // Ansi on the ladder, and a fake infocmp declaring Tc promotes it to
        // TrueColor through the synchronous phase-2 gate.
        $printer = $this->stuffInfocmpPrinting('\\tTc,\\n');
        $this->env('TERM', 'xterm');

        try {
            $this->assertSame(ColorProfile::TrueColor, Probe::colorProfile());
        } finally {
            @\unlink($printer);
            Probe::_reset();
        }
    }

    /**
     * When infocmp cannot resolve the TERM (exits non-zero, prints nothing —
     * what the real binary does for an unknown entry), the chain answer
     * stands: Ansi, never a promotion, never a crash. Stuffed-path arm so
     * neither the host database nor a foreign file's memo decides the
     * verdict.
     */
    public function testUnresolvableTermFallsBackToAnsi(): void
    {
        $printer = \tempnam(\sys_get_temp_dir(), 'infocmp');
        $this->assertIsString($printer);
        \file_put_contents($printer, "#!/bin/sh\nexit 1\n");
        \chmod($printer, 0755);
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);
        $this->env('TERM', 'this-term-does-not-exist-12345xyz');

        try {
            $this->assertSame(ColorProfile::Ansi, Probe::colorProfile());
        } finally {
            @\unlink($printer);
            Probe::_reset();
        }
    }
}

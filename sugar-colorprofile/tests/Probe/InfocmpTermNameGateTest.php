<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Probe;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\InfocmpBinary;
use SugarCraft\ColorProfile\Probe\TerminalProbe;
use SugarCraft\ColorProfile\AsyncProbe;

/**
 * Pins for the shared TERM name-shape gate {@see InfocmpBinary::isSafeTermName()}
 * consulted by all three infocmp exec surfaces before a TERM is handed to the
 * binary (finding M9).
 *
 * The hazard measured against a real infocmp: TERM values beginning with a
 * dash (-e/-x/-0/-C/-F/-D) are parsed by infocmp as its own OPTIONS — the
 * quoting escapeshellarg() adds does not stop that — so `infocmp -1 '-D'`
 * exited 0 printing the terminfo search path into the probe's stdout, which a
 * naive name scan could then misread as capability evidence. The gate refuses
 * every TERM that does not look like a terminal name; the env-anchored chain
 * answer stands.
 *
 * Each refusal pin is paired with a well-formed-TERM control through the SAME
 * fixture so a gate neutered into "refuse everything" cannot pass vacuously.
 * The exec sites are steered by stuffing InfocmpBinary's private memo with a
 * temp script that prints a Tc line on any arguments — no host terminfo
 * database is consulted.
 *
 * @covers \SugarCraft\ColorProfile\Probe\InfocmpBinary::isSafeTermName
 */
final class InfocmpTermNameGateTest extends TestCase
{
    /** @var array<string,string|null> */
    private array $savedEnv = [];

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = [
            'CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM',
            'FORCE_COLOR', 'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY',
            'TERM_PROGRAM',
        ];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
            putenv($key);
        }

        Probe::_reset();
        $this->releaseAsyncSlots();
    }

    protected function tearDown(): void
    {
        Probe::_reset();
        // CP-13: these fixtures swap the planted child binary between cases
        // while reusing a TERM — the async settled-answer memo must drain
        // with the sync dump memo or a case inherits a foreign verdict.
        AsyncProbe::_reset();
        $this->releaseAsyncSlots();
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }
        $this->cleanup = [];

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
    }

    public function testTheSharedPredicateAcceptsNamesAndRefusesOptionShapes(): void
    {
        $this->assertTrue(InfocmpBinary::isSafeTermName('xterm-256color'));
        $this->assertTrue(InfocmpBinary::isSafeTermName('screen.xterm'));
        $this->assertTrue(InfocmpBinary::isSafeTermName('linux'));
        $this->assertTrue(InfocmpBinary::isSafeTermName('vt100'));

        foreach (['-D', '-x', '-e', '-0', '-C', '-F', '', '.', '_', '+x', 'x y', "x\ty"] as $refused) {
            $this->assertFalse(
                InfocmpBinary::isSafeTermName($refused),
                "TERM operand {$refused} must never reach infocmp",
            );
        }
    }

    public function testSyncProbeKeepsTheChainAnswerForAnOptionLikeTerm(): void
    {
        $this->armAmbient('TERM', '-D');
        $this->stuffMemoWithTcPrinter();

        // Base (no gate): the temp binary runs regardless of its arguments,
        // prints Tc, and the phase-2 leg upgrades to TrueColor.
        // Fixed (gate): no exec — the chain rung for '-D' stands; since
        // CP-10 that rung is the colorless floor (an unknown TERM claims
        // no color).
        $this->assertSame(ColorProfile::Ascii, Probe::colorProfile());
    }

    public function testSyncProbeStillUpgradesAWellFormedTermThroughTheSameFixture(): void
    {
        $this->armAmbient('TERM', 'xterm-256color');
        $this->stuffMemoWithTcPrinter();

        $this->assertSame(ColorProfile::TrueColor, Probe::colorProfile());
    }

    public function testAsyncProbeKeepsTheChainAnswerForAnOptionLikeTerm(): void
    {
        $this->armAmbient('TERM', '-D');
        $this->stuffMemoWithTcPrinter();

        $this->assertSame(ColorProfile::Ascii, $this->driveAsync()); // colorless floor — CP-10
    }

    public function testAsyncProbeStillPromotesAWellFormedTermThroughTheSameFixture(): void
    {
        $this->armAmbient('TERM', 'xterm-256color');
        $this->stuffMemoWithTcPrinter();

        $this->assertSame(ColorProfile::TrueColor, $this->driveAsync());
    }

    public function testTerminalProbeBuildsNoCommandForAnOptionLikeTerm(): void
    {
        $probe = $this->capturingProbe('-x');

        $report = $probe->runProbe();

        $this->assertSame([], $probe->commands, 'a TERM beginning with a dash must never reach command construction');
        $this->assertFalse($report->has(Capability::TrueColor));
    }

    public function testTerminalProbeStillBuildsTheCommandForAWellFormedTerm(): void
    {
        $probe = $this->capturingProbe('xterm');

        $report = $probe->runProbe();

        $this->assertCount(1, $probe->commands, 'the control must still construct the infocmp command');
        $this->assertStringContainsString("-1 'xterm'", $probe->commands[0]);
        $this->assertTrue($report->has(Capability::TrueColor));
    }

    private function capturingProbe(string $term): TerminalProbe
    {
        InfocmpBinary::reset();
        $this->stuffMemo('/usr/bin/infocmp');

        return new class ($term) extends TerminalProbe {
            /** @var list<string> */
            public array $commands = [];

            public function __construct(string $term)
            {
                parent::__construct(['TERM' => $term], false);
            }

            protected function isInteractive(): bool
            {
                return false;
            }

            protected function runCommand(string $cmd): ?string
            {
                $this->commands[] = $cmd;

                return "\tTc,\n\trgb=\\E[38;2;%p1;%p2;%p3m,\n";
            }
        };
    }

    private function armAmbient(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    /**
     * Point the located-binary memo at a temp script that prints a Tc
     * capability line for ANY argument list, so a gate that fails to refuse
     * the option-shaped TERM still visibly produces the upgrade on base.
     */
    private function stuffMemoWithTcPrinter(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'cp-tc-printer-');
        $this->assertIsString($script);
        $this->cleanup[] = $script;
        file_put_contents($script, "#!/bin/sh\nprintf '\\tTc,\\n'\n");
        chmod($script, 0755);

        $this->stuffMemo($script);
    }

    private function stuffMemo(string $path): void
    {
        $property = new \ReflectionProperty(InfocmpBinary::class, 'path');
        $property->setValue(null, $path);
    }

    private function driveAsync(): ColorProfile
    {
        // CP-14 contract: the child spawns only on a loop handed in, so the
        // driver passes one explicitly and runs THAT — the react/event-loop
        // singleton is never picked up implicitly anymore.
        $loop = new \React\EventLoop\StreamSelectLoop();
        $resolved = null;
        AsyncProbe::colorProfile($loop)->then(static function (ColorProfile $profile) use (&$resolved): void {
            $resolved = $profile;
        });

        if ($resolved === null) {
            $watchdog = null;
            $poll = null;
            $watchdog = $loop->addTimer(10.0, static function () use ($loop, &$poll, &$watchdog): void {
                $loop->cancelTimer($poll);
                $loop->cancelTimer($watchdog);
                $loop->stop();
            });
            $poll = $loop->addPeriodicTimer(0.005, static function () use ($loop, &$resolved, &$watchdog, &$poll): void {
                if ($resolved !== null) {
                    $loop->cancelTimer($watchdog);
                    $loop->cancelTimer($poll);
                    $loop->stop();
                }
            });
            $loop->run();
        }

        $this->assertInstanceOf(ColorProfile::class, $resolved);

        return $resolved;
    }

    private function releaseAsyncSlots(): void
    {
        $slots = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $slots->setValue(null, []);
    }
}

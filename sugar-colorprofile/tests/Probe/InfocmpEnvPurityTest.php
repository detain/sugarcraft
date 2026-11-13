<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\AsyncProbe;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Probe;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\InfocmpBinary;
use SugarCraft\ColorProfile\Probe\TerminalProbe;

/**
 * Pins for the minimal infocmp child environment (finding M7).
 *
 * The inherited environment used to reach the child, so a parent-exported
 * TERMINFO/TERMINFO_DIRS (and the HOME fall-through terminfo consults when
 * neither is set) steered WHICH capability database answers — any user able
 * to shape the launching environment could mint Tc/RGB/Sixel evidence for a
 * terminal that never declared it. Every exec surface now launches through
 * the shared {@see InfocmpBinary::childEnvironment()} — TERM and a fixed
 * PATH, never the parent's database selectors.
 *
 * The leak fixtures are scripts that print a Tc line ONLY when TERMINFO is
 * visible in their own environment and exit 0 either way: the discriminator
 * is environment purity, never exit status. Each refusal pin is paired with
 * a clean-environment control through a plain Tc printer so the swapped-in
 * proc_open launches cannot pass vacuously by simply losing stdout.
 *
 * @covers \SugarCraft\ColorProfile\Probe\InfocmpBinary::childEnvironment
 */
final class InfocmpEnvPurityTest extends TestCase
{
    /** @var array<string,string|null> */
    private array $savedEnv = [];

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = [
            'CLICOLOR_FORCE', 'FORCE_COLOR', 'NO_COLOR', 'CLICOLOR', 'TERM',
            'COLORTERM', 'TERM_PROGRAM', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY',
            'TERMINFO',
        ];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
            putenv($key);
        }

        Probe::resetForTesting();
        $this->releaseAsyncSlots();
    }

    protected function tearDown(): void
    {
        Probe::resetForTesting();
        // CP-13: these fixtures swap the planted child binary between cases
        // while reusing a TERM — the async settled-answer memo must drain
        // with the sync dump memo or a case inherits a foreign verdict.
        AsyncProbe::resetForTesting();
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

    public function testChildEnvironmentCarriesOnlyTermAndPath(): void
    {
        $this->assertSame(
            ['TERM' => 'xterm', 'PATH' => '/usr/bin:/bin'],
            InfocmpBinary::childEnvironment('xterm'),
        );
        $this->assertSame(['PATH' => '/usr/bin:/bin'], InfocmpBinary::childEnvironment());
        $this->assertSame(['PATH' => '/usr/bin:/bin'], InfocmpBinary::childEnvironment(''));
    }

    public function testSyncProbeIgnoresAnInheritedTerminfoDatabase(): void
    {
        $this->armAmbient('TERM', 'xterm-256color');
        $this->armAmbient('TERMINFO', '/tmp/definitely-not-a-terminfo-db');
        $this->stuffMemo($this->leakPrinter());

        // Base (inherited env): the temp binary sees TERMINFO, prints Tc, and
        // the phase-2 leg upgrades — the injected database decided the answer.
        // Fixed (minimal env): TERMINFO never reaches the child, the printer
        // prints nothing, and the chain rung stands.
        $this->assertSame(ColorProfile::Ansi256, Probe::colorProfile());
    }

    public function testSyncProbeStillUpgradesWithACleanEnvironment(): void
    {
        $this->armAmbient('TERM', 'xterm-256color');
        $this->stuffMemo($this->tcPrinter());

        $this->assertSame(ColorProfile::TrueColor, Probe::colorProfile());
    }

    public function testAsyncProbeIgnoresAnInheritedTerminfoDatabase(): void
    {
        $this->armAmbient('TERM', 'xterm-256color');
        $this->armAmbient('TERMINFO', '/tmp/definitely-not-a-terminfo-db');
        $this->stuffMemo($this->leakPrinter());

        $this->assertSame(ColorProfile::Ansi256, $this->driveAsync());
    }

    public function testAsyncProbeStillUpgradesWithACleanEnvironment(): void
    {
        $this->armAmbient('TERM', 'xterm-256color');
        $this->stuffMemo($this->tcPrinter());

        $this->assertSame(ColorProfile::TrueColor, $this->driveAsync());
    }

    public function testTerminalProbeBaseExecIgnoresAnInheritedTerminfoDatabase(): void
    {
        $probe = $this->binaryBackedProbe();
        $this->armAmbient('TERMINFO', '/tmp/definitely-not-a-terminfo-db');
        $this->stuffMemo($this->leakPrinter());

        $report = $probe->runProbe();

        $this->assertFalse(
            $report->has(Capability::TrueColor),
            'a parent-exported TERMINFO must not be able to mint TrueColor through the base runCommand',
        );
    }

    public function testTerminalProbeBaseExecStillReadsAStdoutDump(): void
    {
        $probe = $this->binaryBackedProbe();
        $this->stuffMemo($this->tcPrinter());

        $report = $probe->runProbe();

        $this->assertTrue(
            $report->has(Capability::TrueColor),
            'the control must still capture the dump — the base exec cannot pass by losing stdout',
        );
    }

    /**
     * A TerminalProbe that keeps the BASE runCommand (the real launch) but
     * pins interactive discovery off and declares the roster binary present.
     */
    private function binaryBackedProbe(): TerminalProbe
    {
        return new class extends TerminalProbe
        {
            public function __construct()
            {
                parent::__construct(['TERM' => 'xterm'], false);
            }

            protected function isInteractive(): bool
            {
                return false;
            }

            protected function infocmpAvailable(): bool
            {
                return true;
            }
        };
    }

    private function tcPrinter(): string
    {
        return $this->writeScript("#!/bin/sh\nprintf '\tTc,\\n'\nexit 0\n");
    }

    private function leakPrinter(): string
    {
        return $this->writeScript(
            "#!/bin/sh\nif [ -n \"\$TERMINFO\" ]; then printf '\tTc,\\n'; else printf '\tENOTA,\\n'; fi\nexit 0\n",
        );
    }

    private function writeScript(string $body): string
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), 'cp-envpin-');
        \file_put_contents($path, $body);
        \chmod($path, 0o755);
        $this->cleanup[] = $path;

        return $path;
    }

    private function stuffMemo(string $path): void
    {
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $path);
    }

    private function armAmbient(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    private function driveAsync(): ?ColorProfile
    {
        // CP-14 contract: the child spawns only on a loop handed in, so the
        // driver passes one explicitly and runs THAT — the react/event-loop
        // singleton is never picked up implicitly anymore.
        $loop = new \React\EventLoop\StreamSelectLoop();
        $resolved = null;
        AsyncProbe::colorProfile($loop)->then(static function (ColorProfile $profile) use (&$resolved): void {
            $resolved = $profile;
        });

        if ($resolved !== null) {
            return $resolved;
        }

        $watchdog = null;
        $poll = null;
        $watchdog = $loop->addTimer(8.0, static function () use ($loop, &$watchdog, &$poll): void {
            if ($poll !== null) {
                $loop->cancelTimer($poll);
            }
            if ($watchdog !== null) {
                $loop->cancelTimer($watchdog);
            }
            $loop->stop();
        });
        $poll = $loop->addPeriodicTimer(0.005, static function () use ($loop, &$watchdog, &$poll, &$resolved): void {
            if ($resolved !== null) {
                if ($watchdog !== null) {
                    $loop->cancelTimer($watchdog);
                }
                $loop->cancelTimer($poll);
                $loop->stop();
            }
        });
        $loop->run();

        return $resolved;
    }

    private function releaseAsyncSlots(): void
    {
        (new \ReflectionProperty(AsyncProbe::class, 'inFlight'))->setValue(null, []);
    }
}

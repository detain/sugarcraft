<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Probe\Capability as ProbeCapability;
use SugarCraft\ColorProfile\Probe\InfocmpBinary;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\ColorProfile\Probe\TerminalProbe;
use SugarCraft\Image\Detect;

/**
 * F-07: the terminfo sixl capability is consulted between env and DA1.
 *
 * A `$TERM` whose published terminal entry declares a Sixel capability
 * (`sixl`, `Sixel#1`, `SixelScreenMode`, `Ss1`/`Ss2` — the spellings
 * enumerated in sugar-colorprofile's SIXEL_TERMCAPS) IS a sixel terminal,
 * and knowing that costs one local `infocmp -1` read — no bytes touch the
 * tty. Chafa's term.c consults the terminal database for the same reason;
 * Detect now does the same, wired through sugar-colorprofile's
 * TerminalProbe Phase 2 rather than a second copy of the table.
 *
 * ## The stage only ever PROMOTES
 *
 * true  — the entry names a Sixel cap → claim sixel, skip the DA1
 *         round-trip the answer makes redundant;
 * false — no entry, no `sixl` in it, no infocmp, NO_COLOR/TERM=dumb
 *         suppression → the DA1 arm runs exactly as before F-07.
 * A definite F-04 feature-list veto skips the stage outright: terminfo
 * describes the emulator `$TERM` names, not tmux's display policy, and a
 * veto must not be laundered back into a sixel claim through a database
 * lookup on the outer terminal's behalf.
 *
 * ## Hermetic law
 *
 * The parsing tests drive a TerminalProbe SUBCLASS faking only the two
 * protected arms TerminalProbe documents for injection (`infocmpAvailable`
 * / `runCommand`), so the REAL capability-name matcher runs against canned
 * `infocmp -1` text — including the `desc=...sixel...` decoy that the old
 * word-boundary regex used to swallow. The one end-to-end test plants a
 * fake `infocmp` first on a PATH with nothing else on it and resets the
 * InfocmpBinary memo around itself, and is skipped (not failed) on a
 * machine without the /usr/bin|/bin gate entry the probe requires.
 */
final class DetectTerminfoTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedEnv = [];

    private string $dir = '';

    /** @var list<resource> */
    private array $open = [];

    /** canned positive reply for the env-ladder sixel path */
    private const DA1_SIXEL_REPLY = "\x1b[?62;4;0c";

    /** canned reply WITHOUT the sixel bit — the fallthrough control */
    private const DA1_NO_SIXEL_REPLY = "\x1b[?62;0c";

    protected function setUp(): void
    {
        parent::setUp();
        // The suppression keys are censused by the live probe's own ladder
        // (DetectionChain reads the merged env), so a developer shell
        // exporting NO_COLOR must not decide what the terminfo stage sees.
        $keys = [
            'TMUX', 'TERM', 'TERM_PROGRAM', 'KITTY_WINDOW_ID', 'XTERM_VERSION',
            'LC_TERMINAL', 'PATH', 'NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE',
        ];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = getenv($key);
            unset($_ENV[$key]);
            putenv($key);
        }
        $this->dir = sys_get_temp_dir() . '/s25-terminfo-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0700, true);

        putenv('TERM=susetm');
        $_ENV['TERM'] = 'susetm';
        Detect::reset();
        Detect::clearTmuxTermFeaturesOverride();
        Detect::clearTerminfoSixelOverride();
        Detect::setTerminfoProbe(null);
        Detect::setAutoPassthrough(false);
        InfocmpBinary::reset();
    }

    protected function tearDown(): void
    {
        Detect::reset();
        Detect::setProbeStdin(null);
        Detect::clearTmuxTermFeaturesOverride();
        Detect::clearTerminfoSixelOverride();
        Detect::setTerminfoProbe(null);
        Detect::setAutoPassthrough(true);
        InfocmpBinary::reset();
        foreach ($this->open as $handle) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
        $this->open = [];
        foreach (glob($this->dir . '/*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        foreach ($this->savedEnv as $key => $value) {
            unset($_ENV[$key]);
            if ($value === false) {
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
        parent::tearDown();
    }

    // --------------------------------------------------------- wiring arms

    public function testTerminfoYesClaimsSixelWithoutTheDa1RoundTrip(): void
    {
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        // Nothing is preloaded: were the DA1 arm entered it could only time
        // out and answer no — sixel=true below is reachable ONLY through
        // the terminfo short-circuit, and the reply stays NULL to prove the
        // arm was never entered.
        Detect::setTerminfoProbe($this->reportingProbe(sixel: true));

        $cap = Detect::probe();

        $this->assertTrue($cap->sixel, 'a sixl terminfo entry must promote the capability');
        $this->assertNull(Detect::lastDa1Reply(), 'the terminfo answer makes the DA1 round-trip redundant');
    }

    public function testOverrideTruePromotesWithoutTouchingAnyProbeObject(): void
    {
        // No double armed at all: with the override set, even the default
        // TerminalProbe construction must be skipped (the override is
        // consulted first inside probeTerminfoSixel()).
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        Detect::setTerminfoSixelOverride(true);

        $cap = Detect::probe();

        $this->assertTrue($cap->sixel);
        $this->assertNull(Detect::lastDa1Reply());
    }

    public function testTerminfoNoFallsThroughToTheDa1Arm(): void
    {
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        $this->preload($pair, self::DA1_SIXEL_REPLY);
        Detect::setTerminfoProbe($this->reportingProbe(sixel: false));

        $cap = Detect::probe();

        $this->assertTrue($cap->sixel, 'a terminfo no is not a veto — the pre-F-07 DA1 trust stands');
        $this->assertSame(
            self::DA1_SIXEL_REPLY,
            Detect::lastDa1Reply(),
            'the fallthrough must reach the DA1 arm; an unrecorded reply means it never ran',
        );
    }

    public function testMissingInfocmpFallsThroughWithoutRunningAnyCommand(): void
    {
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        $this->preload($pair, self::DA1_SIXEL_REPLY);
        $double = $this->textProbe('# infocmp never ran here');
        $double->gateSaysAbsent = true;
        Detect::setTerminfoProbe($double);

        $cap = Detect::probe();

        $this->assertTrue($cap->sixel);
        $this->assertSame([], $double->commands, 'the infocmp gate must short-circuit before exec');
        $this->assertSame(self::DA1_SIXEL_REPLY, Detect::lastDa1Reply());
    }

    public function testAbsentTermNeverConsultsTerminfo(): void
    {
        putenv('TERM');
        unset($_ENV['TERM']);
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        $this->preload($pair, self::DA1_NO_SIXEL_REPLY);
        $double = $this->textProbe("\tsixl,");
        Detect::setTerminfoProbe($double);

        $cap = Detect::probe();

        $this->assertFalse($cap->sixel);
        $this->assertSame([], $double->commands, 'no $TERM means no entry to look up — nothing to exec');
        $this->assertSame(self::DA1_NO_SIXEL_REPLY, Detect::lastDa1Reply());
    }

    // ---------------------------------------------------- F-04 interaction

    public function testFeatureVetoSkipsTheTerminfoStageEntirely(): void
    {
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        // The F-04 veto only exists INSIDE tmux (probeTmuxTermFeatures() is
        // consulted under the $TMUX guard), so arm the socket marker; the
        // override answers without any exec anyway.
        putenv('TMUX=/tmp/s25-made-up.sock,99999,0');
        $_ENV['TMUX'] = '/tmp/s25-made-up.sock,99999,0';
        Detect::setTmuxTermFeaturesOverride(false);
        $double = $this->textProbe("\tsixl,");
        Detect::setTerminfoProbe($double);

        $cap = Detect::probe();

        $this->assertFalse($cap->sixel, 'a definite outer-terminal veto must not be laundered back through terminfo');
        $this->assertSame([], $double->commands, 'under veto the stage is skipped — not consulted-and-refused');
        $this->assertNull(Detect::lastDa1Reply());
    }

    public function testEnvAlreadyClaimingSixelDoesNotPayForATerminfoRead(): void
    {
        putenv('TERM=xterm');
        $_ENV['TERM'] = 'xterm';
        putenv('XTERM_VERSION=XTerm(370)');
        $_ENV['XTERM_VERSION'] = 'XTerm(370)';
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        $this->preload($pair, self::DA1_SIXEL_REPLY);
        $double = $this->textProbe("\tsixl,");
        Detect::setTerminfoProbe($double);

        $cap = Detect::probe();

        $this->assertTrue($cap->sixel);
        $this->assertSame([], $double->commands, 'the env hint already answered; terminfo is redundant there');
    }

    // -------------------------------------------------- real name matching

    /**
     * The canned texts below run through the REAL terminfoHasSixel matcher
     * (only the exec is faked), which is the point: the SIXEL_TERMCAPS
     * spellings must match in the capability-NAME position, and the word
     * "sixel" hidden inside a description or comment must not — the exact
     * failure the old `\bsixel\b` regex invited (see the WHY block at
     * sugar-colorprofile/src/Probe/TerminalProbe.php:247-259).
     *
     * @return array<array{string,bool}>
     */
    public static function terminfoTextProvider(): array
    {
        return [
            'abbreviated bool sixl'        => ["\tsixl,", true],
            'spelled-out bool sixel'       => ["\tsixel,", true],
            'numeric Sixel#1'              => ["\tSixel#1,", true],
            'string Sixel=true'            => ["\tSixel=true,", true],
            'SixelScreenMode glued name'   => ["\tSixelScreenMode=1,", true],
            'underscore respelling'        => ["\tsixel_screen_mode=1,", true],
            'user-defined Ss1'             => ["\tSs1=\\En,", true],
            'positive header line'         => ["sus,xterm with sixel graphics,", false],
            'desc value only'              => ["\tdesc=xterm with sixel,", false],
            'comment mention'              => ["# sixl in a comment", false],
            'glued decoy name'             => ["\tnotsixl=1,", false],
            'bare capability absent'       => ["\tcolors#8,", false],
        ];
    }

    /**
     * @dataProvider terminfoTextProvider
     */
    public function testRealMatcherDecidesEachSpellingFromTheCapabilityNamePosition(
        string $entryLine,
        bool $expectPromote,
    ): void {
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        // A no-sixel DA1 reply is preloaded either way: on a PROMOTE row the
        // stage must return before reading it (reply stays NULL); on a
        // NEGATIVE row the DA1 arm must consume it (reply recorded) and the
        // capability stay false.
        $this->preload($pair, self::DA1_NO_SIXEL_REPLY);
        $double = $this->textProbe("sus,sus terminal,\n{$entryLine}\n");
        Detect::setTerminfoProbe($double);

        $cap = Detect::probe();

        $this->assertSame(
            $expectPromote,
            $cap->sixel,
            'terminfo text ' . var_export($entryLine, true) . ' mis-detected',
        );
        if ($expectPromote) {
            $this->assertNull(Detect::lastDa1Reply(), 'a promoted entry must skip the DA1 read entirely');
            $this->assertSame(
                ["infocmp -1 'susetm' 2>/dev/null"],
                $double->commands,
                'the exec command shape changed',
            );
        } else {
            $this->assertSame(
                self::DA1_NO_SIXEL_REPLY,
                Detect::lastDa1Reply(),
                'a negative entry must fall through to the DA1 arm',
            );
        }
    }

    // ------------------------------------------------ end-to-end via PATH

    public function testPlantedInfocmpIsFoundThroughTheRealGateAndExec(): void
    {
        if (!is_file('/usr/bin/infocmp') && !is_file('/bin/infocmp')) {
            $this->markTestSkipped('InfocmpBinary gate requires a packaged infocmp on this machine');
        }

        $bin = $this->dir . '/infocmp';
        // TAB inside the FORMAT string (POSIX printf processes escapes
        // there; a %s ARGUMENT would print a literal backslash and the
        // name-position matcher would — correctly — reject the line).
        $script = "#!/bin/sh\nprintf 'susetm|sus terminal,\\n\\tsixl,\\n'\n";
        file_put_contents($bin, $script);
        chmod($bin, 0755);
        putenv('PATH=' . $this->dir);
        InfocmpBinary::reset();

        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        // No double: the REAL TerminalProbe, the REAL InfocmpBinary memo
        // (reset so the planted PATH cannot inherit a stale gate answer),
        // the REAL shell_exec — only `infocmp` itself is the fake.

        $cap = Detect::probe();

        $this->assertTrue($cap->sixel, 'the planted sixl entry must promote through the full live chain');
        $this->assertNull(Detect::lastDa1Reply());
    }

    // -------------------------------------------------------------- harness

    /**
     * A TerminalProbe whose runProbe is canned: tests the Detect wiring
     * (stage order, short-circuit, fallthrough) with no exec at all.
     */
    private function reportingProbe(bool $sixel): TerminalProbe
    {
        return new class($sixel) extends TerminalProbe {
            public function __construct(private readonly bool $sixel)
            {
                parent::__construct([], false);
            }

            public function runProbe(array $env = [], ?bool $interactive = null): ProbeReport
            {
                return $this->sixel
                    ? new ProbeReport([ProbeCapability::Sixel->value => 'terminfo:sixel'])
                    : new ProbeReport([]);
            }
        };
    }

    /**
     * A TerminalProbe faking ONLY the two documented protected injection
     * arms, so checkTerminfo + terminfoHasSixel + the command construction
     * all run for real against canned text.
     */
    private function textProbe(string $infocmpOutput): TerminalProbe
    {
        return new class($infocmpOutput) extends TerminalProbe {
            /** @var list<string> every runCommand argv line the stage executed */
            public array $commands = [];

            public bool $gateSaysAbsent = false;

            public function __construct(private readonly string $output)
            {
                parent::__construct([], false);
            }

            protected function infocmpAvailable(): bool
            {
                return !$this->gateSaysAbsent;
            }

            protected function runCommand(string $cmd): ?string
            {
                $this->commands[] = $cmd;

                return $this->output;
            }
        };
    }

    /** @param array{read: resource, write: resource} $pair */
    private function preload(array $pair, string $bytes): void
    {
        $written = @fwrite($pair['write'], $bytes);
        $this->assertIsInt($written);
        @fflush($pair['write']);
    }

    /**
     * Create a blocking stream pair for simulated stdin I/O.
     *
     * @return array{read: resource, write: resource}
     */
    private function createStreamPair(): array
    {
        $pair = @stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }

        // Disable buffering so writes appear immediately.
        stream_set_read_buffer($pair[0], 0);
        stream_set_write_buffer($pair[1], 0);

        return ['read' => $pair[0], 'write' => $pair[1]];
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\PosixShellQuote;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Support\WindowsShell;

/**
 * C-TUI-6.d: the Windows arms of the interactive PTY gate.
 *
 * The live legs (a ConPTY allocation, a bash.exe process tree) cannot run on
 * this Linux box, so nothing here pretends otherwise — what IS pinned with
 * real behavior are the two stat seams the arms consult: with the OS-family
 * read pointed at "Windows" and a planted bash.exe behind the environment
 * seam, the availability gate answers through the vendor ConPTY probe (which
 * on this host refuses for the honest reason — its own OS leg reads the raw
 * family — and that refusal reaching the answer is precisely the wiring
 * proof), the spawn argv carries the planted bash and never /bin/sh, and the
 * bash-missing gate answers true/false/off-host on stats alone. The legs
 * that only a Windows runner can prove are certified STRUCTURALLY (source
 * slices of the gated methods) and run for real in the CI smoke job
 * (tests/Windows) this step adds.
 *
 * Every pin resets the availability memo and both WindowsShell seams: the
 * memo is class-scope and process-wide, and leaving a Windows verdict cached
 * would make the live pty suites on later files falsely refuse — the reset
 * is load-bearing, and the last test proves it re-derives the host's own
 * answer.
 */
final class InteractiveWindowsArmsTest extends TestCase
{
    /** @var non-empty-string */
    private string $fakeBin = '';

    protected function setUp(): void
    {
        parent::setUp();

        ProcessContainment::resetInteractiveAvailabilityForTesting();
        $this->fakeBin = \sys_get_temp_dir() . '/ctd6d-' . \uniqid('', true);
        \mkdir($this->fakeBin, 0o700);
        \file_put_contents($this->fakeBin . '/bash.exe', '@echo off' . "\n");
    }

    protected function tearDown(): void
    {
        WindowsShell::setOsFamilyForTesting(null);
        WindowsShell::setEnvSeamForTesting(null);
        ProcessContainment::resetInteractiveAvailabilityForTesting();

        @\unlink($this->fakeBin . '/bash.exe');
        @\rmdir($this->fakeBin);

        parent::tearDown();
    }

    public function testTheWindowsAvailabilityLegAnswersThroughTheVendorProbe(): void
    {
        // Pointing the family read at Windows must not fake a PASS — the
        // verdict now belongs to Kernel32ConPty::supported(), and on this
        // Linux host the vendor probe refuses (its first leg reads the raw
        // OS family, which no seam can move). A gate that ignored the probe
        // and trusted the seam answer would flip this assertion to true.
        WindowsShell::setOsFamilyForTesting('Windows');

        self::assertFalse(ProcessContainment::interactiveAvailable(), 'the Windows leg must defer to the vendor ConPTY probe, not to the family seam alone');
    }

    public function testTheWindowsSpawnCarriesBashDashCAndNeverBinSh(): void
    {
        WindowsShell::setOsFamilyForTesting('Windows');
        WindowsShell::setEnvSeamForTesting($this->pathSeam($this->fakeBin));

        $argv = ProcessContainment::interactiveSpawnCommand('echo %PATH% !x', null);

        self::assertSame($this->fakeBin . '/bash.exe', $argv[0], 'the resolved bash.exe rides argv[0] — the array-spawn law, never a shell string');
        self::assertSame('-c', $argv[1]);
        self::assertSame('echo %PATH% !x', $argv[2], 'the script reaches bash verbatim: percent signs and bangs intact, no POSIX quoting applied');
        self::assertNotContains('/bin/sh', $argv, 'cmd.exe or /bin/sh substituting for bash would answer through this leg');
    }

    public function testTheWindowsSpawnStillEntersCwdThroughTheCdGuardAndJailPrefix(): void
    {
        WindowsShell::setOsFamilyForTesting('Windows');
        WindowsShell::setEnvSeamForTesting($this->pathSeam($this->fakeBin));

        $argv = ProcessContainment::interactiveSpawnCommand('pwd', '/tmp/ctd6d nowhere', ['jail--bwrap', '--ro-bind']);

        self::assertSame(['jail--bwrap', '--ro-bind', $this->fakeBin . '/bash.exe', '-c'], \array_slice($argv, 0, 4), 'the jail prefix threads ahead of bash exactly as on the POSIX arm');
        $expectedGuard = 'cd ' . PosixShellQuote::quote('/tmp/ctd6d nowhere') . " || exit 1\n";
        self::assertSame($expectedGuard . 'pwd', $argv[4], 'the F-E5 cd guard is host-independent: same guard, same quote, on the bash -c leg too');
    }

    public function testTheBashMissingGateIsStatsOnly(): void
    {
        WindowsShell::setOsFamilyForTesting('Windows');

        // No PATH, no ProgramFiles with a Git install: bash() walks nothing
        // it can find and answers '' — the gate flips true, and the only way
        // it can flip back is planting the file the ladder looks for.
        WindowsShell::setEnvSeamForTesting(static fn (string $name): ?string => null);
        self::assertTrue(ProcessContainment::interactiveBashMissing());

        WindowsShell::setEnvSeamForTesting($this->pathSeam($this->fakeBin));
        self::assertFalse(ProcessContainment::interactiveBashMissing());

        // Off-Windows the family read short-circuits BEFORE the locate walk:
        // an env seam that accuses any read proves the POSIX cost is zero.
        WindowsShell::setOsFamilyForTesting(null);
        WindowsShell::setEnvSeamForTesting(static fn (string $name): ?string => throw new \LogicException('the POSIX path must not consult the Windows locate ladder'));
        self::assertFalse(ProcessContainment::interactiveBashMissing());
    }

    public function testTheAvailabilityArmsAreWiredInTheOrderTheDesignDeclares(): void
    {
        // Source-slice cert for what a Windows runner alone could otherwise
        // prove: the Windows leg consults the ConPTY probe (stat/version
        // only — the slice must not reach for an opener or a spawn), the
        // posix vendor-closure half asks for the FACTORY and the pair
        // contract (C-TUI-6.d retired the facade demand), and the spawn
        // command holds both bash -c and /bin/sh -c legs behind the same
        // isWindows() door.
        $body = $this->methodWindow('interactiveAvailable');

        self::assertGreaterThan(0, \strpos($body, 'WindowsShell::isWindows()'), 'the gate consults the seamable family read, not the raw constant');
        self::assertLessThan(\strpos($body, 'extension_loaded'), \strpos($body, 'WindowsShell::isWindows()'), 'the Windows arm answers before the POSIX ext-ffi leg — an ffi-only refusal above it would strand the ConPTY promise');
        self::assertGreaterThan(0, \strpos($body, 'Kernel32ConPty::supported()'), 'the Windows leg is the vendor probe');
        self::assertGreaterThan(0, \strpos($body, 'PtySystemFactory::class'));
        self::assertGreaterThan(0, \strpos($body, 'Contract\\PtyPair::class'));
        foreach (['proc_open', 'PtyBackend::open', '->spawn(', '::open('] as $forbidden) {
            self::assertFalse(\str_contains($body, $forbidden), "the availability gate promised a STAT and found {$forbidden} — a spawning probe leaks descriptors on exactly the paths this gate survives");
        }

        $spawn = $this->methodWindow('interactiveSpawnCommand');
        self::assertGreaterThan(0, \strpos($spawn, 'WindowsShell::isWindows()'));
        self::assertGreaterThan(0, \strpos($spawn, 'WindowsShell::bash()'));
        self::assertGreaterThan(0, \strpos($spawn, "'/bin/sh'"));
    }

    public function testTheCallerGatesRefuseWithTheInstallNoticeBeforeAnySpawn(): void
    {
        // The §6.3 refusal has to NAME the fix and must sit after the
        // availability gate (no ConPTY answers the pty message; ConPTY fine
        // but no bash answers the install message). Both call sites, same
        // shape — positional slices of the two guarded openers.
        foreach ([
            [__DIR__ . '/../../src/Support/PtyDriver.php', 'PtyDriver'],
            [__DIR__ . '/../../src/Tools/Concerns/CapturesProcessOutput.php', 'CapturesProcessOutput'],
        ] as [$file, $label]) {
            $source = \file_get_contents($file);
            self::assertIsString($source, $label);

            $available = \strpos($source, 'interactiveAvailable()');
            $bashGate = \strpos($source, 'interactiveBashMissing()');
            $notice = \strpos($source, 'WindowsShell::BASH_MISSING_NOTICE');

            self::assertGreaterThan(0, (int) $available, $label . ' keeps its availability gate');
            self::assertGreaterThan($available, (int) $bashGate, $label . ' gates bash AFTER availability — the two refusals stay distinguishable');
            self::assertGreaterThan((int) $bashGate, (int) $notice, $label . ' refuses bash-missing with the install notice verbatim');
        }
    }

    public function testTheResetReDerivesTheHostOwnAnswer(): void
    {
        // The memo hazard these pins live beside: every override above is
        // only safe because the reset re-asks. On this Linux box that answer
        // is true (ext-ffi, /dev/ptmx, factory + pair all present) — and the
        // live suites downstream depend on exactly that.
        ProcessContainment::resetInteractiveAvailabilityForTesting();

        self::assertTrue(ProcessContainment::interactiveAvailable(), 'reset must re-derive the live host verdict, not cache any test answer');
    }

    /**
     * @return \Closure(string): ?string
     */
    private function pathSeam(string $dir): \Closure
    {
        return static function (string $name) use ($dir): ?string {
            return match ($name) {
                'PATH' => $dir . ';C:\\nonexistent-' . \uniqid('', true),
                'PATHEXT' => '.exe',
                default => null,
            };
        };
    }

    private function methodWindow(string $method): string
    {
        $source = \file_get_contents(__DIR__ . '/../../src/Support/ProcessContainment.php');
        self::assertIsString($source);

        $start = \strpos($source, 'public static function ' . $method . '(');
        self::assertIsInt($start, $method . ' still exists on ProcessContainment');
        $end = \strpos($source, "\n    public static function ", $start + 1);

        return \substr($source, $start, ($end === false ? \strlen($source) : $end) - $start);
    }
}

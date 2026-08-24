<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Hooks\BuiltIn\AuditHook;
use SugarCraft\Crush\Hooks\HookContext;

/**
 * E345: A REFUSED AUDIT WRITE IS ANNOUNCED, ONCE PER PROCESS.
 *
 * `AuditHook::append()` answers `false` and `execute()` discards the answer,
 * so an operator whose audit directory has been squatted — or merely made by
 * hand — learned nothing until they went looking. An audit log that silently
 * stops recording is strictly worse than one that never existed, because it
 * looks authoritative.
 *
 * THE ARM THAT MADE THIS URGENT is the mode check. The symlink and
 * foreign-owner arms fire only when something is genuinely amiss. The mode arm
 * fires on a directory the operator created THEMSELVES with an ordinary
 * `mkdir -p` under umask 0022 — no attacker, nothing visibly wrong — and its
 * remedy (`chmod 700`) is invisible from the symptom, which is why that one
 * notice spells the command out.
 *
 * ONCE, AND THAT IS WHAT KILLS THE COST ARGUMENT. E345's objection to a notice
 * was one line per tool call for the whole run on a squatted box, which is the
 * shape that trains an operator to ignore stderr. Every condition reported
 * here is a property of a directory that does not change mid-run, so the
 * second line would carry nothing the first did not.
 *
 * @see AuditHook
 */
final class AuditHookRefusalNoticeTest extends TestCase
{
    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->resetLatch();
        RuntimeNoticeSink::reset();

        // THE SINK DROPS EVERYTHING UNTIL IT IS ARMED, by design: a notice
        // recorded in a process that will never build a `Chat` has no reader.
        // The in-process (array) backend is the one to arm here — a datagram
        // transport would put these rows on a socket this test cannot read.
        // Without this line every assertion below passes vacuously, which is
        // how it was found: the two known-negatives were green and all three
        // positives were empty.
        //
        // `arm(false)` ANSWERS false and that is the success case, not a
        // failure — its return value reports whether a cross-fork TRANSPORT
        // exists, and asking for the in-process backend means there is none.
        // `isArmed()` is the question this test actually has.
        RuntimeNoticeSink::arm(crossFork: false);
        self::assertTrue(RuntimeNoticeSink::isArmed(), 'the notice sink refused to arm');
    }

    protected function tearDown(): void
    {
        // Exact-path removal of names this test made; never a glob, and never
        // anything a sibling lane could own.
        foreach (array_reverse($this->created) as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->created = [];
        $this->resetLatch();
        RuntimeNoticeSink::reset();
    }

    private function resetLatch(): void
    {
        (new \ReflectionProperty(AuditHook::class, 'noticed'))->setValue(null, false);
    }

    /** A process-unique directory name this test owns. */
    private function scratchDirectory(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sc_r49b_notice_');
        @unlink($path);
        $this->created[] = $path;

        return $path;
    }

    private function drive(string $directory): void
    {
        (new AuditHook($directory . '/audit.log'))->execute(new HookContext(
            sessionId: 'sess',
            toolName: 'probe',
            toolArgs: [],
            toolInput: '{}',
            toolOutput: 'ok',
            model: 'm',
            provider: 'p',
            projectRoot: '/',
        ));
    }

    /**
     * A caller-supplied path is NOT guarded and therefore NOT announced —
     * asserted first, because every test below drives the hook through a
     * constructor argument and would otherwise be measuring the wrong gate.
     *
     * The hook only guards, and only complains about, the path it INVENTED.
     */
    public function testACallerSuppliedPathIsNeitherGuardedNorAnnounced(): void
    {
        $directory = $this->scratchDirectory();
        mkdir($directory, 0o777, true);
        $this->created[] = $directory . '/audit.log';

        $this->drive($directory);

        self::assertFileExists($directory . '/audit.log', 'a caller-supplied path stopped being written');
        self::assertSame([], RuntimeNoticeSink::drain(), 'a caller-supplied path was complained about');
    }

    /**
     * THE ARM WITH THE INVISIBLE REMEDY: a group/other-reachable directory the
     * operator made by hand.
     *
     * Driven through `defaultLogDirectory()`'s pin so `$ownsPath` is true,
     * which is the gate the notice sits behind.
     */
    public function testAWorldReachableDirectoryIsAnnouncedWithItsRemedy(): void
    {
        $directory = $this->scratchDirectory();
        mkdir($directory, 0o777, true);
        chmod($directory, 0o755);

        $notices = $this->drainAfterUnconfiguredWriteTo($directory);

        self::assertCount(1, $notices);
        self::assertStringContainsString('audit log disabled', $notices[0]);
        self::assertStringContainsString($directory, $notices[0]);
        self::assertStringContainsString('chmod 700', $notices[0], 'the one arm whose fix is invisible must name it');
        self::assertFileDoesNotExist($directory . '/audit.log', 'the refusal did not actually refuse');
    }

    /**
     * A SYMLINK where the directory should be names the symlink, and does NOT
     * offer `chmod 700` — the remedy belongs to one arm only.
     *
     * Without this, "the mode notice says chmod" is satisfied by a notice that
     * says chmod for everything.
     */
    public function testASymlinkedDirectoryIsAnnouncedAsALinkAndNotAsAModeProblem(): void
    {
        $real = $this->scratchDirectory();
        mkdir($real, 0o700, true);
        $link = $this->scratchDirectory();
        symlink($real, $link);

        $notices = $this->drainAfterUnconfiguredWriteTo($link);

        self::assertCount(1, $notices);
        self::assertStringContainsString('symbolic link', $notices[0]);
        self::assertStringNotContainsString('chmod 700', $notices[0]);
    }

    /**
     * KNOWN-NEGATIVE IN THE SAME SHAPE: a directory that is fine produces NO
     * notice, and does produce a log.
     *
     * Every assertion above is that a notice appeared, which a `warn()` wired
     * unconditionally into `append()` would satisfy for all of them.
     */
    public function testAnAcceptableDirectoryIsWrittenAndSaysNothing(): void
    {
        $directory = $this->scratchDirectory();
        mkdir($directory, 0o700, true);
        $this->created[] = $directory . '/audit.log';

        $notices = $this->drainAfterUnconfiguredWriteTo($directory);

        self::assertSame([], $notices);
        self::assertFileExists($directory . '/audit.log');
    }

    /**
     * THE LATCH: three refused calls, one line — and the three refusals are
     * deliberately about THREE DIFFERENT DIRECTORIES.
     *
     * This is the assertion that answers E345's cost objection, so it is made
     * against real `execute()` calls rather than against the latch flag.
     *
     * WHY THE THREE DIRECTORIES, WHICH IS THE WHOLE POINT OF THE TEST AND WAS
     * NOT IN ITS FIRST VERSION. That version drove three calls at ONE bad
     * directory and asserted one notice, and it PASSED with the latch deleted
     * — found by mutation, not by reading. {@see RuntimeNoticeSink::drain()}
     * de-duplicates identical rows before it returns them, so three identical
     * messages arrive as one whatever this class does. The assertion was
     * measuring the sink's dedup and reporting it as evidence about a latch it
     * never touched.
     *
     * Three distinct directories produce three distinct messages, which
     * `drain()` cannot merge — so the count that comes back is the number of
     * times THIS class decided to speak, which is the property under test.
     */
    public function testThreeRefusalsAboutDifferentDirectoriesStillProduceOneNotice(): void
    {
        $directories = [];
        for ($i = 0; $i < 3; $i++) {
            $directory = $this->scratchDirectory();
            mkdir($directory, 0o777, true);
            chmod($directory, 0o755);
            $directories[] = $directory;
        }

        $notices = [];
        foreach ($directories as $directory) {
            $notices = [...$notices, ...$this->drainAfterUnconfiguredWriteTo($directory)];
        }

        // THE CONTROL FOR THE CONTROL: the three messages really are distinct,
        // so a `1` below cannot be `drain()`'s dedup wearing the latch's hat.
        // Derived by asking the class, not by re-spelling its format.
        $reason = new \ReflectionMethod(AuditHook::class, 'directoryRefusalReason');
        $spoken = array_map(static fn (string $d): string => (string) $reason->invoke(null, $d), $directories);
        self::assertCount(3, array_unique($spoken), 'the three refusals say the same thing, so dedup could explain a 1');

        self::assertCount(1, $notices, 'the notice is per call rather than per process');
    }

    /**
     * Point the unconfigured default at $directory, drive $times tool calls
     * through a no-argument hook, and hand back what reached the sink.
     *
     * The pin is restored to whatever `tests/bootstrap.php` installed, not to
     * null: clearing it would leave the rest of the suite writing to the
     * production path, which is the thing E351 closed.
     *
     * @return list<string>
     */
    private function drainAfterUnconfiguredWriteTo(string $directory, int $times = 1): array
    {
        $installed = AuditHook::defaultLogDirectoryPin();
        AuditHook::pinDefaultLogDirectory($directory);

        try {
            for ($i = 0; $i < $times; $i++) {
                (new AuditHook())->execute(new HookContext(
                    sessionId: 'sess',
                    toolName: 'probe',
                    toolArgs: [],
                    toolInput: '{}',
                    toolOutput: 'ok',
                    model: 'm',
                    provider: 'p',
                    projectRoot: '/',
                ));
            }
        } finally {
            AuditHook::pinDefaultLogDirectory($installed);
        }

        return RuntimeNoticeSink::drain();
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\AuditHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * @see AuditHook
 */
final class AuditHookTest extends TestCase
{
    private string $tempLogFile;

    /** Every private path this test made, removed in tearDown(). */
    private array $scratch = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempLogFile = sys_get_temp_dir() . '/audit-hook-test-' . uniqid((string) getmypid(), true) . '.log';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->tempLogFile)) {
            unlink($this->tempLogFile);
        }

        // reverse order: a directory's contents were appended after it was.
        foreach (array_reverse($this->scratch) as $path) {
            // is_link() first and never is_file(): a dangling link is neither
            // a file nor a directory, and leaving it behind is what turns one
            // failing run into a second run that fails for another reason.
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                foreach (glob($path . '/*') ?: [] as $child) {
                    @unlink($child);
                }
                @rmdir($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
        $this->scratch = [];
    }

    // =========================================================================
    // Basic Interface Tests
    // =========================================================================

    public function testName(): void
    {
        $hook = new AuditHook();

        $this->assertSame('audit', $hook->name());
    }

    public function testEvent(): void
    {
        $hook = new AuditHook();

        $this->assertSame(HookEvent::PostToolUse, $hook->event());
    }

    public function testMatcher(): void
    {
        $hook = new AuditHook();

        $this->assertSame('.*', $hook->matcher());
    }

    // =========================================================================
    // Log File Creation Tests
    // =========================================================================

    public function testExecuteCreatesLogFile(): void
    {
        $this->assertFileDoesNotExist($this->tempLogFile);

        $hook = new AuditHook($this->tempLogFile);
        $context = $this->createContext();
        $hook->execute($context);

        $this->assertFileExists($this->tempLogFile);
    }

    /**
     * THE DEFAULT PATH IS ASSERTED AS CONSTRUCTED, NOT AS WRITTEN, AND THAT IS
     * THE WHOLE POINT OF THIS TEST'S SHAPE (E298).
     *
     * The old version ran the real `execute()` against `AuditHook`'s production
     * default — one FIXED name on the machine's real temp dir, since the
     * suite's `TMPDIR` sandbox does not move `sys_get_temp_dir()` in-process —
     * asserted the file existed, and then UNLINKED it. Two concurrent copies
     * race: one deletes the file between the other's write and its
     * `assertFileExists`. MEASURED at three takes of six concurrent runs: 3, 2
     * and 2 failures out of 6, every one `Failed asserting that file
     * ".../sugar-crush-audit.log" exists`.
     *
     * It was also cross-process VANDALISM rather than mere self-interference:
     * the name is the production default, so the suite deleted the audit log of
     * any real `sugarcrush` on the same box, and of any sibling lane's suite.
     *
     * A shared-name hazard is not an entropy-flag hazard, which is why the
     * round-49 pre-round sweep could not see it: that sweep's alphabet was the
     * name of that one call, and this path has no entropy source at all
     * (rule 11).
     *
     * NEITHER SENTENCE SPELLS THAT CALL, and that is deliberate rather than
     * coy. The sweep in question was TEXTUAL, and a textual sweep cannot tell
     * an offender from a description of one — the same pass ate a guard's own
     * fixture and mangled the doc-block justifying it (rule 26). MEASURED, so
     * this is a choice and not a requirement: the census scanner drops
     * `T_DOC_COMMENT`, so a mention here is invisible to it — a synthetic
     * doc-comment carrying the name reports nothing while a real flagless call
     * in the same check reports its line.
     *
     * WHAT THIS ASSERTED: the literal `sugar-crush-audit.log` on the temp root.
     * WHAT IS TRUE NOW (E328): the leaf is the same idea — a fixed name, so
     * `tail -f` still works across runs — but it sits inside a directory
     * scoped to the effective uid, because the temp root is world-writable and
     * the old leaf was reachable by every other user on the box. WHY THIS TEST
     * STILL EARNS ITS PLACE UNCHANGED IN SHAPE: asserting the CONSTRUCTED value
     * rather than driving a write is what stops this test from being the
     * vandal again, and that is orthogonal to which path is right.
     */
    public function testTheDefaultLogFileIsThePerUserProductionPathWithoutWritingToIt(): void
    {
        $expected = sys_get_temp_dir() . '/sugar-crush-audit-' . posix_geteuid() . '/audit.log';

        [$actual, $constructed] = $this->withNoDirectoryPin(static fn (): array => [
            AuditHook::defaultLogFile(),
            (new \ReflectionProperty(AuditHook::class, 'logFile'))->getValue(new AuditHook()),
        ]);

        self::assertSame($expected, $actual);
        self::assertSame($expected, $constructed);
    }

    /**
     * E328, as the regression it is: the leaf every user on the box shared.
     *
     * Spelled out rather than derived, because the point is that this exact
     * string is no longer what an unconfigured `sugarcrush` appends to, and a
     * derivation from the code under test cannot say that.
     */
    public function testTheDefaultIsNoLongerTheLeafEveryUserOnTheBoxShared(): void
    {
        $actual = $this->withNoDirectoryPin(static fn (): string => AuditHook::defaultLogFile());

        self::assertNotSame(sys_get_temp_dir() . '/sugar-crush-audit.log', $actual);
        self::assertStringContainsString('/sugar-crush-audit-' . posix_geteuid() . '/', $actual);
    }

    /**
     * Run $probe with {@see AuditHook::pinDefaultLogDirectory()} cleared, then
     * put back whatever `tests/bootstrap.php` installed.
     *
     * WHY THE TWO TESTS ABOVE NEED THIS (E351). They are the only assertions
     * in the tree about the PRODUCTION default, and the bootstrap now points
     * the unconfigured default at the suite sandbox so a phpunit run stops
     * creating and populating the real audit directory on the developer's box.
     * Clearing the pin for the length of one probe is what keeps the
     * production claim assertable without giving the claim back the side
     * effect it was rewritten (E298) to lose: nothing here WRITES, it only
     * constructs.
     *
     * RESTORED TO THE INSTALLED VALUE AND NOT TO NULL, and `finally` rather
     * than a trailing line: a throw inside the probe would otherwise leave
     * every later test in the process writing to the production path, which is
     * the exact leak this seam closed.
     *
     * @template T
     * @param \Closure():T $probe
     * @return T
     */
    private function withNoDirectoryPin(\Closure $probe): mixed
    {
        $installed = AuditHook::defaultLogDirectoryPin();
        self::assertIsString($installed, 'tests/bootstrap.php stopped pinning the audit directory');
        AuditHook::pinDefaultLogDirectory(null);

        try {
            return $probe();
        } finally {
            AuditHook::pinDefaultLogDirectory($installed);
        }
    }

    /**
     * The gate the hardening hangs off: this class guards the path it INVENTED
     * and leaves a caller's own path alone.
     */
    public function testOnlyTheSelfChosenPathIsMarkedAsThisClassToGuard(): void
    {
        $property = new \ReflectionProperty(AuditHook::class, 'ownsPath');

        self::assertTrue($property->getValue(new AuditHook()));
        self::assertFalse($property->getValue(new AuditHook($this->tempLogFile)));
    }

    /**
     * The WRITE half, driven at a private path so no two processes can collide
     * and nothing outside the test is touched.
     */
    public function testExecuteCreatesTheLogFileWhenNoneExistsYet(): void
    {
        $logFile = sys_get_temp_dir() . '/sc_audit_default_' . getmypid() . '_' . bin2hex(random_bytes(6)) . '.log';
        self::assertFileDoesNotExist($logFile);

        $result = (new AuditHook($logFile))->execute($this->createContext());

        $this->assertTrue($result->isAllowed());
        $this->assertFileExists($logFile);

        @unlink($logFile);
    }

    // =========================================================================
    // Log Entry Format Tests
    // =========================================================================

    public function testExecuteWritesEntry(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context = new HookContext(
            sessionId: 'session-789',
            toolName: 'bash',
            toolArgs: [],
            toolInput: 'echo "hello world"',
            toolOutput: 'hello world',
            model: 'claude-3-5-sonnet',
            provider: 'anthropic',
            projectRoot: '/home/user/project',
        );

        $hook->execute($context);

        $logContent = file_get_contents($this->tempLogFile);
        $this->assertNotEmpty($logContent);

        // Verify log entry format: [timestamp] sessionId toolName toolInput => truncatedOutput
        $this->assertStringStartsWith('[', $logContent);
        $this->assertStringContainsString('session-789', $logContent);
        $this->assertStringContainsString('bash', $logContent);
        $this->assertStringContainsString('echo "hello world"', $logContent);
    }

    public function testExecuteIncludesTimestamp(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context = $this->createContext();

        $hook->execute($context);

        $logContent = file_get_contents($this->tempLogFile);
        // Timestamp format: [2026-06-03 12:34:56]
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $logContent);
    }

    public function testExecuteIncludesAllContextFields(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context = new HookContext(
            sessionId: 'my-session-id',
            toolName: 'Edit',
            toolArgs: ['file' => 'test.php'],
            toolInput: 'nano src/Controller.php',
            toolOutput: 'File edited successfully',
            model: 'gpt-4',
            provider: 'openai',
            projectRoot: '/workspace/myapp',
        );

        $hook->execute($context);

        $logContent = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('my-session-id', $logContent);
        $this->assertStringContainsString('Edit', $logContent);
        $this->assertStringContainsString('nano src/Controller.php', $logContent);
        $this->assertStringContainsString('File edited successfully', $logContent);
    }

    // =========================================================================
    // Output Truncation Tests
    // =========================================================================

    public function testExecuteTruncatesOutput(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        // Create a very long output (more than 200 chars)
        $longOutput = str_repeat('x', 500);
        $context = $this->createContext(toolOutput: $longOutput);

        $hook->execute($context);

        $logContent = file_get_contents($this->tempLogFile);
        // Find the "=>" marker and extract what follows
        $arrowPos = strpos($logContent, '=>');
        $this->assertNotFalse($arrowPos);
        $outputAfterArrow = trim(substr($logContent, $arrowPos + 2));
        // The truncated output should be at most 200 chars (plus newline)
        $this->assertLessThanOrEqual(200, strlen($outputAfterArrow));
    }

    public function testExecuteDoesNotTruncateShortOutput(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context = $this->createContext(toolOutput: 'short output');

        $hook->execute($context);

        $logContent = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('short output', $logContent);
    }

    public function testExecuteTruncatesExactlyAt200Chars(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        // Exactly 250 chars
        $exactOutput = str_repeat('a', 250);
        $context = $this->createContext(toolOutput: $exactOutput);

        $hook->execute($context);

        $logContent = file_get_contents($this->tempLogFile);
        $arrowPos = strpos($logContent, '=>');
        $outputAfterArrow = trim(substr($logContent, $arrowPos + 2));
        // Should be truncated to 200 chars
        $this->assertSame(200, strlen($outputAfterArrow));
    }

    // =========================================================================
    // Atomic Append Tests
    // =========================================================================

    public function testExecuteAppendsToLogFile(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context1 = $this->createContext(toolInput: 'first command');
        $context2 = $this->createContext(toolInput: 'second command');

        $hook->execute($context1);
        $hook->execute($context2);

        $logContent = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('first command', $logContent);
        $this->assertStringContainsString('second command', $logContent);
        // Should have two entries
        $entryCount = substr_count($logContent, '[');
        $this->assertSame(2, $entryCount);
    }

    // =========================================================================
    // Allow Action Tests
    // =========================================================================

    public function testExecuteAlwaysAllows(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Empty Output Tests
    // =========================================================================

    public function testExecuteHandlesEmptyOutput(): void
    {
        $hook = new AuditHook($this->tempLogFile);
        $context = $this->createContext(toolOutput: '');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
        $logContent = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('=>', $logContent);
    }

    // =========================================================================
    // E328 — the default path's guards, driven at private paths only
    // =========================================================================

    /**
     * WHY THESE RUN THROUGH REFLECTION RATHER THAN THROUGH `execute()`.
     * The arms below only apply to the path this class CHOSE, and that path is
     * the machine's real temp dir — `sys_get_temp_dir()` is resolved and cached
     * once per process, so the suite's `TMPDIR` sandbox cannot move it. Driving
     * them at the real default is precisely the cross-process vandalism E298
     * removed from this file. Reflection lets the same code run against a
     * private directory: {@see AuditHook::$logFile} and
     * {@see AuditHook::$ownsPath} are readonly and UNINITIALISED on an
     * instance built without the constructor, and PHP 8.3.6 permits reflection
     * to initialise a readonly property exactly once.
     */
    public function testTheChosenDirectoryIsCreatedOwnerOnlyAndThenAccepted(): void
    {
        $directory = $this->scratchPath('dir');
        self::assertDirectoryDoesNotExist($directory);

        self::assertTrue(self::directoryIsOurs($directory), 'a directory that did not exist yet was refused');
        self::assertDirectoryExists($directory);
        self::assertSame(0o700, fileperms($directory) & 0o777, 'the created directory is readable by somebody else');

        // Second call, on the directory the first one made: the accept arm
        // rather than the create arm, which is the one production hits on
        // every call after the first.
        self::assertTrue(self::directoryIsOurs($directory));
    }

    public function testASymlinkedDirectoryIsRefusedRatherThanFollowed(): void
    {
        $real = $this->scratchPath('real');
        $link = $this->scratchPath('link');
        self::assertTrue(mkdir($real, 0o700));
        self::assertTrue(symlink($real, $link));

        // is_dir() FOLLOWS the link and answers true, so a guard written
        // is_dir()-first would accept this. That is the mutation this kills.
        self::assertDirectoryExists($link);
        self::assertFalse(self::directoryIsOurs($link), 'a symlinked directory was accepted');
    }

    public function testAPlainFileSittingAtTheDirectoryNameIsRefused(): void
    {
        $path = $this->scratchPath('file');
        self::assertNotFalse(file_put_contents($path, 'squatted'));

        self::assertFalse(self::directoryIsOurs($path), 'a plain file at the directory name was accepted');
        self::assertSame('squatted', file_get_contents($path), 'the squatting file was written through');
    }

    /**
     * The ownership arm, against a directory whose owner the test does not get
     * to choose.
     *
     * WHY THE INPUT IS NOT SIMPLY THE TEMP ROOT ANY MORE. It was, and the
     * expectation was derived from that root's real owner. Since the accept
     * path also refuses on {@see AuditHook::FOREIGN_ACCESS_BITS}, the temp
     * root (mode 1777 on this box, MEASURED) is now refused by the MODE arm
     * whatever its owner is — so a mutation deleting the uid comparison would
     * have survived an input that the next guard along refuses anyway. An arm
     * needs an input only it can refuse.
     *
     * So the input is derived: the first directory on a small candidate list
     * that is real, is not a symlink, is owned by somebody else AND is already
     * tight enough to pass the mode arm. On this box `/root` (0700, uid 0) is
     * the first hit.
     *
     * STATED BOUND, UNCHANGED IN SUBSTANCE: a suite running as root owns every
     * one of those candidates, so the list comes back empty and the arm has no
     * input at all — there is no directory a root process does not own. Under
     * root this arm is vacuous and a mutation deleting the uid comparison
     * survives it; the positive control below still runs, so the test is never
     * silently asserting nothing.
     */
    public function testADirectoryThisUserDoesNotOwnIsRefused(): void
    {
        $foreign = self::aTightDirectoryOwnedBySomebodyElse();

        if ($foreign !== null) {
            self::assertFalse(
                self::directoryIsOurs($foreign),
                $foreign . ' is owned by another user and is tight enough to clear the mode arm, '
                . 'so accepting it means the ownership comparison is gone',
            );
        }

        // THE POSITIVE CONTROL, IN THE SAME TEST (rule 15/25). Every assertion
        // above is a refusal, and a method rewritten to `return false;`
        // satisfies all of them. Under root it is the ONLY live assertion here.
        $ours = $this->scratchPath('owned');
        self::assertTrue(mkdir($ours, 0o700));
        self::assertTrue(
            self::directoryIsOurs($ours),
            'a directory this process owns at the mode this class creates was refused, so the '
            . 'refusals above are statements about the method rather than about ownership',
        );
    }

    /**
     * THE ACCEPT PATH IS JUDGED ON ITS MODE, and that is a different arm from
     * the create path's mode.
     *
     * {@see testTheChosenDirectoryIsCreatedOwnerOnlyAndThenAccepted()} starts
     * from `assertDirectoryDoesNotExist()`, so it only ever exercises the
     * CREATE arm — which runs once in the life of a machine, while this one
     * runs on every tool call of every run afterwards. Before this arm existed
     * a pre-existing 0777 directory owned by this euid was accepted verbatim
     * (MEASURED, PHP 8.3.6) and the log went in at 0664.
     *
     * THE MODE IS NEVER REPAIRED, asserted on every row rather than argued:
     * see {@see AuditHook::directoryIsOurs()} for the measurement that ruled a
     * repair arm out (`/tmp` is 1777 and root-owned, so under root a repair
     * would chmod it to 0700).
     *
     * @dataProvider preExistingDirectoryModes
     */
    public function testAnAlreadyExistingDirectoryIsJudgedOnItsModeAndNeverRepaired(
        int $mode,
        bool $accepted,
    ): void {
        $directory = $this->scratchPath('mode');
        self::assertTrue(mkdir($directory, 0o700));
        // chmod, not mkdir's argument: mkdir's mode is masked by the ambient
        // umask (0002 here), so mkdir(0o777) would quietly give 0775 and the
        // row would be testing a mode it did not ask for.
        self::assertTrue(chmod($directory, $mode));
        clearstatcache(true, $directory);
        self::assertSame($mode, fileperms($directory) & 0o777, 'the fixture did not get the mode it asked for');

        self::assertSame(
            $accepted,
            self::directoryIsOurs($directory),
            sprintf('a pre-existing directory at mode %04o was judged wrongly', $mode),
        );

        clearstatcache(true, $directory);
        self::assertSame(
            $mode,
            fileperms($directory) & 0o777,
            sprintf('the guard changed a mode %04o directory instead of judging it', $mode),
        );
    }

    /**
     * Modes an already-existing audit directory can arrive with, and whether
     * the guard may use it.
     *
     * BOTH POLARITIES ARE IN THIS LIST DELIBERATELY (rule 15): the accepting
     * rows are what makes the refusing rows evidence rather than a method that
     * answers `false`. 0o500 and 0o600 are accepted although they are NOT the
     * mode this class creates, which is the point of testing
     * `& FOREIGN_ACCESS_BITS` rather than equality — an operator who tightened
     * their own directory further must not be refused.
     *
     * @return iterable<string, array{int, bool}>
     */
    public static function preExistingDirectoryModes(): iterable
    {
        yield 'the mode this class creates'      => [0o700, true];
        yield 'tightened further, still ours'    => [0o600, true];
        yield 'no write for us either'           => [0o500, true];
        yield 'other may execute'                => [0o701, false];
        yield 'other may write'                  => [0o702, false];
        yield 'other may read'                   => [0o704, false];
        yield 'group may execute'                => [0o710, false];
        yield 'the umask-0022 mkdir -p shape'    => [0o755, false];
        yield 'group readable'                   => [0o750, false];
        yield 'wide open'                        => [0o777, false];
    }

    /**
     * The leaf is created unreachable by anybody else too.
     *
     * THE AMBIENT UMASK IS PINNED INSIDE THE TEST rather than read, and that
     * is what makes the known-negative real. `file_put_contents()` creates at
     * 0666 minus the umask, so on a box whose umask is already 0o077 the
     * guarded and unguarded arms would land on the same mode and the
     * comparison below would pass with the narrowing deleted. Forcing 0o022
     * for the duration means the unguarded arm MUST come back 0644 — a mode
     * only the ambient umask can produce — so 0600 on the guarded arm is
     * attributable to the narrowing and nothing else.
     */
    public function testTheGuardedLeafIsCreatedOwnerOnlyAndTheCallersIsLeftToTheUmask(): void
    {
        $directory = $this->scratchPath('leafmode');
        self::assertTrue(mkdir($directory, 0o700));

        $guarded   = $directory . '/audit.log';
        $unguarded = $directory . '/caller.log';

        $previous = umask(0o022);

        try {
            self::assertTrue(self::append($guarded, true, "one\n"), 'the guarded append refused an ordinary leaf');
            self::assertTrue(self::append($unguarded, false, "two\n"), 'the caller-supplied append was refused');
        } finally {
            umask($previous);
        }

        clearstatcache();
        self::assertSame(
            0o600,
            fileperms($guarded) & 0o777,
            'the log this class chose the path for is readable by somebody else. It carries every '
            . "tool's arguments and 200 bytes of its output",
        );

        // THE KNOWN-NEGATIVE. Without it, `0600` is also what this assertion
        // would see on a box whose umask happens to be tight, i.e. what a
        // DELETED narrowing returns (rule 25).
        self::assertSame(
            0o644,
            fileperms($unguarded) & 0o777,
            'the caller-supplied arm did not get the ambient umask, so the guarded arm being 0600 '
            . 'says nothing about the narrowing — both arms may simply be inheriting a tight umask',
        );
    }

    /**
     * The posix-less fallback scope, which no build this suite runs on can
     * reach through {@see AuditHook::defaultLogDirectory()}.
     *
     * WHY THIS EXISTS: with the `posix_geteuid()` lookup inline, renaming the
     * fallback literal was a mutation the whole `AuditHook` suite survived
     * (MEASURED, round 49) — the arm had no test and the paragraph on
     * `defaultLogDirectory()` was its only record. Rule 8: a mechanism stated
     * in a comment and nowhere else.
     */
    public function testTheDirectoryNameFallsBackToASharedScopeOnABuildWithNoPosix(): void
    {
        $for = new \ReflectionMethod(AuditHook::class, 'directoryFor');
        $for->setAccessible(true);

        self::assertSame(
            sys_get_temp_dir() . '/sugar-crush-audit-noposix',
            $for->invoke(null, null),
            'the name a build with no posix_geteuid() shares changed. That is allowed, but the '
            . 'paragraph on defaultLogDirectory() explaining what the shared scope costs names it',
        );

        // The uid arm, in the same test, so the assertion above is a statement
        // about the null branch and not about a method that ignores its input.
        self::assertSame(
            sys_get_temp_dir() . '/sugar-crush-audit-4242',
            $for->invoke(null, 4242),
            'the directory name no longer carries the uid it was given',
        );
    }

    /**
     * The write half, both polarities in one place (rule 25: the refusal
     * assertions are worthless without the positive beside them).
     */
    public function testTheGuardedAppendRefusesASymlinkedLeafAndWritesAnOrdinaryOne(): void
    {
        $directory = $this->scratchPath('guarded');
        self::assertTrue(mkdir($directory, 0o700));

        $plain = $directory . '/audit.log';
        self::assertTrue(self::append($plain, true, "one\n"), 'the guarded append refused an ordinary leaf');
        self::assertSame("one\n", file_get_contents($plain));

        $target = $this->scratchPath('planted');
        self::assertNotFalse(file_put_contents($target, 'untouched'));
        $planted = $directory . '/planted.log';
        self::assertTrue(symlink($target, $planted));

        self::assertFalse(self::append($planted, true, "two\n"), 'the guarded append followed a symlink');
        self::assertSame('untouched', file_get_contents($target), 'the symlink target was appended through');
    }

    /**
     * The deliberate asymmetry, pinned so it cannot be "tidied" into
     * symmetry: a path an embedder passed is that embedder's choice, and a
     * symlink into a log-rotation directory is an ordinary thing to want.
     */
    public function testACallerSuppliedSymlinkIsStillAppendedThrough(): void
    {
        $target = $this->scratchPath('rotated');
        self::assertNotFalse(file_put_contents($target, "kept\n"));
        $link = $this->scratchPath('caller-link');
        self::assertTrue(symlink($target, $link));

        self::assertTrue(self::append($link, false, "added\n"));
        self::assertSame("kept\nadded\n", file_get_contents($target));
    }

    /**
     * A path whose parent this class will not use is refused by the guarded
     * arm and honoured by the unguarded one — the two halves of the gate, on
     * one input, so neither can be read as an accident of the path.
     *
     * WHAT THE INPUT USED TO BE: the temp root, refused because this process
     * does not own it — with a whole second branch for a suite running as
     * root, in which both arms accept and the test asserts that instead.
     * WHAT IS TRUE NOW: the accept path also refuses on
     * {@see AuditHook::FOREIGN_ACCESS_BITS}, and a directory this process owns
     * and has left group- or world-reachable is refused on EVERY box, root
     * included. WHY THAT IS THE BETTER INPUT: the root branch was vacuous —
     * it asserted that a gate which refuses nothing refuses nothing — and this
     * one gives that box a real refusal to observe. The ownership arm keeps
     * its own test, with its own stated bound.
     */
    public function testTheGateItselfDecidesAndNotThePath(): void
    {
        $directory = $this->scratchPath('gate');
        self::assertTrue(mkdir($directory, 0o700));
        self::assertTrue(chmod($directory, 0o755));

        $path = $directory . '/audit.log';

        self::assertFalse(self::append($path, true, "guarded\n"), 'the guarded arm wrote into a directory '
            . 'somebody else can read');
        self::assertFileDoesNotExist($path);
        self::assertTrue(self::append($path, false, "unguarded\n"), 'the unguarded arm refused a caller path');
        self::assertSame("unguarded\n", file_get_contents($path));
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /** {@see AuditHook::directoryIsOurs()}, which is private and static. */
    private static function directoryIsOurs(string $directory): bool
    {
        $method = new \ReflectionMethod(AuditHook::class, 'directoryIsOurs');

        return (bool) $method->invoke(null, $directory);
    }

    /**
     * {@see AuditHook::append()} on an instance carrying $logFile and
     * $ownsPath, built without the constructor so the path is never the
     * production default.
     */
    private static function append(string $logFile, bool $ownsPath, string $entry): bool
    {
        $class = new \ReflectionClass(AuditHook::class);
        $hook = $class->newInstanceWithoutConstructor();
        $class->getProperty('logFile')->setValue($hook, $logFile);
        $class->getProperty('ownsPath')->setValue($hook, $ownsPath);

        return (bool) $class->getMethod('append')->invoke($hook, $entry);
    }

    /** A private, process-unique path under the machine temp dir. */
    private function scratchPath(string $tag): string
    {
        $path = sys_get_temp_dir() . '/sc_audit_' . $tag . '_' . getmypid() . '_' . bin2hex(random_bytes(6));
        $this->scratch[] = $path;

        return $path;
    }

    /**
     * A real directory owned by another user that is ALREADY tight enough to
     * clear the mode arm, so refusing it is attributable to ownership alone —
     * or null when this process owns every candidate, which is the root case.
     *
     * A FIXED CANDIDATE LIST RATHER THAN A WALK: the list is short, every
     * entry is a filesystem-hierarchy standard path, and each is checked
     * against the tree at call time rather than assumed — a candidate that is
     * absent, is a symlink, is ours, or is loose is passed over.
     */
    private static function aTightDirectoryOwnedBySomebodyElse(): ?string
    {
        foreach (['/root', '/lost+found', '/etc/ssl/private', '/var/lib/private'] as $candidate) {
            if (is_link($candidate) || !is_dir($candidate)) {
                continue;
            }

            $owner = @fileowner($candidate);
            $mode  = @fileperms($candidate);
            if ($owner === false || $mode === false) {
                continue;
            }

            if ($owner !== posix_geteuid() && ($mode & 0o077) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    private function createContext(
        string $sessionId = 'test-session',
        string $toolName = 'bash',
        string $toolInput = 'echo test',
        string $toolOutput = 'test output',
    ): HookContext {
        return new HookContext(
            sessionId: $sessionId,
            toolName: $toolName,
            toolArgs: [],
            toolInput: $toolInput,
            toolOutput: $toolOutput,
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        );
    }
}

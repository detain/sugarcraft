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
     * A shared-name hazard is not a `uniqid` hazard, which is why the round-49
     * pre-round sweep could not see it: that sweep's alphabet was the token
     * for that call, and this path has no entropy source at all (rule 11).
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
        $hook = new AuditHook();

        $expected = sys_get_temp_dir() . '/sugar-crush-audit-' . posix_geteuid() . '/audit.log';

        self::assertSame($expected, AuditHook::defaultLogFile());
        self::assertSame(
            $expected,
            (new \ReflectionProperty(AuditHook::class, 'logFile'))->getValue($hook),
        );
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
        self::assertNotSame(sys_get_temp_dir() . '/sugar-crush-audit.log', AuditHook::defaultLogFile());
        self::assertStringContainsString(
            '/sugar-crush-audit-' . posix_geteuid() . '/',
            AuditHook::defaultLogFile(),
        );
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
     * STATED BOUND: the expectation is DERIVED from the temp root's real owner
     * rather than hard-coded, because a suite running as root owns the temp
     * root and the honest answer there is `true`. Under root this assertion is
     * therefore vacuous and a mutation deleting the uid comparison survives it
     * — there is no directory a root process does not own, so the arm has no
     * input on that box. Under an ordinary uid (the case this suite runs in on
     * CI and locally, PHP 8.3.6) the temp root is root-owned and the mutation
     * dies here.
     */
    public function testADirectoryThisUserDoesNotOwnIsRefused(): void
    {
        $root = sys_get_temp_dir();
        $owner = fileowner($root);
        self::assertNotFalse($owner);

        self::assertSame(
            $owner === posix_geteuid(),
            self::directoryIsOurs($root),
            'the temp root was accepted or refused against the wrong owner',
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
     * A path whose parent this process cannot own is refused by the guarded
     * arm and honoured by the unguarded one — the two halves of the gate, on
     * one input, so neither can be read as an accident of the path.
     */
    public function testTheGateItselfDecidesAndNotThePath(): void
    {
        $root = sys_get_temp_dir();
        if (fileowner($root) === posix_geteuid()) {
            // Running as the temp root's owner: see the stated bound on
            // testADirectoryThisUserDoesNotOwnIsRefused(). Both arms accept,
            // and asserting that is still a real statement about the gate.
            $path = $this->scratchPath('both');
            self::assertTrue(self::append($path, true, "x\n"));
            self::assertTrue(self::append($path, false, "y\n"));

            return;
        }

        $path = $root . '/sc_audit_gate_' . getmypid() . '_' . bin2hex(random_bytes(6)) . '.log';
        $this->scratch[] = $path;

        self::assertFalse(self::append($path, true, "guarded\n"), 'the guarded arm wrote into an unowned directory');
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

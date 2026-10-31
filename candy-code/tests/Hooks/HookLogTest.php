<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Hooks\BuiltIn\AuditHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookLog;
use SugarCraft\Code\Hooks\HookLogEntry;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Memory\SecretRedactor;

/**
 * C-EXT-6.b (GAP-HK-08): the hook log's write, guard and read contract.
 *
 * The pins follow {@see AuditHook}, {@see AuditHookRedactionRotationTest} and
 * {@see AuditHookRefusalNoticeTest} on purpose — same refusal-not-repair
 * policy, same redact-before-cut ORDER law, same one-notice-per-process
 * announcement — because the design chose AuditHook as the pattern precisely
 * so this trail could not drift from the one the audit already owes its user.
 * Where this class DIFFERS is policed by its own pin: the shared-HOME
 * directory arm accepts a mode AuditHook's own-directory rule would refuse
 * (see `HookLog::directoryIsUsable()`'s note on the write-bit vector), and
 * the fail-open law reaches one step further than AuditHook's — a THROWING
 * append is swallowed by `record()`, because a hook log that can break a
 * hook is the exact defect the design names as this step's headline risk.
 *
 * Every write lands on an explicitly-created path (the constructor argument
 * or a saved-and-restored pin); nothing here touches the real HOME default,
 * and one test asserts the suite bootstrap pin that guarantees THAT is still
 * in place for every OTHER suite that drives configured hooks.
 */
final class HookLogTest extends TestCase
{
    /**
     * 40 characters from the bearer pattern's alphabet, comfortably past its
     * `{16,}` — the audit pair's fixture, deterministic and diffed by eye.
     */
    private const LEAK_CANARY_TOKEN = 'Zk9q7Tm2LpVx8Rn4Wc6Yh1Bd3Sg5Fj7Kl0Pi2Qt';

    private string $tempDir = '';

    private ?string $suitePin = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->suitePin = HookLog::defaultLogFilePin();
        $this->resetHookLogLatch();
        // Start every test on a KNOWN sink: unarmed. A shard that runs a
        // notice-arming suite first would otherwise leak its armed process
        // sink into this file and flip the refusal-notice pins by order
        // alone; the tests that assert a notice arm it themselves.
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(crossFork: false);
        self::assertTrue(RuntimeNoticeSink::isArmed(), 'the notice sink refused to arm');

        $this->tempDir = sys_get_temp_dir() . '/candy-code-hooklog-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        HookLog::pinDefaultLogFile($this->suitePin);
        $this->resetHookLogLatch();
        RuntimeNoticeSink::reset();

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($walk as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    private function resetHookLogLatch(): void
    {
        (new \ReflectionProperty(HookLog::class, 'noticed'))->setValue(null, false);
    }

    private function hookLogEntry(
        string $session = 'hooklog-session',
        string $hook = 'probe-hook',
        string $verdict = HookResult::DENY,
        string $message = 'nope',
    ): HookLogEntry {
        return HookLogEntry::forRun(
            'PreToolUse',
            new HookContext(
                sessionId: $session,
                toolName: 'Bash',
                toolArgs: [],
                toolInput: '',
                toolOutput: '',
                model: 'probe-model',
                provider: 'probe',
                projectRoot: '/',
            ),
            $hook,
            new HookResult($verdict, $message),
            0.003,
        );
    }

    // ---- bootstrap pin (the E351 guard) -------------------------------------

    /**
     * Deleting the `tests/bootstrap.php` line does not fail any test HERE —
     * it reappears as a quietly growing `~/.candy-code/hooks-log.jsonl` on
     * the developer's box, fed by every suite that drives configured hooks.
     * This is the named guard the E351 blockquote in bootstrap promises, in
     * the same read-not-reflected shape `AuditHookProductionPathIsolationTest`
     * uses for the audit directory.
     */
    public function testTheSuiteBootstrapPinsTheDefaultHookLogFile(): void
    {
        self::assertNotNull(
            $this->suitePin,
            'tests/bootstrap.php no longer pins the default hook log; configured-hook suites '
            . 'would populate the developer\'s own ~/.candy-code/hooks-log.jsonl with test traffic (E351, replayed)',
        );
        self::assertStringEndsWith(
            '/' . HookLog::DEFAULT_LEAF,
            $this->suitePin,
            'the suite pin must name this class\'s own leaf',
        );
    }

    // ---- round trip ----------------------------------------------------------

    public function testAnEntryRoundTripsThroughTheFileWhole(): void
    {
        $log = $this->tempDir . '/hooks-log.jsonl';
        $entry = $this->hookLogEntry(message: 'first line' . "\n" . 'second line');

        self::assertTrue((new HookLog($log))->record($entry));
        self::assertSame(1, count(file($log, FILE_IGNORE_NEW_LINES) ?: []), 'one record must be one physical line');

        $decoded = HookLogEntry::fromLine((string) file($log, FILE_IGNORE_NEW_LINES)[0]);
        self::assertNotNull($decoded);
        self::assertSame($entry->session, $decoded->session);
        self::assertSame($entry->event, $decoded->event);
        self::assertSame($entry->hook, $decoded->hook);
        self::assertSame(HookResult::DENY, $decoded->verdict);
        self::assertSame($entry->ms, $decoded->ms);
        self::assertSame($entry->message, $decoded->message, 'the file preserves the entry it was handed');

        $tail = HookLog::tail(HookLog::DEFAULT_ROWS, 'hooklog-session', $log);
        self::assertFalse($tail['unreadable']);
        self::assertSame([$entry->toArray()], $tail['rows']);
    }

    /**
     * The first-line pick is the ENTRY's promise (forRun), not the file's:
     * what record() writes is what it is handed, whole, on one physical
     * line (json escapes the newlines — see the hostile-bytes pin below).
     * Splitting the two pins is what the round-trip test used to blur.
     */
    public function testForRunKeepsOnlyTheFirstLineOfTheMessage(): void
    {
        $entry = HookLogEntry::forRun(
            'PreToolUse',
            $this->probeContext('multiline-session'),
            'probe-hook',
            HookResult::deny("first line\nsecond line"),
            0.001,
        );

        self::assertSame('first line', $entry->message);
        self::assertStringNotContainsString("\n", substr($entry->toLine(), 0, -1), 'a logged record is one physical line by construction');
    }

    public function testForRunRedactsTheMessageBeforeAnythingElse(): void
    {
        $entry = HookLogEntry::forRun(
            'PreToolUse',
            $this->probeContext('run-session'),
            'probe-hook',
            HookResult::deny('refused: Authorization: Bearer ' . self::LEAK_CANARY_TOKEN . ' was replayed'),
            0.012,
        );

        $line = $entry->toLine();
        self::assertStringContainsString('Bearer ' . SecretRedactor::MARKER, $line, 'the marker must appear');
        self::assertStringNotContainsString(self::LEAK_CANARY_TOKEN, $line, 'and the token must not');
        self::assertSame(12, $entry->ms, 'ms is the rounded wall clock the ledger charged');
    }

    /**
     * THE ORDER LAW, behaviorally. With 189 pad bytes and a cap of 200 the
     * token straddles the cut: cut-then-redact would keep a 189+11-byte
     * prefix whose token fragment is too short for `{16,}` and print it;
     * redact-then-cut replaces the token first, so no fragment of it can
     * reach the record even when the cap then trims the marker itself. Same
     * fixture family as {@see AuditHookRedactionRotationTest}'s straddle pin,
     * for the same reason: only this shape discriminates the sequence.
     */
    public function testRedactionPrecedesTheByteCut(): void
    {
        $straddle = str_repeat('a', 189) . ' Bearer ' . self::LEAK_CANARY_TOKEN;

        $entry = HookLogEntry::forRun(
            'PreToolUse',
            $this->probeContext('straddle-session'),
            'probe-hook',
            HookResult::deny($straddle),
            0.001,
        );

        self::assertStringNotContainsString(self::LEAK_CANARY_TOKEN, $entry->message);
        self::assertStringNotContainsString(
            substr(self::LEAK_CANARY_TOKEN, 0, 8),
            $entry->message,
            'no token fragment survives when redaction runs before the cut',
        );
        // THE discriminating leg for the order law (C-EXT-6.b review-1): under
        // cut-then-redact the cap stops at byte 200 — `189 pad + " Bearer " +
        // "Zk9"` — and that 3-byte head is below the bearer pattern's {16,},
        // so the redactor never fires and the fragment RENDERS. The head-8 leg
        // above cannot see that swap (only 3 of those 8 bytes survive it);
        // absence of the head-3 can. Redact-then-cut ends `" Bearer " + "[RE"`
        // — not one token character anywhere.
        self::assertStringNotContainsString(
            substr(self::LEAK_CANARY_TOKEN, 0, 3),
            $entry->message,
            'even the 3-byte head the cut-then-redact swap would print is absent',
        );
        // The full marker cannot be asserted here: the 200-byte cap lands
        // mid-marker (`189 pad + " Bearer " + "[RE"`), which is the honest
        // shape of an edge-cut record.
        self::assertStringContainsString('Bearer ', $entry->message);
        self::assertLessThanOrEqual(HookLogEntry::MESSAGE_CAP_BYTES, strlen($entry->message));

        // The other half of the edge: with pad short enough that the WHOLE
        // marker fits inside the cap, redact-then-cut must leave it standing
        // complete — the cut only trims the pad-free tail. (Cut-then-redact
        // on this fixture would see a {16,}-long token and also redact; the
        // straddle arm above is the discriminating one. This arm is the
        // whole-marker shape the reader actually sees most of the time.)
        $short = HookLogEntry::forRun(
            'PreToolUse',
            $this->probeContext('shortpad-session'),
            'probe-hook',
            HookResult::deny(str_repeat('a', 40) . ' Bearer ' . self::LEAK_CANARY_TOKEN),
            0.001,
        );

        self::assertStringContainsString('Bearer ' . SecretRedactor::MARKER, $short->message);
        self::assertStringNotContainsString(self::LEAK_CANARY_TOKEN, $short->message);
    }

    public function testHostileControlBytesCannotBreakTheLine(): void
    {
        $log = $this->tempDir . '/hostile.jsonl';
        $entry = $this->hookLogEntry(message: "esc\x1b[31mred\x00null\nsecond\u{2028}line");

        self::assertTrue((new HookLog($log))->record($entry));
        self::assertSame(
            1,
            count(file($log, FILE_IGNORE_NEW_LINES) ?: []),
            'JSON escapes what AuditHook hand-escapes: one record stays one line',
        );
        self::assertNotNull(HookLogEntry::fromLine((string) file($log, FILE_IGNORE_NEW_LINES)[0]));
    }

    public function testFromLineRefusesJunkShapes(): void
    {
        self::assertNull(HookLogEntry::fromLine('not json at all'));
        self::assertNull(HookLogEntry::fromLine('{"ts":"now"}'), 'a partial object is not an entry');
        self::assertNull(HookLogEntry::fromLine(json_encode([
            'ts' => 'now', 'session' => 's', 'event' => 'e', 'hook' => 'h',
            'verdict' => 'deny', 'ms' => 'wide', 'message' => 'm',
        ], JSON_THROW_ON_ERROR)), 'ms must be an int, not a numeric string');
    }

    // ---- registry wiring ------------------------------------------------------

    public function testTheRegistryLogsOneEntryPerConfiguredRun(): void
    {
        $log = $this->tempDir . '/wired.jsonl';
        HookLog::pinDefaultLogFile($log);

        $registry = new HookRegistry();
        $registry->register($this->verdictHook('wired-probe-' . getmypid(), HookResult::deny('blocked by probe')));

        // The sink starts unarmed per setUp; arm it so any refusal notice the
        // write would emit lands in the drain the fail-open pin below reads.
        RuntimeNoticeSink::arm(crossFork: false);
        $result = $registry->executeHooks('PreToolUse', $this->probeContext('wired-session'));

        self::assertSame(HookResult::DENY, $result->action, 'the log ride must not change the verdict');
        $rows = HookLog::tail(HookLog::MAX_ROWS, 'wired-session', $log)['rows'];
        self::assertCount(1, $rows);
        self::assertSame('wired-probe-' . getmypid(), $rows[0]['hook']);
        self::assertSame(HookResult::DENY, $rows[0]['verdict'], 'a DENIED run is logged — the run happened');
        self::assertSame('blocked by probe', $rows[0]['message']);
    }

    public function testBuiltInHooksNeverLandInTheHookLog(): void
    {
        $log = $this->tempDir . '/builtin.jsonl';
        $auditTrail = $this->tempDir . '/audit-trail.log';
        HookLog::pinDefaultLogFile($log);

        $registry = new HookRegistry();
        // A shipped built-in (BuiltIn namespace arm) and a probe carrying a
        // RESERVED name (the other half of isBuiltInHook) must BOTH be out.
        $registry->register(new AuditHook($auditTrail));

        // AuditHook subscribes PostToolUse (it logs finished calls) — running
        // it on PreToolUse would exercise neither its own trail nor the
        // exclusion.
        $registry->executeHooks(HookEvent::PostToolUse->value, $this->probeContext('builtin-session'));

        self::assertFileExists($auditTrail, 'the built-in wrote its own audit trail');
        self::assertSame(
            [],
            HookLog::tail(HookLog::MAX_ROWS, 'builtin-session', $log)['rows'],
            'the audit log is the built-in\'s trail; the hook log answers for CONFIGURED hooks only',
        );

        $report = $registry->all();
        $found = false;
        foreach ($report as $hooks) {
            foreach ($hooks as $entry) {
                if ($entry['name'] === 'audit') {
                    $found = true;
                    self::assertTrue($entry['builtIn'], 'the panel predicate and the log exclusion must agree');
                }
            }
        }
        self::assertTrue($found, 'the fixture must actually reach all() — otherwise the agreement pin is vacuous');
    }

    public function testAFailingWriteStillDeliversTheHookVerdict(): void
    {
        // The parent of the pinned leaf is a FILE: every guard arm plus the
        // catch behind it says "refused", and the CHAIN must not notice.
        $blocker = $this->tempDir . '/blocker';
        file_put_contents($blocker, 'not a directory');
        $log = $this->suitePin;
        HookLog::pinDefaultLogFile($blocker . '/hooks-log.jsonl');

        try {
            $registry = new HookRegistry();
            $registry->register($this->verdictHook('failopen-probe-' . getmypid(), HookResult::allow('')));

            RuntimeNoticeSink::arm(crossFork: false);
            $result = $registry->executeHooks('PreToolUse', $this->probeContext('failopen-session'));

            self::assertSame(HookResult::ALLOW, $result->action, 'a broken log never fails a hook');
            self::assertFileDoesNotExist($blocker . '/hooks-log.jsonl');
            $notices = RuntimeNoticeSink::drain();
            self::assertNotEmpty($notices, 'the refusal announces itself once, through the notices channel');
        } finally {
            HookLog::pinDefaultLogFile($log);
        }
    }

    // ---- refusals: guard arms --------------------------------------------------

    public function testADirectoryThatIsAFileIsRefusedNotRepaired(): void
    {
        $blocker = $this->tempDir . '/squatter';
        file_put_contents($blocker, '');
        $log = $this->suitePin;
        HookLog::pinDefaultLogFile($blocker . '/nested/hooks-log.jsonl');

        try {
            RuntimeNoticeSink::arm(crossFork: false);
            self::assertFalse((new HookLog())->record($this->hookLogEntry()));
            self::assertCount(1, RuntimeNoticeSink::drain(), 'the directory refusal rides the notices channel once');
            self::assertFileDoesNotExist($blocker . '/nested/hooks-log.jsonl');
        } finally {
            HookLog::pinDefaultLogFile($log);
        }
    }

    public function testASymlinkedLeafIsRefusedThroughTheDefaultPath(): void
    {
        $outside = $this->tempDir . '/outside-target';
        $log = $this->suitePin;
        HookLog::pinDefaultLogFile($this->tempDir . '/' . HookLog::DEFAULT_LEAF);

        try {
            self::assertTrue(symlink($outside, $this->tempDir . '/' . HookLog::DEFAULT_LEAF));
            RuntimeNoticeSink::arm(crossFork: false);
            self::assertFalse((new HookLog())->record($this->hookLogEntry()));
            self::assertFileDoesNotExist($outside, 'the write never followed the link');
            self::assertNotEmpty(RuntimeNoticeSink::drain());
        } finally {
            HookLog::pinDefaultLogFile($log);
        }
    }

    public function testAForeignWritableSharedDirectoryIsRefused(): void
    {
        $squatted = $this->tempDir . '/open-dir';
        mkdir($squatted, 0o777, true);
        @chmod($squatted, 0o777); // defeat the process umask so the bits really are there
        $log = $this->suitePin;
        HookLog::pinDefaultLogFile($squatted . '/' . HookLog::DEFAULT_LEAF);

        try {
            self::assertFalse(
                (new HookLog())->record($this->hookLogEntry()),
                'a parent anybody can write into is the plant vector this guard exists for',
            );
        } finally {
            HookLog::pinDefaultLogFile($log);
            @chmod($squatted, 0o700);
        }
    }

    public function testASharedDirectoryTheUserKeepsTightIsAccepted(): void
    {
        // The HOME arm's rule is the WRITE bits: 0755 is the shape of the
        // real `~/.candy-code` (owned, world-readable, nobody-else-writable)
        // and refusing it would switch the log off on every ordinary install.
        $shared = $this->tempDir . '/candy-code-shape';
        mkdir($shared, 0o700, true);
        @chmod($shared, 0o755);
        $log = $this->suitePin;
        HookLog::pinDefaultLogFile($shared . '/' . HookLog::DEFAULT_LEAF);

        try {
            self::assertTrue((new HookLog())->record($this->hookLogEntry()));
        } finally {
            HookLog::pinDefaultLogFile($log);
        }
    }

    // ---- the leaf and the budget -----------------------------------------------

    public function testTheCreatedLeafAndDirectoryArePrivate(): void
    {
        $log = $this->suitePin;
        $target = $this->tempDir . '/deep/nested/' . HookLog::DEFAULT_LEAF;
        HookLog::pinDefaultLogFile($target);

        try {
            self::assertTrue((new HookLog())->record($this->hookLogEntry()));

            $leafPerms = @fileperms($target);
            self::assertIsInt($leafPerms);
            self::assertSame(0, $leafPerms & 0o077, 'the leaf is 0600 (umask held across the open)');

            $dirPerms = @fileperms(\dirname($target));
            self::assertIsInt($dirPerms);
            self::assertSame(0, $dirPerms & 0o077, 'a directory this class creates is 0700');
        } finally {
            HookLog::pinDefaultLogFile($log);
        }
    }

    public function testTheOwnedDefaultRotatesOneGeneration(): void
    {
        $log = $this->suitePin;
        $target = $this->tempDir . '/' . HookLog::DEFAULT_LEAF;
        HookLog::pinDefaultLogFile($target);

        try {
            // Past the ceiling by one byte: the NEXT record moves the elder
            // aside first (AuditHook's rotate-then-append order).
            file_put_contents($target, str_repeat('x', HookLog::ROTATE_AT_BYTES + 1));

            self::assertTrue((new HookLog())->record($this->hookLogEntry()));

            self::assertFileExists($target . HookLog::ROTATED_SUFFIX);
            self::assertSame(
                HookLog::ROTATE_AT_BYTES + 1,
                \strlen((string) file_get_contents($target . HookLog::ROTATED_SUFFIX)),
                'the rotated generation is the whole elder file, not a truncation',
            );
            self::assertLessThan(4096, \strlen((string) file_get_contents($target)));
        } finally {
            HookLog::pinDefaultLogFile($log);
        }
    }

    public function testACallerSuppliedPathNeverRotates(): void
    {
        $target = $this->tempDir . '/caller-owned.jsonl';
        file_put_contents($target, str_repeat('x', HookLog::ROTATE_AT_BYTES + 1));

        self::assertTrue((new HookLog($target))->record($this->hookLogEntry()));
        self::assertFileDoesNotExist($target . HookLog::ROTATED_SUFFIX, 'the caller owns that file\'s budget');
    }

    // ---- the read side ----------------------------------------------------------

    public function testAnAbsentFileIsTheEmptyTrailNotAnError(): void
    {
        $seen = HookLog::tail(HookLog::DEFAULT_ROWS, null, $this->tempDir . '/never-written.jsonl');

        self::assertSame([], $seen['rows']);
        self::assertFalse($seen['unreadable'], 'no hooks logged yet is the honest empty answer');
    }

    public function testTheTailDropsATornTrailingFragment(): void
    {
        $log = $this->tempDir . '/torn.jsonl';
        $one = $this->hookLogEntry(message: 'one')->toLine();
        $two = $this->hookLogEntry(message: 'two')->toLine();
        file_put_contents($log, $one . $two . '{"ts":"half-a-record');

        $seen = HookLog::tail(HookLog::MAX_ROWS, null, $log);

        self::assertCount(2, $seen['rows'], 'the reader never invents a row from a partial tail write');
        self::assertSame(['one', 'two'], array_column($seen['rows'], 'message'));
    }

    public function testTheRotatedGenerationPrependsAndOnlyFillsWhatIsMissing(): void
    {
        $log = $this->tempDir . '/' . HookLog::DEFAULT_LEAF;
        $elder = $this->tempDir . '/' . HookLog::DEFAULT_LEAF . HookLog::ROTATED_SUFFIX;
        file_put_contents($elder, $this->hookLogEntry(message: 'o1')->toLine() . $this->hookLogEntry(message: 'o2')->toLine());
        file_put_contents($log, $this->hookLogEntry(message: 'new')->toLine());

        $full = HookLog::tail(3, null, $log);
        self::assertSame(['o1', 'o2', 'new'], array_column($full['rows'], 'message'), 'oldest first across the rotation');

        $capped = HookLog::tail(2, null, $log);
        self::assertSame(['o2', 'new'], array_column($capped['rows'], 'message'), 'the newest rows win the budget');
    }

    public function testTheSessionFilterKeepsOnlyItsOwnRows(): void
    {
        $log = $this->tempDir . '/sessions.jsonl';
        file_put_contents($log, implode('', [
            $this->hookLogEntry(session: 'S1', message: 'mine one')->toLine(),
            $this->hookLogEntry(session: 'S2', message: 'yours')->toLine(),
            $this->hookLogEntry(session: 'S1', message: 'mine two')->toLine(),
        ]));

        $seen = HookLog::tail(HookLog::MAX_ROWS, 'S1', $log);
        self::assertSame(['mine one', 'mine two'], array_column($seen['rows'], 'message'));
    }

    public function testAPathThatExistsButCannotBeReadIsFlagged(): void
    {
        $notAFile = $this->tempDir . '/leaf-is-a-directory';
        mkdir($notAFile, 0o700, true);

        $seen = HookLog::tail(HookLog::DEFAULT_ROWS, null, $notAFile);

        self::assertTrue($seen['unreadable'], 'an unreadable leaf is never rendered as an empty trail');
        self::assertSame([], $seen['rows']);
    }

    // ---- default-path naming -----------------------------------------------------

    public function testTheUnpinnedNoHomeDefaultNamesThePerUidFamily(): void
    {
        $log = $this->suitePin;
        $home = getenv('HOME');
        // An UNVERIFIABLE HOME — not an unset one. HomeDirectory::resolved()
        // falls back to the passwd entry when HOME is absent, so
        // `putenv('HOME')` changes nothing on a real box; a directory that
        // cannot be realpath'ed is what actually answers owned() === null.
        putenv('HOME=' . $this->tempDir . '/no-such-home');

        try {
            HookLog::pinDefaultLogFile(null);
            $path = HookLog::defaultLogFile();
            self::assertStringStartsWith(
                sys_get_temp_dir() . '/candy-code-hooks-',
                $path,
                'with neither pin nor verifiable HOME, the fallback family is per-uid, AuditHook-style',
            );
            self::assertStringEndsWith('/' . HookLog::DEFAULT_LEAF, $path);
        } finally {
            HookLog::pinDefaultLogFile($log);
            if ($home !== false) {
                putenv('HOME=' . $home);
            }
        }
    }

    // ---- fixtures ------------------------------------------------------------------

    private function probeContext(string $sessionId): HookContext
    {
        return new HookContext(
            sessionId: $sessionId,
            toolName: 'Bash',
            toolArgs: [],
            toolInput: '',
            toolOutput: '',
            model: 'probe-model',
            provider: 'probe',
            projectRoot: '/',
        );
    }

    /**
     * A configured (non-built-in, non-reserved) hook that always answers the
     * same verdict — the writer's production shape without a subprocess.
     *
     * @return HookInterface&HookInterface
     */
    private function verdictHook(string $name, HookResult $verdict): HookInterface
    {
        return new class ($name, $verdict) implements HookInterface {
            public function __construct(private readonly string $hookName, private readonly HookResult $verdict)
            {
            }

            public function name(): string
            {
                return $this->hookName;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                // PCRE fragment semantics (HookConfig::pattern wraps it):
                // the shipped all-tools matcher is `.*` — a bare `*` does not
                // compile and matches nothing.
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return $this->verdict;
            }
        };
    }
}

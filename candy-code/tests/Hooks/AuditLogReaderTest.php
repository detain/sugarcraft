<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\BuiltIn\AuditHook;
use SugarCraft\Code\Hooks\BuiltIn\AuditLogReader;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Permissions\DenialKind;

/**
 * @see AuditLogReader
 *
 * The read half of the audit trail, driven against lines the WRITE half
 * actually produced ({@see AuditHook::execute()}, recordDenial(),
 * recordWithheld()) rather than hand-typed fixtures — a parser tested only
 * against a parser author's idea of the format proves nothing about the log
 * on disk.
 */
final class AuditLogReaderTest extends TestCase
{
    private string $dir;

    private string $log;

    /** The suite-wide pin {@see AuditHook::pinDefaultLogDirectory()} had before this test. */
    private ?string $priorPin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/audit-reader-test-' . uniqid((string) getmypid(), true);
        self::assertTrue(mkdir($this->dir, 0o700, true));
        $this->log = $this->dir . '/audit.log';
        $this->priorPin = AuditHook::defaultLogDirectoryPin();
    }

    protected function tearDown(): void
    {
        AuditHook::pinDefaultLogDirectory($this->priorPin);
        // children first: the default-path test nests a directory under $dir.
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($walk as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testAnAllowLineReadsBackAsAllowWithNoKindOrReason(): void
    {
        (new AuditHook($this->log))->execute($this->context(sessionId: 's-1', toolName: 'Read', toolInput: '{"file_path":".env"}', toolOutput: 'SECRET=abc'));

        $rows = AuditLogReader::tail(logFile: $this->log)['rows'];

        self::assertCount(1, $rows);
        self::assertSame(
            ['time', 'session', 'tool', 'verdict', 'kind', 'reason'],
            array_keys($rows[0]),
        );
        self::assertSame('s-1', $rows[0]['session']);
        self::assertSame('Read', $rows[0]['tool']);
        self::assertSame('allow', $rows[0]['verdict']);
        self::assertNull($rows[0]['kind']);
        self::assertNull($rows[0]['reason']);
        // The excerpt itself is NOT a row field — the log redacts and cuts it
        // for the file's own consumers; this view names the decision.
        self::assertStringMatchesFormat('%d-%d-%d %d:%d:%d', $rows[0]['time']);
    }

    /**
     * Every denial kind lands as its token, so the three
     * {@see DenialKind} cases are walked, not trusted to one fixture.
     */
    public function testEveryDenialLineReadsBackWithItsKindAndReason(): void
    {
        $hook = new AuditHook($this->log);
        $hook->recordDenial($this->context(sessionId: 's-1'), DenialKind::Refused, 'writing to a protected path');
        $hook->recordDenial($this->context(sessionId: 's-1'), DenialKind::Unanswered, 'no answer inside the prompt window');
        $hook->recordDenial($this->context(sessionId: 's-1'), DenialKind::Hook, 'the pre-tool hook refused');

        $rows = AuditLogReader::tail(logFile: $this->log)['rows'];

        self::assertSame(['refused', 'unanswered', 'hook'], array_column($rows, 'kind'));
        self::assertSame(['deny', 'deny', 'deny'], array_column($rows, 'verdict'));
        self::assertSame('writing to a protected path', $rows[0]['reason']);
    }

    public function testAWithheldLineCarriesTheRefusingHookAsItsKindAndThePlainShapeCarriesNone(): void
    {
        $hook = new AuditHook($this->log);
        $hook->recordWithheld($this->context(sessionId: 's-1'), 'the chain stopped at the first refusal', 'RedactorHook');
        $hook->recordWithheld($this->context(sessionId: 's-1'), 'no single hook owned it');

        $rows = AuditLogReader::tail(logFile: $this->log)['rows'];

        self::assertCount(2, $rows);
        self::assertSame('withheld', $rows[0]['verdict']);
        self::assertSame('RedactorHook', $rows[0]['kind']);
        self::assertSame('the chain stopped at the first refusal', $rows[0]['reason']);
        self::assertNull($rows[1]['kind']);
        self::assertSame('no single hook owned it', $rows[1]['reason']);
    }

    public function testAnEmptySessionFieldSurvivesTheDoubleSpaceAndReadsBackEmpty(): void
    {
        // AuditHook writes an empty sessionId verbatim, which puts two spaces
        // where the reader's regex must still find a (empty) session token.
        (new AuditHook($this->log))->execute($this->context(sessionId: ''));

        $rows = AuditLogReader::tail(logFile: $this->log)['rows'];

        self::assertCount(1, $rows);
        self::assertSame('', $rows[0]['session']);
    }

    public function testATornLastLineIsDroppedAndEveryWholeLineAboveItSurvives(): void
    {
        $hook = new AuditHook($this->log);
        $hook->execute($this->context(sessionId: 's-1', toolName: 'Read'));
        // A crash between the write and its newline leaves half a record —
        // torn AFTER the marker, so the fragment is a shape the line parser
        // would otherwise accept: dropping the last unterminated line is
        // what keeps a torn `=! DENY refused: reas` out of the answer.
        file_put_contents($this->log, "[2026-10-28 09:00:00] s-9 Write {\"a\":\"b\"} =! DENY refused: reas", FILE_APPEND | LOCK_EX);

        $rows = AuditLogReader::tail(logFile: $this->log)['rows'];

        self::assertCount(1, $rows);
        self::assertSame('Read', $rows[0]['tool']);
        self::assertNotContains('s-9', \array_column($rows, 'session'), 'the torn half is never a verdict');
    }

    public function testALineWrittenAfterAnEarlierReadStillShowsInTheNextOne(): void
    {
        // The stat-cache regression: PHP caches filesize() per path, the
        // audit trail is appended by the same long-lived server process that
        // reads it back, and a tail trusting the cache would stay frozen one
        // (or a hundred) writes behind — silently, for the life of the server.
        $hook = new AuditHook($this->log);
        $hook->execute($this->context(sessionId: 's-1', toolName: 'Read'));

        self::assertCount(1, AuditLogReader::tail(logFile: $this->log)['rows'], 'the first read warms the size cache');

        $hook->execute($this->context(sessionId: 's-2', toolName: 'Write'));
        $rows = AuditLogReader::tail(logFile: $this->log)['rows'];

        self::assertSame(['s-1', 's-2'], \array_column($rows, 'session'), 'the read after the append sees the append');
    }

    public function testAWindowCutMidLineStillReturnsWholeLinesOnly(): void
    {
        // One record longer than any single window, so the backward walk must
        // grow past its first window to find a whole line at all; the filler
        // IS a complete record (it ends in its newline) and must read back,
        // above the two that fit in the first window. A reader that started
        // parsing where its first window happened to cut would answer with a
        // fragment or lose the filler row.
        $filler = '[2026-10-28 09:00:00] s-0 Read ' . str_repeat('x', 70_000) . " => done\n";
        $hook = new AuditHook($this->log);
        file_put_contents($this->log, $filler, FILE_APPEND | LOCK_EX);
        $hook->execute($this->context(sessionId: 's-1', toolName: 'Grep'));
        $hook->execute($this->context(sessionId: 's-2', toolName: 'Glob'));

        $rows = AuditLogReader::tail(logFile: $this->log)['rows'];

        self::assertSame(['s-0', 's-1', 's-2'], array_column($rows, 'session'));
    }

    public function testAfterARotationTheRolledGenerationFillsTheFreshOneOldestFirst(): void
    {
        $old = new AuditHook($this->dir . '/rolled.log');
        $old->recordDenial($this->context(sessionId: 's-old'), DenialKind::Refused, 'the pre-rotation refusal');
        // AuditHook::rotateIfNeeded() moves `audit.log` to `audit.log.1`; the
        // same pair, planted, is what the reader must stitch.
        self::assertTrue(rename($this->dir . '/rolled.log', $this->dir . '/rolled.log' . AuditLogReader::ROTATED_SUFFIX));

        $fresh = new AuditHook($this->dir . '/rolled.log');
        $fresh->execute($this->context(sessionId: 's-new', toolName: 'Write'));

        $rows = AuditLogReader::tail(logFile: $this->dir . '/rolled.log')['rows'];

        self::assertSame(['s-old', 's-new'], array_column($rows, 'session'));
        self::assertSame(['deny', 'allow'], array_column($rows, 'verdict'));
    }

    public function testTheRotationGenerationCannotPushTheAnswerPastTheCap(): void
    {
        // A full rotated generation and a fresh file both ask the same limit:
        // the stitch may never answer more than the cap, however the two
        // files happen to split the newest rows between them.
        $rolled = $this->dir . '/overshoot.log';
        $old = new AuditHook($rolled);
        for ($i = 1; $i <= 300; $i++) {
            $old->execute($this->context(sessionId: 'old-' . $i, toolName: 'Read'));
        }
        self::assertTrue(rename($rolled, $rolled . AuditLogReader::ROTATED_SUFFIX));

        $fresh = new AuditHook($rolled);
        for ($i = 1; $i <= 10; $i++) {
            $fresh->execute($this->context(sessionId: 'new-' . $i, toolName: 'Read'));
        }

        $rows = AuditLogReader::tail(limit: AuditLogReader::MAX_ROWS, logFile: $rolled)['rows'];

        $expected = [];
        for ($i = 111; $i <= 300; $i++) {
            $expected[] = 'old-' . $i;
        }
        for ($i = 1; $i <= 10; $i++) {
            $expected[] = 'new-' . $i;
        }

        self::assertCount(AuditLogReader::MAX_ROWS, $rows);
        self::assertSame($expected, array_column($rows, 'session'), 'the ten fresh rows and the newest 190 rolled ones — nothing older');
    }

    public function testTheCapKeepsTheNewestRowsAndNothingOlder(): void
    {
        $hook = new AuditHook($this->log);
        for ($i = 1; $i <= 300; $i++) {
            $hook->execute($this->context(sessionId: 's-' . $i, toolName: 'Read'));
        }

        $capped = AuditLogReader::tail(limit: AuditLogReader::MAX_ROWS, logFile: $this->log)['rows'];

        self::assertCount(AuditLogReader::MAX_ROWS, $capped);
        self::assertSame('s-300', $capped[AuditLogReader::MAX_ROWS - 1]['session']);
        self::assertSame('s-101', $capped[0]['session']);

        // A request past the ceiling gets the ceiling, never the file.
        $over = AuditLogReader::tail(limit: 5_000, logFile: $this->log)['rows'];
        self::assertCount(AuditLogReader::MAX_ROWS, $over);

        // And the no-limit default is a screenful, not the cap.
        $default = AuditLogReader::tail(logFile: $this->log)['rows'];
        self::assertCount(AuditLogReader::DEFAULT_ROWS, $default);
        self::assertSame('s-300', $default[array_key_last($default)]['session']);
    }

    public function testAMissingLogIsAnEmptyTrailAndNotAFailure(): void
    {
        $result = AuditLogReader::tail(logFile: $this->dir . '/never-written.log');

        self::assertSame([], $result['rows']);
        self::assertFalse($result['unreadable']);
    }

    public function testAPathThatExistsButCannotBeReadReportsUnreadable(): void
    {
        // A directory wearing the log's leaf name: present, not a file,
        // refused — the honest "there is a trail and this process could not
        // open it" answer the empty-list default must not lie about.
        self::assertTrue(mkdir($this->dir . '/audit.log'));

        $result = AuditLogReader::tail(logFile: $this->log);

        self::assertSame([], $result['rows']);
        self::assertTrue($result['unreadable']);
        @rmdir($this->dir . '/audit.log');
    }

    public function testTheSessionFilterKeepsOnlyTheNamedTrail(): void
    {
        $hook = new AuditHook($this->log);
        $hook->execute($this->context(sessionId: 's-1'));
        $hook->execute($this->context(sessionId: 's-2'));
        $hook->execute($this->context(sessionId: 's-1', toolName: 'Write'));

        $rows = AuditLogReader::tail(session: 's-1', logFile: $this->log)['rows'];

        self::assertSame(['s-1', 's-1'], array_column($rows, 'session'));
        self::assertSame(['Bash', 'Write'], array_column($rows, 'tool'));
    }

    public function testJunkLinesAreSkippedAroundRealRecords(): void
    {
        $hook = new AuditHook($this->log);
        $hook->execute($this->context(sessionId: 's-1'));
        file_put_contents($this->log, "not a record at all\n[no-timestamp] s-1 Read x => y\n", FILE_APPEND | LOCK_EX);
        $hook->execute($this->context(sessionId: 's-2'));

        $rows = AuditLogReader::tail(logFile: $this->log)['rows'];

        self::assertSame(['s-1', 's-2'], array_column($rows, 'session'));
    }

    public function testTheDefaultPathFollowsTheAuditHookDefaultLogFileSeam(): void
    {
        // No explicit path anywhere in this test: a default AuditHook writes
        // where defaultLogFile() points, and a default tail() must find it —
        // the wiring between the two statics, pinned.
        AuditHook::pinDefaultLogDirectory($this->dir . '/default-home');
        self::assertTrue(mkdir($this->dir . '/default-home', 0o700, true));

        (new AuditHook())->execute($this->context(sessionId: 's-pin', toolName: 'Read'));

        $rows = AuditLogReader::tail()['rows'];

        self::assertSame(['s-pin'], array_column($rows, 'session'));
    }

    private function context(string $sessionId = 'test-session', string $toolName = 'Bash', string $toolInput = 'echo test', string $toolOutput = 'ok'): HookContext
    {
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

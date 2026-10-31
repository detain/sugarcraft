<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\HookLog;
use SugarCraft\Code\Hooks\HookLogEntry;
use SugarCraft\Code\Support\ForkedChild;
use SugarCraft\Code\Tests\Support\ReapsForkedChildrenTrait;
use SugarCraft\Code\Tests\Support\RefusesAnUnreadableSourceTrait;

/**
 * THE CLAIM HOOK LOG APPEND CARRIES: concurrent writers lose nothing and split
 * nothing, on the same evidence footing as {@see AuditHookConcurrentAppendTest}
 * rather than as a paragraph asserting "LOCK_EX makes it safe".
 *
 * WHY THIS FILE EXISTS BESIDES THE AUDIT-HOOK ONE. HookLog is the SECOND
 * append site in `src/Hooks/` and the only one whose readers parse the file as
 * JSON: a torn line there is not a cosmetic artifact, it is a row
 * {@see HookLog::tail()} drops by design (the walk-back keeps only complete
 * lines). So the flag pair on HookLog's single `file_put_contents()` is
 * load-bearing in a way the audit trail's text shape never was, and the same
 * one-token mutation — dropping `LOCK_EX`, or hoisting the flags into a const
 * the scan cannot see — applies here.
 *
 * WHY THE FLAGS ARE PINNED TEXTUALLY AND THE RACE IS PINNED BEHAVIOURALLY,
 * BOTH, in one file. AuditHookConcurrentAppendTest measured that deleting
 * `LOCK_EX` SURVIVES the race on local ext4 (O_APPEND's atomic seek is a
 * filesystem property this box happens to honour); the behavioural half buys
 * the loss guarantee `FILE_APPEND` carries, and only the textual half can
 * object when a future edit drops the flag that defends the NFS case. The
 * scan is scoped to the call's OWN argument list — a `private const
 * WRITE_FLAGS` hoist is the realistic regression, and it passed the old
 * file-scoped version of this guard at round 49.
 *
 * The children leave through {@see ForkedChild::exitNow()}, per the
 * convention {@see \SugarCraft\Code\Tests\Support\ForkedChildExitConventionTest}
 * enforces, and `Hooks/` is under the reaper adoption in
 * {@see \SugarCraft\Code\Tests\Support\ForkedChildReaperAdoptionTest::SCOPE}:
 * this file forks through the trait and reaps first in `tearDown()`.
 *
 * @see HookLog
 */
final class HookLogConcurrentAppendTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    use RefusesAnUnreadableSourceTrait;

    /** Writers, records each — same scale class as the audit-hook race. */
    private const RACE_WRITERS = 4;
    private const RACE_RECORDS = 25;

    /**
     * Message bytes per record: past `PIPE_BUF` (4096 on Linux) and past one
     * page, so a whole record is bigger than one atomic write and the
     * "a partial write can split a record" claim is about something. Direct
     * constructor entries (not `forRun()`), because `forRun()` caps the
     * message at 200 bytes and the cap is pinned in HookLogTest — here the
     * size IS the instrument.
     */
    private const PAYLOAD_BYTES = 4200;

    /**
     * The two flag names, assembled from halves rather than spelled — a
     * sweep over either constant must not be able to rewrite the fixtures
     * that document it (the round-48 uniqid pass ate its own guard's
     * known-positive otherwise).
     */
    private const APPEND_FLAG_NAME = 'FILE' . '_APPEND';
    private const LOCK_FLAG_NAME = 'LOCK' . '_EX';

    private string $log = '';

    protected function tearDown(): void
    {
        // BEFORE the unlink, not after: an orphaned writer must not keep
        // appending into a name that is about to be reused. Same law as
        // AuditHookConcurrentAppendTest records in its doc-block.
        $this->reapTrackedForkedChildren();

        if ($this->log !== '' && is_file($this->log)) {
            @unlink($this->log);
        }
        $this->log = '';
    }

    /**
     * A fresh, process-unique log path that does not exist yet. Composed
     * from random bytes rather than `tempnam()` — the writers below open the
     * file themselves with `FILE_APPEND | LOCK_EX` (which creates it on the
     * first write), and the race is about appends from the very first byte,
     * so nothing here pre-creates the inode. Several suites share this
     * machine's temp directory (E242); the name carries the lib's own prefix
     * and 12 hex digits, and the assertion fails loudly if we ever collide.
     */
    private function reserveRacePath(): string
    {
        $path = sys_get_temp_dir() . '/candy-code-hooklog-race-' . bin2hex(random_bytes(6)) . '.jsonl';
        self::assertFalse(is_file($path), 'the reserved race path must not pre-exist: ' . $path);
        $this->log = $path;

        return $path;
    }

    /** One record's JSONL line for a given writer letter. */
    private static function lineFor(string $letter): string
    {
        return (new HookLogEntry(
            date('Y-m-d H:i:s'),
            'sess' . $letter,
            'PreToolUse',
            'probe',
            'ALLOW',
            3,
            str_repeat($letter, self::PAYLOAD_BYTES),
        ))->toLine();
    }

    /**
     * Classify every line of $path as whole / split / interleaved, on the
     * JSONL shape: a whole line decodes, carries exactly the seven wire keys,
     * and its message is homogeneous in one letter.
     *
     * @return array{lines:int,whole:int,split:int,interleaved:int}
     */
    private static function analyse(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $whole = $split = $interleaved = 0;

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (
                !\is_array($decoded)
                || \count($decoded) !== 7
                || !isset($decoded['ts'], $decoded['session'], $decoded['event'], $decoded['hook'], $decoded['verdict'], $decoded['ms'], $decoded['message'])
            ) {
                $split++;
                continue;
            }

            $message = $decoded['message'];
            if (!\is_string($message) || strlen($message) !== self::PAYLOAD_BYTES) {
                $split++;
                continue;
            }

            $letter = $decoded['session'] === 'sess' . $message[0] ? $message[0] : '';
            if ($letter === '' || $message !== str_repeat($letter, self::PAYLOAD_BYTES)) {
                $interleaved++;
                continue;
            }
            $whole++;
        }

        return [
            'lines' => count($lines),
            'whole' => $whole,
            'split' => $split,
            'interleaved' => $interleaved,
        ];
    }

    /**
     * THE CLAIM. Concurrent appends through the real writer — a caller-supplied
     * path, so `ownsPath` is false and the append is the bare
     * `FILE_APPEND | LOCK_EX` write, the same shape the production default
     * path funnels through after its guards — lose nothing and split nothing.
     */
    public function testConcurrentAppendsThroughTheRealWriterAreNeitherLostNorSplit(): void
    {
        self::assertTrue(
            function_exists('pcntl_fork') && function_exists('pcntl_waitpid'),
            'this host has no pcntl, so the claim this file exists to pin cannot be measured here',
        );

        $log = $this->reserveRacePath();
        $expected = self::RACE_WRITERS * self::RACE_RECORDS;

        $pids = [];
        for ($w = 0; $w < self::RACE_WRITERS; $w++) {
            $pid = $this->forkTracked();
            self::assertNotSame(-1, $pid, 'fork failed');
            if ($pid === 0) {
                $letter = chr(ord('A') + $w);
                $writer = new HookLog($log);
                for ($r = 0; $r < self::RACE_RECORDS; $r++) {
                    // record(), not append(): the fail-open arm is part of
                    // the shape under test — a writer that dies mid-race
                    // would itself be the lost-record bug this measures.
                    $writer->record(new HookLogEntry(
                        date('Y-m-d H:i:s'),
                        'sess' . $letter,
                        'PreToolUse',
                        'probe',
                        'ALLOW',
                        3,
                        str_repeat($letter, self::PAYLOAD_BYTES),
                    ));
                }
                ForkedChild::exitNow(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $seen = self::analyse($log);

        self::assertSame($expected, $seen['lines'], 'records were LOST — FILE_APPEND is not holding');
        self::assertSame(0, $seen['split'], 'a record was TORN — LOCK_EX is not holding');
        self::assertSame(0, $seen['interleaved'], 'two writers landed in one record');
        self::assertSame($expected, $seen['whole']);
    }

    /**
     * `LOCK_EX` SURVIVES ITS OWN BEHAVIOURAL MUTATION ON LOCAL EXT4 (measured
     * for the audit-hook write by AuditHookConcurrentAppendTest, and the
     * mechanism is the filesystem's rather than the call site's), so it is
     * pinned textually here as well: every direct `file_put_contents()` in
     * HookLog carries BOTH flags in its OWN argument list, and a reference
     * the token walk cannot read as a call reddens the coverage arm rather
     * than being silently skipped. Zero calls found is a failure, not a pass
     * — that is the answer a deleted scanner also gives (rule 14).
     */
    public function testEveryWriteInTheClassCarriesBothFlagsInItsOwnArgumentList(): void
    {
        $source = self::readOrFail(\dirname(__DIR__, 2) . '/src/Hooks/HookLog.php');
        $seen = self::scanWriteCalls($source);

        self::assertNotSame(
            [],
            $seen['calls'],
            'the scanner found no file_put_contents() call in HookLog at all, which is what a broken '
            . 'scanner also reports — the flags below would then be asserted of nothing',
        );
        self::assertSame(
            \count($seen['calls']),
            $seen['references'],
            'HookLog names file_put_contents somewhere the token walk could not read as a direct call '
            . '(a variable function, a method, or a string literal), so this scan is not covering every write',
        );

        foreach ($seen['calls'] as $index => $arguments) {
            self::assertStringContainsString(self::APPEND_FLAG_NAME, $arguments, 'write ' . $index);
            self::assertStringContainsString(self::LOCK_FLAG_NAME, $arguments, 'write ' . $index);
        }
    }

    /**
     * POSITIVE CONTROL — the analyser reports LOSS. Constructed, not raced:
     * one whole record where the race above produced every writer's. Without
     * this, the line-count arm above is equally satisfied by an analyser that
     * answers with the number it was asked for.
     */
    public function testTheHookLogAnalyserReportsLoss(): void
    {
        $log = $this->reserveRacePath();
        file_put_contents($log, self::lineFor('A'));

        $seen = self::analyse($log);

        self::assertSame(1, $seen['lines']);
        self::assertNotSame(self::RACE_WRITERS * self::RACE_RECORDS, $seen['lines']);
        self::assertSame(1, $seen['whole']);
    }

    /**
     * POSITIVE CONTROL — the analyser reports a SPLIT record: a whole line, a
     * truncated JSON line, a whole line again. Exactly what a non-atomic
     * append leaves, and the shape {@see HookLog::tail()} refuses to read.
     */
    public function testTheHookLogAnalyserReportsASplitRecord(): void
    {
        $log = $this->reserveRacePath();
        $torn = substr(self::lineFor('B'), 0, 2000) . "\n";
        file_put_contents($log, self::lineFor('A') . $torn . self::lineFor('C'));

        $seen = self::analyse($log);

        self::assertSame(3, $seen['lines']);
        self::assertSame(1, $seen['split']);
        self::assertSame(2, $seen['whole']);
    }

    /**
     * POSITIVE CONTROL — the analyser reports an INTERLEAVED record: a valid
     * JSON line of the right size whose message carries two writers' bytes.
     * The size check alone cannot see this arm.
     */
    public function testTheHookLogAnalyserReportsAnInterleavedRecord(): void
    {
        $log = $this->reserveRacePath();
        $mixed = (new HookLogEntry(
            date('Y-m-d H:i:s'),
            'sessA',
            'PreToolUse',
            'probe',
            'ALLOW',
            3,
            str_repeat('A', self::PAYLOAD_BYTES - 500) . str_repeat('D', 500),
        ))->toLine();
        file_put_contents($log, self::lineFor('A') . $mixed);

        $seen = self::analyse($log);

        self::assertSame(2, $seen['lines']);
        self::assertSame(0, $seen['split'], 'the mixed record is the right SIZE; it must not be scored as torn');
        self::assertSame(1, $seen['interleaved']);
        self::assertSame(1, $seen['whole']);
    }

    /**
     * Every direct `file_put_contents(` call in $source as the raw text of its
     * own argument list, plus an independent count of name occurrences in the
     * comment-stripped code. The two numbers are separate instruments on
     * purpose; where they disagree the caller goes red rather than trusting a
     * scan that saw a subset of the writes.
     *
     * @return array{calls:list<string>, references:int}
     */
    private static function scanWriteCalls(string $source): array
    {
        $tokens = token_get_all($source);
        $total = \count($tokens);
        $calls = [];

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || ($token[0] !== \T_STRING && $token[0] !== \T_NAME_FULLY_QUALIFIED)) {
                continue;
            }
            if (\ltrim($token[1], '\\') !== 'file_put_contents') {
                continue;
            }

            $previous = self::significantToken($tokens, $i, -1);
            if (\in_array($previous, ['->', '?->', '::'], true)) {
                continue;
            }
            if (\is_array($previous) && \in_array($previous[0], [\T_FUNCTION, \T_CONST, \T_OBJECT_OPERATOR], true)) {
                continue;
            }

            $open = self::significantIndex($tokens, $i, 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            $calls[] = self::argumentText($tokens, $open, $source);
        }

        return [
            'calls' => $calls,
            'references' => \substr_count(self::codeWithoutComments($source), 'file_put_contents'),
        ];
    }

    /**
     * The balanced argument text of the call whose `(` sits at $open. Comment
     * tokens inside the list are dropped, so a flag name mentioned in an
     * inline comment between two arguments is not read as a flag on the write.
     */
    private static function argumentText(array $tokens, int $open, string $source): string
    {
        $depth = 0;
        $text = '';

        for ($k = $open, $total = \count($tokens); $k < $total; $k++) {
            $token = $tokens[$k];

            if (\is_array($token)) {
                if ($token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
                    continue;
                }
                $text .= $token[1];
                continue;
            }

            if ($token === '(') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    return $text;
                }
            }

            $text .= $token;
        }

        // An unbalanced call is unparseable, and the one thing it must NOT do
        // is come back looking like a call with no flags (rule 14).
        throw new \RuntimeException('unbalanced file_put_contents() argument list in a source of '
            . \strlen($source) . ' bytes; this scan cannot be trusted');
    }

    /** The nearest token in $direction from $from that is not whitespace or a comment. */
    private static function significantToken(array $tokens, int $from, int $direction): array|string|null
    {
        $index = self::significantIndex($tokens, $from, $direction);

        return $index === null ? null : $tokens[$index];
    }

    /** {@see significantToken()}'s index. */
    private static function significantIndex(array $tokens, int $from, int $direction): ?int
    {
        for ($i = $from + $direction; $i >= 0 && $i < \count($tokens); $i += $direction) {
            $token = $tokens[$i];
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * $source with every comment and doc-block token removed. BOTH comment
     * kinds are stripped — `//`/`#` lines are T_COMMENT and block comments are
     * T_DOC_COMMENT, and dropping either arm lets a flag name parked in prose
     * masquerade as a flag on a write.
     */
    private static function codeWithoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                if ($token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}

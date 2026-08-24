<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\AuditHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;
use SugarCraft\Crush\Tests\Support\RefusesAnUnreadableSourceTrait;

/**
 * WHAT E328 SAID, AND WHY A TEST RATHER THAN A PARAGRAPH SAYS OTHERWISE.
 *
 * E328 recorded the hazard of `AuditHook`'s old shared default leaf as: "Two
 * `sugarcrush` processes on one box append to one file (interleaved, and a
 * partial write from either can split a record)". E344 objected that neither
 * half reproduces. It does not, and this file is the settlement — the claim
 * was FALSE WHEN IT WAS WRITTEN rather than superseded by the fix, because
 * `git show d881f552^` (the commit immediately before E328's fix) already
 * spells `FILE_APPEND | LOCK_EX`. The reachability half of E328 was true and
 * is what the fix closed; see {@see AuditHook::defaultLogFile()}.
 *
 * WHY THIS IS NOT LEFT AS PROSE. Both {@see AuditHook::defaultLogFile()}'s
 * doc-block and the backlog entry now assert "cross-process appending was
 * already safe" as a load-bearing fact — it is the reason a future reader is
 * told NOT to reach for locking. A load-bearing fact stated only in a comment
 * rots the first time somebody changes the write, and the change that would
 * break it (dropping a flag from one `file_put_contents()` call) is a
 * one-token edit that no other test in this tree would notice.
 *
 * THE ANALYSER IS THE THING THAT HAS TO BE TRUSTED, so it is controlled
 * separately from the race. {@see analyse()} is pushed through three
 * synthetic files whose damage is CONSTRUCTED rather than raced for — one
 * missing records, one carrying a truncated record, one carrying a record
 * with a mixed payload — and each must be reported. An "everything intact"
 * verdict from an analyser that cannot report damage is not evidence, and
 * building the controls deterministically rather than by racing the kernel a
 * second time keeps them from being the flaky half of their own proof.
 *
 * The children leave through {@see ForkedChild::exitNow()}, per the
 * convention {@see \SugarCraft\Crush\Tests\Support\ForkedChildExitConventionTest}
 * enforces.
 *
 * @see AuditHook
 */
final class AuditHookConcurrentAppendTest extends TestCase
{
    /**
     * This file forks inside the PHPUnit process, so it owes the ledger
     * {@see ForkedChildReaperAdoptionTest} requires: `phpunit.xml`'s time
     * limit is a `pcntl_alarm()`, an alarm is not inherited across a fork, so
     * a timed-out parent leaves these writers running unbounded against a log
     * `tearDown()` is about to delete.
     */
    use ReapsForkedChildrenTrait;

    /**
     * THE SOURCE READ IS REFUSED RATHER THAN CAST, on the convention the
     * censuses in `tests/Cli/` follow: `(string) file_get_contents()` turns an
     * unreadable source into empty text, empty text into "no writes found",
     * and this file's own zero-calls arm would then be reporting a permission
     * bit as a defect in `AuditHook`. The trait carries the fixture that pins
     * that arm, which nothing in the scanned population can reach.
     */
    use RefusesAnUnreadableSourceTrait;

    /**
     * Writers, records each, and payload bytes.
     *
     * The payload is past `PIPE_BUF` (4096 on Linux) and past one page, which
     * is the size class E328's "a partial write can split a record" clause
     * needs to be about anything: a record that fits in one atomic write
     * would prove nothing. It is deliberately far smaller than the round-49
     * scratchpad generator's 8 x 200 — a suite test buys the INVARIANT, and
     * the load figure lives in the backlog entry with its generator.
     */
    private const WRITERS = 4;
    private const RECORDS = 25;
    private const PAYLOAD_BYTES = 9000;

    /**
     * The two flag names, assembled from halves rather than spelled.
     *
     * A sweep over either constant must not be able to rewrite the fixtures
     * that document it, which is how the round-48 `uniqid` pass ate its own
     * guard's known-positive (rule 26).
     */
    private const FILE_APPEND_NAME = 'FILE' . '_APPEND';
    private const LOCK_EX_NAME = 'LOCK' . '_EX';

    private string $log = '';

    protected function tearDown(): void
    {
        // BEFORE the unlink, not after: the reaper's whole point is that the
        // children must be dead before the file they are appending to goes
        // away, or the orphans go on writing into whatever takes its inode.
        $this->reapTrackedForkedChildren();

        // Exact-path delete of a name this test created; never a glob.
        if ($this->log !== '' && is_file($this->log)) {
            @unlink($this->log);
        }
        $this->log = '';
    }

    /**
     * A fresh, process-unique log path. `tempnam()` because it is atomic and
     * collision-free by construction — several suites share this machine's
     * temp directory and a composed name is a race of its own (E242).
     */
    private function freshLog(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sc_r49b_audit_');
        self::assertIsString($path, 'could not reserve a temp path for this test');
        $this->log = $path;
        // Reserve the NAME, not an empty first line.
        file_put_contents($path, '');

        return $path;
    }

    /**
     * One record as {@see AuditHook::execute()} spells it, for a given writer
     * letter. Kept beside {@see analyse()} so the two cannot disagree about
     * the shape.
     */
    private static function recordFor(string $letter): string
    {
        return sprintf(
            "[%s] %s %s %s => %s\n",
            date('Y-m-d H:i:s'),
            'sess' . $letter,
            'probe',
            str_repeat($letter, self::PAYLOAD_BYTES),
            str_repeat($letter, 3),
        );
    }

    /**
     * Classify every line of $path as whole / split / interleaved.
     *
     * "Split" is any line that is not a complete, correctly-sized record —
     * the shape a torn write leaves. "Interleaved" is a complete record whose
     * payload is not homogeneous, i.e. two writers' bytes in one line.
     *
     * @return array{lines:int,whole:int,split:int,interleaved:int}
     */
    private static function analyse(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $whole = $split = $interleaved = 0;

        foreach ($lines as $line) {
            $matched = preg_match('/^\[[0-9 :-]+\] sess([A-Z]) probe ([A-Z]+) => ([A-Z]{3})$/', $line, $m) === 1;
            if (!$matched || strlen($m[2]) !== self::PAYLOAD_BYTES) {
                $split++;
                continue;
            }
            if ($m[2] !== str_repeat($m[1], self::PAYLOAD_BYTES) || $m[3] !== str_repeat($m[1], 3)) {
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
     * THE CLAIM. Concurrent writers through the real hook lose nothing, split
     * nothing and interleave nothing.
     */
    public function testConcurrentAppendsThroughTheRealHookAreNeitherLostNorSplit(): void
    {
        self::assertTrue(
            function_exists('pcntl_fork') && function_exists('pcntl_waitpid'),
            'this host has no pcntl, so the claim this file exists to pin cannot be measured here',
        );

        $log = $this->freshLog();
        $expected = self::WRITERS * self::RECORDS;

        $pids = [];
        for ($w = 0; $w < self::WRITERS; $w++) {
            $pid = $this->forkTracked();
            self::assertNotSame(-1, $pid, 'fork failed');
            if ($pid === 0) {
                // A CALLER-SUPPLIED path, so `$ownsPath` is false and the write
                // is byte-for-byte the pre-E328 one: no directory guard, no
                // symlink refusal, no umask narrowing, just the append.
                $hook = new AuditHook($log);
                $letter = chr(ord('A') + $w);
                for ($r = 0; $r < self::RECORDS; $r++) {
                    $hook->execute(new HookContext(
                        sessionId: 'sess' . $letter,
                        toolName: 'probe',
                        toolArgs: [],
                        toolInput: str_repeat($letter, self::PAYLOAD_BYTES),
                        toolOutput: str_repeat($letter, 3),
                        model: 'probe-model',
                        provider: 'probe',
                        projectRoot: '/',
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
     * `LOCK_EX` SURVIVES ITS OWN BEHAVIOURAL MUTATION ON THIS BOX, AND THAT IS
     * WHY IT IS PINNED TEXTUALLY HERE INSTEAD OF BEING QUIETLY DROPPED.
     *
     * MEASURED, PHP 8.3.6, Linux, local ext4 (generator `probe_lockex_r49b.php`
     * in the round-49 scratchpad): with the write reduced to `FILE_APPEND`
     * alone, 8 processes x 60 records at payloads of 9000, 100000 and 1000000
     * bytes — nine runs — produced 480/480 whole records and zero damage every
     * time. Deleting `LOCK_EX` from {@see AuditHook} is a mutation that
     * SURVIVES {@see testConcurrentAppendsThroughTheRealHookAreNeitherLostNorSplit()},
     * and the window is not the reason: deleting `FILE_APPEND` instead KILLS
     * that same test, so the instrument is awake and it is `O_APPEND` doing
     * the work.
     *
     * WHY THE FLAG STAYS ANYWAY, which is a measured reason and not a shrug.
     * `O_APPEND`'s seek-and-write atomicity is a property of the FILESYSTEM,
     * and the one filesystem this box can offer is the one that has it. It is
     * exactly the guarantee NFS is known not to honour, and a temp directory
     * on a network mount is an ordinary thing on a shared build host — so the
     * flag defends a case this hardware cannot produce. Under the standing
     * rule that dormant code is documented and pinned rather than removed,
     * that makes this the only kind of test the property can have: a
     * behavioural test would have to lie about what it proved.
     *
     * THE SCAN IS SCOPED TO THE CALL, NOT TO THE FILE, and that distinction is
     * the whole strength of this test. WHAT IT DID WHEN IT LANDED: it stripped
     * comment tokens and asserted the remaining FILE contained both names.
     * WHAT IS TRUE NOW: that window is wider than the property. MEASURED at
     * round 49 — with the flag taken off the write and a dead
     * `$legacyFlags = FILE_APPEND | LOCK_EX;` left a few lines above, the
     * file-scoped assertion PASSED. The realistic route in is not sabotage:
     * hoisting the pair into a `private const WRITE_FLAGS`, or adding a second
     * write site, each leave the old scan green while the write loses the
     * flag. WHY THE COMMENT-STRIPPING STILL EARNS ITS PLACE: both names appear
     * several times in {@see AuditHook}'s prose — including in the paragraph
     * arguing for them — so a raw-byte scan is still wrong, and
     * {@see codeWithoutComments()} is now the independent second instrument
     * that catches a `file_put_contents` reference the token walk could not
     * classify.
     *
     * IT IS ASSERTED OF EVERY WRITE IN THE CLASS rather than of "the" write,
     * because the second write site is precisely the change that would slip
     * past a scan looking for one. And it goes RED on a source it cannot read
     * a write out of at all (rule 14): zero calls is the answer a deleted
     * scanner gives, so zero calls is a failure and not a pass.
     *
     * {@see flagScannerFixtures()} pushes eight constructed sources through
     * the same scanner in the same class — five that must be reported as NOT
     * carrying the flags, one that must be reported as carrying them, one
     * unclassifiable reference and one source with no write at all.
     */
    public function testEveryWriteInTheClassCarriesBothFlagsInItsOwnArgumentList(): void
    {
        $source = self::readOrFail(\dirname(__DIR__, 2) . '/src/Hooks/BuiltIn/AuditHook.php');
        $seen = self::scanWriteCalls($source);

        self::assertNotSame(
            [],
            $seen['calls'],
            'the scanner found no file_put_contents() call in AuditHook at all, which is what a broken '
            . 'scanner also reports — the flags below would then be asserted of nothing',
        );
        self::assertSame(
            \count($seen['calls']),
            $seen['references'],
            'AuditHook names file_put_contents somewhere the token walk could not read as a direct call '
            . '(a variable function, a method, or a string literal), so this scan is not covering every write',
        );

        foreach ($seen['calls'] as $index => $arguments) {
            self::assertStringContainsString(self::FILE_APPEND_NAME, $arguments, 'write ' . $index);
            self::assertStringContainsString(self::LOCK_EX_NAME, $arguments, 'write ' . $index);
        }
    }

    /**
     * Constructed sources with a known answer, for the scanner above.
     *
     * THE ALPHABET IS PART OF THE COVERAGE (rule 11). The fixture this
     * replaces built its "in a comment" case out of a `/** *\/` block only,
     * i.e. `T_DOC_COMMENT` — so dropping the `T_COMMENT` arm of
     * {@see codeWithoutComments()} was a mutation it SURVIVED, and the first
     * `//` line carrying one of these names would have re-opened the exact
     * hole the fixture existed to close. All three comment spellings are here.
     *
     * The names are assembled from halves rather than spelled, so a future
     * blanket sweep over either constant cannot rewrite the fixture that
     * documents it (rule 26).
     *
     * @return iterable<string, array{0:string, 1:int, 2:int, 3:bool}>
     *         source, expected direct calls, expected references, expected "carries both"
     */
    public static function flagScannerFixtures(): iterable
    {
        $append = self::FILE_APPEND_NAME;
        $lock = self::LOCK_EX_NAME;

        yield 'both names in a doc-block only' => [
            "<?php\n/** written with {$lock} and {$append}, allegedly */\n"
            . "\$ok = file_put_contents(\$p, \$e);\n",
            1, 1, false,
        ];

        yield 'both names in a double-slash line comment only' => [
            "<?php\n// written with {$lock} and {$append}, allegedly\n"
            . "\$ok = file_put_contents(\$p, \$e);\n",
            1, 1, false,
        ];

        yield 'both names in a hash line comment only' => [
            "<?php\n# written with {$lock} and {$append}, allegedly\n"
            . "\$ok = file_put_contents(\$p, \$e);\n",
            1, 1, false,
        ];

        // THE CASE THE FILE-SCOPED SCAN COULD NOT SEE: live code carrying both
        // names, and a write carrying neither.
        yield 'both names in dead code beside a bare write' => [
            "<?php\n\$legacyFlags = {$append} | {$lock};\nunset(\$legacyFlags);\n"
            . "\$ok = file_put_contents(\$p, \$e);\n",
            1, 1, false,
        ];

        yield 'one flag on the write and the other beside it' => [
            "<?php\n\$other = {$lock};\n\$ok = file_put_contents(\$p, \$e, {$append});\n",
            1, 1, false,
        ];

        // POSITIVE CONTROL: without this the scanner is allowed to answer "no"
        // to everything, which every negative above would accept.
        yield 'both flags inside the write' => [
            "<?php\n\$ok = file_put_contents(\$p, \$e, {$append} | {$lock});\n",
            1, 1, true,
        ];

        // RULE 14: a reference the token walk cannot read as a call is
        // REPORTED, not dropped. references outruns calls, which is the
        // mismatch the real-source test fails on.
        yield 'an indirect write through a variable function' => [
            "<?php\n\$writer = 'file_put_contents';\n\$ok = \$writer(\$p, \$e, {$append} | {$lock});\n",
            0, 1, false,
        ];

        yield 'no write at all' => [
            "<?php\n\$ok = \$e;\n",
            0, 0, false,
        ];
    }

    /**
     * @param int  $calls      direct `file_put_contents(` calls the walk must find
     * @param int  $references occurrences the comment-stripped code must hold
     * @param bool $carries    whether the first call's own arguments carry both flags
     */
    #[DataProvider('flagScannerFixtures')]
    public function testTheFlagScannerAnswersEachConstructedSourceCorrectly(
        string $source,
        int $calls,
        int $references,
        bool $carries,
    ): void {
        $seen = self::scanWriteCalls($source);

        self::assertCount($calls, $seen['calls']);
        self::assertSame($references, $seen['references']);

        if ($calls === 0) {
            return;
        }

        $arguments = $seen['calls'][0];
        self::assertStringContainsString('$p', $arguments, 'the walk captured no argument text at all');
        self::assertSame(
            $carries,
            str_contains($arguments, self::FILE_APPEND_NAME) && str_contains($arguments, self::LOCK_EX_NAME),
            'the scanner read the write\'s own argument list wrongly',
        );
    }

    /**
     * Every direct `file_put_contents(` call in $source, as the raw text of
     * its own argument list, plus an independent count of how many times the
     * name appears in the comment-stripped code.
     *
     * The two numbers are separate instruments on purpose. The walk knows what
     * a call looks like; the count knows only that the name is there. Where
     * they disagree, something names this function in a shape the walk cannot
     * read — and the caller's job is to go red on that rather than to scan a
     * subset of the writes and report a clean verdict.
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

            // A `->`, `?->`, `::`, `function` or `const` in front means this is
            // not the global function. It is deliberately NOT captured as a
            // call and deliberately still counted by the reference instrument,
            // so the caller sees a mismatch rather than a short list.
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
     * The balanced argument text of the call whose `(` sits at $open.
     *
     * Comment tokens inside the list are dropped, so a name mentioned in an
     * inline comment between two arguments is not read as a flag.
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

        // RULE 14 AGAIN: an unbalanced call is unparseable, and the one thing
        // it must not do is come back looking like a call with no flags.
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
     * $source with every comment and doc-block token removed.
     *
     * BOTH COMMENT KINDS, and the fixture set above now proves it: `//` and
     * `#` lines are `T_COMMENT` and `/** *\/` blocks are `T_DOC_COMMENT`, and
     * dropping either arm is a mutation the old single-spelling fixture
     * survived.
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

    /**
     * KNOWN-POSITIVE CONTROL 1 — the analyser reports LOSS.
     *
     * Constructed, not raced: a file holding one record where the run above
     * would have produced every writer's. This is the damage `FILE_APPEND`
     * prevents, and without this control the `assertSame($expected, …)` above
     * would be equally satisfied by an analyser that always answered with the
     * number it was asked for.
     */
    public function testTheAnalyserReportsLoss(): void
    {
        $log = $this->freshLog();
        file_put_contents($log, self::recordFor('A'));

        $seen = self::analyse($log);

        self::assertSame(1, $seen['lines']);
        self::assertNotSame(self::WRITERS * self::RECORDS, $seen['lines']);
        self::assertSame(1, $seen['whole']);
    }

    /**
     * KNOWN-POSITIVE CONTROL 2 — the analyser reports a SPLIT record.
     *
     * A whole record, then a torn one, then a whole one: exactly the shape a
     * non-atomic write leaves behind, and the shape E328's second clause
     * claimed to see. The `split` count above is worthless unless this fires.
     */
    public function testTheAnalyserReportsASplitRecord(): void
    {
        $log = $this->freshLog();
        $torn = substr(self::recordFor('B'), 0, 2000) . "\n";
        file_put_contents($log, self::recordFor('A') . $torn . self::recordFor('C'));

        $seen = self::analyse($log);

        self::assertSame(3, $seen['lines']);
        self::assertSame(1, $seen['split']);
        self::assertSame(2, $seen['whole']);
    }

    /**
     * KNOWN-POSITIVE CONTROL 3 — the analyser reports an INTERLEAVED record.
     *
     * A correctly-shaped, correctly-sized record whose payload carries two
     * writers' bytes. This is the arm a length check alone cannot see, and it
     * is the one E328's word "interleaved" names.
     */
    public function testTheAnalyserReportsAnInterleavedRecord(): void
    {
        $log = $this->freshLog();
        $mixed = sprintf(
            "[%s] %s %s %s => %s\n",
            date('Y-m-d H:i:s'),
            'sessA',
            'probe',
            str_repeat('A', self::PAYLOAD_BYTES - 500) . str_repeat('D', 500),
            'AAA',
        );
        file_put_contents($log, self::recordFor('A') . $mixed);

        $seen = self::analyse($log);

        self::assertSame(2, $seen['lines']);
        self::assertSame(0, $seen['split'], 'the mixed record is the right SIZE; it must not be scored as torn');
        self::assertSame(1, $seen['interleaved']);
        self::assertSame(1, $seen['whole']);
    }
}

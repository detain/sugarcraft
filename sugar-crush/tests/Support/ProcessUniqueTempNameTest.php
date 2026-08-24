<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * TWO WAYS A TEMP PATH COLLIDES BETWEEN PROCESSES, AND THE SECOND ONE IS THE
 * ONE THIS GUARD USED TO BE UNABLE TO EXPRESS.
 *
 * THE FIRST. An argument-less `uniqid` call is derived from the current
 * microtime and NOTHING else, so it is not unique across processes. Two suites
 * running as the same user at the same moment can produce the SAME value. That
 * matters here because `tests/bootstrap.php` points `TMPDIR` at a sandbox keyed
 * by uid alone — every concurrent suite writes into one directory — so a
 * collision is a collision on a real path. Observed 2026-08-23 while measuring
 * E242: five concurrent runs of `tests/Agents` produced `SQLite3Exception: Task
 * not found: dep` in one of them, from two processes opening one
 * `tasklist_test_<id>.sqlite3`. Run alone, the same range is green — which is
 * why this is invisible to a lane working by itself and reads as a flake. The
 * fix is a pid prefix plus the more-entropy flag.
 *
 * THE SECOND, AND IT IS NOT A `uniqid` HAZARD AT ALL. A path with NO entropy
 * source whatsoever — one fixed name, written to. `AuditHook`'s default log
 * file is exactly that, and a test that drove it wrote the file and then
 * unlinked it: two concurrent copies race, one deleting the file between the
 * other's write and its `assertFileExists`, and both vandalising the audit log
 * of any real `sugarcrush` on the same box. Measured under one shared private
 * `TMPDIR`, six concurrent runs: 2, 1 and 1 failures across three takes before
 * the fix, 0, 0 and 0 after it.
 *
 * A GUARD WHOSE ALPHABET IS THE TOKEN `uniqid` CANNOT EXPRESS THE SECOND SHAPE
 * BY CONSTRUCTION, so a sweep that fixed 91 call sites walked straight past it
 * and reported success. Rule 11: when a census reports zero, ask what its
 * alphabet cannot say. Both shapes now have a scanner here, and each scanner is
 * proved alive by something OTHER than a green tree — see
 * {@see STATIC_TEMP_PATH_INVENTORY}, whose single row is a real site in `src/`
 * that the second scanner must keep finding.
 *
 * ⚠️ THIS FILE MUST SURVIVE A BLANKET REWRITE OF THE PATTERN IT DESCRIBES.
 * The 2026-08-23 sweep that fixed those 91 call sites also ate this test's own
 * fixture and mangled its prose, because a regex cannot tell the offender from
 * the description of the offender. So: the prose never spells the bare call,
 * and every fixture BUILDS it at runtime by concatenation instead of containing
 * it literally. Keep it that way.
 */
final class ProcessUniqueTempNameTest extends TestCase
{
    /**
     * Directories scanned by both censuses, plus the one entry-point script.
     *
     * `tests/` ALONE WAS THE ORIGINAL SCOPE AND THAT WAS A HOLE, not a
     * decision: the hazard is a path two processes share, and `src/` writes
     * temp paths at runtime exactly as the suite does. Widening it is what
     * turned up both inventory rows below — neither of which any `tests/`-only
     * scan could have reported.
     */
    private const SCOPE = ['tests', 'src', 'bin/sugarcrush'];

    /**
     * Sites that make the argument-less call and are meant to, with the reason.
     *
     * NOT AN EXEMPTION LIST. Both directions are checked against the tree by
     * {@see testEveryArgumentlessInventoryRowStillDescribesTheSitesItClaims()}:
     * a row whose sites have been fixed fails and must be deleted, and a site
     * with no row fails and must be argued or fixed. A deferral is a claim
     * about the tree, so the tree is asked.
     *
     * THE COUNT IS EXACT AND THAT IS DELIBERATE. A range would let a sixth site
     * arrive unremarked in a file that already has five.
     *
     * @var array<string,array{sites:int,why:string}>
     */
    private const ARGUMENTLESS_INVENTORY = [
        'src/Workflows/WorkflowEngine.php' => [
            'sites' => 5,
            'why' =>
                'THE ID BUILT HERE DOES REACH A FILE PATH, AND IT IS STILL NOT A CROSS-PROCESS '
                . 'HAZARD — measured, not assumed. Each of the five builds a SubAgent id as '
                . '`<stage>-` . the call. That id reaches disk through '
                . 'AgentWorkerPool::resultFile()/progressFile(), which are '
                . '`$this->resultDir . "/" . hash("sha256", $agentId)`. But `$resultDir` is '
                . 'makeResultDirPath(), which is `sys_get_temp_dir() . "/sc_pool_" . getmypid() '
                . '. "_" . bin2hex(random_bytes(8))` — the DIRECTORY already carries a pid and '
                . '64 bits of entropy, so no two processes can meet on that path whatever the '
                . 'id is. What is left is intra-process uniqueness, which the argument-less '
                . 'call does guarantee: PHP sleeps to advance the microtime, measured on PHP '
                . '8.3.6 as 200 calls in one process yielding 200 distinct values. '
                . 'FIXING THEM ANYWAY IS STILL WORTH A ROUND: the guarantee is a property of '
                . 'the enclosing directory rather than of these call sites, so it is one edit '
                . 'to makeResultDirPath() away from being untrue. That edit is out of this '
                . 'lane (tests only) and is recorded as a deferred finding rather than done '
                . 'here.',
        ],
    ];

    /**
     * Sites that build a FULLY STATIC temp path and write to it, with the
     * reason each is allowed to.
     *
     * THIS ROW IS ALSO THE SECOND SCANNER'S KNOWN-POSITIVE CONTROL, and that is
     * why it earns its place twice over. Rule 15: an assertion that nothing was
     * found is satisfied perfectly by an instrument that has been deleted. The
     * synthetic fixtures below cover the shapes; THIS row covers the walk over
     * the real tree, because the check runs in both directions — a scanner that
     * stops finding this site fails
     * {@see testEveryStaticTempPathInventoryRowStillDescribesTheSitesItClaims()}
     * even though every other assertion in the file would be green.
     *
     * @var array<string,array{sites:int,why:string}>
     */
    private const STATIC_TEMP_PATH_INVENTORY = [
        'src/Hooks/BuiltIn/AuditHook.php' => [
            'sites' => 1,
            'why' =>
                'THE SITE E298 WAS ABOUT, AND IN PRODUCTION IT IS THE INTENDED BEHAVIOUR. '
                . 'The default log file is one fixed name on the machine\'s temp dir, and an '
                . 'audit log that moves every run is not an audit log — a caller who wants a '
                . 'private one passes it in. What was wrong was never this line: it was a TEST '
                . 'that drove the production default, wrote it and then unlinked it, so two '
                . 'concurrent suites raced on it and both deleted the real log of anything '
                . 'else on the box. That test now asserts the default is CONSTRUCTED and does '
                . 'its writing at a pid+entropy path. '
                . 'KEEP THIS ROW EVEN IF THE HOOK MOVES: it is the only real-tree input that '
                . 'proves the static-path scanner still walks, and the day it stops matching '
                . 'is the day this whole census goes quiet.',
        ],
    ];

    /** Calls whose first argument being a fixed shared path is the hazard. */
    private const MUTATING_CALLS = [
        'file_put_contents', 'mkdir', 'touch', 'unlink', 'rmdir',
        'copy', 'rename', 'symlink', 'link', 'fopen', 'chmod',
    ];

    /** Token ids that make a following name a member call rather than a global one. */
    private const MEMBER_OPERATORS = [
        \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION,
    ];

    // =========================================================================
    // Channel 1 — the argument-less call
    // =========================================================================

    public function testNoFileMakesAProcessColludingTempNameOutsideTheInventory(): void
    {
        $this->assertTheUniqidScannerIsAlive();

        $offenders = [];
        $problems  = [];
        $counts    = [];

        foreach (self::filesInScope() as $relative => $absolute) {
            [$lines, $unparseable] = self::argumentlessSites(self::readOrFail($absolute));

            foreach ($unparseable as $problem) {
                $problems[] = $relative . ': ' . $problem;
            }
            if ($lines === []) {
                continue;
            }

            $counts[$relative] = \count($lines);
            if (isset(self::ARGUMENTLESS_INVENTORY[$relative])) {
                continue;
            }
            foreach ($lines as $line) {
                $offenders[] = $relative . ':' . $line;
            }
        }

        // RULE: A GUARD MUST GO RED ON WHAT IT CANNOT PARSE. A call whose
        // argument list this scanner cannot delimit is not "clean" — it is a
        // hole shaped exactly like the next colliding temp name.
        self::assertSame([], $problems, \sprintf(
            "%d call site(s) this scanner could not read. It found the name and then could not "
            . "find where its arguments end, so it cannot say whether the call is argument-less. "
            . "Reported rather than skipped: a site silently dropped is one this guard has "
            . "stopped covering, which is indistinguishable from one it has cleared.\n  %s",
            \count($problems),
            \implode("\n  ", $problems),
        ));

        self::assertSame([], $offenders, self::argumentlessMessage($offenders));

        foreach (self::ARGUMENTLESS_INVENTORY as $file => $row) {
            self::assertSame($row['sites'], $counts[$file] ?? 0, $file
                . ' has a different number of argument-less calls than its inventory row claims. '
                . 'A row with a stale count lets a new site arrive unremarked in a file that '
                . 'already had some. Re-count and rewrite the row, or fix the new site.');
        }
    }

    /**
     * A row cannot outlive the sites it was written for.
     *
     * Without this the inventory decays into a list of files somebody once
     * looked at, and the census above keeps passing because a stale row still
     * matches the name.
     */
    public function testEveryArgumentlessInventoryRowStillDescribesTheSitesItClaims(): void
    {
        $this->assertTheUniqidScannerIsAlive();

        $files = self::filesInScope();

        foreach (self::ARGUMENTLESS_INVENTORY as $relative => $row) {
            self::assertNotSame('', trim($row['why']), $relative . ' is inventoried without a reason');
            self::assertArrayHasKey($relative, $files, $relative
                . ' is inventoried and is no longer in the census scope at all. Delete the row.');

            [$lines] = self::argumentlessSites(self::readOrFail($files[$relative]));

            self::assertNotSame([], $lines, $relative
                . ' is inventoried as making the argument-less call and no longer makes it. '
                . 'Either the sites were fixed — delete the row — or this scanner has stopped '
                . 'seeing them, in which case every "nothing found" answer beside it is a '
                . 'statement about a dead walk.');
        }
    }

    /**
     * KNOWN-ANSWER CONTROL FOR EVERY SPELLING, POSITIVE AND NEGATIVE.
     *
     * The two spellings that matter are the bare name and the FULLY QUALIFIED
     * one, and the second is this codebase's own house style. The scanner keyed
     * on `T_STRING` alone, and PHP 8 lexes a leading-backslash call as
     * `T_NAME_FULLY_QUALIFIED`, so the house spelling was invisible to it: a
     * guard covering the minority of its own subject. How much of the tree that
     * is, is NOT written down here — a cardinality taken in a lane worktree is
     * void at the next merge — it is derived by
     * {@see testTheHouseSpellingIsPresentSoTheWideningIsLoadBearing()}.
     *
     * The negatives matter as much. A predicate stuck at "any call of this
     * name" would report the prefixed and entropic forms, which are the FIX,
     * and would be answered with an exemption list — which is where the next
     * real collision hides.
     */
    public function testTheUniqidScannerSeesEverySpellingAndSparesTheGoodOnes(): void
    {
        $bare = 'uniq' . 'id';

        $source = "<?php\n"                                   // 1
            . "\$a = {$bare}();\n"                            // 2  bare, offending
            . "\$b = \\{$bare}();\n"                          // 3  fully qualified, offending
            . "\$c = {$bare} (   );\n"                        // 4  spaced, offending
            . "\$d = {$bare}(\n);\n"                          // 5  split over lines, offending
            . "\$e = {$bare}('prefix');\n"                    // 7  spared
            . "\$f = \\{$bare}((string) \\getmypid(), true);\n" // 8  spared
            . "\$g = \$o->{$bare}();\n"                       // 9  spared: a method
            . "\$h = Foo::{$bare}();\n"                       // 10 spared: a static method
            . "\$i = '{$bare}';\n"                            // 11 spared: a string
            . "function {$bare}() { return 1; }\n";           // 12 spared: a declaration

        [$lines, $problems] = self::argumentlessSites($source);

        self::assertSame([2, 3, 4, 5], $lines, 'the scanner does not see every offending spelling');
        self::assertSame([], $problems, 'a well-formed fixture was reported as unparseable');
    }

    /**
     * THE WIDENING IS LOAD-BEARING RIGHT NOW, derived from the tree rather than
     * asserted about it.
     *
     * A scanner widened for a spelling nothing uses is a scanner nobody can
     * tell is working. This counts the qualified spelling where it really
     * occurs, so the claim moves when the tree does instead of being re-read as
     * still true. The day it reads zero, ask whether the alphabet still needs
     * the arm before deleting it — the answer is probably yes, because the next
     * file written in the house style puts it back.
     */
    public function testTheHouseSpellingIsPresentSoTheWideningIsLoadBearing(): void
    {
        $qualified = 0;
        $bare      = 0;

        foreach (self::filesInScope() as $absolute) {
            foreach (self::callSpellings(self::readOrFail($absolute)) as $spelling) {
                $spelling === 'qualified' ? $qualified++ : $bare++;
            }
        }

        self::assertGreaterThan(0, $bare + $qualified, 'no call of any spelling was found at all, '
            . 'so this file\'s whole subject has left the tree and the scan is walking nothing');

        self::assertGreaterThan(
            0,
            $qualified,
            'the fully-qualified spelling no longer occurs anywhere in scope. Nothing is broken, '
                . 'but the T_NAME_FULLY_QUALIFIED arm in argumentlessSites() is now dormant and '
                . 'the next reader will take it for dead code. Leave it — the arm is what makes '
                . 'the guard cover the house style — and rewrite this test\'s reason rather '
                . 'than deleting the arm.',
        );
    }

    /**
     * `qualified` or `bare` per call site of the name, for the measurement
     * above. Shares {@see argumentlessSites()}'s notion of what a call is, so
     * the two cannot drift into two definitions.
     *
     * @return list<string>
     */
    private static function callSpellings(string $source): array
    {
        $tokens = \token_get_all($source);
        $target = 'uniq' . 'id';
        $found  = [];

        foreach ($tokens as $i => $token) {
            if (!\is_array($token)) {
                continue;
            }
            if ($token[0] !== \T_STRING && $token[0] !== \T_NAME_FULLY_QUALIFIED) {
                continue;
            }
            if (\ltrim($token[1], '\\') !== $target) {
                continue;
            }

            $before = self::significantNeighbour($tokens, $i, -1);
            if ($before !== null && \is_array($tokens[$before])
                && \in_array($tokens[$before][0], self::MEMBER_OPERATORS, true)) {
                continue;
            }

            $open = self::significantNeighbour($tokens, $i, 1);
            if ($open === null || self::text($tokens[$open]) !== '(') {
                continue;
            }

            $found[] = $token[0] === \T_NAME_FULLY_QUALIFIED ? 'qualified' : 'bare';
        }

        return $found;
    }

    /**
     * RULE 14, as a fixture: the scanner reports a call it cannot delimit
     * instead of walking past it.
     */
    public function testTheUniqidScannerRedsOnACallItCannotDelimit(): void
    {
        $bare = 'uniq' . 'id';

        [$lines, $problems] = self::argumentlessSites("<?php\n\$a = {$bare}(\n");

        self::assertSame([], $lines, 'an unterminated call was counted as a clean answer');
        self::assertNotSame([], $problems, 'an unterminated call was dropped instead of reported');
    }

    // =========================================================================
    // Channel 2 — the fixed shared path, which carries no `uniqid` at all
    // =========================================================================

    public function testNoFileWritesToAFixedSharedTempPathOutsideTheInventory(): void
    {
        $this->assertTheStaticPathScannerIsAlive();

        $offenders = [];
        $counts    = [];

        foreach (self::filesInScope() as $relative => $absolute) {
            $sites = self::staticTempPathWrites(self::readOrFail($absolute));
            if ($sites === []) {
                continue;
            }

            $counts[$relative] = \count($sites);
            if (isset(self::STATIC_TEMP_PATH_INVENTORY[$relative])) {
                continue;
            }
            foreach ($sites as $site) {
                $offenders[] = $relative . ':' . $site;
            }
        }

        self::assertSame([], $offenders, self::staticPathMessage($offenders));

        foreach (self::STATIC_TEMP_PATH_INVENTORY as $file => $row) {
            self::assertSame($row['sites'], $counts[$file] ?? 0, $file
                . ' has a different number of fixed-shared-path writes than its inventory row '
                . 'claims. Re-count and rewrite the row, or fix the new site.');
        }
    }

    /**
     * The row is checked back against the tree, and doing so is what proves the
     * static-path walk still runs over real sources.
     */
    public function testEveryStaticTempPathInventoryRowStillDescribesTheSitesItClaims(): void
    {
        $this->assertTheStaticPathScannerIsAlive();

        $files = self::filesInScope();

        foreach (self::STATIC_TEMP_PATH_INVENTORY as $relative => $row) {
            self::assertNotSame('', trim($row['why']), $relative . ' is inventoried without a reason');
            self::assertArrayHasKey($relative, $files, $relative
                . ' is inventoried and is no longer in the census scope at all. Delete the row — '
                . 'and read the row first, because it is also this scanner\'s only real-tree '
                . 'control and something else must take that job.');

            self::assertNotSame([], self::staticTempPathWrites(self::readOrFail($files[$relative])), $relative
                . ' is inventoried as writing to a fixed shared temp path and no longer does. '
                . 'If the site was changed, delete the row AND give this scanner another '
                . 'real-tree positive; if it was not, this walk has gone blind and the census '
                . 'beside it is reporting a dead instrument\'s silence as a clean tree.');
        }
    }

    /**
     * KNOWN-ANSWER CONTROL FOR THE STATIC-PATH SCANNER, in the same test file
     * that uses it to assert an absence.
     *
     * Every fixture here was written by running the shape past the scanner and
     * checking the answer against the tree, not the other way round. Two of
     * them exist because the scanner FAILED them first: the defaulted shape
     * (`$x ?? <static path>`) is the exact spelling of the one real site in the
     * tree and an earlier version reported zero over the whole repository
     * because a `??` put a variable in the expression; and the bind-then-write
     * shape is the only one E298 ever took, so a scanner that could see the
     * inline argument alone would have reported a clean tree.
     */
    public function testTheStaticPathScannerSeesTheShapeE298TookAndSparesEntropicPaths(): void
    {
        $this->assertTheStaticPathScannerIsAlive();

        // A variable in the path is NOT the same claim as "no entropy". This
        // scanner refuses to guess: a path it cannot evaluate statically is
        // outside its alphabet and is reported as nothing, which is a hole it
        // states rather than one it hides. See the class doc-block.
        self::assertSame([], self::staticTempPathWrites(
            "<?php\n\$p = sys_get_temp_dir() . '/x_' . \$suffix;\nfile_put_contents(\$p, 'x');\n",
        ), 'a path carrying a variable was reported, so every dynamic path in the tree is on the hook');

        self::assertSame([], self::staticTempPathWrites(
            "<?php\n\$p = sys_get_temp_dir() . '/x_' . getmypid();\nunlink(\$p);\n",
        ), 'a path carrying a call was reported as static');

        // CONSTRUCTED BUT NEVER WRITTEN is not the hazard; asserting a
        // production default's VALUE is precisely the fix E298 landed.
        self::assertSame([], self::staticTempPathWrites(
            "<?php\n\$p = sys_get_temp_dir() . '/fixed.log';\nself::assertSame(\$p, \$hook->path());\n",
        ), 'a fixed path that is only compared, never written, was reported');
    }

    /**
     * RULE 14 AT THE READ IS ALSO PINNED, because a robustness arm nothing
     * exercises is a robustness arm nobody notices the loss of.
     *
     * The alternative — `(string) file_get_contents()` — turns an unreadable
     * file into an empty one, an empty one into "no hits", and "no hits" into a
     * clean census. Three silent steps, and every count in this file would then
     * be a statement about how many files the process happened to be allowed to
     * open. Reverting {@see readOrFail()} to the cast is a mutation nothing
     * else here can kill: no real source in the tree is unreadable, so the arm
     * has no natural input. This is that input.
     */
    public function testTheReadRefusesAFileItCannotOpenInsteadOfReadingItAsEmpty(): void
    {
        $absent = \dirname(__DIR__, 2) . '/tests/Support/no_such_file_'
            . \getmypid() . '_' . \bin2hex(\random_bytes(6)) . '.php';

        self::assertFileDoesNotExist($absent);

        // The PHP-level warning from the failed open is the point of the
        // fixture and is not the thing under test; this suite runs with
        // failOnWarning, so it is swallowed HERE rather than with an `@` in
        // readOrFail(), where it would also swallow the diagnosis on a real
        // unreadable source.
        $previous = \set_error_handler(static fn (): bool => true);
        $refused  = false;

        try {
            self::readOrFail($absent);
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            $refused = true;
        } finally {
            \set_error_handler($previous);
        }

        self::assertTrue($refused, 'the read returned instead of refusing a file it could not '
            . 'open, so an unreadable source now reaches every scanner in this file as empty '
            . 'text and is reported as a clean one');
    }

    // =========================================================================
    // Scanners
    // =========================================================================

    /**
     * The 1-indexed lines of every argument-less call, plus the sites this
     * scanner could not delimit.
     *
     * TOKENS AND NOT A LINE REGEX. The line-regex form this replaced could not
     * see a call split across lines, could not see the fully-qualified
     * spelling, matched the name inside a comment or a string, and — the part
     * that mattered — had no way to say "I found the name and could not read
     * the call", so it answered "clean" for anything it could not parse.
     *
     * @return array{list<int>, list<string>} offending lines, problems
     */
    private static function argumentlessSites(string $source): array
    {
        $tokens = \token_get_all($source);
        $count  = \count($tokens);
        $target = 'uniq' . 'id';

        $lines    = [];
        $problems = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }
            if ($token[0] !== \T_STRING && $token[0] !== \T_NAME_FULLY_QUALIFIED) {
                continue;
            }
            if (\ltrim($token[1], '\\') !== $target) {
                continue;
            }

            $before = self::significantNeighbour($tokens, $i, -1);
            if ($before !== null && \is_array($tokens[$before])
                && \in_array($tokens[$before][0], self::MEMBER_OPERATORS, true)) {
                continue;
            }

            $open = self::significantNeighbour($tokens, $i, 1);
            if ($open === null || self::text($tokens[$open]) !== '(') {
                // Not a call at all: an import, a callable string is a
                // different token, a constant of that name. Nothing to say.
                continue;
            }

            $close = self::matching($tokens, $open);
            if ($close === null) {
                $problems[] = 'line ' . $token[2] . ': the argument list opened and never closed';

                continue;
            }

            if (self::significantNeighbour($tokens, $open, 1) === $close) {
                $lines[] = $token[2];
            }
        }

        return [$lines, $problems];
    }

    /**
     * The lines at which a FULLY STATIC temp path reaches a filesystem-mutating
     * call — inline as the first argument, or through one binding in the file.
     *
     * ONE BINDING AND NOT A DATAFLOW ANALYSIS, stated so nobody mistakes the
     * bound for an answer. `$x = <static temp path>;` anywhere in the file, and
     * `$x` as an argument to a mutating call anywhere in the file, is enough:
     * the only real site in the tree assigns in a constructor and writes in a
     * method, so a scope-local rule would miss it. The cost is that a name
     * rebound between the two is followed anyway — a false positive, which is
     * loud, rather than a false negative, which is not.
     *
     * @return list<int> 1-indexed lines, sorted, unique
     */
    private static function staticTempPathWrites(string $source): array
    {
        $tokens = self::significantTokens($source);
        $count  = \count($tokens);

        $bound = [];
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $text = self::text($tokens[$i]);
            if ($text === '(' || $text === '[') {
                $depth++;

                continue;
            }
            if ($text === ')' || $text === ']') {
                $depth--;

                continue;
            }
            // A depth-0 `=` is an assignment; one inside parentheses is a
            // parameter default, and reading those as assignments was how an
            // earlier version of this walk swallowed an entire class body.
            if ($text !== '=' || \is_array($tokens[$i]) || $depth !== 0) {
                continue;
            }

            $target = self::assignmentTarget($tokens, $i);
            if ($target === null) {
                continue;
            }

            $end = self::statementEnd($tokens, $i + 1);
            if (self::anyStaticBranchIsATempPath($tokens, $i + 1, $end)) {
                $bound[$target] = true;
            }
        }

        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            $name = self::globalCallName($tokens, $i);
            if ($name === null || !\in_array($name, self::MUTATING_CALLS, true)) {
                continue;
            }

            $close = self::matching($tokens, $i + 1);
            if ($close === null) {
                continue;
            }

            $firstEnd = self::argumentEnd($tokens, $i + 2, $close);
            if ($firstEnd >= $i + 2 && self::isStaticTempPath($tokens, $i + 2, $firstEnd)) {
                $lines[] = self::lineOf($tokens, $i);
            }

            for ($j = $i + 2; $j < $close; $j++) {
                $candidate = self::referenceAt($tokens, $j);
                if ($candidate !== null && isset($bound[$candidate])) {
                    $lines[] = self::lineOf($tokens, $j);
                }
            }
        }

        $lines = array_values(array_unique($lines));
        sort($lines);

        return $lines;
    }

    /**
     * Whether any `??`/`?:` branch of tokens[$from..$to] is a static temp path.
     *
     * THE DEFAULTED SHAPE IS THE ONLY ONE THE TREE ACTUALLY HAS, and an earlier
     * version of this walk reported ZERO over the whole repository because
     * `$given ?? sys_get_temp_dir() . '/fixed.log'` contains a variable and so
     * was read as dynamic. A scanner that cannot see the one real instance of
     * the shape it was built for is a scanner that reports a clean tree.
     *
     * @param list<array{int,string,int}|string> $tokens
     */
    private static function anyStaticBranchIsATempPath(array $tokens, int $from, int $to): bool
    {
        $depth = 0;
        $start = $from;

        for ($j = $from; $j <= $to; $j++) {
            $token = $tokens[$j];
            $text  = self::text($token);

            if ($text === '(' || $text === '[') {
                $depth++;

                continue;
            }
            if ($text === ')' || $text === ']') {
                $depth--;

                continue;
            }

            $splits = $depth === 0
                && ((\is_array($token) && $token[0] === \T_COALESCE)
                    || (!\is_array($token) && ($text === '?' || $text === ':')));

            if ($splits) {
                if ($start <= $j - 1 && self::isStaticTempPath($tokens, $start, $j - 1)) {
                    return true;
                }
                $start = $j + 1;
            }
        }

        return $start <= $to && self::isStaticTempPath($tokens, $start, $to);
    }

    /**
     * Whether tokens[$from..$to] is a temp root concatenated with literals and
     * nothing else — no variable, no call, no constant this scanner cannot
     * evaluate.
     *
     * @param list<array{int,string,int}|string> $tokens
     */
    private static function isStaticTempPath(array $tokens, int $from, int $to): bool
    {
        $sawRoot = false;

        for ($j = $from; $j <= $to; $j++) {
            $token = $tokens[$j];

            if (\is_array($token) && $token[0] === \T_CONSTANT_ENCAPSED_STRING) {
                if (preg_match('#^.[\'"]?/tmp/#', $token[1]) === 1) {
                    $sawRoot = true;
                }

                continue;
            }
            if (\is_array($token) && $token[0] === \T_VARIABLE) {
                return false;
            }

            $name = self::callableName($token);
            if ($name === 'sys_get_temp_dir') {
                $sawRoot = true;

                continue;
            }
            if ($name !== null) {
                return false;
            }
            if (\in_array(self::text($token), ['.', '(', ')'], true)) {
                continue;
            }

            return false;
        }

        return $sawRoot;
    }

    // =========================================================================
    // Failure text — extracted so it can be run over a real population
    // =========================================================================

    /**
     * A FAILURE MESSAGE'S GENERATOR IS THE ONE PART OF A GREEN SUITE THAT
     * NEVER REALLY RUNS (E270), and both of this file's censuses build an
     * elaborate one.
     *
     * PHP evaluates an assertion's message argument eagerly, so an inline
     * `sprintf()` here would execute on every green run — but only ever over
     * the EMPTY list, which exercises none of the formatting a reader will
     * actually be handed. The one moment the text matters is the moment nobody
     * has read it yet. Extracted, both are run over a real population by
     * {@see testBothFailureTextsNameTheOffendersTheyWereHanded()}.
     *
     * @param list<string> $offenders
     */
    private static function argumentlessMessage(array $offenders): string
    {
        return \sprintf(
            "%d argument-less call(s) found outside the inventory. That form is microtime-derived "
            . "and NOT unique across processes; under the shared TMPDIR two concurrent suites "
            . "collide on one path. Pass a pid prefix and the more-entropy flag — or, if the "
            . "collision genuinely cannot happen, add the file to ARGUMENTLESS_INVENTORY with "
            . "the MEASUREMENT that shows so.\n  %s",
            \count($offenders),
            \implode("\n  ", $offenders),
        );
    }

    /** @param list<string> $offenders */
    private static function staticPathMessage(array $offenders): string
    {
        return \sprintf(
            "%d fixed shared temp path(s) reaching a filesystem-mutating call. A path with NO "
            . "entropy source is the same name in every process on the box: two concurrent runs "
            . "write, read and delete one file, and whichever loses the race fails with a "
            . "message about the file rather than about the race. This is not a `uniqid` "
            . "hazard and no scan keyed on that name can see it. Give the path a pid and some "
            . "entropy — or inventory it with the reason a fixed name is correct there.\n  %s",
            \count($offenders),
            \implode("\n  ", $offenders),
        );
    }

    /**
     * Both failure texts, run over a population a green suite never gives them.
     *
     * What this catches is small and real: a message that names none of the
     * offenders, or names the count and not the rows, or interpolates the wrong
     * variable. All three read as a green test until the day the guard fires,
     * and on that day the reader is handed a paragraph with the evidence
     * missing from it.
     */
    public function testBothFailureTextsNameTheOffendersTheyWereHanded(): void
    {
        $rows = ['src/One.php:11', 'tests/Two.php:22'];

        foreach ([self::argumentlessMessage($rows), self::staticPathMessage($rows)] as $message) {
            self::assertStringContainsString('2 ', $message, 'the count is not the population\'s');
            self::assertStringContainsString('src/One.php:11', $message, 'the first row is not named');
            self::assertStringContainsString('tests/Two.php:22', $message, 'the second row is not named');
        }

        self::assertStringContainsString('ARGUMENTLESS_INVENTORY', self::argumentlessMessage($rows));
        self::assertStringContainsString('entropy', self::staticPathMessage($rows));

        // The two texts must not be the same text. They are handed to a reader
        // who has to tell which of the two hazards fired, and a copy-paste that
        // left one of them naming the other is invisible on a green run.
        self::assertNotSame(self::argumentlessMessage($rows), self::staticPathMessage($rows));

        // AND THE EMPTY CASE, which is the one the green suite really does hand
        // them on every run, still reads as prose rather than crashing.
        self::assertStringContainsString('0 ', self::argumentlessMessage([]));
        self::assertStringContainsString('0 ', self::staticPathMessage([]));
    }

    // =========================================================================
    // Liveness controls
    // =========================================================================

    /**
     * The uniqid scanner is pushed through an input whose answer is known
     * BEFORE it is used to assert an absence.
     *
     * Rule 15: `assertSame([], …)` is satisfied perfectly by a deleted
     * instrument. Rule 25, one level down: a fixture whose expected value is
     * what a DEAD instrument returns proves nothing, so the load-bearing half
     * here is the POSITIVE one.
     */
    private function assertTheUniqidScannerIsAlive(): void
    {
        $bare = 'uniq' . 'id';

        [$lines] = self::argumentlessSites("<?php\n\$a = \\{$bare}();\n");
        self::assertSame([2], $lines, 'the argument-less scanner is not reporting a known offender');

        [$spared] = self::argumentlessSites("<?php\n\$a = {$bare}('p', true);\n");
        self::assertSame([], $spared, 'the argument-less scanner reports a call that carries entropy');
    }

    /** The same, for the static-path scanner. */
    private function assertTheStaticPathScannerIsAlive(): void
    {
        // Inline, as the first argument.
        self::assertSame([2], self::staticTempPathWrites(
            "<?php\nfile_put_contents(sys_get_temp_dir() . '/fixed.log', 'x');\n",
        ), 'the static-path scanner is not reporting a fixed path written to inline');

        // Bound in one statement and written in another — E298's real shape,
        // and the only one it ever took.
        self::assertSame([3], self::staticTempPathWrites(
            "<?php\n\$p = sys_get_temp_dir() . '/fixed.log';\nunlink(\$p);\n",
        ), 'the static-path scanner cannot follow a path bound before it is written');

        // Defaulted, through a property — the shape of the one real site.
        self::assertSame([5], self::staticTempPathWrites(
            "<?php\nclass A {\n"
            . "  public function __construct(?string \$f = null) { \$this->log = \$f ?? sys_get_temp_dir() . '/fixed.log'; }\n"
            . "  public function w(): void {\n"
            . "    file_put_contents(\$this->log, 'x');\n"
            . "  }\n}\n",
        ), 'the static-path scanner cannot follow a defaulted property, which is the tree\'s only real site');
    }

    // =========================================================================
    // Token plumbing
    // =========================================================================

    /** @param list<array{int,string,int}|string> $tokens */
    private static function significantNeighbour(array $tokens, int $from, int $direction): ?int
    {
        for ($j = $from + $direction; isset($tokens[$j]); $j += $direction) {
            if (\is_array($tokens[$j])
                && \in_array($tokens[$j][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    }

    /**
     * The index of the `)` matching the `(` at $open, or null.
     *
     * @param list<array{int,string,int}|string> $tokens
     */
    private static function matching(array $tokens, int $open): ?int
    {
        if (!isset($tokens[$open]) || self::text($tokens[$open]) !== '(') {
            return null;
        }

        $depth = 0;
        for ($j = $open, $n = \count($tokens); $j < $n; $j++) {
            $text = self::text($tokens[$j]);
            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return null;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private static function argumentEnd(array $tokens, int $from, int $close): int
    {
        $depth = 0;
        for ($j = $from; $j < $close; $j++) {
            $text = self::text($tokens[$j]);
            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                $depth--;
            } elseif ($depth === 0 && $text === ',') {
                return $j - 1;
            }
        }

        return $close - 1;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private static function statementEnd(array $tokens, int $from): int
    {
        $depth = 0;
        for ($j = $from, $n = \count($tokens); $j < $n; $j++) {
            $text = self::text($tokens[$j]);
            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                $depth--;
            } elseif ($depth === 0 && $text === ';') {
                return $j - 1;
            }
        }

        return \count($tokens) - 1;
    }

    /**
     * `$var` or `$this->prop` immediately left of the `=` at $at, or null.
     *
     * @param list<array{int,string,int}|string> $tokens
     */
    private static function assignmentTarget(array $tokens, int $at): ?string
    {
        if ($at >= 1 && \is_array($tokens[$at - 1]) && $tokens[$at - 1][0] === \T_VARIABLE) {
            if ($at >= 3 && \is_array($tokens[$at - 2]) && $tokens[$at - 2][0] === \T_OBJECT_OPERATOR) {
                return null;
            }

            return $tokens[$at - 1][1];
        }

        if ($at >= 3
            && \is_array($tokens[$at - 3]) && $tokens[$at - 3][0] === \T_VARIABLE && $tokens[$at - 3][1] === '$this'
            && \is_array($tokens[$at - 2]) && $tokens[$at - 2][0] === \T_OBJECT_OPERATOR
            && \is_array($tokens[$at - 1]) && $tokens[$at - 1][0] === \T_STRING) {
            return '$this->' . $tokens[$at - 1][1];
        }

        return null;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private static function referenceAt(array $tokens, int $at): ?string
    {
        if (!\is_array($tokens[$at]) || $tokens[$at][0] !== \T_VARIABLE) {
            return null;
        }
        if ($tokens[$at][1] === '$this'
            && isset($tokens[$at + 2])
            && \is_array($tokens[$at + 1]) && $tokens[$at + 1][0] === \T_OBJECT_OPERATOR
            && \is_array($tokens[$at + 2]) && $tokens[$at + 2][0] === \T_STRING) {
            return '$this->' . $tokens[$at + 2][1];
        }

        return $tokens[$at][1];
    }

    /**
     * The name of a global function call at $at, or null when $at is not one.
     *
     * @param list<array{int,string,int}|string> $tokens
     */
    private static function globalCallName(array $tokens, int $at): ?string
    {
        $name = self::callableName($tokens[$at] ?? null);
        if ($name === null) {
            return null;
        }
        if (!isset($tokens[$at + 1]) || self::text($tokens[$at + 1]) !== '(') {
            return null;
        }
        if ($at >= 1 && \is_array($tokens[$at - 1])
            && \in_array($tokens[$at - 1][0], self::MEMBER_OPERATORS, true)) {
            return null;
        }

        return $name;
    }

    /** @param array{int,string,int}|string|null $token */
    private static function callableName(array|string|null $token): ?string
    {
        if (!\is_array($token)) {
            return null;
        }
        if ($token[0] !== \T_STRING && $token[0] !== \T_NAME_FULLY_QUALIFIED) {
            return null;
        }

        return \ltrim($token[1], '\\');
    }

    /** @param array{int,string,int}|string $token */
    private static function text(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private static function lineOf(array $tokens, int $at): int
    {
        for ($j = $at; $j >= 0; $j--) {
            if (\is_array($tokens[$j])) {
                return $tokens[$j][2];
            }
        }

        return 0;
    }

    /**
     * $source without whitespace or comments.
     *
     * @return list<array{int,string,int}|string>
     */
    private static function significantTokens(string $source): array
    {
        $out = [];
        foreach (\token_get_all($source) as $token) {
            if (\is_array($token)
                && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $out[] = $token;
        }

        return $out;
    }

    /**
     * RULE 14 AT THE READ, not only at the parse. `(string) file_get_contents()`
     * turns an unreadable file into an empty one, an empty file into "no hits",
     * and "no hits" into a clean census — three silent steps from a permission
     * bit to a green suite.
     */
    private static function readOrFail(string $path): string
    {
        $text = file_get_contents($path);
        self::assertIsString($text, $path . ' is unreadable, so the census over it is void');

        return $text;
    }

    /**
     * Every scanned file, keyed by its path relative to the library root.
     *
     * @return array<string,string> relative path => absolute path
     */
    private static function filesInScope(): array
    {
        $root  = \dirname(__DIR__, 2);
        $found = [];

        foreach (self::SCOPE as $entry) {
            $path = $root . '/' . $entry;

            if (\is_file($path)) {
                $found[$entry] = $path;

                continue;
            }
            if (!\is_dir($path)) {
                continue;
            }

            /** @var \SplFileInfo $info */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $info) {
                if ($info->isFile() && $info->getExtension() === 'php') {
                    $found[substr($info->getPathname(), \strlen($root) + 1)] = $info->getPathname();
                }
            }
        }

        ksort($found);

        return $found;
    }
}

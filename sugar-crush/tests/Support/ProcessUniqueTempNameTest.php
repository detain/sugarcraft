<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * TWO WAYS A TEMP PATH COLLIDES BETWEEN PROCESSES, AND THE SECOND ONE IS THE
 * ONE THIS GUARD USED TO BE UNABLE TO EXPRESS.
 *
 * THE FIRST. A `uniqid` call with no more-entropy flag is derived from the
 * current microtime and NOTHING else, so it is not unique across processes.
 * THAT INCLUDES A CALL WITH A PREFIX, and mistaking arity for entropy is how
 * four `src/` sites sat outside this guard while its own sentence described
 * them: `uniqid($p)` returns `$p` followed by the SAME 13-hex microtime suffix
 * the bare call returns, so a literal prefix moves the collision without
 * removing it. Two suites
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
 * the fix, 0, 0 and 0 after it — that figure is NOT this file's own, it is
 * carried forward verbatim from the commit that landed the fix (`906fa666`)
 * and was verified against that commit message rather than re-run here. Rule 3
 * applies to a sentence you copy as much as to a number you generate, and a
 * measurement whose generator lives somewhere else has to say so or the next
 * reader will re-cite it as this file's evidence.
 *
 * A GUARD WHOSE ALPHABET IS THE TOKEN `uniqid` CANNOT EXPRESS THE SECOND SHAPE
 * BY CONSTRUCTION, so a sweep that fixed 91 call sites walked straight past it
 * and reported success. Rule 11: when a census reports zero, ask what its
 * alphabet cannot say. Both shapes now have a scanner here, and each scanner is
 * proved alive by something OTHER than a green tree — see
 * {@see STATIC_TEMP_PATH_INVENTORY}, whose single row is a file in scope that
 * the second scanner must keep finding.
 *
 * WHAT THAT SENTENCE SAID: the row was "a real site in `src/`", namely
 * `AuditHook`'s production default. WHAT IS TRUE NOW: E328 fixed that default
 * — it is a per-user directory the process creates 0700 — so `src/` has no
 * fixed shared temp path left, and an absence census with no positive input is
 * exactly the dead instrument rule 15 is about. The row is now
 * `tests/Support/Fixtures/StaticTempPathWalkControl.php`, a file written to be
 * found. WHY THE ARRANGEMENT STILL EARNS ITS PLACE, unchanged in form: the
 * claim being defended was never "a real site exists" — it was "the walk over
 * real files still reaches the matcher", and a purpose-built file in scope
 * carries that claim better than a production site, because nobody can close
 * it by improving production code.
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
     * Sites that build the name with no more-entropy flag and are meant to,
     * with the reason.
     *
     * NOT AN EXEMPTION LIST. Both directions are checked against the tree by
     * {@see testEveryEntropylessInventoryRowStillDescribesTheSitesItClaims()}:
     * a row whose sites have been fixed fails and must be deleted, and a site
     * with no row fails and must be argued or fixed. A deferral is a claim
     * about the tree, so the tree is asked.
     *
     * THE COUNT IS EXACT AND THAT IS DELIBERATE. A range would let a sixth site
     * arrive unremarked in a file that already has five.
     *
     * WHAT THIS ROSTER SAID: it was called ARGUMENTLESS_INVENTORY and held one
     * row, because the scanner beside it asked "does this call take no
     * arguments". WHAT IS TRUE NOW: a constant literal prefix is not an entropy
     * source — `uniqid($p)` is `$p` followed by the SAME 13-hex microtime
     * suffix the bare call returns — so four `src/` sites carrying one were
     * spared by a guard whose own stated subject describes them exactly, and
     * ONE OF THEM BUILDS A SubAgent ID IN THE SAME SHAPE the WorkflowEngine row
     * below spends six hundred words on. The predicate is now "no more-entropy
     * flag" and those four were rostered.
     *
     * AND THEN THEY WERE FIXED (E329), which is why four rows are gone from
     * here rather than rewritten: `src/Agents/AgentManager.php`,
     * `src/App/App.php`, `src/Hooks/ScriptHook.php` and
     * `src/Providers/ClaudeCodeProvider.php` now spell the call with the pid in
     * the prefix and the more-entropy flag set, so the scanner spares them on
     * their own tokens and a row for any of them would fail
     * {@see testEveryEntropylessInventoryRowStillDescribesTheSitesItClaims()}.
     * The WorkflowEngine row is deliberately left standing — it is E324, a
     * different entry with a different argument, and it is also what keeps this
     * channel's real-tree walk honest.
     *
     * WHY THE ROSTER STILL EARNS ITS PLACE UNCHANGED IN FORM: the argument that
     * a site is safe is a claim about the tree, and the tree is still asked in
     * both directions.
     *
     * @var array<string,array{sites:int,why:string}>
     */
    private const NO_ENTROPY_FLAG_INVENTORY = [
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
        'tests/Support/Fixtures/StaticTempPathWalkControl.php' => [
            'sites' => 1,
            'why' =>
                'THIS ROW IS NOT AN EXEMPTION. It is the scanner\'s real-tree control, and the '
                . 'file exists for no other reason: nothing calls it, its constructor and its '
                . 'one method are private, and the body spells the exact shape E298 took — a '
                . 'fully static temp path bound in one statement and written in another. '
                . 'WHAT THIS ROW SAID BEFORE: src/Hooks/BuiltIn/AuditHook.php, whose production '
                . 'default was one fixed name on the world-writable temp root, kept because "an '
                . 'audit log that moves every run is not an audit log" and a caller who wants a '
                . 'private one passes it in. WHAT IS TRUE NOW (E328): that argument survived and '
                . 'the path did not. The leaf is still fixed, so tail -f still works across '
                . 'runs, but it now sits inside a directory scoped to the effective uid which '
                . 'the hook creates 0700 and refuses to use when it is not its own — because '
                . 'the hazard was never two sugarcrush processes racing (measured on PHP 8.3.6: '
                . '8 processes x 200 appends x 9000 bytes under FILE_APPEND|LOCK_EX, three '
                . 'takes, 1600 intact lines every time and no truncation) but every OTHER user '
                . 'on the box being able to plant a symlink at that name and to read a log '
                . 'carrying tool arguments and 200 bytes of every tool output, which under the '
                . 'ordinary umask 0002 was created mode 0664. WHY A PURPOSE-BUILT FILE RATHER '
                . 'THAN THE NEXT REAL SITE: the previous row could be — and was — closed by '
                . 'somebody fixing production code, which is the one event that silently '
                . 'removes an absence census\'s only positive input. This one cannot be. '
                . 'DELETING IT IS NOT A FIX FOR ANYTHING: the ten synthetic fixtures in '
                . 'assertTheStaticPathScannerIsAlive() prove the MATCHER works and say nothing '
                . 'about whether filesInScope() still enumerates anything.',
        ],
    ];

    /** Global calls for which a fixed shared path in ANY argument is the hazard. */
    private const MUTATING_CALLS = [
        'file_put_contents', 'mkdir', 'touch', 'unlink', 'rmdir',
        'copy', 'rename', 'symlink', 'link', 'fopen', 'chmod',
    ];

    /**
     * Classes that OPEN a path from their constructor, which a list of global
     * function names cannot express.
     *
     * E242 — the incident in this file's own first paragraph — was two
     * processes meeting on one `tasklist_test_<id>.sqlite3`, and
     * `src/Agents/TaskList.php` reaches SQLite through `new \SQLite3($dbPath)`.
     * A scanner whose whole alphabet was global calls would not have seen that
     * shape EVEN WITH A FULLY FIXED PATH: the incident this census exists to
     * describe was outside the census. Rule 11 — a scanner's alphabet is part
     * of its coverage, and this one had been written to match the sites already
     * known.
     */
    private const MUTATING_CONSTRUCTORS = ['SQLite3', 'PDO'];

    /** {@see classifyStaticPath()}: not a static temp path at all. */
    private const PATH_NOT_STATIC = 0;

    /**
     * {@see classifyStaticPath()}: the temp ROOT and nothing appended.
     *
     * NOT A HAZARD BY ITSELF, and conflating it with one is the defect this
     * three-way answer replaced. `$d = sys_get_temp_dir();` used to bind `$d`
     * as a fixed shared path, after which EVERY later appearance of `$d` in a
     * mutating call's argument list was reported — including
     * `$d . '/x_' . bin2hex(random_bytes(8))`, whose whole point is that it is
     * not fixed. The author of the next entropic path would have been told
     * their entropic path has no entropy source.
     */
    private const PATH_TEMP_ROOT = 1;

    /** {@see classifyStaticPath()}: a temp root plus a fixed leaf — the hazard. */
    private const PATH_FIXED_FILE = 2;

    /** Token ids that make a following name a member call rather than a global one. */
    private const MEMBER_OPERATORS = [
        \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION,
    ];

    // =========================================================================
    // Channel 1 — the call with no more-entropy flag
    // =========================================================================

    public function testNoFileMakesAProcessColludingTempNameOutsideTheInventory(): void
    {
        $this->assertTheUniqidScannerIsAlive();

        $offenders = [];
        $problems  = [];
        $counts    = [];

        foreach (self::filesInScope() as $relative => $absolute) {
            [$lines, $unparseable] = self::entropylessSites(self::readOrFail($absolute));

            foreach ($unparseable as $problem) {
                $problems[] = $relative . ': ' . $problem;
            }
            if ($lines === []) {
                continue;
            }

            $counts[$relative] = \count($lines);
            if (isset(self::NO_ENTROPY_FLAG_INVENTORY[$relative])) {
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
            . "find where its arguments end, so it cannot say whether the call carries the flag. "
            . "Reported rather than skipped: a site silently dropped is one this guard has "
            . "stopped covering, which is indistinguishable from one it has cleared.\n  %s",
            \count($problems),
            \implode("\n  ", $problems),
        ));

        self::assertSame([], $offenders, self::entropylessMessage($offenders));

        foreach (self::NO_ENTROPY_FLAG_INVENTORY as $file => $row) {
            self::assertSame($row['sites'], $counts[$file] ?? 0, $file
                . ' has a different number of flagless calls than its inventory row claims. '
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
    public function testEveryEntropylessInventoryRowStillDescribesTheSitesItClaims(): void
    {
        $this->assertTheUniqidScannerIsAlive();

        $files = self::filesInScope();

        foreach (self::NO_ENTROPY_FLAG_INVENTORY as $relative => $row) {
            self::assertNotSame('', trim($row['why']), $relative . ' is inventoried without a reason');
            self::assertArrayHasKey($relative, $files, $relative
                . ' is inventoried and is no longer in the census scope at all. Delete the row.');

            [$lines] = self::entropylessSites(self::readOrFail($files[$relative]));

            self::assertNotSame([], $lines, $relative
                . ' is inventoried as making the flagless call and no longer makes it. '
                . 'Either the sites were fixed — delete the row — or this scanner has stopped '
                . 'seeing them, in which case every "nothing found" answer beside it is a '
                . 'statement about a dead walk.');
        }
    }

    /**
     * KNOWN-ANSWER CONTROL FOR EVERY SPELLING, POSITIVE AND NEGATIVE.
     *
     * The two spellings that matter are the bare name and the FULLY QUALIFIED
     * one. The scanner keyed on `T_STRING` alone, and PHP 8 lexes a
     * leading-backslash call as `T_NAME_FULLY_QUALIFIED`, so the qualified
     * spelling was invisible to it.
     *
     * WHAT THIS SAID: that the qualified spelling "is this codebase's own house
     * style", making the old scanner "a guard covering the minority of its own
     * subject". WHAT IS TRUE, measured through {@see callSpellings()} over
     * {@see filesInScope()} on PHP 8.3.6: the qualified spelling is the
     * MINORITY by a wide margin and the bare one is the house style, so the old
     * scanner covered the large majority of its subject and the widening closes
     * the remainder. WHY THE WIDENING STILL EARNS ITS PLACE: a hole is not
     * excused by being small — the sites it left open are ordinary calls in
     * ordinary files, and the arm costs one token id. The reason was wrong; the
     * change was not. NEITHER SHARE IS WRITTEN DOWN HERE — a cardinality taken
     * in a lane worktree is void at the next merge — and the RELATIONSHIP this
     * paragraph now rests on is asserted rather than narrated by
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

        $source = "<?php\n"                                    // 1
            . "\$a = {$bare}();\n"                             // 2  bare, offending
            . "\$b = \\{$bare}();\n"                           // 3  fully qualified, offending
            . "\$c = {$bare} (   );\n"                         // 4  spaced, offending
            . "\$d = {$bare}(\n);\n"                           // 5  split over lines, offending
            . "\$e = {$bare}('prefix');\n"                     // 7  LITERAL PREFIX, offending
            . "\$e2 = {$bare}('a' . 'b');\n"                   // 8  literal concat, offending
            . "\$e3 = {$bare}('prefix' , false );\n"           // 9  flag false, offending
            . "\$f = \\{$bare}((string) \\getmypid(), true);\n"  // 10 spared: the flag
            . "\$f2 = {$bare}('', true);\n"                    // 11 spared: the flag
            . "\$g = \$o->{$bare}();\n"                        // 12 spared: a method
            . "\$h = Foo::{$bare}();\n"                        // 13 spared: a static method
            . "\$i = '{$bare}';\n"                             // 14 spared: a string
            . "function {$bare}() { return 1; }\n";            // 15 spared: a declaration

        [$lines, $problems] = self::entropylessSites($source);

        self::assertSame([2, 3, 4, 5, 7, 8, 9], $lines, 'the scanner does not see every '
            . 'offending spelling. A LITERAL PREFIX IS ONE OF THEM: it is not an entropy '
            . 'source, so a call carrying one collides across processes exactly as the bare '
            . 'call does, and the four src/ sites shaped that way were spared by a predicate '
            . 'that asked about arity instead of about entropy.');
        self::assertSame([], $problems, 'a well-formed fixture was reported as unparseable');

        // ...AND WHAT THE SCANNER CANNOT DECIDE, IT SAYS SO ABOUT. A computed
        // prefix may or may not carry cross-process entropy and a computed flag
        // may or may not be set; clearing either would be a guess in the
        // direction that leaves a hole, and condemning either would be a guess
        // in the direction that trains people to write exemptions.
        [$lines, $problems] = self::entropylessSites(
            "<?php\n"
            . "\$a = {$bare}(\$prefix);\n"                    // 2 undecidable prefix
            . "\$b = {$bare}('p', \$flag);\n"                 // 3 undecidable flag
            . "\$c = {$bare}(self::PREFIX);\n",               // 4 undecidable: a constant
        );

        self::assertSame([], $lines, 'a call this scanner cannot evaluate was reported as a '
            . 'definite offender, which is a guess dressed as a finding');
        self::assertCount(3, $problems, 'a call this scanner cannot evaluate was walked past '
            . 'instead of being reported. A guard that quietly ignores the undecidable has a '
            . 'hole shaped exactly like the next defect.');
        self::assertStringContainsString('$prefix', $problems[0]);
        self::assertStringContainsString('$flag', $problems[1]);
        self::assertStringContainsString('self::PREFIX', $problems[2]);
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
     * file written that way puts it back.
     *
     * AND IT ASSERTS THE RELATIONSHIP THE PARAGRAPH BESIDE IT RESTS ON, not
     * merely that the arm is non-dormant. The doc-block on
     * {@see testTheUniqidScannerSeesEverySpellingAndSparesTheGoodOnes()} used
     * to claim the qualified spelling was the house style, and a test asserting
     * only "more than zero" passes whether that is true or false — which is how
     * the claim stood, repeated into a commit message and a report, while the
     * tree said the opposite. A RELATIONSHIP rather than a share is what can
     * honestly be asserted here: a percentage taken in a lane worktree is void
     * at the next merge, but "the bare spelling outnumbers the qualified one"
     * is a fact about the tree that survives a merge and reds if the house
     * style ever really does flip — at which point the paragraph gets rewritten
     * instead of quietly becoming true by accident.
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
                . 'but the T_NAME_FULLY_QUALIFIED arm in entropylessSites() is now dormant and '
                . 'the next reader will take it for dead code. Leave it — the arm is what makes '
                . 'the guard cover every spelling — and rewrite this test\'s reason rather '
                . 'than deleting the arm.',
        );

        self::assertGreaterThan(
            $qualified,
            $bare,
            'the fully-qualified spelling now outnumbers the bare one. Nothing is broken, but '
                . 'the doc-block on testTheUniqidScannerSeesEverySpellingAndSparesTheGoodOnes() '
                . 'says the bare spelling is the house style, and that is no longer what the '
                . 'tree says. Rewrite the paragraph against a fresh measurement — do NOT relax '
                . 'this assertion to make the old sentence true again, which is the repair this '
                . 'failure invites and the one that left an inverted claim standing in a '
                . 'doc-block, a commit message and a report at the same time.',
        );
    }

    /**
     * `qualified` or `bare` per call site of the name, for the measurement
     * above. Shares {@see entropylessSites()}'s notion of what a call is, so
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

        [$lines, $problems] = self::entropylessSites("<?php\n\$a = {$bare}(\n");

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
     * The 1-indexed lines of every call with no more-entropy flag, plus the
     * sites this scanner could not delimit or could not evaluate.
     *
     * TOKENS AND NOT A LINE REGEX. The line-regex form this replaced could not
     * see a call split across lines, could not see the fully-qualified
     * spelling, matched the name inside a comment or a string, and — the part
     * that mattered — had no way to say "I found the name and could not read
     * the call", so it answered "clean" for anything it could not parse.
     *
     * @return array{list<int>, list<string>} offending lines, problems
     */
    private static function entropylessSites(string $source): array
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

            // THE PREDICATE IS "NO MORE-ENTROPY FLAG", NOT "NO ARGUMENTS".
            // `uniqid($p)` is `$p . sprintf('%08x%05x', sec, usec)` — the SAME
            // 13-hex microtime suffix the bare call returns — so a constant
            // literal prefix contributes exactly zero cross-process entropy and
            // a call carrying one is the very thing this guard's own sentence
            // describes. Measured on PHP 8.3.6: 20000/20000 prefixed values
            // carry a bare 13-char suffix, and two prefixes at one instant give
            // `A_6a8bc9d22f617` / `B_6a8bc9d22f618`. The flag is the only
            // argument that changes the answer.
            $arguments = self::argumentSlices($tokens, $open, $close);

            if (isset($arguments[1])) {
                $flag = self::sliceText($tokens, $arguments[1][0], $arguments[1][1]);

                if (\strtolower($flag) === 'true') {
                    continue;
                }

                if (\strtolower($flag) !== 'false') {
                    // RULE 14: the flag decides the verdict and this one is not
                    // a literal, so the verdict is unknown. Saying so is the
                    // whole difference between a guard and a filter.
                    $problems[] = 'line ' . $token[2] . ': the more-entropy flag is `' . $flag
                        . '`, which this scanner cannot evaluate, so it can say neither that '
                        . 'this call is safe nor that it is not';

                    continue;
                }
            }

            if (!isset($arguments[0])) {
                $lines[] = $token[2];

                continue;
            }

            [$from, $to] = $arguments[0];
            if (self::isConstantStringExpression($tokens, $from, $to)) {
                // A literal prefix. Same microtime suffix, same collision.
                $lines[] = $token[2];

                continue;
            }

            // A computed prefix MIGHT carry cross-process entropy — a pid, a
            // session id — and might be a constant reached through a name.
            // Neither is decidable here, so it is reported rather than guessed
            // in either direction.
            $problems[] = 'line ' . $token[2] . ': the prefix is `'
                . self::sliceText($tokens, $from, $to) . '` and carries no more-entropy flag. '
                . 'This scanner can only prove a LITERAL prefix contributes nothing; whether '
                . 'this one carries cross-process entropy is a question about its value';
        }

        return [$lines, $problems];
    }

    /**
     * The `[from, to]` token index pair of each argument in the call whose
     * parentheses are $open..$close, splitting on depth-0 commas.
     *
     * Shares {@see argumentEnd()}'s notion of where one argument stops, so the
     * two cannot drift into two definitions of an argument list.
     *
     * @param  list<array{int,string,int}|string> $tokens
     * @return list<array{int,int}>
     */
    private static function argumentSlices(array $tokens, int $open, int $close): array
    {
        $slices = [];
        $from   = self::significantNeighbour($tokens, $open, 1);

        if ($from === null || $from >= $close) {
            return $slices;
        }

        while ($from < $close) {
            // argumentEnd() stops BEFORE the delimiter, so a slice can END in a
            // whitespace or comment run. IT IS DELIBERATELY NOT TRIMMED HERE:
            // both readers of a slice — {@see sliceText()} and
            // {@see isConstantStringExpression()} — skip insignificant tokens
            // themselves, so a trim could not change any answer. It was written
            // and then removed after the mutation that deleted it SURVIVED,
            // which is the only evidence that would have settled it.
            $to = self::argumentEnd($tokens, $from, $close);

            if ($to < $from) {
                break;
            }

            $slices[] = [$from, $to];

            $next = self::significantNeighbour($tokens, $to, 1);
            if ($next === null || $next >= $close || self::text($tokens[$next]) !== ',') {
                break;
            }

            $after = self::significantNeighbour($tokens, $next, 1);
            if ($after === null || $after >= $close) {
                // A trailing comma. `uniqid('x',)` is one argument, not two.
                break;
            }

            $from = $after;
        }

        return $slices;
    }

    /**
     * Whether tokens $from..$to are nothing but quoted literals joined by `.`.
     *
     * THE BOUND IS "PROVABLY CONSTANT", not "looks constant". A concatenation
     * of literals is decidable from the tokens alone and contributes no
     * cross-process entropy, so it belongs with the bare call. A name, a call
     * or a variable anywhere in the expression is NOT decidable here and takes
     * the other branch, where it is reported rather than guessed at — a
     * constant reached through a class constant would be wrongly cleared, and a
     * pid reached through a variable wrongly condemned, if this tried.
     *
     * @param list<array{int,string,int}|string> $tokens
     */
    private static function isConstantStringExpression(array $tokens, int $from, int $to): bool
    {
        $sawLiteral = false;

        for ($j = $from; $j <= $to; $j++) {
            $token = $tokens[$j] ?? null;
            if ($token === null) {
                return false;
            }
            if (\is_array($token)
                && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            if (\is_array($token) && $token[0] === \T_CONSTANT_ENCAPSED_STRING) {
                $sawLiteral = true;

                continue;
            }
            if (self::text($token) === '.') {
                continue;
            }

            return false;
        }

        return $sawLiteral;
    }

    /**
     * The source text of tokens $from..$to with insignificant runs dropped, for
     * comparing an argument against a literal and for quoting it in a message.
     *
     * @param list<array{int,string,int}|string> $tokens
     */
    private static function sliceText(array $tokens, int $from, int $to): string
    {
        $text = '';

        for ($j = $from; $j <= $to; $j++) {
            if (!isset($tokens[$j])) {
                break;
            }
            if (\is_array($tokens[$j])
                && \in_array($tokens[$j][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $text .= self::text($tokens[$j]);
        }

        return $text;
    }

    /**
     * The lines at which a FULLY STATIC temp path reaches a filesystem-mutating
     * call — inline in any argument, or through a binding in the file.
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

        $roots = [];
        $paths = [];
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

            // A BINDING CARRIES ITS CLASSIFICATION, it does not collapse to a
            // yes. A name bound to the bare temp ROOT is a directory, and what
            // gets appended to it at the write site is what decides; a name
            // bound to a COMPLETE fixed path is the hazard wherever it appears.
            switch (self::classifyAnyStaticBranch($tokens, $i + 1, $end, $roots, $paths)) {
                case self::PATH_FIXED_FILE:
                    $paths[$target] = true;

                    break;
                case self::PATH_TEMP_ROOT:
                    $roots[$target] = true;

                    break;
            }
        }

        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            $open = self::mutatingCallOpener($tokens, $i);
            if ($open === null) {
                continue;
            }

            $close = self::matching($tokens, $open);
            if ($close === null) {
                continue;
            }

            // EVERY argument, not only the first: `rename($from, $fixed)` puts
            // the shared path second, and `symlink()` puts the one that gets
            // created second.
            foreach (self::argumentSlices($tokens, $open, $close) as [$from, $to]) {
                if (self::classifyAnyStaticBranch($tokens, $from, $to, $roots, $paths)
                    === self::PATH_FIXED_FILE) {
                    $lines[] = self::lineOf($tokens, $i);

                    break;
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
    private static function classifyAnyStaticBranch(
        array $tokens,
        int $from,
        int $to,
        array $rootVariables,
        array $pathVariables,
    ): int {
        $depth = 0;
        $start = $from;
        $best  = self::PATH_NOT_STATIC;

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
                if ($start <= $j - 1) {
                    $best = max($best, self::classifyStaticPath(
                        $tokens,
                        $start,
                        $j - 1,
                        $rootVariables,
                        $pathVariables,
                    ));
                }
                $start = $j + 1;
            }
        }

        if ($start <= $to) {
            $best = max($best, self::classifyStaticPath(
                $tokens,
                $start,
                $to,
                $rootVariables,
                $pathVariables,
            ));
        }

        return $best;
    }

    /**
     * The index of the `(` opening a filesystem-mutating call at $at, or null.
     *
     * Covers a global function from {@see MUTATING_CALLS} and a `new` of a
     * class from {@see MUTATING_CONSTRUCTORS}, because the shape E242 actually
     * took was a constructor and a list of function names cannot say so.
     *
     * @param list<array{int,string,int}|string> $tokens
     */
    private static function mutatingCallOpener(array $tokens, int $at): ?int
    {
        $name = self::globalCallName($tokens, $at);
        if ($name !== null && \in_array($name, self::MUTATING_CALLS, true)) {
            return $at + 1;
        }

        if (!\is_array($tokens[$at]) || $tokens[$at][0] !== \T_NEW) {
            return null;
        }

        $classAt = $at + 1;
        if (!isset($tokens[$classAt]) || !\is_array($tokens[$classAt])) {
            return null;
        }
        if (!\in_array($tokens[$classAt][0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }
        if (!\in_array(\ltrim($tokens[$classAt][1], '\\'), self::MUTATING_CONSTRUCTORS, true)) {
            return null;
        }
        if (!isset($tokens[$classAt + 1]) || self::text($tokens[$classAt + 1]) !== '(') {
            return null;
        }

        return $classAt + 1;
    }

    /**
     * Whether tokens[$from..$to] is a temp root concatenated with literals and
     * nothing else, and if so whether anything is appended to the root.
     *
     * THE ANSWER IS THREE-WAY BECAUSE A DIRECTORY IS NOT A FILE. Returning a
     * bare yes/no made `sys_get_temp_dir()` on its own indistinguishable from
     * `sys_get_temp_dir() . '/fixed.log'`, and since a binding of the former is
     * the ordinary idiom, every write through that name was reported however
     * much entropy the write site added. See {@see PATH_TEMP_ROOT}.
     *
     * WHAT THIS ALPHABET STILL CANNOT EXPRESS, measured rather than assumed and
     * pinned by {@see testTheStaticPathScannerSaysWhatItCannotSee()}: a path
     * built in a heredoc, one reached through a class constant, and a path
     * handed to a call outside {@see MUTATING_CALLS} and
     * {@see MUTATING_CONSTRUCTORS} — `proc_open()`'s cwd, a unix socket URI.
     * Each reads as NOT_STATIC, which is the safe direction for a false
     * negative and the reason the bound is written down here rather than
     * discovered later. A REBINDING IS NOT ON THAT LIST, and the first draft of
     * this paragraph said it was: `$b = $a;` classifies `$a` and carries the
     * answer to `$b`, so a chain of plain bindings is followed to any depth.
     * Checked before shipping the sentence, not after.
     *
     * @param  list<array{int,string,int}|string> $tokens
     * @param  array<string,true>                 $rootVariables names bound to a bare temp root
     * @param  array<string,true>                 $pathVariables names bound to a complete fixed path
     * @return self::PATH_*
     */
    private static function classifyStaticPath(
        array $tokens,
        int $from,
        int $to,
        array $rootVariables = [],
        array $pathVariables = [],
    ): int {
        $sawRoot = false;
        $sawLeaf = false;

        for ($j = $from; $j <= $to; $j++) {
            $token = $tokens[$j];

            if (\is_array($token) && $token[0] === \T_CONSTANT_ENCAPSED_STRING) {
                $content = \substr($token[1], 1, -1);

                if (preg_match('#^/(?:var/)?tmp/#', $content) === 1) {
                    $sawRoot = true;
                }
                if (trim(preg_replace('#^/(?:var/)?tmp/#', '', $content) ?? '', '/') !== '') {
                    $sawLeaf = true;
                }

                continue;
            }

            if (\is_array($token) && $token[0] === \T_VARIABLE) {
                $reference = self::referenceAt($tokens, $j);
                if ($reference === null) {
                    return self::PATH_NOT_STATIC;
                }

                if (isset($pathVariables[$reference])) {
                    $sawRoot = true;
                    $sawLeaf = true;
                } elseif (isset($rootVariables[$reference])) {
                    $sawRoot = true;
                } else {
                    return self::PATH_NOT_STATIC;
                }

                // `$this->logFile` is three tokens; stepping over only the
                // first would hand `logFile` to the callable-name arm below.
                if ($reference !== $token[1]) {
                    $j += 2;
                }

                continue;
            }

            $name = self::callableName($token);
            if ($name === 'sys_get_temp_dir') {
                $sawRoot = true;

                continue;
            }
            if ($name === 'DIRECTORY_SEPARATOR') {
                // A separator carries no entropy and is not a leaf on its own.
                continue;
            }
            if ($name !== null) {
                return self::PATH_NOT_STATIC;
            }
            if (\in_array(self::text($token), ['.', '(', ')'], true)) {
                continue;
            }

            return self::PATH_NOT_STATIC;
        }

        if (!$sawRoot) {
            return self::PATH_NOT_STATIC;
        }

        return $sawLeaf ? self::PATH_FIXED_FILE : self::PATH_TEMP_ROOT;
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
    private static function entropylessMessage(array $offenders): string
    {
        return \sprintf(
            "%d call(s) with no more-entropy flag found outside the inventory. That form is "
            . "microtime-derived and NOT unique across processes, and a CONSTANT LITERAL PREFIX "
            . "does not change that — the suffix is the same 13 hex characters the bare call "
            . "returns, so `uniqid('a_')` and `uniqid('b_')` in two processes at one instant "
            . "differ only where you told them to. Under the shared TMPDIR two concurrent "
            . "suites collide on one path. Pass a pid prefix AND the more-entropy flag — or, if "
            . "the collision genuinely cannot happen, add the file to NO_ENTROPY_FLAG_INVENTORY "
            . "with the MEASUREMENT that shows so.\n  %s",
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

        foreach ([self::entropylessMessage($rows), self::staticPathMessage($rows)] as $message) {
            self::assertStringContainsString('2 ', $message, 'the count is not the population\'s');
            self::assertStringContainsString('src/One.php:11', $message, 'the first row is not named');
            self::assertStringContainsString('tests/Two.php:22', $message, 'the second row is not named');
        }

        self::assertStringContainsString('NO_ENTROPY_FLAG_INVENTORY', self::entropylessMessage($rows));
        self::assertStringContainsString('entropy', self::staticPathMessage($rows));

        // The two texts must not be the same text. They are handed to a reader
        // who has to tell which of the two hazards fired, and a copy-paste that
        // left one of them naming the other is invisible on a green run.
        self::assertNotSame(self::entropylessMessage($rows), self::staticPathMessage($rows));

        // AND THE EMPTY CASE, which is the one the green suite really does hand
        // them on every run, still reads as prose rather than crashing.
        self::assertStringContainsString('0 ', self::entropylessMessage([]));
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

        [$lines] = self::entropylessSites("<?php\n\$a = \\{$bare}();\n");
        self::assertSame([2], $lines, 'the flagless-call scanner is not reporting a known offender');

        // THE PREFIXED FORM IS PART OF THE KNOWN-POSITIVE, not only of the
        // fixture table. The predicate that shipped first asked about ARITY,
        // and every control beside it used the bare call — so a scanner that
        // had silently gone back to counting arguments would still pass its own
        // liveness check while four real sites walked past it.
        [$prefixed] = self::entropylessSites("<?php\n\$a = {$bare}('subagent_');\n");
        self::assertSame([2], $prefixed, 'the flagless-call scanner does not report a LITERAL '
            . 'PREFIX with no more-entropy flag, which is the same microtime value with a '
            . 'different first few bytes and the shape four src/ sites are in');

        [$spared] = self::entropylessSites("<?php\n\$a = {$bare}('p', true);\n");
        self::assertSame([], $spared, 'the flagless-call scanner reports a call that carries entropy');
    }

    /** The same, for the static-path scanner. */
    private function assertTheStaticPathScannerIsAlive(): void
    {
        // Inline, in the first argument.
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

        // A CONSTRUCTOR, because the incident this census narrates was one.
        self::assertSame([2], self::staticTempPathWrites(
            "<?php\n\$db = new \\SQLite3(sys_get_temp_dir() . '/tasklist.sqlite3');\n",
        ), 'the static-path scanner does not see a fixed path opened by a constructor, which is '
            . 'the shape E242 actually took — src/Agents/TaskList.php reaches SQLite through '
            . 'new \\SQLite3($dbPath), and a scanner whose alphabet is global function names '
            . 'would have missed that incident even with a fully fixed path');

        // AND THE NEGATIVE THAT THE THREE-WAY ANSWER EXISTS FOR. A name bound
        // to the bare temp ROOT is a directory; what the write site appends is
        // what decides. Reporting this was M5: the author of an entropic path
        // would have been told their entropic path has no entropy source.
        self::assertSame([], self::staticTempPathWrites(
            "<?php\n\$d = sys_get_temp_dir();\n"
            . "file_put_contents(\$d . '/x_' . bin2hex(random_bytes(8)), 'y');\n",
        ), 'a bare temp-root binding still condemns every write through that name, whatever the '
            . 'write site concatenates');

        self::assertSame([3], self::staticTempPathWrites(
            "<?php\n\$d = sys_get_temp_dir();\nfile_put_contents(\$d . '/fixed.log', 'x');\n",
        ), 'a bare temp-root binding followed by a FIXED leaf at the write site is the hazard '
            . 'and must still be reported — sparing the root must not spare the file');

        // ...AND THIS IS THE CASE THAT ACTUALLY SEPARATES THE TWO. The pair
        // above does not: an entropic write site is refused on its own tokens
        // whatever the binding was classified as, so a mutation collapsing
        // PATH_TEMP_ROOT into PATH_FIXED_FILE SURVIVED them both. The binding's
        // classification is only ever observable when the bound name is the
        // WHOLE path — `mkdir($d)` on the temp directory itself, which is not a
        // shared-file hazard and must not be reported as one.
        self::assertSame([], self::staticTempPathWrites(
            "<?php\n\$d = sys_get_temp_dir();\nmkdir(\$d);\n",
        ), 'the temp DIRECTORY itself, bound and then written whole, was reported as a fixed '
            . 'shared file path. A directory is not the hazard; the fixed leaf inside it is.');

        self::assertSame([], self::staticTempPathWrites(
            "<?php\n\$d = sys_get_temp_dir();\n\$e = \$d;\nrmdir(\$e);\n",
        ), 'the classification did not survive a plain rebinding, so a chain of bindings is '
            . 'either not followed or not carried faithfully');

        // EVERY ARGUMENT, NOT ONLY THE FIRST. `rename()` and `symlink()` put
        // the path that gets CREATED second, and the walk this replaced covered
        // them by sweeping the whole argument list for a bound name — so
        // classifying only the first argument would have been a capability
        // quietly lost in a change that reads as a tightening. The mutation
        // that restricted the walk to argument one SURVIVED until this ran.
        self::assertSame([2], self::staticTempPathWrites(
            "<?php\nrename(\$tmp, sys_get_temp_dir() . '/fixed.log');\n",
        ), 'a fixed shared path in rename()\'s SECOND argument — the name that actually gets '
            . 'created — was not reported');

        self::assertSame([3], self::staticTempPathWrites(
            "<?php\n\$p = sys_get_temp_dir() . '/fixed.link';\nsymlink(\$target, \$p);\n",
        ), 'a bound fixed path in symlink()\'s second argument was not reported');
    }

    /**
     * THE ALPHABET'S OUTSIDE, ASSERTED RATHER THAN NARRATED.
     *
     * Rule 11: a scanner's alphabet is part of its coverage, and it is usually
     * written to match the cases already known. A paragraph listing what a
     * scanner cannot see is prose, and prose is not a measurement — it drifts
     * silently in both directions, and a hole that quietly CLOSES is how a
     * stated bound becomes a lie that reads as modesty.
     *
     * Each shape here is checked to be genuinely missed. THE POINT IS NOT THAT
     * MISSING THEM IS RIGHT — it is not; three of them are real hazards. The
     * point is that the bound is derived on every run, so widening the alphabet
     * reds this test and forces the paragraph above to be rewritten with it.
     */
    public function testTheStaticPathScannerSaysWhatItCannotSee(): void
    {
        $missed = [
            'a heredoc path' =>
                "<?php\nfile_put_contents(<<<T\n/tmp/fixed.log\nT, 'x');\n",
            'a path reached through a class constant' =>
                "<?php\nfile_put_contents(self::LOG_PATH, 'x');\n",
            'a unix socket URI, whose literal does not begin at the root' =>
                "<?php\nstream_socket_server('unix:///tmp/fixed.sock');\n",
            'a proc_open() cwd' =>
                "<?php\nproc_open('ls', [], \$pipes, '/tmp/fixed-dir');\n",
        ];

        foreach ($missed as $why => $source) {
            self::assertSame([], self::staticTempPathWrites($source), $why . ' is now SEEN by the '
                . 'static-path scanner. That is an improvement, not a failure — widen the '
                . 'paragraph on classifyStaticPath() that lists this as outside the alphabet, '
                . 'and delete this row. Do not narrow the scanner to make this pass.');
        }

        // ...AND THE CONTROL THAT KEEPS THE FOUR ABOVE FROM BEING VACUOUS.
        // Rule 25: `[]` is also what a deleted scanner returns, so every one of
        // those rows would pass in a tree where this instrument is dead.
        self::assertSame([2], self::staticTempPathWrites(
            "<?php\nfile_put_contents('/tmp/fixed.log', 'x');\n",
        ), 'the scanner is dead, so the four "cannot see" rows above prove nothing at all');
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

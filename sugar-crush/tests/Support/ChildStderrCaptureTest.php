<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * No child process launched from a directory in {@see SCOPE} may leave its
 * stderr on the suite's.
 *
 * The heading used to name `tests/Integration/`, which was the whole of
 * SCOPE when this file was written and has been three directories and then
 * six since. The constant is the list; a heading that repeats it is a second
 * copy that goes stale on the commit that widens the first.
 *
 * WHAT THIS WAS COMMISSIONED TO FIX, AND WHAT WAS ACTUALLY THERE. Round 45
 * recorded the suite's `sugarcrush: ` stderr lines as a HARNESS property -
 * "child-process launches whose stderr the PHPUnit process inherits rather
 * than keeping on the pipe the test already reads" - and named
 * `tests/Integration/BinSugarcrushDispatchTest.php` and
 * `tests/Integration/McpToolWiringTest.php` among the owning files.
 *
 * Neither owns what it was said to. Measured at 62f4e5d1 on PHP 8.3.6, one
 * file per run, `vendor/bin/phpunit <file> 2>&1 | grep -ac 'sugarcrush: '`
 * (the `-a` is not optional - plain `grep` calls that log binary and prints
 * NOTHING, which reads exactly like a real zero): BinSugarcrushDispatchTest
 * emits NONE AT ALL, its `runBin()` having piped fd 2 all along, and
 * McpToolWiringTest emits EXACTLY ONE.
 *
 * The counts are stated as none and exactly one rather than as integers on
 * purpose. A number measured over `tests/` is invalidated by the next lane
 * that merges, and the load-bearing claim here is not the size of the figure
 * but its shape: the prescribed mechanism had nothing to remove. The command
 * above regenerates both at any commit.
 *
 * And that one line is not a child's. It is an in-process
 * `fwrite(\STDERR, ...)` reached from
 * `testAClientWhoseConfigThrewPartWayThroughIsStillReachableByTheShutdownSeam()`,
 * which already argues at length for accepting exactly one such line and pins
 * the count by reading the growth of `Bootstrap`'s own de-dup map. Per-spawn
 * redirection cannot touch it, and silencing it was rejected there on its
 * merits.
 *
 * The mechanism behind the bulk of the suite's stderr lines is that same one:
 * in-process `fwrite(\STDERR, ...)`. `src/Cli/NonInteractive.php` writes on it
 * directly in several places, and the two test files that drive it hardest -
 * `tests/Cli/NonInteractiveProviderFailureTest.php` and
 * `tests/Cli/NonInteractiveTest.php` - account between them for most of what
 * remains, with no child process anywhere in either. Closing those needs a
 * stderr sink seam in `src/`, not a descriptor spec in a test.
 *
 * SO WHAT THIS FILE IS. The spawn sites under `tests/Integration/` were
 * already clean, every one of them, and nothing was keeping them that way.
 * This turns "we looked, and they all pipe fd 2" into something that stays
 * true - and gives the round that does own `tests/Cli/` a scanner it can point
 * at its own directory by widening one constant.
 *
 * NOT A SILENCER, and that is now enforced rather than asserted. WHAT THIS
 * SAID: "{@see ChildStderrCaptureScanner} treats `2>/dev/null` as captured
 * because it cannot tell a sink from a file, but the standard this file is
 * defending is 'the test can read it'." WHAT IS TRUE NOW: the scanner reports
 * the null device as {@see ChildStderrCaptureScanner::SHAPE_DISCARDED}, which
 * this guard reds like any other non-capture, and the two sites under
 * `tests/Integration/` that it found are dealt with - one converted to write
 * fd 2 to a real file, one argued in {@see ACCEPTED_DISCARDED_STDERR}. WHY THE
 * OLD SENTENCE STILL EARNS ITS PLACE: the general form of the limit has not
 * moved. `2>$path` is a file to this scanner whatever `$path` holds, so the
 * standard is still wider than the mechanism, and a reader who takes a green
 * run as proof that every child's stderr is readable is taking more than the
 * scanner offers.
 */
final class ChildStderrCaptureTest extends TestCase
{
    /**
     * Path prefixes, relative to `tests/`, the rule is enforced under.
     *
     * WHAT THIS WAS: three prefixes - `Agents/`, `Integration/`, `Support/` -
     * the directories round 47's lane split gave that lane, widened from the
     * single `Integration/` that was all anyone had censused. That `Chat/`
     * and `MCP/` had been measured clean and were "free to adopt" was
     * recorded in the hardening backlog and NOT here - attributed correctly
     * because a reader who goes looking for it in this file's history will
     * not find it, and a "WHAT IT SAID" that was never said is the same rot
     * as a stale one.
     *
     * WHAT IS TRUE NOW: round 48 owned them and adopted both, plus
     * `Backend/`. The census was re-run rather than inherited - a count taken
     * in one lane's worktree is invalidated by the next merge, so the claim
     * was re-derived at this commit with this file's own scanner over the
     * whole of `tests/`. `Chat/` and `MCP/` held non-captures: NONE.
     * `Backend/` held exactly one, `EngineBackendTest::isRaw()`'s
     * `stty ... 2>/dev/null` - a COPY of the helper round 47 fixed in
     * `Support/ForkedChildTest.php`, sitting one directory away with the
     * opposite behaviour because it was in no lane's file list. It was FIXED
     * the same way rather than exempted, so the widening added no row at all
     * to {@see ACCEPTED_DISCARDED_STDERR}. (The count of prefixes is not
     * written out here: it was wrong in the commit that shipped it, said
     * "five" over a list of six, and it is a number a reader can take from
     * {@see SCOPE} itself.)
     *
     * WHY THE REMAINDER IS STILL OUT. This paragraph used to end the matter
     * in prose: the remaining directories "hold non-captures that only its
     * owning lane may edit", and widening was "a decision for the round that
     * owns those directories". WHAT IS TRUE NOW: that reasoning is unchanged
     * and still correct, but it was not CHECKED anywhere, and prose is not a
     * partition. Measured: narrowing this constant all the way down to
     * `['Integration/']` - undoing every widening this file has ever had -
     * left the whole guard green with the same assertion count as the
     * unmutated run. Membership of SCOPE had no signal in either direction,
     * so nothing distinguished "deliberately deferred" from "never looked
     * at". {@see OUT_OF_SCOPE} is where that reasoning now lives, one argued
     * row per prefix, and {@see testNoDirectoryWithAnUnguardedSpawnIsUnaccountedFor()}
     * is what makes the two lists jointly total over the offenders.
     *
     * @var list<string>
     */
    private const SCOPE = ['Agents/', 'Backend/', 'Chat/', 'Integration/', 'MCP/', 'Support/'];

    /**
     * Directories that hold an offending spawn and are NOT yet guarded, each
     * with the reason it cannot be adopted in the round that recorded it.
     *
     * WHY THIS MAP EXISTS AT ALL, since {@see SCOPE} could simply have been
     * widened: every prefix below names a directory in ANOTHER lane's file
     * list. Adopting one means editing files this lane may not touch, and
     * adding a directory to SCOPE without fixing its sites reds the guard for
     * whoever merges next. The alternative to a row is not a fix - it is
     * silence, which is what this file had.
     *
     * THE ROWS ARE CHECKED IN BOTH DIRECTIONS, so this cannot become a
     * rubber stamp. {@see testEveryOutOfScopeDirectoryStillHasAnOffendingSpawn()}
     * fails on a row whose directory has been cleaned up - a deferral that
     * has been overtaken is how a directory silently stops being tracked -
     * and {@see testNoDirectoryWithAnUnguardedSpawnIsUnaccountedFor()} fails
     * on an offender matched by neither list, which is the only outcome
     * refused outright.
     *
     * A FILE AT THE ROOT OF `tests/` has no directory to name, so its key is
     * its own filename. Both maps are matched with `str_starts_with()`, which
     * makes that work; it reads oddly enough that the failure message says so
     * rather than sending the reader looking for a directory.
     *
     * THE REASONS DELIBERATELY CARRY NO SITE COUNTS. A cardinality measured
     * over `tests/` in one lane's worktree is wrong by the next merge, and
     * every count a reader could want is derived by the two tests from the
     * tree itself.
     *
     * @var array<string, string>
     */
    private const OUT_OF_SCOPE = [
        'BaseSystemPromptTest.php' =>
            'A root-level file, so the key is the filename. Its one offender is an `exec()` '
            . 'removing a temp tree, where the shell has no output the test reads and the '
            . 'redirection is pure noise-suppression. Cheap to close, but the file is at the '
            . "root of tests/ and in no lane's list.",
        'ChatTest.php' =>
            'A root-level file, so the key is the filename. Its offender probes a tty with '
            . '`stty ... 2>/dev/null`, where the discard is load-bearing: the call is a FEATURE '
            . 'TEST whose failure output is expected and must not reach the suite. Closing it '
            . 'means a pipe plus a decision about what to do with the text, not a redirection '
            . 'swap.',
        'Cli/' =>
            'Offenders are `exec()` calls with no redirection at all, which is the cheap shape '
            . 'to close - the child writes to a file the helper reads back, so fd 2 has an '
            . 'obvious home. Deferred on ownership only.',
        'Commands/' =>
            'The largest inherited-shape cluster outside SCOPE and the cheapest to close: bare '
            . '`exec()` calls, several of them `rm -rf` on a sandbox where nothing reads any '
            . 'output. Deferred on ownership only.',
        'Config/' =>
            'One `exec(... 2>/dev/null)` whose exit status IS the assertion. The discard hides '
            . "the diagnostic that would explain a failure, so closing it improves the test's "
            . 'failure message rather than just its shape. Deferred on ownership only.',
        'Context/' =>
            'The largest discard cluster in the tree: `git init` / `git config` fixture setup '
            . 'with `2>/dev/null` on each line. The discards are deliberate - a missing git '
            . 'must not print - but they are also the shape this guard exists to refuse, so '
            . 'each needs either a pipe or an argued exemption row. The volume is why this is '
            . 'a round of its own.',
        'Diagnostics/' =>
            'A single bare `exec()`. Cheap, deferred on ownership only.',
        'Hooks/' =>
            'One `shell_exec()` reading `getconf PAGESIZE`, already `@`-suppressed and guarded '
            . 'by a `<= 0` check, so the inherited fd 2 is the only thing that can reach the '
            . 'suite. Cheap, deferred on ownership only.',
        'Providers/' =>
            'One `git init ... 2>/dev/null` behind a `markTestSkipped()` for a missing git - '
            . 'the same fixture shape as Context/ and it should be settled with it rather than '
            . 'piecemeal.',
        'Renderer/' =>
            'A POSITIONAL descriptor spec sending all three fds to /dev/null in a `runQuietly()` '
            . 'helper. Read as `inherited` until round 48 fixed the classifier, so this row '
            . 'records a site that was invisible rather than deferred. The discard is the '
            . "helper's entire purpose, so this one wants an exemption row, not a fix.",
        'Sessions/' =>
            'A POSITIONAL descriptor spec sending all three fds to /dev/null while spawning a '
            . 'process purely to harvest a pid that is guaranteed dead. Same classifier fix as '
            . 'Renderer/, same conclusion: the discard is the point, so this wants an exemption '
            . 'row.',
        'Tools/' =>
            'A `git init` fixture cluster with `2>/dev/null` plus one bare `exec()`. The git '
            . 'half belongs with Context/ and Providers/.',
        'Workflows/' =>
            'A single bare `exec()`. Cheap, deferred on ownership only.',
    ];

    /**
     * Spawn sites that send fd 2 to the null device ON PURPOSE, with the count
     * and the reason.
     *
     * A COUNT, not a bare file key. A file-keyed exemption is a blank cheque:
     * it licenses every future spawn added to the file as well as the one that
     * was argued for. Same shape, and for the same reason, as
     * {@see ForkedChildReaperAdoptionTest::UNTRACKED_FORKS_ALLOWED}.
     *
     * @var array<string,array{count:int,reason:string}>
     */
    private const ACCEPTED_DISCARDED_STDERR = [
        'Integration/BinSugarcrushDispatchTest.php' => [
            'count' => 1,
            'reason' =>
                'armWatchdog()\'s detached SIGKILL timer. It is a backgrounded subshell that '
                . 'DELIBERATELY outlives the test that armed it - that is its whole job, to still '
                . 'be there when the child it guards has hung - so there is no reader left for a '
                . 'pipe and no tearDown() left to collect a file. Its own doc-block explains at '
                . 'length why every descriptor it holds is closed by number rather than left '
                . 'open: under `phpunit | tail` an inherited dup of PHPUnit\'s stdout pipe kept '
                . 'the reader from ever seeing EOF, turning a 1.6s run into 27s. A stderr file '
                . 'this test could read would be one more descriptor with the same problem, and '
                . 'nothing would ever read it.',
        ],
    ];

    /**
     * THE SCANNER IS ALIVE, on inputs whose answers are known, in the same
     * test that uses it to assert an absence. Both failure shapes are
     * produced here as well as the passing one - and so is the
     * fully-qualified spelling, because leaving it out is precisely how the
     * first version of this scanner reported a file with three spawn sites as
     * having none.
     */
    public function testTheScannerDistinguishesTheShapesItClaimsTo(): void
    {
        $one = static function (string $body): array {
            $sites = ChildStderrCaptureScanner::scan("<?php\n" . $body . "\n");
            self::assertCount(1, $sites, 'fixture did not produce exactly one site: ' . $body);

            return $sites[0];
        };

        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_INHERITED,
            $one('exec("ls", $out, $rc);')['shape'],
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $one('exec("ls 2>&1", $out, $rc);')['shape'],
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $one('shell_exec("git init 2>&1");')['shape'],
        );

        $inlineOpen = $one('proc_open("ls", [0 => ["pipe", "r"], 1 => ["pipe", "w"]], $pipes);');
        $this->assertSame('proc_open', $inlineOpen['call']);
        $this->assertSame(ChildStderrCaptureScanner::SHAPE_INHERITED, $inlineOpen['shape']);

        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $one('proc_open("ls", [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);')['shape'],
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $one('proc_open("ls", [2 => ["file", "/tmp/e", "w"]], $pipes);')['shape'],
        );

        // The descriptor spec held in a variable is RESOLVED, both ways round.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $one('$d = [1 => ["pipe", "w"], 2 => ["pipe", "w"]]; proc_open("ls", $d, $pipes);')['shape'],
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_INHERITED,
            $one('$d = [1 => ["pipe", "w"]]; proc_open("ls", $d, $pipes);')['shape'],
        );

        // A spec the scanner cannot follow is NAMED, not waved through.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_UNCLASSIFIED,
            $one('proc_open("ls", self::descriptors(), $pipes);')['shape'],
        );

        // ...AND NEITHER IS AN fd-2 ENTRY IT CANNOT READ, which is a
        // different hole in the same wall and was open until round 48. The
        // scanner used to answer `captured` for any spec that merely NAMED
        // fd 2 without holding the literal `/dev/null`, so an entry behind a
        // call came back compliant on the strength of the key. The live shape
        // is `BinSugarcrushDispatchTest::armWatchdog()`'s `2 => $devNull('w')`
        // - a closure returning `['file','/dev/null','w']`, i.e. a discard
        // reported as a capture.
        //
        // NOTHING IN THE TREE EXERCISES THIS, and that is stated rather than
        // discovered later: a per-site census of all of `tests/` was run
        // before and after each of the two widenings - the bracket check and
        // the member check below it - and ZERO real sites moved either time,
        // because that one site is settled as `discarded` on the
        // command-string branch before its spec is ever read. The census
        // harness was itself checked against the fixture cases below, which
        // DO move, before its zero was believed. These fixtures are the only
        // thing keeping the branch honest.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_UNCLASSIFIED,
            $one('proc_open("ls", [1 => ["pipe","w"], 2 => $devNull("w")], $p);')['shape'],
            'an fd-2 entry behind a call is not a capture - the scanner cannot see where it goes',
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_UNCLASSIFIED,
            $one('proc_open("ls", [2 => self::PIPE], $p);')['shape'],
            'an fd-2 entry that is a constant is not a capture either',
        );
        // The long array syntax is deliberately NOT accepted as a literal:
        // widening the shape is a decision for somebody holding a census.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_UNCLASSIFIED,
            $one('proc_open("ls", [2 => array("pipe","w")], $p);')['shape'],
        );

        // AND AN ENTRY THAT IS A LITERAL ARRAY OF NON-LITERALS, which the
        // first version of the rule above claimed to cover and did not. Its
        // doc-block said "an entry that is not an inline literal array is
        // unclassified" while the code only checked the BRACKETS: the
        // decision underneath is `str_contains($entry, '/dev/null')` over the
        // entry's SOURCE TEXT, so a member that is not its own value makes
        // that search meaningless in the direction that waves an offender
        // through. `['file', $devNull, 'w']` is the nearest sibling of
        // `armWatchdog()`'s live site and read `captured` for a full round.
        //
        // All four spellings of "the text is not the value" are pinned,
        // because each fails the substring search for a different reason and
        // any one of them could be re-admitted by a narrower fix.
        foreach ([
            'a variable member' => '$devNull',
            'a class constant member' => 'self::DEV_NULL',
            'a global constant member' => 'DEV_NULL',
            'a concatenated member' => "'/dev' . '/null'",
            'an interpolated member' => '"/dev/{$name}"',
        ] as $why => $member) {
            $this->assertSame(
                ChildStderrCaptureScanner::SHAPE_UNCLASSIFIED,
                $one('proc_open("ls", [2 => ["file", ' . $member . ', "w"]], $p);')['shape'],
                $why . ' is not a capture - the scanner is deciding on source text, and this '
                    . "member's text is not its value",
            );
        }

        // A POSITIONAL DESCRIPTOR SPEC, which `proc_open()` reads BY
        // POSITION - element 2 is fd 2, with no `2 =>` key anywhere in the
        // source. Every spelling of this returned `inherited` until round 48,
        // because the classifier's first branch answered on the absence of
        // the key alone. Four different truths came back as one answer, and
        // `inherited` is a definite claim rather than an "I cannot tell", so
        // it was wrong in BOTH polarities at once - understating a real
        // discard and redding a real capture. All four are pinned.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_DISCARDED,
            $one('proc_open("ls", [["file","/dev/null","r"],["file","/dev/null","w"],'
                . '["file","/dev/null","w"]], $p);')['shape'],
            'a positional spec sending fd 2 to the null device is a discard - reading it as '
                . 'inherited is the polarity that waves a real offender through',
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $one('proc_open("ls", [["pipe","r"],["pipe","w"],["pipe","w"]], $p);')['shape'],
            'a positional spec piping fd 2 is a capture - reading it as inherited is the '
                . 'polarity that reds correct code',
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_INHERITED,
            $one('proc_open("ls", [["pipe","r"],["pipe","w"]], $p);')['shape'],
            'a spec with no third element really does leave fd 2 where the parent had it, so '
                . 'this one shape must NOT move',
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_UNCLASSIFIED,
            $one('proc_open("ls", [["pipe","r"],["pipe","w"],$err], $p);')['shape'],
            'a positional element 2 that is not its own value is unreadable, and the honest '
                . 'answer to that is a failure rather than a confident inherited',
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_INHERITED,
            $one('proc_open("ls", [0 => ["pipe","r"], 1 => ["pipe","w"]], $p);')['shape'],
            'a KEYED spec that simply does not mention fd 2 leaves it inherited, and must not '
                . 'be dragged into the positional reading',
        );

        // THE LIMIT OF THAT RULE, named here rather than left to be
        // discovered: both of these are all-literal, so they are judged by
        // the `/dev/null` text alone and come back `captured`. `redirect`
        // merges fd 2 into fd 1 and this scanner does not model where fd 1
        // went; a real path is a file the test may or may not read back.
        // Closing either needs fd-1 destination modelling, and nothing in the
        // tree exercises it.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $one('proc_open("ls", [1 => ["pipe","w"], 2 => ["redirect", 1]], $p);')['shape'],
        );

        // THE OTHER POLARITY, because a rule that answers `unclassified` for
        // everything reds correct code, and that is how the next real
        // offender buys its exemption. Both literal shapes still resolve.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $one('proc_open("ls", [2 => ["file", "/tmp/err", "w"]], $p);')['shape'],
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_DISCARDED,
            $one('proc_open("ls", [2 => ["file", "/dev/null", "w"]], $p);')['shape'],
        );

        // THE SPELLING THAT WAS MISSED. `\proc_open(...)` is
        // T_NAME_FULLY_QUALIFIED, and matching only T_STRING made three real
        // sites vanish.
        $qualified = $one('$d = [2 => ["pipe", "w"]]; \\proc_open($cmd, $d, $pipes);');
        $this->assertSame('proc_open', $qualified['call']);
        $this->assertSame(ChildStderrCaptureScanner::SHAPE_CAPTURED, $qualified['shape']);

        // AN INTERPOLATED COMMAND STRING. `"{$x}"` opens with an ARRAY token
        // (T_CURLY_OPEN) and closes with a plain '}', so counting only the
        // closer drove the argument-splitter's depth negative and top-level
        // commas after the string stopped being seen: this correctly-capturing
        // call came back `unclassified`, and a guard that reds correct code
        // invites the exemption the next real offender hides behind.
        $interpolated = $one('proc_open("php {$script}", [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $p);');
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $interpolated['shape'],
            'string interpolation in the command broke the argument split',
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_INHERITED,
            $one('proc_open("php {$script}", [1 => ["pipe", "w"]], $p);')['shape'],
            'the same call without fd 2 must still be reported as inherited',
        );

        // BACKTICKS ARE SHELL EXECUTION, and were outside the alphabet
        // entirely - not `unclassified` but SILENT, which is the one answer a
        // guard may never give to something it cannot parse.
        $backtick = $one('$out = `php foo.php`;');
        $this->assertSame(ChildStderrCaptureScanner::CALL_BACKTICK, $backtick['call']);
        $this->assertSame(ChildStderrCaptureScanner::SHAPE_INHERITED, $backtick['shape']);
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $one('$out = `php foo.php 2>&1`;')['shape'],
        );

        // A `2>` IN A COMMENT IS NOT A REDIRECTION. Same window defect as the
        // fork scanner's: comments are source text, and the `2>` search ran
        // over rendered source.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_INHERITED,
            $one('shell_exec("php x" /* 2> somewhere */);')['shape'],
            'a comment mentioning a redirection is not a redirection',
        );

        // A method named `exec` is not the launcher.
        $this->assertSame([], ChildStderrCaptureScanner::scan('<?php $this->exec("ls");'));

        // THE NULL DEVICE IS NOT A FILE. Every spelling that reaches it, and
        // the near-misses that must NOT: a guard that reds a correct capture
        // is answered with an exemption, and the exemption is where the next
        // real offender hides.
        $discarded = [
            'exec("ls 2>/dev/null", $out, $rc);',
            'exec("ls 2> /dev/null", $out, $rc);',
            'shell_exec("ls 2>>/dev/null");',
            'shell_exec("ls &> /dev/null");',
            // fd 1 to the sink FIRST, then fd 2 onto fd 1: both gone.
            'shell_exec("ls >/dev/null 2>&1");',
            'proc_open("ls", [1 => ["pipe", "w"], 2 => ["file", "/dev/null", "w"]], $p);',
            '$d = [2 => ["file", "/dev/null", "w"]]; proc_open("ls", $d, $p);',
            // A REDIRECTION IN proc_open()'s COMMAND STRING, which is a third
            // path to `discarded` and not the same code as either shape
            // above: the shell applies it before the descriptor spec is ever
            // consulted, so a spec that says `pipe` does not make this a
            // capture. This catalogue listed every OTHER spelling and left
            // this one out, and blinding the branch behind it reddened
            // nothing in this test.
            'proc_open("ls >/dev/null 2>&1", [2 => ["pipe", "w"]], $p);',
        ];
        foreach ($discarded as $body) {
            $this->assertSame(
                ChildStderrCaptureScanner::SHAPE_DISCARDED,
                $one($body)['shape'],
                'not reported as a discard: ' . $body,
            );
        }

        // THE REVERSED ORDER IS A CAPTURE, and the difference is not
        // cosmetic. `2>&1 >/dev/null` points fd 2 at whatever fd 1 was at that
        // moment - for a child of exec()/proc_open(), the pipe the caller
        // reads - and only then moves fd 1 to the sink.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $one('shell_exec("ls 2>&1 >/dev/null");')['shape'],
            'fd 2 was duplicated from stdout BEFORE stdout became the sink',
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $one('proc_open("ls 2>&1 >/dev/null", [2 => ["pipe", "w"]], $p);')['shape'],
            'the order check must hold on the proc_open command-string path too, not just the shell one',
        );

        // TWO KNOWN FALSE POSITIVES, pinned rather than described. Both are
        // limits of
        // {@see ChildStderrCaptureScanner::sendsFdTwoToTheNullDevice()} and
        // both are argued at length in its doc-block; neither occurs under
        // {@see SCOPE}. They are asserted at their CURRENT answer so that the
        // day somebody teaches the predicate quote-awareness or last-wins
        // precedence, these two lines red and get updated deliberately -
        // instead of the limit quietly outliving the sentence describing it.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_DISCARDED,
            $one('proc_open("sh -c \'inner 2>/dev/null\'", [2 => ["pipe", "w"]], $p);')['shape'],
            'KNOWN LIMIT: a redirection belonging to an INNER shell is read as the outer '
                . "command's. The outer child's fd 2 is really the pipe. If this now reports a "
                . 'capture the scanner got quote-aware - delete this line and say so',
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_DISCARDED,
            $one('exec("sh -c \'inner 2>/dev/null\' 2>$err", $out, $rc);')['shape'],
            'KNOWN LIMIT: a bare 2>/dev/null matches wherever it appears, so a LATER fd 2 '
                . 'redirection that overrides it is not consulted. If this now reports a capture '
                . 'the predicate learned last-wins precedence - delete this line and say so',
        );

        // ...and the composition that makes the second limit hard to "fix"
        // naively: here the null device really IS the last word on fd 2.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_DISCARDED,
            $one('exec("cmd 2>$err 2>/dev/null", $out, $rc);')['shape'],
            'a later 2>/dev/null genuinely does override an earlier 2>$err',
        );

        // fd 0 on the null device is an ordinary child with no stdin, and says
        // nothing at all about fd 2.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $one('proc_open("ls", [0 => ["file", "/dev/null", "r"], 2 => ["pipe", "w"]], $p);')['shape'],
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_INHERITED,
            $one('proc_open("ls", [0 => ["file", "/dev/null", "r"]], $p);')['shape'],
        );

        // A real file named on fd 2 is still a capture, /dev/null-shaped path
        // fragments notwithstanding.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $one('exec("ls >$out 2>$err", $ignored, $rc);')['shape'],
        );

        // THE SCOPE BOUND ON THE DESCRIPTOR-SPEC RESOLUTION. A `$d` assigned
        // in an EARLIER METHOD is not this spawn's spec, and answering from it
        // was the documented-but-unenforced hole. `unclassified` is a failure
        // shape, which is the correct answer to "I cannot tell".
        $crossMethod = ChildStderrCaptureScanner::scan(
            "<?php\nclass F {\n"
            . '  public function a(): void { $d = [1 => ["pipe","w"], 2 => ["pipe","w"]]; }' . "\n"
            . '  public function b(): void { proc_open("ls", $d, $p); }' . "\n}\n",
        );
        $this->assertCount(1, $crossMethod);
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_UNCLASSIFIED,
            $crossMethod[0]['shape'],
            'a descriptor spec from another method answered for this spawn',
        );

        // ...and the same shape WITHIN one method still resolves, so the
        // bound did not simply blind the resolver.
        $sameMethod = ChildStderrCaptureScanner::scan(
            "<?php\nclass F {\n"
            . '  public function b(): void { $d = [1 => ["pipe","w"], 2 => ["pipe","w"]];' . "\n"
            . '    proc_open("ls", $d, $p); }' . "\n}\n",
        );
        $this->assertSame(ChildStderrCaptureScanner::SHAPE_CAPTURED, $sameMethod[0]['shape']);

        // A CLOSURE IS NOT A SCOPE BOUNDARY, deliberately: PHP closures
        // capture by `use`, so an assignment before one really is in effect
        // inside it and the enclosing NAMED function is the honest floor.
        $inClosure = ChildStderrCaptureScanner::scan(
            "<?php\nclass F {\n"
            . '  public function b(): void { $d = [2 => ["pipe","w"]];' . "\n"
            . '    $go = function () use ($d, &$p) { proc_open("ls", $d, $p); }; $go(); }' . "\n}\n",
        );
        $this->assertSame(ChildStderrCaptureScanner::SHAPE_CAPTURED, $inClosure[0]['shape']);
    }

    /**
     * A known-positive run through the SAME scanner, for callers that
     * otherwise assert only an absence or a count.
     *
     * MEASURED TWICE, and each measurement bought a fixture below rather than
     * a reassurance.
     *
     * ONE. With `classifyShell()`'s null-device branch mutated to
     * `if (false)`, BOTH
     * {@see testNoChildLaunchedInScopeLeavesItsStderrOnTheSuites()} and
     * {@see testEveryDiscardExemptionStillDescribesRealSites()} passed. The
     * first because everything read as `captured` again, and the second
     * because its one exempted site is a `proc_open()` whose discard is
     * decided on a different branch - so the count still came out at 1. Two
     * green guards over a half-dead instrument.
     *
     * TWO, and this is why the sentence here no longer says "both branches".
     * There are THREE paths that can return `SHAPE_DISCARDED`, this helper
     * covered two, and the uncovered one was the path the tree's only live
     * exemption actually rests on. With `classifyProcOpen()`'s command-string
     * branch blinded, this fixture test passed - 60 assertions, entirely
     * green - and so did the absence guard. The only thing that reddened was
     * the exemption row for a real site in
     * `Integration/BinSugarcrushDispatchTest.php`, and that row is
     * deliberately SELF-DELETING: the day `armWatchdog()` stops discarding,
     * the row goes, and the branch would have been left with no liveness
     * coverage at all.
     *
     * THREE, and it is why this helper is no longer NAMED for the discard
     * branch. WHAT THIS DOC-BLOCK SAID: that the thing needing a
     * known-positive is the discard branch, and the name
     * `assertTheDiscardBranchIsAlive()` said the same. WHAT IS TRUE NOW:
     * {@see testNoChildLaunchedInScopeLeavesItsStderrOnTheSuites()} treats
     * {@see ChildStderrCaptureScanner::SHAPE_UNCLASSIFIED} as an offender too
     * and carries a whole paragraph about it in its failure message, so
     * `unclassified` is a second shape whose ABSENCE that guard asserts - and
     * nothing here proved that shape could still be produced. Measured:
     * with `fdTwoEntryIsAllLiteral()` mutated to `return true`, the absence
     * guard passed - 1 test, 12 assertions, entirely green - while the
     * unit-level fixture test above killed it. Blind the scanner's
     * unclassified branch and the REAL-TREE guard stays quiet, which is the
     * exact failure mode measurements ONE and TWO were about.
     *
     * WHY THIS EARNS ITS PLACE: an assertion of "no occurrences" is not
     * evidence unless something in the same test proves the instrument can
     * still produce one - for EVERY shape that assertion is claiming zero of,
     * not just the shape that happened to be found first. All three discard
     * paths are exercised below - a shell command string, a `proc_open()`
     * COMMAND STRING, and a `proc_open()` DESCRIPTOR SPEC - and both
     * unclassified paths after them, each with the opposite polarity beside
     * it, because a scanner stuck at `discarded` (or at `unclassified`) reds
     * correct code and that is how the next real offender buys its exemption.
     */
    private function assertTheOffendingShapeBranchesAreAlive(): void
    {
        $shape = static function (string $body): string {
            $sites = ChildStderrCaptureScanner::scan("<?php\n" . $body . "\n");
            self::assertCount(1, $sites, 'liveness fixture did not produce one site: ' . $body);

            return $sites[0]['shape'];
        };

        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_DISCARDED,
            $shape('shell_exec("ls 2>/dev/null");'),
            'the shell null-device branch is dead, so every /dev/null below reads as a capture',
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_DISCARDED,
            $shape('proc_open("ls", [2 => ["file", "/dev/null", "w"]], $p);'),
            'the descriptor-spec null-device branch is dead',
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $shape('proc_open("ls", [2 => ["pipe", "w"]], $p);'),
            'the scanner now calls a real capture a discard, which is the other polarity',
        );

        // THE THIRD PATH, and not reachable through either assertion above: a
        // redirection in `proc_open()`'s COMMAND STRING is applied by the
        // shell before the descriptor spec is ever consulted, so the spec
        // here says `pipe` and the honest answer is still `discarded`.
        // Blinding this branch alone left every other assertion in this file
        // green.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_DISCARDED,
            $shape('proc_open("ls >/dev/null 2>&1", [2 => ["pipe", "w"]], $p);'),
            'the proc_open command-string null-device branch is dead, so a shell redirection that '
                . 'really does discard fd 2 now reads as the capture its descriptor spec claims',
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $shape('proc_open("ls 2>&1 >/dev/null", [2 => ["pipe", "w"]], $p);'),
            'the reversed order points fd 2 at whatever fd 1 held AT THAT MOMENT - the pipe the '
                . 'caller reads - so reporting it as a discard is the polarity that reds correct '
                . 'code',
        );

        // THE FOURTH DISCARD PATH, added when it turned out to exist: a
        // POSITIONAL spec, decided by `positionalShape()` rather than by
        // either branch above. Two real sites in the tree read `inherited`
        // until it was fixed.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_DISCARDED,
            $shape('proc_open("ls", [["file","/dev/null","r"],["file","/dev/null","w"],'
                . '["file","/dev/null","w"]], $p);'),
            'the positional null-device path is dead, so a spec that discards fd 2 by position '
                . 'reads as the inherited it is not',
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $shape('proc_open("ls", [["pipe","r"],["pipe","w"],["pipe","w"]], $p);'),
            'the positional path now calls a real capture something else, which is the other '
                . 'polarity',
        );

        // THE UNCLASSIFIED SHAPE, which the caller also asserts zero of. Two
        // paths reach it and a mutation of either one alone left the
        // real-tree guard green, so both are fixtured here.
        //
        // PATH ONE: the fd-2 entry is found, but a member is not its own
        // value. `fdTwoEntryIsAllLiteral()` is what refuses it; blinded to
        // `return true` this reads as a capture and the absence guard passes.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_UNCLASSIFIED,
            $shape('proc_open("ls", [2 => $x], $p);'),
            'an fd-2 entry whose member is a variable now reads as a shape this guard accepts, so '
                . 'a spec the scanner cannot actually follow passes as innocent',
        );
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_UNCLASSIFIED,
            $shape('proc_open("ls", [2 => ["file", "/dev/{$n}", "w"]], $p);'),
            'an interpolated member is not its own value either - if this is classified, a '
                . '"/dev/{$n}" that resolves to /dev/null is being called a capture',
        );

        // PATH TWO: the descriptor spec is a variable this scanner cannot
        // resolve at the call site at all, so there is no entry to read.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_UNCLASSIFIED,
            $shape('function b() { proc_open("ls", $d, $p); }'),
            'an unresolvable descriptor spec now reads as a shape this guard accepts, so every '
                . 'spawn that builds its spec elsewhere becomes invisible to the absence guard',
        );

        // THE OPPOSITE POLARITY FOR BOTH PATHS, because a scanner stuck at
        // `unclassified` reds every correct spawn in the tree rather than
        // hiding one - the other way this instrument can be broken.
        $this->assertSame(
            ChildStderrCaptureScanner::SHAPE_CAPTURED,
            $shape('proc_open("ls", [2 => ["file", "/tmp/e.log", "w"]], $p);'),
            'a fully literal fd-2 entry is readable and must not be reported unclassified',
        );
    }

    private static function inScope(string $relative): bool
    {
        foreach (self::SCOPE as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function testNoChildLaunchedInScopeLeavesItsStderrOnTheSuites(): void
    {
        $this->assertTheOffendingShapeBranchesAreAlive();

        $root = \dirname(__DIR__);
        $offenders = [];
        $captured = 0;

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            if (!self::inScope($relative)) {
                continue;
            }

            $discardAllowance = self::ACCEPTED_DISCARDED_STDERR[$relative]['count'] ?? 0;

            foreach (ChildStderrCaptureScanner::scan((string) file_get_contents($file->getPathname())) as $site) {
                if ($site['shape'] === ChildStderrCaptureScanner::SHAPE_CAPTURED) {
                    $captured++;

                    continue;
                }

                // Spent one at a time, so a second discarded site in an
                // exempted file is still reported.
                if ($site['shape'] === ChildStderrCaptureScanner::SHAPE_DISCARDED && $discardAllowance > 0) {
                    $discardAllowance--;

                    continue;
                }

                $offenders[] = $relative . ':' . $site['line'] . ' (' . $site['call'] . ' -> ' . $site['shape'] . ')';
            }
        }

        // Not a headcount - the number is free to move. Only a statement that
        // the loop found spawn sites at all, so that a scope or a scanner that
        // matched nothing cannot pass as an absence.
        $this->assertGreaterThan(
            0,
            $captured,
            'no child-process launches found under ' . implode(', ', self::SCOPE)
                . ' - the scanner is dead',
        );

        $this->assertSame(
            [],
            $offenders,
            "a child launched here writes its stderr onto the suite's, where the test cannot read "
                . 'it and everyone running phpunit can. Give the spawn somewhere to put fd 2 - a '
                . "pipe, a file, or `2>&1` onto the stdout already being read. Do NOT send it to "
                . '/dev/null: for most of these shapes the line is the assertion. An '
                . '"unclassified" site is a descriptor spec this scanner could not follow, which '
                . 'is a hole in the guard rather than a pass for the site. A "discarded" site '
                . 'sends fd 2 to /dev/null, which is the one destination this guard exists to '
                . 'refuse: nobody can read it, including the test.',
        );
    }

    /**
     * A discard exemption cannot outlive the site it was written for, and
     * cannot quietly grow.
     *
     * This is also the KNOWN-POSITIVE fixture for the null-device branch of
     * the scanner, run against the real tree rather than a string: the
     * absence assertion above would stay green if `sendsFdTwoToTheNullDevice()`
     * stopped matching, because everything would simply read as `captured`
     * again. Here a scanner that stopped matching reports zero discards
     * against an exemption claiming one, and fails.
     */
    public function testEveryDiscardExemptionStillDescribesRealSites(): void
    {
        $this->assertTheOffendingShapeBranchesAreAlive();

        $root = \dirname(__DIR__);

        foreach (self::ACCEPTED_DISCARDED_STDERR as $file => $exemption) {
            $path = $root . '/' . $file;
            $this->assertFileExists($path, "{$file} is exempted but no longer exists");
            $this->assertNotSame('', trim($exemption['reason']), "{$file} is exempted without a reason");

            $discarded = 0;
            foreach (ChildStderrCaptureScanner::scan((string) file_get_contents($path)) as $site) {
                if ($site['shape'] === ChildStderrCaptureScanner::SHAPE_DISCARDED) {
                    $discarded++;
                }
            }

            $this->assertSame(
                $exemption['count'],
                $discarded,
                "{$file} is exempted for {$exemption['count']} discarded-stderr spawn(s) but has "
                    . "{$discarded}. Re-argue the exemption or delete it - a count that no longer "
                    . 'matches is a licence nobody checked.',
            );
        }
    }

    /**
     * {@see SCOPE} AND {@see OUT_OF_SCOPE} MUST BE JOINTLY TOTAL over the
     * offenders, or a deferral is a hole rather than a record.
     *
     * WHY THIS TEST HAD TO EXIST, measured rather than argued. Narrowing
     * {@see SCOPE} to `['Integration/']` - undoing every widening this file
     * has ever received, including the one made in the same round as this
     * test - left the whole guard green, with the SAME assertion count as the
     * unmutated run. {@see testNoChildLaunchedInScopeLeavesItsStderrOnTheSuites()}
     * never looks outside SCOPE, and
     * {@see testEveryDiscardExemptionStillDescribesRealSites()} only checks
     * rows that exist, so between them the two agreed that a directory nobody
     * covers is fine. Dropping `MCP/` from SCOPE *and* deleting a real `2>&1`
     * from a spawn in that directory also survived - a widening and the
     * offender it was supposed to catch, removed together, in silence.
     *
     * So this one starts from the SPAWN SITES rather than from either list:
     * every file anywhere under `tests/` holding a site this guard would
     * refuse has to be matched by one list or the other. Adopting a directory
     * is then a visible act, and so is declining to.
     *
     * NOTE THE REACH, because it is wider than {@see SCOPE} and easy to
     * describe as though it were not. The walk is over ALL of `tests/`. An
     * offending spawn added ANYWHERE - including a directory no lane has ever
     * listed - reds this test until its directory is put in one of the two
     * maps. That is intended, and it is a standing obligation on every other
     * lane, so it is written down here rather than left to be rediscovered
     * from a red merge.
     *
     * THE SIBLING GUARD ALREADY WORKS THIS WAY.
     * {@see ForkedChildReaperAdoptionTest::testNoDirectoryWithUnreapedForksIsUnaccountedFor()}
     * is the same invariant over the reaper, and the equivalent mutation
     * there - drop a prefix, restore a real offender in it - is killed. This
     * file was widened in the same round and did not get the invariant, which
     * is the whole reason both mutations above survived here.
     */
    public function testNoDirectoryWithAnUnguardedSpawnIsUnaccountedFor(): void
    {
        $this->assertTheOffendingShapeBranchesAreAlive();

        $root = \dirname(__DIR__);
        $unaccounted = [];
        $checked = 0;

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            $offenders = self::offendingSites($relative, (string) file_get_contents($file->getPathname()));
            if ($offenders === []) {
                continue;
            }
            $checked++;

            if (!self::accountedFor($relative)) {
                $unaccounted[] = $relative . ' (' . implode(', ', $offenders) . ')';
            }
        }

        // The scanner has to have found something to reason about, or
        // "nothing is unaccounted for" is a statement about a dead
        // instrument rather than about the tree.
        $this->assertGreaterThan(
            0,
            $checked,
            'the stderr scanner found no offending spawn anywhere under tests/ - it is dead',
        );

        $this->assertSame(
            [],
            $unaccounted,
            'this file launches a child whose stderr lands on the suite\'s, and it is matched by '
                . 'no prefix in either SCOPE or OUT_OF_SCOPE. Either give the spawn somewhere to '
                . 'put fd 2 and add its directory to SCOPE, or add that directory to '
                . 'OUT_OF_SCOPE with the reason it cannot be adopted yet. Both maps are matched '
                . 'with str_starts_with(), so for a test at the ROOT of tests/ - which has no '
                . 'directory - the entry is the filename itself. Leaving it in neither is the '
                . 'only outcome this guard refuses.',
        );
    }

    /**
     * A deferral cannot outlive the offender it was written for.
     *
     * Without this, {@see OUT_OF_SCOPE} decays into a list of directories
     * somebody once worried about, and the partition above would keep passing
     * because a stale row still matches the prefix. A row whose directory has
     * been cleaned up means the directory is ready to JOIN {@see SCOPE}, and
     * that is the one moment anybody is likely to notice.
     */
    public function testEveryOutOfScopeDirectoryStillHasAnOffendingSpawn(): void
    {
        $this->assertTheOffendingShapeBranchesAreAlive();

        $root = \dirname(__DIR__);
        $withOffenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            if (self::offendingSites($relative, (string) file_get_contents($file->getPathname())) !== []) {
                $withOffenders[] = $relative;
            }
        }

        foreach (self::OUT_OF_SCOPE as $prefix => $reason) {
            $this->assertNotSame('', trim($reason), $prefix . ' is deferred without a reason');

            $stillOffending = false;
            foreach ($withOffenders as $relative) {
                $stillOffending = $stillOffending || str_starts_with($relative, $prefix);
            }

            $this->assertTrue(
                $stillOffending,
                $prefix . ' is recorded in OUT_OF_SCOPE as holding a spawn whose stderr reaches '
                    . 'the suite, and it no longer does. Move the prefix into SCOPE and delete '
                    . 'this row - a deferral that has been overtaken is how a directory '
                    . 'silently stops being guarded.',
            );
        }

        // A prefix naming nothing at all is a typo that would satisfy neither
        // direction of the partition, so it is refused separately rather than
        // read as "clean".
        foreach (array_keys(self::OUT_OF_SCOPE) as $prefix) {
            $this->assertTrue(
                is_dir($root . '/' . rtrim($prefix, '/')) || is_file($root . '/' . $prefix),
                $prefix . ' is recorded in OUT_OF_SCOPE but no such directory or file exists '
                    . 'under tests/.',
            );
        }
    }

    /**
     * The sites in one file this guard would refuse, after the file's own
     * discard allowance is spent.
     *
     * Shared by the partition guards above so that "offending" means the same
     * thing to both of them as it does to
     * {@see testNoChildLaunchedInScopeLeavesItsStderrOnTheSuites()} - a
     * second definition would let a site be an offender to one guard and not
     * to another, which is the seam a deferral would slip through.
     *
     * @return list<string>
     */
    private static function offendingSites(string $relative, string $source): array
    {
        $allowance = self::ACCEPTED_DISCARDED_STDERR[$relative]['count'] ?? 0;
        $offenders = [];

        foreach (ChildStderrCaptureScanner::scan($source) as $site) {
            if ($site['shape'] === ChildStderrCaptureScanner::SHAPE_CAPTURED) {
                continue;
            }

            if ($site['shape'] === ChildStderrCaptureScanner::SHAPE_DISCARDED && $allowance > 0) {
                $allowance--;

                continue;
            }

            $offenders[] = $site['line'] . ':' . $site['call'] . ' -> ' . $site['shape'];
        }

        return $offenders;
    }

    private static function accountedFor(string $relative): bool
    {
        if (self::inScope($relative)) {
            return true;
        }

        foreach (array_keys(self::OUT_OF_SCOPE) as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

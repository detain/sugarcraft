<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * No child process launched from `tests/Integration/` may leave its stderr on
 * the suite's.
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
    /** Path prefix, relative to `tests/`, the rule is enforced under. */
    private const SCOPE = 'Integration/';

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
     * WHY THIS EARNS ITS PLACE: an assertion of "no occurrences" is not
     * evidence unless something in the same test proves the instrument can
     * still produce one. All three discard paths are exercised below - a
     * shell command string, a `proc_open()` COMMAND STRING, and a
     * `proc_open()` DESCRIPTOR SPEC - each with the opposite polarity beside
     * it, because a scanner stuck at `discarded` reds correct code and that
     * is how the next real offender buys its exemption.
     */
    private function assertTheDiscardBranchIsAlive(): void
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
    }

    public function testNoChildLaunchedInScopeLeavesItsStderrOnTheSuites(): void
    {
        $this->assertTheDiscardBranchIsAlive();

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
            if (!str_starts_with($relative, self::SCOPE)) {
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
            'no child-process launches found under ' . self::SCOPE . ' - the scanner is dead',
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
        $this->assertTheDiscardBranchIsAlive();

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
}

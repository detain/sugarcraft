<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Known-answer controls for {@see ChildLifetimeScanner}, in BOTH directions.
 *
 * WHY THIS FILE EXISTS AND NOT JUST THE GUARD. Round 48 graded this scanner's
 * older sibling by reading its findings rather than running it, and missed
 * that `classifySpec()` answered `inherited` for EVERY positional descriptor
 * spec regardless of truth - wrong in both polarities at once. A findings list
 * is not evidence about the instrument that produced it. So every rule this
 * scanner implements is pinned here against a source whose answer is known
 * before the scanner is asked, and pinned in the direction that must FLAG as
 * well as the direction that must NOT: a scanner that flags nothing is
 * indistinguishable from a scanner that is dead unless something proves it
 * still fires.
 *
 * THE FIXTURES ARE SYNTHETIC ON PURPOSE. Pinning against real files in `src/`
 * would make this file red whenever another lane edits a spawn site, which is
 * the guard's job and not this one's. Here the source IS the expectation.
 */
final class ChildLifetimeScannerFixtureTest extends TestCase
{
    /**
     * A long-lived child whose spec says nothing about fd 3 and above.
     *
     * THE POSITIVE CONTROL. If this stops flagging, every "nothing to report"
     * elsewhere in the guard is worthless, because that is exactly what a dead
     * scanner returns.
     */
    public function testAPropertyStoredHandleWithNoHighFdsIsFlagged(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            class Server {
                private $process;
                public function start(): void {
                    $pipes = [];
                    $this->process = @proc_open(['srv'], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ], $pipes);
                }
            }
            PHP);

        self::assertCount(1, $sites);
        self::assertSame(ChildLifetimeScanner::LIFETIME_LONG, $sites[0]['lifetime']);
        self::assertSame([0, 1, 2], $sites[0]['fds']);
        self::assertSame([], $sites[0]['highFds']);
        self::assertSame('start', $sites[0]['function']);
    }

    /**
     * The same child, with fd 3 named.
     *
     * THE NEGATIVE CONTROL FOR THE FD HALF. Without it the guard could be
     * satisfied by a scanner that reports `highFds` as empty unconditionally,
     * which would flag the whole tree and read as thoroughness.
     */
    public function testAPropertyStoredHandleThatNamesFdThreeIsNotFlagged(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            class Server {
                private $process;
                public function start(): void {
                    $pipes = [];
                    $this->process = @proc_open(['srv'], [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                        3 => ['file', '/dev/null', 'r'],
                    ], $pipes);
                }
            }
            PHP);

        self::assertCount(1, $sites);
        self::assertSame(ChildLifetimeScanner::LIFETIME_LONG, $sites[0]['lifetime']);
        self::assertSame([3], $sites[0]['highFds']);
    }

    /**
     * THE NEGATIVE CONTROL FOR THE LIFETIME HALF: a child drained and closed
     * where it was spawned inherits descriptors for microseconds and is not
     * a finding.
     */
    public function testAHandleClosedInTheSameFunctionIsShortLived(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            class Runner {
                public function run(): string {
                    $pipes = [];
                    $process = @proc_open('ls', [1 => ['pipe', 'w']], $pipes);
                    $out = stream_get_contents($pipes[1]);
                    proc_close($process);
                    return $out;
                }
            }
            PHP);

        self::assertCount(1, $sites);
        self::assertSame(ChildLifetimeScanner::LIFETIME_SHORT, $sites[0]['lifetime']);
        self::assertSame([1], $sites[0]['fds']);
    }

    /**
     * A `proc_close()` reached on some paths and not others does not make the
     * child short-lived.
     *
     * THE LIVE CASE IS `Sessions/BackgroundSupervisor.php::spawnSession()`,
     * whose only `proc_close()` is inside the branch taken when the IPC
     * handshake times out. On the happy path nothing reaps the child at all -
     * which is what E366 recorded by hand - and a scanner that counted the
     * textual presence of the call said "short", the polarity that reports a
     * leak as fine.
     */
    public function testACloseOnlyOnAFailureBranchIsNotAShortLife(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            class Supervisor {
                public function spawn(): void {
                    $pipes = [];
                    $proc = proc_open('daemon', [1 => ['pipe', 'w']], $pipes);
                    if (!is_resource($proc)) {
                        throw new \RuntimeException('no');
                    }
                    $client = @stream_socket_accept($this->server, 5);
                    if ($client === false) {
                        proc_close($proc);
                        throw new \RuntimeException('timeout');
                    }
                }
            }
            PHP);

        self::assertCount(1, $sites);
        self::assertSame(ChildLifetimeScanner::LIFETIME_UNCLASSIFIED, $sites[0]['lifetime']);
        self::assertStringContainsString('nested block', $sites[0]['reason']);
    }

    /**
     * A spawn inside a branch, closed at the function's own level, IS short.
     *
     * THE GUARD AGAINST OVER-CORRECTING, and it is not hypothetical: the first
     * version of the depth rule compared against a fixed zero, and the second
     * spawn in `Hooks/ScriptHook.php::executeStaged()` - a retry inside an
     * `if`, closed once at the bottom of the method - came back unclassified.
     * Reddening correct code is how the next real offender buys its exemption,
     * so the shape that was wrongly flagged is pinned here in its own right.
     */
    public function testASpawnInsideABranchClosedAtFunctionLevelIsShortLived(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            class Retry {
                public function run(): int {
                    $pipes = [];
                    $process = @proc_open('a', [1 => ['pipe', 'w']], $pipes);
                    if (!is_resource($process)) {
                        $process = @proc_open('b', [1 => ['pipe', 'w']], $pipes);
                    }
                    return proc_close($process);
                }
            }
            PHP);

        self::assertCount(2, $sites);
        self::assertSame(ChildLifetimeScanner::LIFETIME_SHORT, $sites[0]['lifetime']);
        self::assertSame(ChildLifetimeScanner::LIFETIME_SHORT, $sites[1]['lifetime']);
    }

    /**
     * A close inside a LATER block is still conditional.
     *
     * Leaving the spawn's own block lowers the floor; entering a new one
     * raises the depth above it again. Without that second half the running
     * minimum would wave through any close that happened to follow a closed
     * brace.
     */
    public function testACloseInsideAnUnrelatedLaterBranchIsStillConditional(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            class Later {
                public function run(bool $tidy): void {
                    $pipes = [];
                    if (true) {
                        $process = @proc_open('a', [1 => ['pipe', 'w']], $pipes);
                    }
                    if ($tidy) {
                        proc_close($process);
                    }
                }
            }
            PHP);

        self::assertCount(1, $sites);
        self::assertSame(ChildLifetimeScanner::LIFETIME_UNCLASSIFIED, $sites[0]['lifetime']);
        self::assertStringContainsString('nested block', $sites[0]['reason']);
    }

    /**
     * A handle that leaves the function inside a returned array literal.
     *
     * `return [$proc, $pipes];` is the commonest escape spelling in this tree,
     * and a bare `return $proc` check would miss every one of them - a miss in
     * the polarity that reports a leaked child as fine.
     */
    public function testAHandleReturnedInsideAnArrayIsLongLived(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            class Runner {
                public function spawn(): array {
                    $pipes = [];
                    $proc = @proc_open('ls', [1 => ['pipe', 'w']], $pipes);
                    return [$proc, $pipes];
                }
            }
            PHP);

        self::assertSame(ChildLifetimeScanner::LIFETIME_LONG, $sites[0]['lifetime']);
    }

    public function testAHandleReturnedDirectlyIsLongLived(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            function spawn(array &$pipes) {
                return proc_open('ls', [1 => ['pipe', 'w']], $pipes);
            }
            PHP);

        self::assertSame(ChildLifetimeScanner::LIFETIME_LONG, $sites[0]['lifetime']);
        self::assertSame('spawn', $sites[0]['function']);
    }

    public function testAHandleAppendedToAPropertyArrayIsLongLived(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            class Pool {
                private array $children = [];
                public function add(string $id): void {
                    $pipes = [];
                    $proc = @proc_open('ls', [1 => ['pipe', 'w']], $pipes);
                    $this->children[$id] = $proc;
                }
            }
            PHP);

        self::assertSame(ChildLifetimeScanner::LIFETIME_LONG, $sites[0]['lifetime']);
    }

    /**
     * A function that both STORES the handle and closes it is LONG.
     *
     * The assignment escape beats the close, because a child reachable from
     * `$this` can outlive the call on any path that does not reach the
     * `proc_close()`, and reading the close as proof of a short life is the
     * polarity that hides a leak.
     *
     * ⚠️ THE CALL SPELLING IS THE OTHER WAY ROUND, pinned in
     * {@see testAnUnconditionalCloseBeatsACallEscape()} beside this one so the
     * asymmetry cannot be read as an accident. It is not "escape wins"; it is
     * "an assignment escape wins", and the reason the two differ is in
     * {@see ChildLifetimeScanner::classifyLifetime()}'s doc-block.
     */
    public function testEscapeBeatsCloseWhenAFunctionDoesBoth(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            class Both {
                private $handle;
                public function go(bool $keep): void {
                    $pipes = [];
                    $proc = @proc_open('ls', [1 => ['pipe', 'w']], $pipes);
                    $this->handle = $proc;
                    if (!$keep) {
                        proc_close($proc);
                    }
                }
            }
            PHP);

        self::assertSame(ChildLifetimeScanner::LIFETIME_LONG, $sites[0]['lifetime']);
    }

    /**
     * A function that HANDS the handle somewhere and then closes it is SHORT.
     *
     * THE HALF OF "ESCAPE WINS" THAT DOES NOT WIN, and it is correct that it
     * does not. The question this scanner asks is whether the CHILD outlives
     * the call; an unconditional `proc_close()` ends it whatever some other
     * function did with the handle beforehand. A stored handle is different
     * because it stays reachable from a path that never reaches the close.
     *
     * Pinned so that a reader who takes the doc-block's ordering literally and
     * "fixes" the call spelling to match reds here and finds the argument.
     */
    public function testAnUnconditionalCloseBeatsACallEscape(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            class Both {
                public function go(): void {
                    $pipes = [];
                    $proc = @proc_open('ls', [1 => ['pipe', 'w']], $pipes);
                    $this->register($proc);
                    proc_close($proc);
                }
            }
            PHP);

        self::assertSame(ChildLifetimeScanner::LIFETIME_SHORT, $sites[0]['lifetime']);
    }

    /**
     * Shapes the scanner cannot follow are FAILURES, not silent passes.
     *
     * @dataProvider unfollowableHandles
     */
    public function testAnUnfollowableHandleIsUnclassifiedRatherThanShort(string $body): void
    {
        $sites = $this->sitesIn("<?php\nclass C {\n    public function m(array \$pipes) {\n{$body}\n    }\n}\n");

        self::assertCount(1, $sites);
        self::assertSame(
            ChildLifetimeScanner::LIFETIME_UNCLASSIFIED,
            $sites[0]['lifetime'],
            'A handle this scanner cannot follow must be reported, not assumed short-lived: ' . $sites[0]['reason'],
        );
    }

    /** @return iterable<string, array{0:string}> */
    public static function unfollowableHandles(): iterable
    {
        yield 'result discarded' => ['        @proc_open("ls", [1 => ["pipe", "w"]], $pipes);'];
        yield 'passed straight into a call' => ['        $this->register(@proc_open("ls", [1 => ["pipe", "w"]], $pipes));'];
        yield 'handed to a local nothing does anything with' => [
            '        $p = @proc_open("ls", [1 => ["pipe", "w"]], $pipes);' . "\n" . '        return 1;',
        ];
        yield 'destructured' => ['        [$a, $b] = [@proc_open("ls", [1 => ["pipe", "w"]], $pipes), 1];'];
    }

    /**
     * Descriptor specs the scanner can resolve, and specs it must refuse.
     *
     * A REFUSAL IS `null`, NOT AN EMPTY LIST, and the difference is the whole
     * point: `[]` says "the spec names no fds", which is a claim, and `null`
     * says "I cannot tell", which is a failure the guard reds on.
     *
     * @param list<int>|null $expected
     * @dataProvider descriptorSpecs
     */
    public function testTheFdSetIsReadOrRefused(string $source, ?array $expected): void
    {
        $sites = $this->sitesIn($source);

        self::assertCount(1, $sites);
        self::assertSame($expected, $sites[0]['fds']);
    }

    /** @return iterable<string, array{0:string,1:list<int>|null}> */
    public static function descriptorSpecs(): iterable
    {
        $wrap = static fn (string $spec): string => "<?php\nclass C {\n    private \$h;\n    public function m(array \$pipes) {\n"
            . "        \$this->h = @proc_open('x', {$spec}, \$pipes);\n    }\n}\n";

        yield 'inline keyed' => [$wrap("[0 => ['pipe','r'], 2 => ['pipe','w']]"), [0, 2]];
        yield 'inline positional' => [$wrap("[['pipe','r'], ['pipe','w'], ['pipe','w']]"), [0, 1, 2]];
        yield 'long array syntax' => [$wrap("array(1 => array('pipe','w'))"), [1]];
        yield 'empty spec' => [$wrap('[]'), []];
        yield 'high fd named' => [$wrap("[2 => ['pipe','w'], 7 => ['file','/dev/null','w']]"), [2, 7]];

        // Mixed keyed/positional: PHP gives a positional element ONE GREATER
        // THAN THE LARGEST INTEGER KEY SO FAR, so position no longer tells you
        // the fd. (Not "the next free key", which this comment used to say and
        // which is a different rule: measured on PHP 8.3.6, `[5 => 'a',
        // 0 => 'b', 'c']` has keys 5, 0 and 6, where "next free" predicts 1.)
        yield 'mixed keyed and positional' => [$wrap("[['pipe','r'], 2 => ['pipe','w']]"), null];

        // A key that is not an integer literal names an fd this scanner cannot
        // evaluate.
        yield 'constant key' => [$wrap("[self::FD_ERR => ['pipe','w']]"), null];

        // A STRING-SPELLED INTEGER KEY IS AN INTEGER KEY. Measured on PHP
        // 8.3.6: `["0" => x]` has the int key 0. Reading it as unknowable
        // would make the guard red an ordinary spec while telling its author
        // not to add an exemption - a red on correct code, which is where the
        // next real offender buys its row.
        yield 'double-quoted integer keys' => [
            $wrap('["0" => [\'pipe\',\'r\'], "1" => [\'pipe\',\'w\'], "2" => [\'pipe\',\'w\']]'),
            [0, 1, 2],
        ];
        yield 'single-quoted integer key' => [$wrap("['3' => ['file','/dev/null','w']]"), [3]];

        // ...but only the CANONICAL spelling converts. `"01"` stays a string
        // key naming no fd at all, so the honest answer is that the spec is
        // unreadable, not that fd 1 is covered.
        yield 'non-canonical quoted key' => [$wrap('["01" => [\'pipe\',\'r\']]'), null];

        // Not an array literal at all.
        yield 'method call' => [$wrap('$this->descriptors()'), null];

        yield 'local variable' => [
            "<?php\nclass C {\n    private \$h;\n    public function m(array \$pipes) {\n"
            . "        \$d = [2 => ['pipe','w']];\n"
            . "        \$this->h = @proc_open('x', \$d, \$pipes);\n    }\n}\n",
            [2],
        ];

        yield 'class constant in the same file' => [
            "<?php\nclass C {\n    private const DESCRIPTOR = [0 => ['pipe','r'], 1 => ['pipe','w']];\n"
            . "    private \$h;\n    public function m(array \$pipes) {\n"
            . "        \$this->h = @proc_open('x', self::DESCRIPTOR, \$pipes);\n    }\n}\n",
            [0, 1],
        ];

        // The scope floor: a spec assigned in an EARLIER method is not this
        // call's spec, and borrowing it would be an answer from another
        // function's body.
        yield 'variable assigned in a different method' => [
            "<?php\nclass C {\n    private \$h;\n"
            . "    public function other(): void { \$d = [2 => ['pipe','w']]; }\n"
            . "    public function m(array \$pipes) {\n"
            . "        \$this->h = @proc_open('x', \$d, \$pipes);\n    }\n}\n",
            null,
        ];
    }

    /**
     * `proc_open` appearing as something other than a direct global call.
     *
     * `intval()` is not a cast and `$this->proc_open()` is not the launcher;
     * an alphabet written to match only the cases already known has a hole
     * shaped exactly like the next defect. These are not sites, and they are
     * not silence either.
     *
     * @dataProvider appearancesThatAreNotCalls
     */
    public function testAnAppearanceThatIsNotACallIsReportedRatherThanDropped(string $source, string $kind): void
    {
        $scanned = ChildLifetimeScanner::scan($source);

        self::assertSame([], $scanned['sites'], 'This is not a spawn site.');
        self::assertCount(1, $scanned['unresolved']);
        self::assertSame($kind, $scanned['unresolved'][0]['kind']);
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function appearancesThatAreNotCalls(): iterable
    {
        yield 'method call' => [
            "<?php\nclass C { public function m() { \$this->proc_open('x'); } }\n",
            ChildLifetimeScanner::REF_METHOD,
        ];
        yield 'nullsafe method call' => [
            "<?php\nclass C { public function m(\$o) { \$o?->proc_open('x'); } }\n",
            ChildLifetimeScanner::REF_METHOD,
        ];
        yield 'static call' => [
            "<?php\nclass C { public function m() { Shell::proc_open('x'); } }\n",
            ChildLifetimeScanner::REF_STATIC,
        ];
        yield 'declaration' => [
            "<?php\nfunction proc_open(\$c) { return null; }\n",
            ChildLifetimeScanner::REF_DECLARATION,
        ];

        // Built by concatenation rather than spelled out, so a future textual
        // sweep over the name cannot quietly rewrite the fixture that proves
        // this branch fires.
        yield 'string reference' => [
            "<?php\nfunction m() { return function_exists('" . 'proc_' . "open'); }\n",
            ChildLifetimeScanner::REF_STRING,
        ];
    }

    /**
     * A doc-block is source text, and a doc-block that spells a descriptor
     * spec sits in the token stream exactly where the code would.
     *
     * PAIRED WITH ITS OWN POSITIVE, in this test rather than a neighbouring
     * one. "Zero sites" is also what a deleted scanner returns, so a fixture
     * whose whole expectation is a zero proves nothing on its own; the second
     * half runs the SAME scanner over the SAME text with the comment markers
     * gone and requires a site to appear.
     */
    public function testProseAboutASpawnIsNotASpawn(): void
    {
        $code = "\$this->h = @proc_open('x', [2 => ['pipe','w']], \$pipes);";

        $commented = "<?php\nclass C {\n    /**\n     * {$code}\n     */\n    public function m() {}\n}\n";
        $live = "<?php\nclass C {\n    private \$h;\n    public function m(array \$pipes) {\n        {$code}\n    }\n}\n";

        self::assertSame([], ChildLifetimeScanner::scan($commented)['sites']);
        self::assertSame([], ChildLifetimeScanner::scan($commented)['unresolved']);
        self::assertCount(1, ChildLifetimeScanner::scan($live)['sites']);
    }

    public function testTheFullyQualifiedSpellingIsSeen(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            namespace App;
            class C {
                private $h;
                public function m(array $pipes) {
                    $this->h = \proc_open('x', [2 => ['pipe','w']], $pipes);
                }
            }
            PHP);

        self::assertCount(1, $sites);
        self::assertSame(ChildLifetimeScanner::LIFETIME_LONG, $sites[0]['lifetime']);
    }

    /**
     * A tree that grows a reaping helper stops spelling `proc_close()`.
     *
     * NOT A HYPOTHETICAL. Scanning a concurrent lane's `src/` with this class
     * found `Providers/ClaudeCodeProvider.php` reaping through
     * `ProcessReaper::terminateAndClose($process)` where this tree still
     * spells `proc_close($process)`; the site read as short-lived here and as
     * "nothing returns, stores or proc_close()s it" there, on a change that
     * ADDED a bounded SIGTERM->SIGKILL ladder. A guard that reds the stricter
     * version of the code earns an exemption row written for correct code,
     * and that row is where the next real offender hides.
     */
    public function testARosteredReapingHelperClosesTheChild(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            use SugarCraft\Crush\Support\ProcessReaper;
            function m(array $pipes) {
                $h = proc_open('x', [2 => ['pipe','w']], $pipes);
                ProcessReaper::terminateAndClose($h);
            }
            PHP);

        self::assertSame(ChildLifetimeScanner::LIFETIME_SHORT, $sites[0]['lifetime']);
        self::assertStringContainsString(
            'ProcessReaper::terminateAndClose($h)',
            $sites[0]['reason'],
            'the reason must name the call that actually reaps; a message that always says '
                . 'proc_close describes a line the reader will not find at the site',
        );
    }

    /**
     * The roster is keyed on `Class::method`, and the class half is load-bearing.
     *
     * ⚠️ THE DANGEROUS POLARITY, which is why it is pinned beside the useful
     * one. A roster matched on the method name alone would let ANY class with
     * a `terminateAndClose()` reap a handle as far as this scanner is
     * concerned - and a wrongly-short child is the reading that waves a real
     * leak through, the one direction this class exists to refuse.
     */
    public function testARosteredMethodNameOnAnotherClassIsNotAClose(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            function m(array $pipes) {
                $h = proc_open('x', [2 => ['pipe','w']], $pipes);
                Bookkeeping::terminateAndClose($h);
            }
            PHP);

        self::assertSame(ChildLifetimeScanner::LIFETIME_UNCLASSIFIED, $sites[0]['lifetime']);
    }

    /**
     * An empty roster is a dead roster, and every row above would still pass.
     *
     * Rule 25's shape: the assertions in the two tests above are about what
     * the roster DOES, so they cannot notice a roster that has been emptied to
     * make something else green.
     */
    public function testTheClosingHelperRosterIsNotEmpty(): void
    {
        self::assertNotSame([], ChildLifetimeScanner::CLOSING_HELPERS);
        self::assertNotSame([], ChildLifetimeScanner::BEST_EFFORT_REAPERS);

        $rosters = [
            'CLOSING_HELPERS' => ChildLifetimeScanner::CLOSING_HELPERS,
            'BEST_EFFORT_REAPERS' => ChildLifetimeScanner::BEST_EFFORT_REAPERS,
        ];

        foreach ($rosters as $name => $roster) {
            foreach ($roster as $helper => $why) {
                self::assertSame(
                    \strtolower($helper),
                    $helper,
                    "{$name} rows are compared lowercased, so a row with capitals can never "
                        . 'match: ' . $helper,
                );
                self::assertStringContainsString(
                    '::',
                    $helper,
                    "a bare method name in {$name} would let any class of that name count: " . $helper,
                );
                self::assertNotSame(
                    '',
                    \trim($why),
                    "{$name}[{$helper}] carries no reason. The reason is the only thing that "
                        . 'distinguishes a measured row from a guess about a method in another '
                        . 'package, read off its name.',
                );
            }
        }

        self::assertSame(
            [],
            \array_intersect_key(
                ChildLifetimeScanner::CLOSING_HELPERS,
                ChildLifetimeScanner::BEST_EFFORT_REAPERS,
            ),
            'a helper cannot both close on every path and close on only some of them; the two '
                . 'rosters produce opposite verdicts, so an overlap makes the answer depend on '
                . 'which branch runs first',
        );
    }

    /**
     * EVERY ROSTERED ROW IS SPENT, one synthetic site per row.
     *
     * WHAT THIS EXISTS TO CATCH, measured rather than assumed. Before it,
     * deleting `processreaper::reapifexited` from the roster SURVIVED the whole
     * of this file plus {@see DescriptorInheritanceGuardTest} - 41 tests, 99
     * assertions, rc 0 - because the only rows any fixture spelled were
     * `terminateAndClose` and a deliberately-unrostered `Bookkeeping::`. A
     * roster whose rows are individually free to be wrong is a roster whose
     * contract is decorative, and this one's rows can DELETE a finding.
     *
     * WHAT IT DOES NOT PROVE, said out loud so the next reader does not take
     * more from it than it offers: that the named helper really closes. That
     * is a fact about a method in `src/` - in another lane's package, at that -
     * and it cannot be settled from a synthetic string. What it does prove is
     * that the row is wired, that its polarity is the one the roster claims,
     * and that removing it costs a red. The residual is recorded in the
     * hardening backlog.
     *
     * @dataProvider rosteredHelpers
     */
    public function testEveryRosteredHelperProducesItsRostersVerdict(
        string $helper,
        string $expected,
    ): void {
        // Rebuilt from the lowercased roster key, so the fixture cannot drift
        // away from the row it is spending: `processreaper::terminateandclose`
        // becomes `Processreaper::terminateandclose($h)`, which matches
        // case-insensitively exactly as the roster promises.
        [$class, $method] = \explode('::', $helper, 2);
        $call = \ucfirst($class) . '::' . $method;

        $sites = $this->sitesIn(<<<PHP
            <?php
            function m(array \$pipes) {
                \$h = proc_open('x', [2 => ['pipe','w']], \$pipes);
                {$call}(\$h);
            }
            PHP);

        self::assertCount(1, $sites, 'the fixture for ' . $helper . ' produced no site');
        self::assertSame(
            $expected,
            $sites[0]['lifetime'],
            $helper . ' is rostered but the scanner does not give it that roster\'s verdict. A '
                . 'row nothing exercises is a row that can be wrong for free.',
        );
        self::assertStringContainsString(
            $method,
            \strtolower($sites[0]['reason']),
            'the reason must name the call that was actually found, not a generic proc_close()',
        );
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function rosteredHelpers(): iterable
    {
        foreach (ChildLifetimeScanner::CLOSING_HELPERS as $helper => $_) {
            yield 'closes: ' . $helper => [$helper, ChildLifetimeScanner::LIFETIME_SHORT];
        }
        foreach (ChildLifetimeScanner::BEST_EFFORT_REAPERS as $helper => $_) {
            yield 'best effort: ' . $helper => [$helper, ChildLifetimeScanner::LIFETIME_UNCLASSIFIED];
        }
    }

    /**
     * A BEST-EFFORT REAP IS NOT A CLOSE, and this is the row that was wrong.
     *
     * `ProcessReaper::reapIfExited()` was rostered in
     * {@see ChildLifetimeScanner::CLOSING_HELPERS} under a doc-block warning
     * that a row there "is a claim that the helper really closes". Read off
     * that method's own source rather than its name: it waits WITHOUT
     * signalling, and on the branch where the child is still running at the
     * end of the budget it `return null`s with the handle untouched. So it
     * reaps sometimes.
     *
     * WHY THAT MATTERED RATHER THAN BEING A WORDING QUIBBLE. With the row in
     * place the scanner answered {@see ChildLifetimeScanner::LIFETIME_SHORT}
     * for `Sessions/BackgroundSupervisor::spawnSession` - and
     * {@see DescriptorInheritanceGuardTest::exposedIn()} `continue`s past every
     * short site, so E366's own HIGH finding would have vanished from the
     * guard entirely on the merge that introduced the helper. Not reported and
     * exempted: gone.
     */
    public function testABestEffortReaperIsNotAClose(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            use SugarCraft\Crush\Support\ProcessReaper;
            function m(array $pipes) {
                $h = proc_open('x', [2 => ['pipe','w']], $pipes);
                ProcessReaper::reapIfExited($h);
            }
            PHP);

        self::assertNotSame(
            ChildLifetimeScanner::LIFETIME_SHORT,
            $sites[0]['lifetime'],
            'a helper that returns without closing when the child is still running does not end '
                . "the child's life, and reading it as short DELETES the site from the guard",
        );
        self::assertSame(ChildLifetimeScanner::LIFETIME_UNCLASSIFIED, $sites[0]['lifetime']);
        self::assertStringContainsString('BEST-EFFORT', $sites[0]['reason']);
    }

    /**
     * The unfollowable-call sentence must not PRESCRIBE the defect above.
     *
     * A handle handed to an unrostered call is unclassified with advice
     * attached, and the advice used to be "if one of those reaps the child,
     * roster it in CLOSING_HELPERS" - which is precisely the edit that hid
     * `spawnSession`. The advice now names both rosters.
     */
    public function testTheUnfollowableCallAdviceNamesBothRosters(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            function m(array $pipes) {
                $h = proc_open('x', [2 => ['pipe','w']], $pipes);
                $this->registry->adopt($h);
            }
            PHP);

        self::assertStringContainsString('CLOSING_HELPERS', $sites[0]['reason']);
        self::assertStringContainsString('BEST_EFFORT_REAPERS', $sites[0]['reason']);
    }

    /**
     * The reason names the close that ACTUALLY covered every path.
     *
     * A single overwritten `$closer` reported whichever reaping call came
     * last, so a function with an unconditional `proc_close()` followed by a
     * conditional helper said the CONDITIONAL one "runs unconditionally". The
     * verdict was right and the sentence sent the reader to the wrong line -
     * the same defect the named-closer change existed to remove.
     */
    public function testTheNamedCloserIsTheOneThatCoveredEveryPath(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            use SugarCraft\Crush\Support\ProcessReaper;
            function m(array $pipes, bool $extra) {
                $h = proc_open('x', [2 => ['pipe','w']], $pipes);
                proc_close($h);
                if ($extra) {
                    ProcessReaper::terminateAndClose($h);
                }
            }
            PHP);

        self::assertSame(ChildLifetimeScanner::LIFETIME_SHORT, $sites[0]['lifetime']);
        self::assertStringContainsString('proc_close($h) runs unconditionally', $sites[0]['reason']);
        self::assertStringNotContainsString(
            'terminateAndClose($h) runs unconditionally',
            $sites[0]['reason'],
            'the conditional call is inside an if; naming it as the unconditional one describes '
                . 'a line the reader will not find where the message says it is',
        );
    }

    /**
     * A handle inside an array literal is UNFOLLOWED, not untouched.
     *
     * `$a = ['p' => $h];` has no callee to name, so the escape branch does not
     * fire and the fall-through claimed "nothing in this function returns,
     * stores or proc_close()s $h" - flatly false about a function that plainly
     * puts it somewhere. Same family as the unfollowable-call sentence, and
     * the same rule: a reviewer can act on "I could not follow this", and
     * cannot act on a false absence.
     */
    public function testAHandleInsideAnArrayLiteralSaysSoRatherThanClaimingNothingHappens(): void
    {
        $member = $this->sitesIn(<<<'PHP'
            <?php
            function m(array $pipes) {
                $h = proc_open('x', [2 => ['pipe','w']], $pipes);
                $bundle = ['process' => $h];
                return $bundle;
            }
            PHP);
        $untouched = $this->sitesIn(<<<'PHP'
            <?php
            function m(array $pipes) {
                $h = proc_open('x', [2 => ['pipe','w']], $pipes);
            }
            PHP);

        self::assertSame(ChildLifetimeScanner::LIFETIME_UNCLASSIFIED, $member[0]['lifetime']);
        self::assertStringContainsString('appears again on line', $member[0]['reason']);
        self::assertStringNotContainsString('nothing in this function', $member[0]['reason']);

        // The other polarity, in the same test: a function that really does
        // nothing with the handle must still get the absence sentence, or the
        // fix above has simply replaced one always-wrong message with another.
        self::assertStringContainsString('nothing in this function', $untouched[0]['reason']);
    }

    /**
     * "Handed to something I cannot follow" is not "nothing happens to it".
     *
     * Both are {@see ChildLifetimeScanner::LIFETIME_UNCLASSIFIED}, and the
     * REASON is the whole difference: one tells a reviewer where to look and
     * the other is a confident false statement about a function that plainly
     * does something with the handle. Rule 14 one level in - a scanner that
     * cannot follow a call must say so rather than report an absence.
     */
    public function testAHandleHandedToAnUnknownCallSaysSoRatherThanClaimingNothingHappens(): void
    {
        $handed = $this->sitesIn(<<<'PHP'
            <?php
            function m(array $pipes) {
                $h = proc_open('x', [2 => ['pipe','w']], $pipes);
                $this->registry->adopt($h);
            }
            PHP);
        $untouched = $this->sitesIn(<<<'PHP'
            <?php
            function m(array $pipes) {
                $h = proc_open('x', [2 => ['pipe','w']], $pipes);
            }
            PHP);

        self::assertSame(ChildLifetimeScanner::LIFETIME_UNCLASSIFIED, $handed[0]['lifetime']);
        self::assertSame(ChildLifetimeScanner::LIFETIME_UNCLASSIFIED, $untouched[0]['lifetime']);

        self::assertStringContainsString('->adopt', $handed[0]['reason']);
        self::assertStringContainsString('nothing in this function', $untouched[0]['reason']);
        self::assertNotSame(
            $untouched[0]['reason'],
            $handed[0]['reason'],
            'the two must not share a sentence: a reviewer can act on the name of a call and '
                . 'cannot act on a false absence',
        );
    }

    /**
     * @return list<array{line:int,function:string,lifetime:string,reason:string,fds:list<int>|null,highFds:list<int>}>
     */
    /**
     * A closer inside `finally` runs on EVERY path out, so it is SHORT.
     *
     * THE POSITIVE CONTROL FOR THE `finally` RULE. Before this, the scanner
     * classified `Providers/ClaudeCodeProvider.php::completeStream` as
     * unclassified with the reason "runs only inside a nested block, so it
     * does not cover every path out of this function" - a sentence that is
     * flatly false about `finally`, and one that reddened correct code at the
     * round-53 merge. An exemption row for correct code is where the next real
     * offender hides, so the classifier learned the rule instead.
     */
    public function testACloserInsideFinallyRunsOnEveryPathAndIsShort(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            class C {
                public function m(array $pipes) {
                    $h = proc_open('x', [2 => ['pipe','w']], $pipes);
                    try {
                        $this->pump($h);
                    } finally {
                        proc_close($h);
                    }
                }
            }
            PHP);

        self::assertCount(1, $sites);
        self::assertSame(ChildLifetimeScanner::LIFETIME_SHORT, $sites[0]['lifetime']);
    }

    /**
     * THE NEGATIVE HALF, and the reason the rule is keyed by depth rather than
     * by a bare "saw a finally" flag: an `if` INSIDE a `finally` is still
     * conditional. A flag would have called this short and licensed exactly
     * the shape the guard exists to catch.
     */
    public function testACloserInsideAnIfInsideFinallyIsStillUnclassified(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            class C {
                public function m(array $pipes, bool $tidy) {
                    $h = proc_open('x', [2 => ['pipe','w']], $pipes);
                    try {
                        $this->pump($h);
                    } finally {
                        if ($tidy) {
                            proc_close($h);
                        }
                    }
                }
            }
            PHP);

        self::assertCount(1, $sites);
        self::assertSame(ChildLifetimeScanner::LIFETIME_UNCLASSIFIED, $sites[0]['lifetime']);
    }

    /**
     * And the other half of the same trap: a `finally` the spawn is NOT inside
     * still has to leave the block the spawn opened. A `finally` nested in a
     * `foreach` runs on every path out of the LOOP BODY, not out of the
     * function.
     */
    public function testAFinallyInsideALoopDoesNotCoverEveryPathOutOfTheFunction(): void
    {
        $sites = $this->sitesIn(<<<'PHP'
            <?php
            class C {
                public function m(array $pipes, array $jobs) {
                    $h = proc_open('x', [2 => ['pipe','w']], $pipes);
                    foreach ($jobs as $job) {
                        try {
                            $this->pump($h, $job);
                        } finally {
                            proc_close($h);
                        }
                    }
                }
            }
            PHP);

        self::assertCount(1, $sites);
        self::assertSame(ChildLifetimeScanner::LIFETIME_UNCLASSIFIED, $sites[0]['lifetime']);
    }

    private function sitesIn(string $source): array
    {
        return ChildLifetimeScanner::scan($source)['sites'];
    }
}

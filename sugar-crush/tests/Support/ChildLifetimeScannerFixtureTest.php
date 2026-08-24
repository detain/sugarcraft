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
     * A function that both stores the handle AND closes it is LONG.
     *
     * Escape beats close, because a child reachable from `$this` can outlive
     * the call on any path that does not reach the `proc_close()`, and reading
     * the close as proof of a short life is the polarity that hides a leak.
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

        // Mixed keyed/positional: PHP hands a positional element the next free
        // integer key, so position no longer tells you the fd.
        yield 'mixed keyed and positional' => [$wrap("[['pipe','r'], 2 => ['pipe','w']]"), null];

        // A key that is not an integer literal names an fd this scanner cannot
        // evaluate.
        yield 'constant key' => [$wrap("[self::FD_ERR => ['pipe','w']]"), null];

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
     * @return list<array{line:int,function:string,lifetime:string,reason:string,fds:list<int>|null,highFds:list<int>}>
     */
    private function sitesIn(string $source): array
    {
        return ChildLifetimeScanner::scan($source)['sites'];
    }
}

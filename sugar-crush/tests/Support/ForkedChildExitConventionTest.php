<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * THE CONVENTION: a child forked INSIDE the PHPUnit process must never leave
 * through a plain `exit()` or by falling off the end of its branch.
 *
 * Either one runs PHP's whole shutdown sequence a second time, in a second
 * process, over an object graph the child only has a COPY of. The
 * consequences are not hypothetical and each was measured in this tree
 * before it was written down:
 *
 *  - `React\EventLoop\Loop::get()` registers a shutdown function that RUNS
 *    the loop (`vendor/react/event-loop/src/Loop.php`), so a child inheriting
 *    a loop with any live watcher blocks at exit forever. This suite is
 *    shielded only because `tests/bootstrap.php` installs the loop with
 *    `Loop::set(new StreamSelectLoop())`, which never registers that hook -
 *    a shield that exists for an unrelated reason (ext-uv clock pinning) and
 *    could be reworked away without anybody connecting it to this.
 *  - An inherited destructor with a real OS-level side effect fires in the
 *    child: candy-core's `Tty`/`PosixBackend` putting the SHARED kernel tty
 *    back into cooked mode is the one that cost this project a four-round
 *    bug hunt ({@see \SugarCraft\Crush\Support\ForkedChild}).
 *  - PHPUnit's own after-test hooks run twice, so a `tearDown()` that removes
 *    a temp tree removes it out from under the parent that is still reading
 *    it ({@see \SugarCraft\Crush\Tests\Integration\MultiAgentRefactorTest}).
 *
 * The convention was documented on {@see \SugarCraft\Crush\Support\ForkedChild}
 * and honoured by everyone who had read that doc-block. Nothing MADE the next
 * fork honour it, which is what this file is: the scanner
 * ({@see ForkedChildExitScanner}) classifies every in-process fork's child
 * branch, and a shape outside the accepted set fails here rather than in
 * whatever unrelated test the orphan happens to corrupt.
 *
 * SCOPE. Only forks that happen INSIDE this process. Roughly half the
 * `pcntl_fork()` text in `tests/` sits in a heredoc that a test writes to
 * disk and runs as its own `php` process; a plain `exit()` there is correct,
 * and the scanner's doc-block explains how the two are told apart.
 */
final class ForkedChildExitConventionTest extends TestCase
{
    /**
     * Files whose child branch leaves through a plain `exit()`, with the
     * reason it is accepted THERE. Not a list of things to get around to:
     * every entry is either a deliberate exemption or a recorded open defect,
     * and says which.
     *
     * Keyed by path relative to `tests/`. Line numbers are deliberately not
     * recorded - they rot within a round and would make this a list nobody
     * dares touch.
     *
     * @var array<string,string>
     */
    private const ACCEPTED_BARE_EXIT = [
        'Support/ForkedChildTest.php' =>
            'DELIBERATE, and the bare exit IS the assertion: '
            . 'testPlainExitInAForkedChildNoLongerClobbersRawMode() exists to prove that '
            . 'candy-core\'s PID-aware PosixBackend::restore() makes a bare exit() safe for the '
            . 'termios case specifically. Rewriting it to exitNow() would delete the test.',

        'Integration/WorkflowResumptionTest.php' =>
            'RECORDED OPEN, and NOT closable from inside tests/. Both children here are driving '
            . 'WorkflowEngine\'s real SIGTERM handler, which itself ends in a plain '
            . '`exit($signo === SIGINT ? 130 : 143)` (src/Workflows/WorkflowEngine.php) - that is '
            . 'the shape the PASSING path takes. The bare `exit(99)`/`exit(98)` the scanner sees '
            . 'are only the unreachable "the handler did not fire" sentinels beside it. Converting '
            . 'the sentinels alone would turn this row green while leaving the path it stands for '
            . 'entirely untouched, which is worse than the row.',

        'Agents/TaskListTest.php' =>
            'RECORDED OPEN, and found only because the scanner learned to read '
            . '`\\pcntl_fork()`. testForkedClaimRace() forks $childCount claimants and one '
            . 'completer, all spelled with a leading backslash, and every child branch ends in a '
            . 'plain exit(0) inside PHPUnit. They were invisible to the census until '
            . 'T_NAME_FULLY_QUALIFIED was added to the fork token types - the guard reported this '
            . 'file as having no forks at all. Out of this lane\'s file split to fix; they should '
            . 'be ForkedChild::exitNow(0).',

        'Agents/MailboxTest.php' =>
            'RECORDED OPEN. testCrossProcessWake()\'s child sends a real Mailbox message and then '
            . 'falls into a plain exit(0) inside PHPUnit. It has no inherited tty and no armed '
            . 'loop watcher today, which is why it has not bitten - but that is a property of '
            . 'what the test currently does, not a reason. It should be ForkedChild::exitNow(0).',
    ];

    /**
     * Shapes that leave WITHOUT running PHP's shutdown sequence.
     *
     * @var list<string>
     */
    private const SAFE_SHAPES = [
        ForkedChildExitScanner::SHAPE_EXIT_NOW,
        ForkedChildExitScanner::SHAPE_NEVER_HELPER,
        ForkedChildExitScanner::SHAPE_FORK_WRAPPER,
    ];

    /**
     * @return array<string,list<array{line:int,spelling:string,shape:string}>>
     */
    private function census(): array
    {
        $root = \dirname(__DIR__);
        $out = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $sites = ForkedChildExitScanner::scan((string) file_get_contents($file->getPathname()));
            if ($sites !== []) {
                $out[substr($file->getPathname(), \strlen($root) + 1)] = $sites;
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * THE SCANNER IS ALIVE, proved in the same test that asserts an absence
     * with it. Every shape the census can report is produced here from a
     * fixture whose answer is known, INCLUDING the two failure shapes - an
     * assertion that "nothing is unsafe" is worth nothing if the instrument
     * that decides "unsafe" has quietly stopped matching.
     *
     * The last fixture is the one that most needs proving: a `pcntl_fork()`
     * inside a heredoc is a DIFFERENT PROCESS's fork and must produce no site
     * at all. If the scanner ever started counting those, the census below
     * would fill with rows nobody can act on and the real ones would drown.
     */
    public function testTheScannerReportsEveryShapeItClaimsToDistinguish(): void
    {
        $head = "<?php\nclass F {\n  public function t(): void {\n";
        $tail = "  }\n}\n";

        $shape = static fn (string $body): array => ForkedChildExitScanner::scan($head . $body . $tail);

        $safe = $shape('    $pid = pcntl_fork();' . "\n" . '    if ($pid === 0) { ForkedChild::exitNow(0); }' . "\n");
        $this->assertCount(1, $safe);
        $this->assertSame(ForkedChildExitScanner::SHAPE_EXIT_NOW, $safe[0]['shape']);
        $this->assertSame('pcntl_fork', $safe[0]['spelling']);

        $bare = $shape('    $pid = pcntl_fork();' . "\n" . '    if ($pid === 0) { exit(0); }' . "\n");
        $this->assertCount(1, $bare);
        $this->assertSame(ForkedChildExitScanner::SHAPE_BARE_EXIT, $bare[0]['shape']);

        $die = $shape('    $pid = pcntl_fork();' . "\n" . '    if ($pid === 0) { die(1); }' . "\n");
        $this->assertSame(ForkedChildExitScanner::SHAPE_BARE_EXIT, $die[0]['shape']);

        $through = $shape('    $pid = pcntl_fork();' . "\n" . '    if ($pid === 0) { usleep(1); }' . "\n");
        $this->assertCount(1, $through);
        $this->assertSame(ForkedChildExitScanner::SHAPE_FALLS_THROUGH, $through[0]['shape']);

        // The failure branch is skipped, not mistaken for the child's.
        $twoBranches = $shape(
            '    $pid = pcntl_fork();' . "\n"
            . '    if ($pid === -1) { $this->fail("no fork"); }' . "\n"
            . '    if ($pid === 0) { ForkedChild::exitNow(0); }' . "\n",
        );
        $this->assertSame(ForkedChildExitScanner::SHAPE_EXIT_NOW, $twoBranches[0]['shape']);

        $mystery = $shape('    $pid = pcntl_fork();' . "\n" . '    if ($pid > 0) { usleep(1); }' . "\n");
        $this->assertSame(ForkedChildExitScanner::SHAPE_UNCLASSIFIED, $mystery[0]['shape']);

        // THE MUTATION THAT GOT THROUGH, kept as a fixture. A branch whose
        // LAST statement is a plain exit is unsafe even though a nested
        // branch inside it leaves correctly. Searching the branch's whole
        // text for the terminator - which is what the first version of the
        // scanner did - reported this as `exitNow`, and the census stayed
        // green with the defect reintroduced into
        // ParallelToolCallsTest by hand.
        $masked = $shape(
            '    $pid = pcntl_fork();' . "\n"
            . '    if ($pid === 0) {' . "\n"
            . '        $probe = pcntl_fork();' . "\n"
            . '        if ($probe === 0) { ForkedChild::exitNow(0); }' . "\n"
            . '        exit(0);' . "\n"
            . '    }' . "\n",
        );
        $this->assertSame(
            ForkedChildExitScanner::SHAPE_BARE_EXIT,
            $masked[0]['shape'],
            'a nested safe exit masked the branch\'s own plain exit',
        );

        // A branch that ends in a BLOCK rather than a statement is named, not
        // waved through.
        $block = $shape(
            '    $pid = pcntl_fork();' . "\n"
            . '    if ($pid === 0) { try { ForkedChild::exitNow(0); } catch (\\Throwable $e) {} }' . "\n",
        );
        $this->assertSame(ForkedChildExitScanner::SHAPE_UNCLASSIFIED, $block[0]['shape']);

        $never = ForkedChildExitScanner::scan(
            "<?php\nclass F {\n"
            . '  public function t(): void { $pid = pcntl_fork(); if ($pid === 0) { $this->go(); } }' . "\n"
            . '  private function go(): never { exit(0); }' . "\n}\n",
        );
        $this->assertCount(1, $never);
        $this->assertSame(ForkedChildExitScanner::SHAPE_NEVER_HELPER, $never[0]['shape']);

        // The reaper trait's wrapper is a fork site under its own name, so
        // adopting the trait cannot make a site invisible here.
        $tracked = $shape('    $pid = $this->forkTracked();' . "\n" . '    if ($pid === 0) { exit(0); }' . "\n");
        $this->assertCount(1, $tracked);
        $this->assertSame('forkTracked', $tracked[0]['spelling']);
        $this->assertSame(ForkedChildExitScanner::SHAPE_BARE_EXIT, $tracked[0]['shape']);

        // THE SECOND MUTATION THAT GOT THROUGH, kept as a fixture. One
        // leading backslash made a site VANISH: `\pcntl_fork` is a single
        // T_NAME_FULLY_QUALIFIED token, not a T_STRING, and the scanner
        // tested T_STRING alone. Two real offenders in
        // tests/Agents/TaskListTest.php were reported as not existing.
        $qualified = $shape('    $pid = \\pcntl_fork();' . "\n" . '    if ($pid === 0) { exit(0); }' . "\n");
        $this->assertCount(1, $qualified, 'a fully-qualified \\pcntl_fork() is still a fork site');
        $this->assertSame(ForkedChildExitScanner::SHAPE_BARE_EXIT, $qualified[0]['shape']);
        $this->assertSame(
            'pcntl_fork',
            $qualified[0]['spelling'],
            'the spelling is normalised without its leading backslash',
        );

        // THE THIRD, and it is rule 17 in one line: a doc-block wraps, so
        // prose about a terminator sits in the same source text the
        // terminator would. Classifying on rendered text meant a COMMENT
        // naming the safe helper made a plain exit read as safe.
        $commented = $shape(
            '    $pid = pcntl_fork();' . "\n"
            . '    if ($pid === 0) {' . "\n"
            . '        // ForkedChild::exitNow(0) is not usable on this path.' . "\n"
            . '        exit(0);' . "\n"
            . '    }' . "\n",
        );
        $this->assertSame(
            ForkedChildExitScanner::SHAPE_BARE_EXIT,
            $commented[0]['shape'],
            'a comment naming the safe helper is not the safe helper',
        );

        // Same window, via a string literal rather than a comment.
        $quoted = $shape(
            '    $pid = pcntl_fork();' . "\n"
            . '    if ($pid === 0) { $log("call ForkedChild::exitNow next"); }' . "\n",
        );
        $this->assertSame(ForkedChildExitScanner::SHAPE_FALLS_THROUGH, $quoted[0]['shape']);

        // And the same window for a `never` helper named only in prose.
        $neverProse = ForkedChildExitScanner::scan(
            "<?php\nclass F {\n"
            . '  public function t(): void { $pid = pcntl_fork(); if ($pid === 0) {' . "\n"
            . '    // $this->go() would be correct here.' . "\n"
            . '    exit(0); } }' . "\n"
            . '  private function go(): never { exit(0); }' . "\n}\n",
        );
        $this->assertSame(ForkedChildExitScanner::SHAPE_BARE_EXIT, $neverProse[0]['shape']);

        // A fork WRAPPER returns to its caller in both processes; that is its
        // contract, not a fall-through. The shape is granted only inside a
        // function this scanner already treats as a fork spelling.
        $wrapper = ForkedChildExitScanner::scan(
            "<?php\ntrait T {\n"
            . '  protected function forkTracked(): int {' . "\n"
            . '    $pid = \\pcntl_fork();' . "\n"
            . '    if ($pid === 0) { $this->ledger = []; return 0; }' . "\n"
            . '    return $pid;' . "\n"
            . "  }\n}\n",
        );
        $this->assertCount(1, $wrapper);
        $this->assertSame(ForkedChildExitScanner::SHAPE_FORK_WRAPPER, $wrapper[0]['shape']);

        // The SAME branch in a function that is not a fork spelling is a
        // child returning into the test runner - the defect, not the wrapper.
        $notWrapper = ForkedChildExitScanner::scan(
            "<?php\nclass F {\n"
            . '  public function t(): int {' . "\n"
            . '    $pid = \\pcntl_fork();' . "\n"
            . '    if ($pid === 0) { return 0; }' . "\n"
            . '    return $pid;' . "\n"
            . "  }\n}\n",
        );
        $this->assertSame(
            ForkedChildExitScanner::SHAPE_FALLS_THROUGH,
            $notWrapper[0]['shape'],
            'only a function named as a fork spelling may return from a child branch',
        );

        // A fork in a heredoc runs in a DIFFERENT process. No site at all.
        $embedded = ForkedChildExitScanner::scan(
            "<?php\n" . '$script = <<<PHP' . "\n"
            . '    \$pid = pcntl_fork();' . "\n"
            . '    if (\$pid === 0) { exit(0); }' . "\n"
            . "    PHP;\n",
        );
        $this->assertSame([], $embedded, 'a fork inside a heredoc is another process\'s fork');
    }

    public function testEveryInProcessForkedChildLeavesWithoutRunningPhpunitsShutdown(): void
    {
        $census = $this->census();
        $this->assertNotSame([], $census, 'the scanner found no in-process forks at all - it is dead');

        $offenders = [];
        foreach ($census as $file => $sites) {
            foreach ($sites as $site) {
                if (\in_array($site['shape'], self::SAFE_SHAPES, true)) {
                    continue;
                }
                if ($site['shape'] === ForkedChildExitScanner::SHAPE_BARE_EXIT
                    && isset(self::ACCEPTED_BARE_EXIT[$file])) {
                    continue;
                }

                $offenders[] = $file . ':' . $site['line'] . ' (' . $site['shape'] . ')';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "a forked child here runs PHPUnit's shutdown sequence in a second process. End the "
                . "child branch with ForkedChild::exitNow(), or delegate to a helper declared "
                . "`: never`. If the plain exit is genuinely the point, add the FILE to "
                . 'ForkedChildExitConventionTest::ACCEPTED_BARE_EXIT with the reason. A shape of '
                . '"unclassified" means the scanner could not find the child branch at all, which '
                . "is a hole in the guard rather than a licence: teach the scanner the shape.",
        );
    }

    /**
     * The exemption list cannot rot into a list of things that are already
     * fixed. A file that no longer has a bare exit must lose its row, so the
     * reason it carries stays a live claim about live code.
     */
    public function testEveryAcceptedBareExitFileStillHasOne(): void
    {
        $census = $this->census();

        foreach (self::ACCEPTED_BARE_EXIT as $file => $reason) {
            $shapes = array_column($census[$file] ?? [], 'shape');

            $this->assertContains(
                ForkedChildExitScanner::SHAPE_BARE_EXIT,
                $shapes,
                "{$file} is listed in ACCEPTED_BARE_EXIT but no longer has a bare exit in a forked "
                    . 'child. Delete its row - the reason it carries is no longer about anything.',
            );
            $this->assertNotSame('', trim($reason), "{$file} is exempted without a reason");
        }
    }
}

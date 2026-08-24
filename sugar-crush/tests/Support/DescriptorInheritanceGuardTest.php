<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * No `proc_open()` child in `src/` may outlive the call that spawned it while
 * its descriptor spec declines to say anything about fd 3 and above.
 *
 * WHY THE PAIR AND NOT EITHER HALF. `proc_open()` remaps only the fds its spec
 * names; the child inherits every other descriptor the parent had open. For a
 * child closed where it was spawned that lasts microseconds. For an MCP
 * server, a language server or a session daemon it lasts as long as the child
 * does - and E365 is what that costs: a leaked `php -S` held the write end of
 * the caller's stdout on fd 4, so `vendor/bin/phpunit | tail` blocked forever
 * on an EOF that never came, after a green run. Two measurements were lost to
 * it, one of 11.5 hours.
 *
 * NO COUNT IS ASSERTED ANYWHERE IN THIS FILE, deliberately. E366's HIGH list
 * was five sites on the day it was written, and the round that acted on it had
 * four of those files open in another lane. A census pinned to "five" reds on
 * the commit that lands the fix, and the red looks like the fixer's defect
 * rather than the instrument's brittleness. What is asserted is the SHAPE:
 * every exposed spawn is either handled or carries a row here saying why not,
 * and every row still matches something.
 *
 * THE ROSTER IS KEYED BY SYMBOL, NOT BY LINE. Line numbers in this tree rot
 * inside one round; `File.php::method` survives everything except a rename,
 * and a rename is a thing a reviewer should see.
 *
 * ⚠️ THIS GUARD READS FILES OTHER LANES OWN. `Agents/ProcessExecutor.php`,
 * `Commands/CommandSpec.php`, `Sessions/BackgroundSupervisor.php` and the rest
 * are not this file's to edit, and this file does not edit them - it counts
 * them. If a merge makes it red, read
 * {@see testEveryExposedSpawnIsHandledOrAccountedFor()}'s message: the fix is a
 * data edit in the roster below, in the direction the message names, and never
 * a weakening of the check.
 */
final class DescriptorInheritanceGuardTest extends TestCase
{
    /**
     * Spawns whose child outlives the call with nothing said about fd 3+.
     *
     * A ROW IS NOT AN EXCUSE, IT IS A RECORD. Everything here is E366's own
     * finding, kept where the instrument can see it go stale rather than in a
     * backlog file nothing executes. Deleting a row because it is inconvenient
     * makes the guard red, not green.
     *
     * EVERY ROW CARRIES A COUNT, and it is spent one site at a time. WHAT THIS
     * MAP USED TO BE: `File.php::function => reason`, with membership tested
     * by `isset()`. WHAT IS TRUE NOW - measured, not anticipated: one row
     * absorbed unboundedly many spawns in the same function. Injecting a
     * SECOND long-lived `proc_open()` with nothing said about fd 3+ into
     * `MCP/StdioMcpServer::start()`, which has a row, left this guard green -
     * 5 tests, 13 assertions, rc 0. The identical spawn in a method with no
     * row reddened it. So the guard was live everywhere except behind its own
     * exemptions, which is where a new offender is most likely to be added:
     * `Hooks/ScriptHook.php::executeStaged()` already holds two `proc_open()`
     * sites in one function today.
     *
     * WHY THIS STILL EARNS ITS PLACE: the reason text is unchanged and is
     * still the point of the row. The count is not a headcount of the tree -
     * it is the SIZE OF THE LICENCE, and a file-keyed exemption without one is
     * a blank cheque. Same shape, and for the same reason, as
     * {@see ChildStderrCaptureTest::ACCEPTED_DISCARDED_STDERR}.
     *
     * @var array<string, array{count:int, reason:string}>
     */
    private const ACCOUNTED_FOR = [
        // E366 HIGH. Third-party stdio MCP server, held in `$this->process`
        // for the life of the client; `disconnect()` is fclose + a bare
        // `proc_close()`. The child is not ours and inherits whatever the host
        // had open at spawn.
        'ClaudeCodeMcpClient.php::connect' => [
            'count' => 1,
            'reason' => 'long-lived third-party MCP server; E366 HIGH, fix deferred with the '
                . 'finding recorded',
        ],

        // E366 HIGH. Language server, `$this->process`; `stopProcess()`
        // terminates and immediately closes, and there is no `__destruct()`.
        'LSP/LspConnection.php::connect' => [
            'count' => 1,
            'reason' => 'long-lived language server; E366 HIGH, fix deferred with the finding '
                . 'recorded',
        ],

        // Reaping here is already the reference implementation - SIGTERM, poll,
        // SIGKILL - which is why E366 called this one the fixed twin. The
        // REAPING being right is not the fd half being right: the child is
        // still long-lived and still inherits fd 3+.
        'MCP/StdioMcpServer.php::start' => [
            'count' => 1,
            'reason' => 'long-lived stdio server; reaping is correct, descriptor inheritance is '
                . 'not addressed',
        ],

        // E366 HIGH. Deliberately double-forks into a session daemon, and the
        // only `proc_close()` is on the handshake-timeout branch - the happy
        // path never reaps. The scanner reports `unclassified` for exactly
        // that reason and is right to.
        'Sessions/BackgroundSupervisor.php::spawnSession' => [
            'count' => 1,
            'reason' => 'double-forked session daemon whose happy path never reaps; E366 HIGH',
        ],

        // E366 MEDIUM. The handle goes into a local array literal, that array
        // into `$this->processes[$id]`, and the array is returned as well.
        //
        // THE REASON THE READER WILL SEE IS NOT THE ONE THIS COMMENT USED TO
        // GIVE. It said the scanner reports `unclassified` because "the handle
        // escapes through an array member", which is true of the code and is
        // not what the instrument says: `is_resource($process)` is called on
        // the handle first, so the escape branch fires on THAT and the failure
        // output names `is_resource`. A row whose comment describes a
        // different sentence from the one the guard prints sends the reader
        // looking for something that is not there.
        'Agents/ProcessExecutor.php::spawnWorker' => [
            'count' => 1,
            'reason' => 'agent worker held in $this->processes; the handle is handed to '
                . 'is_resource() and then escapes through an array member, neither of which this '
                . 'scanner follows',
        ],

        // The handle is returned to a caller that drains it from a periodic
        // timer on the event loop, so the child outlives `spawn()` by design.
        'Backend/CommandBackend.php::spawn' => [
            'count' => 1,
            'reason' => 'handle returned for loop-driven draining; child outlives the call by design',
        ],
        'Backend/StreamingCommandBackend.php::begin' => [
            'count' => 1,
            'reason' => 'handle returned for loop-driven draining; child outlives the call by design',
        ],
    ];

    /**
     * Appearances of the name that are not calls, and what each one is.
     *
     * The rule-14 half. `function_exists('proc_open')` is a capability probe,
     * not a spawn - but an instrument that silently drops what it cannot
     * classify has a hole shaped exactly like the next defect, so the scanner
     * reports these and this roster accounts for them.
     *
     * COUNTED, for the same reason {@see ACCOUNTED_FOR} is: a boolean row here
     * licenses every future indirect appearance in the same function as well
     * as the one that was argued for, and an indirectly-reached spawn is a
     * spawn whose descriptor spec nothing can see at all.
     *
     * @var array<string, array{count:int, reason:string}>
     */
    private const NOT_A_SPAWN = [
        'Context/EnvironmentBlock.php::gitField' => [
            'count' => 1,
            'reason' => 'function_exists() capability probe for a build with proc_open disabled',
        ],
        'Context/EnvironmentBlock.php::gitDiffSection' => [
            'count' => 1,
            'reason' => 'function_exists() capability probe for a build with proc_open disabled',
        ],
    ];

    /**
     * A synthetic spawn whose answer is known before the scanner is asked.
     *
     * PUSHED THROUGH THE SAME HELPER AS THE TREE, IN THE SAME TEST. Round 44
     * emptied a census and proved the point: with the scanner mutated to never
     * match, the "nothing is stale" assertion PASSED - 18,228 assertions,
     * entirely green, in a tree where the instrument was dead. An assertion
     * that something is absent is worth nothing unless the same run shows the
     * instrument still finds what is present.
     */
    private const KNOWN_POSITIVE = <<<'PHP'
        <?php
        class Fixture {
            private $process;
            public function knownPositive(array $pipes): void {
                $this->process = @proc_open(['srv'], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes);
            }
        }
        PHP;

    /**
     * A synthetic spawn that must NOT be flagged, for the other direction.
     *
     * Without it a scanner that flags unconditionally would satisfy every
     * assertion above by reporting the whole tree, and reddening correct code
     * is how the next real offender buys its exemption.
     */
    private const KNOWN_NEGATIVE = <<<'PHP'
        <?php
        class Fixture {
            private $process;
            public function knownNegative(array $pipes): void {
                $this->process = @proc_open(['srv'], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                    3 => ['file', '/dev/null', 'r'],
                ], $pipes);
            }
        }
        PHP;

    /**
     * TWO exposed spawns in ONE function, for the allowance.
     *
     * A roster keyed `File.php::function` with boolean membership cannot
     * express "one of these is argued for and the next is not", and a function
     * is exactly the scope in which a second spawn quietly appears -
     * `Hooks/ScriptHook.php::executeStaged()` has two today. This fixture is
     * what proves the licence is spent rather than granted.
     */
    private const KNOWN_POSITIVE_SECOND_SITE = <<<'PHP'
        <?php
        class Fixture {
            private $first;
            private $second;
            public function secondSpawn(array $pipes): void {
                $this->first = @proc_open(['srv'], [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes);
                $this->second = @proc_open(['srv2'], [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes);
            }
        }
        PHP;

    /** Two appearances of the name that are not direct global calls. */
    private const KNOWN_POSITIVE_NOT_A_CALL = <<<'PHP'
        <?php
        class Fixture {
            public function probe(): bool {
                return \function_exists('proc_open') && $this->proc_open();
            }
        }
        PHP;

    /** ...and the other direction: a plain call is a site, not an appearance. */
    private const KNOWN_NEGATIVE_PLAIN_CALL = <<<'PHP'
        <?php
        class Fixture {
            private $h;
            public function go(array $pipes): void {
                $this->h = proc_open('x', [2 => ['pipe', 'w']], $pipes);
            }
        }
        PHP;

    public function testEveryExposedSpawnIsHandledOrAccountedFor(): void
    {
        self::assertSame(
            ['knownPositive'],
            \array_column($this->exposedIn(self::KNOWN_POSITIVE), 'function'),
            'The instrument is dead. Everything else this file asserts is worthless until this passes.',
        );
        self::assertSame(
            [],
            $this->exposedIn(self::KNOWN_NEGATIVE),
            'A spec that names fd 3 is handled; flagging it would red correct code.',
        );

        // THE ALLOWANCE IS SPENT ONE SITE AT A TIME, pushed through the SAME
        // helper the tree goes through, in this test. Measured before the row
        // carried a count: injecting a second exposed spawn into
        // `MCP/StdioMcpServer::start()`, which has a row, left this guard
        // green - 5 tests, 13 assertions, rc 0.
        self::assertSame(
            ['fixture.php::secondSpawn', 'fixture.php::secondSpawn'],
            $this->overspent(self::KNOWN_POSITIVE_SECOND_SITE, 'fixture.php', []),
            'the fixture must produce TWO exposed spawns in ONE function, or the licence below '
                . 'is being spent against something that cannot overspend it.',
        );
        self::assertSame(
            ['fixture.php::secondSpawn'],
            $this->overspent(self::KNOWN_POSITIVE_SECOND_SITE, 'fixture.php', ['fixture.php::secondSpawn' => 1]),
            'a licence for ONE must cover one and report the other. If this returns [] the row '
                . 'is a blank cheque again and every future spawn in an exempted function is '
                . 'invisible.',
        );
        self::assertSame(
            [],
            $this->overspent(self::KNOWN_POSITIVE_SECOND_SITE, 'fixture.php', ['fixture.php::secondSpawn' => 2]),
            'a licence for two must cover both, or the count is not being read at all.',
        );

        $licences = \array_map(static fn (array $row): int => $row['count'], self::ACCOUNTED_FOR);

        $unaccounted = [];
        foreach ($this->sourceFiles() as $relative => $source) {
            foreach ($this->overspent($source, $relative, $licences, true) as $detail) {
                $unaccounted[] = $detail;
            }
        }

        self::assertSame([], $unaccounted, <<<'TEXT'
            A proc_open() child here outlives the call and its descriptor spec says
            nothing about fd 3 and above, so it inherits every descriptor this
            process had open at spawn - E365's shape.

            TWO WAYS TO RESOLVE THIS, AND BOTH ARE FINE:

              1. NAME THE FDS in the spec so the child cannot inherit them, and
                 this row disappears on its own.
              2. ADD A ROW to ACCOUNTED_FOR with the reason it is acceptable, or
                 RAISE THE COUNT on the row that is already there. A DATA EDIT IN
                 THIS FILE - not a reason to relax the check, and not a reason to
                 make the scanner quieter.

            A ROW ALREADY EXISTS FOR THIS SYMBOL? Then the function has grown a
            SECOND exposed spawn and the licence was written for one. Argue for the
            new one on its own terms before raising the count; the reason field
            covers whatever the count says it covers.

            If the lifetime reads "unclassified" the scanner could not follow the
            handle. That is a failure, not an absence: work out where the handle
            goes and either fix it or say so in a row.
            TEXT);
    }

    /**
     * A row that matches nothing is the only thing that notices a dead scanner.
     *
     * This is the assertion that cannot be satisfied by an instrument returning
     * nothing, which is why it is separate from the one above rather than
     * folded into it.
     */
    public function testNoAccountedForRowIsStale(): void
    {
        $seen = [];
        foreach ($this->sourceFiles() as $relative => $source) {
            foreach ($this->exposedIn($source) as $site) {
                $key = $relative . '::' . $site['function'];
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
        }

        $wrong = [];
        foreach (self::ACCOUNTED_FOR as $key => $row) {
            self::assertNotSame(
                '',
                \trim($row['reason']),
                $key . ' is exempted without a reason. The reason is the record; without it the '
                    . 'row is an unargued exemption that nobody can review.',
            );

            $found = $seen[$key] ?? 0;
            if ($found !== $row['count']) {
                $wrong[] = $key . ': licensed for ' . $row['count'] . ', found ' . $found;
            }
        }

        self::assertSame([], $wrong, <<<'TEXT'
            An ACCOUNTED_FOR row's count no longer matches what the scanner reports
            for that symbol.

            FOUND FEWER (0 included): the spawn was fixed, removed or renamed -
            delete the row or lower the count, a data edit here. OR the scanner
            stopped seeing it, and this row is the only thing that noticed. Do not
            delete it before finding out which.

            FOUND MORE: the function grew another exposed spawn. That is the case
            the count exists for; go and read it before raising the number.
            TEXT);
    }

    public function testEveryAppearanceThatIsNotACallIsAccountedFor(): void
    {
        // RULE 15, IN THIS TEST RATHER THAN A NEIGHBOURING ONE. What follows
        // is an assertion that a set is EMPTY, and an empty set is also what a
        // scanner that reports nothing returns. The `unresolved` half had its
        // liveness proved only over in testNoNotASpawnRowIsStale - true, and
        // one refactor away from not being true, with nothing here saying so.
        self::assertSame(
            [ChildLifetimeScanner::REF_STRING, ChildLifetimeScanner::REF_METHOD],
            \array_column(
                ChildLifetimeScanner::scan(self::KNOWN_POSITIVE_NOT_A_CALL)['unresolved'],
                'kind',
            ),
            'the unresolved half of the instrument is dead; the absence asserted below is '
                . 'worthless until this passes.',
        );
        self::assertSame(
            [],
            ChildLifetimeScanner::scan(self::KNOWN_NEGATIVE_PLAIN_CALL)['unresolved'],
            'a plain global call is a SITE, not an unresolved appearance; reporting it here '
                . 'would make every real call need a NOT_A_SPAWN row.',
        );

        $unaccounted = [];
        foreach ($this->sourceFiles() as $relative => $source) {
            $allowance = [];
            foreach (ChildLifetimeScanner::scan($source)['unresolved'] as $appearance) {
                $key = $relative . '::' . $appearance['function'];
                $allowance[$key] ??= self::NOT_A_SPAWN[$key]['count'] ?? 0;
                if ($allowance[$key] > 0) {
                    $allowance[$key]--;

                    continue;
                }
                $unaccounted[] = $key . ': ' . $appearance['kind'];
            }
        }

        self::assertSame([], $unaccounted, <<<'TEXT'
            The name proc_open appears here as something other than a direct global
            call - a method, a static, a declaration, a string. It is not counted as
            a spawn and it is not dropped silently either, because an alphabet
            written to match only the cases already known has a hole shaped exactly
            like the next defect.

            If it really is not a spawn, add a row to NOT_A_SPAWN saying what it is,
            or raise the count on the row already there if the function has grown a
            second one.
            If it IS a spawn reached indirectly, the scanner cannot see its
            descriptor spec at all and that is the finding.
            TEXT);
    }

    public function testNoNotASpawnRowIsStale(): void
    {
        $seen = [];
        foreach ($this->sourceFiles() as $relative => $source) {
            foreach (ChildLifetimeScanner::scan($source)['unresolved'] as $appearance) {
                $key = $relative . '::' . $appearance['function'];
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
        }

        $wrong = [];
        foreach (self::NOT_A_SPAWN as $key => $row) {
            self::assertNotSame(
                '',
                \trim($row['reason']),
                $key . ' is exempted without a reason - the row says nothing about what the '
                    . 'appearance actually is.',
            );

            $found = $seen[$key] ?? 0;
            if ($found !== $row['count']) {
                $wrong[] = $key . ': licensed for ' . $row['count'] . ', found ' . $found;
            }
        }

        self::assertSame(
            [],
            $wrong,
            'a NOT_A_SPAWN count no longer matches what the scanner reports. Fewer means the '
                . 'appearance went away (delete or lower the row) or the scanner stopped seeing '
                . 'it; more means the function grew another indirect appearance. A data edit '
                . 'here either way, once you know which.',
        );
    }

    /**
     * Every spawn's descriptor spec must be READABLE, exposed or not.
     *
     * An unreadable spec is not a clean bill of health, it is the scanner
     * saying it has no opinion - and a site whose spec it cannot read is a site
     * whose fd set nobody is checking. Paired with its own positive, because
     * "no unreadable specs" is also what a scanner that reads nothing reports.
     */
    public function testNoDescriptorSpecInSrcIsUnreadable(): void
    {
        // INDEXED ONLY AFTER THE COUNT IS CHECKED. With the scanner blind to
        // `proc_open`, `['sites'][0]` is an undefined key, so the failure a
        // future reader gets is a PHP warning rather than the sentence written
        // for them. It still reds under failOnWarning; it reds unhelpfully.
        $probe = ChildLifetimeScanner::scan(
            "<?php\nclass F { private \$h; function m(\$p) { \$this->h = proc_open('x', \$this->spec(), \$p); } }\n",
        )['sites'];
        self::assertCount(1, $probe, 'the scanner found no site in the probe at all - it is dead.');
        self::assertNull(
            $probe[0]['fds'],
            'A spec behind a method call is unreadable; if this passes as readable the test below means nothing.',
        );

        $unreadable = [];
        foreach ($this->sourceFiles() as $relative => $source) {
            foreach (ChildLifetimeScanner::scan($source)['sites'] as $site) {
                if ($site['fds'] === null) {
                    $unreadable[] = $relative . '::' . $site['function'];
                }
            }
        }

        self::assertSame([], $unreadable, <<<'TEXT'
            This spawn's descriptor spec is in a shape ChildLifetimeScanner cannot
            read - a constant from another file, a method call, a spread - so
            nothing is checking which fds it names.

            Either spell the spec where the call can see it (an inline literal, a
            local, or a class constant in the same file), or widen the scanner and
            pin the new shape in ChildLifetimeScannerFixtureTest. Do NOT add an
            exemption: an unreadable spec is the one shape this guard cannot make
            any statement about at all.
            TEXT);
    }

    /**
     * The exposed spawns in one source that its licences do not cover.
     *
     * ONE FUNCTION FOR THE FIXTURE AND FOR THE TREE, which is the whole point:
     * a licence-spending rule verified against a synthetic pair and then
     * re-implemented inline for the real scan is two rules, and the one that
     * matters is the untested one.
     *
     * @param array<string,int> $licences key => how many sites the row covers
     * @param bool $detailed whether to append the scanner's own verdict, which
     *                       a failure message needs and a fixture assertion
     *                       would only have to spell out again
     * @return list<string>
     */
    private function overspent(
        string $source,
        string $relative,
        array $licences,
        bool $detailed = false,
    ): array {
        $remaining = [];
        $over = [];

        foreach ($this->exposedIn($source) as $site) {
            $key = $relative . '::' . $site['function'];
            $remaining[$key] ??= $licences[$key] ?? 0;

            if ($remaining[$key] > 0) {
                $remaining[$key]--;

                continue;
            }

            $over[] = $detailed
                ? $key . ': ' . $site['lifetime'] . ' - ' . $site['reason']
                : $key;
        }

        return $over;
    }

    /**
     * Sites whose child outlives the call with no fd 3+ named.
     *
     * @return list<array{function:string,lifetime:string,reason:string}>
     */
    private function exposedIn(string $source): array
    {
        $exposed = [];

        foreach (ChildLifetimeScanner::scan($source)['sites'] as $site) {
            if ($site['lifetime'] === ChildLifetimeScanner::LIFETIME_SHORT) {
                continue;
            }
            if ($site['highFds'] !== []) {
                continue;
            }

            $exposed[] = [
                'function' => $site['function'],
                'lifetime' => $site['lifetime'],
                'reason' => $site['reason'],
            ];
        }

        return $exposed;
    }

    /** @return iterable<string, string> relative path => source */
    private function sourceFiles(): iterable
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        \sort($files);

        // A `src/` with no PHP files in it would make every absence assertion
        // above pass, which is the same dead-instrument shape one level up.
        self::assertNotSame([], $files, 'No source files were found to scan.');

        foreach ($files as $path) {
            yield \substr($path, \strlen($root) + 1) => (string) \file_get_contents($path);
        }
    }
}

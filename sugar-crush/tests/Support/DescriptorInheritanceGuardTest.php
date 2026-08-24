<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * No `proc_open()` child in `src/` may outlive the call that spawned it
 * without a row here saying why that is acceptable.
 *
 * WHY LIFETIME AND NOT THE SPEC. `proc_open()` remaps only the fds its spec
 * names; the child inherits every other descriptor the parent had open. For a
 * child closed where it was spawned that lasts microseconds. For an MCP
 * server, a language server or a session daemon it lasts as long as the child
 * does - and E365 is what that costs: a leaked `php -S` held the write end of
 * the caller's stdout on fd 4, so `vendor/bin/phpunit | tail` blocked forever
 * on an EOF that never came, after a green run. Two measurements were lost to
 * it, one of 11.5 hours.
 *
 * WHAT THIS HEADLINE USED TO SAY, and it is the round's finding rather than a
 * tidy-up: "...while its descriptor spec declines to say anything about fd 3
 * and above". WHAT IS TRUE NOW, measured by
 * {@see testNamingAHighFdDoesNotStopTheInheritance()} rather than reasoned
 * about: a spec that DOES say something about fd 3 is not safer in any
 * respect. proc_open() replaces the descriptors named and inherits the rest,
 * so naming fd 3 moves one descriptor and leaves fd 4 upwards untouched, and
 * the parent's fd numbering is a runtime property no source-level spec can
 * enumerate. The old headline described a condition this guard skipped on,
 * which made "append one array element" a complete and undetectable way to
 * delete any row below. WHY THE SPEC IS STILL READ AT ALL: an UNREADABLE spec
 * is still a real finding of its own
 * ({@see testNoDescriptorSpecInSrcIsUnreadable()}), and what a spec does name
 * is useful detail on a failure. It is detail, never an exemption.
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
     * ⚠️ THERE IS ONE WAY TO CLOSE A ROW HERE AND IT IS NOT THE OBVIOUS ONE.
     * Until round 54 a row could be retired by appending an fd of 3 or above
     * to the spawn's descriptor spec, which {@see exposedIn()} treated as
     * handled. It is not: `proc_open()` replaces the descriptors its spec
     * names and inherits every one it does not, so the append moved one fd and
     * left the leak whole - measured in
     * {@see testNamingAHighFdDoesNotStopTheInheritance()}. A row closes when
     * the CHILD'S LIFETIME closes, by reaping it in the function that spawned
     * it. E417 asked for all seven of these to be closed by naming fds; that
     * measurement is why none of them were.
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
     * Where a reachable sibling library's sources live.
     *
     * `vendor/sugarcraft` IS THE REACHABILITY DEFINITION, not the monorepo
     * directory beside this package - the same choice, for the same reason, as
     * {@see \SugarCraft\Crush\Tests\TtyStreamArgumentCensusTest}. A lib
     * nothing requires cannot spawn anything in this process whatever it
     * contains, and a lib that IS required is here whether it arrived as a
     * path-repo symlink (the monorepo, and CI's injection) or as a Packagist
     * copy (a split-repo clone). Pointing at `../` instead would be a hard
     * fatal in a split clone, which is the same class of mistake as a
     * `repositories[]` entry in a published manifest.
     */
    private const LIB_SCOPE = 'vendor/sugarcraft';

    /**
     * Exposed spawns in reachable SIBLING libraries. E418.
     *
     * WHY THE GUARD WIDENED. Round 53 built this instrument, rostered seven
     * sites, and scoped it to `sugar-crush/src` - and the defect class is not
     * a sugar-crush property. Measured over the reachable closure at the time
     * of writing: 8 spawn sites outside this package, 3 of them exposed, and
     * two of those three are in candy-pty, which every PTY-driven child in the
     * tree goes through.
     *
     * A ROW HERE IS A DIFFERENT ANIMAL FROM ONE IN {@see ACCOUNTED_FOR}, and
     * the split into two rosters is the whole point rather than tidiness.
     * A sugar-crush row is a deferral: this package could fix it and has
     * chosen not to yet. A row here is a REPORT: sugar-crush cannot fix
     * candy-pty from inside its own test suite, and a fix pushed from here
     * would be an edit to a file this package does not own. What this roster
     * buys is that the site cannot appear, move or multiply without somebody
     * seeing it - which is precisely what was missing before.
     *
     * ⚠️ THIS ROSTER COUNTS CODE OTHER LANES OWN. It reads through
     * `vendor/sugarcraft`, whose entries are symlinks into the monorepo, so a
     * sibling's edit reds THIS suite. That is intended and it is also a merge
     * hazard, so read {@see testEveryExposedSpawnInAReachableLibIsAccountedFor()}'s
     * message before touching anything: the resolution is always a data edit
     * here plus a finding filed against the lib, and never a narrowing of
     * LIB_SCOPE.
     *
     * @var array<string, array{count:int, reason:string}>
     */
    private const ACCOUNTED_FOR_IN_LIBS = [
        'candy-core/WorkerPool.php::spawnWorker' => [
            'count' => 1,
            'reason' => 'pool worker held in $this->workers and drained from the ReactPHP loop, '
                . 'so it outlives spawnWorker() by design. The scanner reads it as unclassified '
                . 'rather than long because the handle goes to is_resource() first. Spec is '
                . '0,1,2 only. NOT FIXABLE FROM THIS PACKAGE - candy-core owns it.',
        ],
        'candy-pty/Spawn.php::proc' => [
            'count' => 1,
            'reason' => 'the PTY child, whose three stdio descriptors are all the one open slave '
                . 'stream; the handle is kept for the life of the pty. Spec names 0,1,2 only, so '
                . 'anything the parent holds above that goes into a child that by design lives '
                . 'as long as the terminal does. NOT FIXABLE FROM THIS PACKAGE.',
        ],
        'candy-pty/Posix/PosixProcess.php::spawn' => [
            'count' => 1,
            'reason' => 'the same shape one layer down, and the spec here is the more '
                . 'interesting one: it already names fd 0 as a file and routes 1 and 2 to pipes '
                . 'or the real STDOUT/STDERR, which shows the author thinking about descriptors '
                . 'and still saying nothing about 3+. NOT FIXABLE FROM THIS PACKAGE.',
        ],
    ];

    /**
     * Sites that are short ONLY because a CLOSING_HELPERS row says so.
     *
     * E425, AND IT IS THE STRUCTURAL REASON THE PREVIOUS ROUND'S FINDING WAS
     * EXPENSIVE TO FIND. {@see exposedIn()} drops every {@see
     * ChildLifetimeScanner::LIFETIME_SHORT} site, which is correct - a child
     * reaped in the function that spawned it is not the shape this guard is
     * about. But "short" has two provenances and they are not equally
     * trustworthy. A literal `proc_close($h)` is the language ending the
     * child. A {@see ChildLifetimeScanner::CLOSING_HELPERS} row is a PERSON'S
     * CLAIM about a method in another file, made at a glance, from its name -
     * and the scanner's own doc-block says so: "this is the one roster whose
     * rows can HIDE a finding rather than raise one".
     *
     * Before this roster existed those two were spelled the same way in the
     * output and the second vanished without trace: a wrong row promoted an
     * exposed spawn to short, `exposedIn()` dropped it, and nothing anywhere
     * recorded that a judgement had been relied on. The count is the size of
     * the reliance, for the same reason {@see ACCOUNTED_FOR}'s is.
     *
     * A ROW HERE IS NOT AN EXEMPTION FROM ANYTHING - the site is already not
     * reported. It is a receipt. Adding a CLOSING_HELPERS row now costs a row
     * here too, which is the point: the promotion has to be written down
     * somewhere a reviewer reads.
     *
     * @var array<string, array{count:int, reason:string}>
     */
    private const SHORT_VIA_HELPER = [
        'Providers/ClaudeCodeProvider.php::completeStream' => [
            'count' => 1,
            'reason' => 'reaped by ProcessReaper::terminateAndClose() in a generator finally, '
                . 'which runs on normal completion, on an exception, and on a consumer that '
                . 'breaks out of the foreach and destroys the generator mid-body. The short '
                . 'verdict rests entirely on the CLOSING_HELPERS row for that helper; if that '
                . 'row is ever wrong this site is a long-lived exposed spawn and nothing else '
                . 'in this file would say so.',
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
     *
     * WHAT THIS FIXTURE USED TO BE, because the swap is the whole finding: a
     * long-lived spawn whose spec named `3 => ['file', '/dev/null', 'r']`,
     * asserted NOT exposed under the sentence "a spec that names fd 3 is
     * handled". WHAT IS TRUE NOW: that source is
     * {@see KNOWN_POSITIVE_HIGH_FD} and is asserted EXPOSED, because naming
     * fd 3 replaces fd 3 and leaves fd 4 upwards inherited - measured in
     * {@see testNamingAHighFdDoesNotStopTheInheritance()}. WHY A NEGATIVE
     * STILL EARNS ITS PLACE: the polarity argument above is unaffected and
     * still needs a case that is genuinely fine. A child drained and
     * `proc_close()`d in the function that spawned it is that case, and it is
     * the ONLY shape this guard has ever had a real reason to pass - the
     * inheritance window is the body of one function rather than the life of
     * a daemon.
     */
    private const KNOWN_NEGATIVE = <<<'PHP'
        <?php
        class Fixture {
            public function knownNegative(array $pipes): void {
                $process = @proc_open(['srv'], [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes);
                proc_close($process);
            }
        }
        PHP;

    /**
     * The spec that used to buy an exemption, and now buys a finding.
     *
     * THIS IS THE HOLE THE ROUND CLOSED, kept executable rather than described.
     * `exposedIn()` skipped every site whose spec named an fd of 3 or above,
     * so the cheapest way to make any row here disappear was to append one
     * element to an array - no reaping, no closing, no change to what the child
     * inherits. The guard's own failure text recommended it, first of two
     * resolutions, in capitals.
     *
     * Its counterpart {@see KNOWN_NEGATIVE} is what keeps this from being a
     * scanner that simply flags everything.
     */
    private const KNOWN_POSITIVE_HIGH_FD = <<<'PHP'
        <?php
        class Fixture {
            private $process;
            public function highFdNamed(array $pipes): void {
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
            'A child closed in the function that spawned it is not exposed; flagging it would '
                . 'red correct code, and reddening correct code is how the next real offender '
                . 'buys its exemption.',
        );
        self::assertSame(
            ['highFdNamed'],
            \array_column($this->exposedIn(self::KNOWN_POSITIVE_HIGH_FD), 'function'),
            'NAMING A HIGH FD MUST NOT BUY AN EXEMPTION. proc_open() replaces the descriptors '
                . 'its spec names and inherits every one it does not, so a spec naming fd 3 '
                . 'leaves fd 4 upwards exactly as exposed as before - measured in '
                . 'testNamingAHighFdDoesNotStopTheInheritance(). If this returns [] the escape '
                . 'hatch is back and every row in ACCOUNTED_FOR can be deleted by appending one '
                . 'array element that changes nothing.',
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

            ⚠️ NAMING FDS IN THE SPEC IS NOT A RESOLUTION, and this message used
            to say it was - in capitals, as the first of two. proc_open() REPLACES
            the descriptors its spec names and inherits every one it does not, so
            appending `3 => ['file', '/dev/null', 'r']` swaps fd 3 in the child and
            leaves fd 4 upwards precisely as inherited as they were. Measured, not
            argued: testNamingAHighFdDoesNotStopTheInheritance() in this file
            spawns real children and shows a parent handle surviving the "fixed"
            spec. Until this round that spec ALSO silenced this guard, which made
            it the cheapest way to delete a row without changing anything.

            THREE WAYS TO RESOLVE THIS:

              1. REAP THE CHILD in the function that spawned it - proc_close(), or
                 a helper rostered in ChildLifetimeScanner::CLOSING_HELPERS. This
                 does not stop the inheritance; it BOUNDS it to one function body
                 instead of the life of a daemon, and that is the whole difference
                 E365 turned on. The row disappears on its own.
              2. DO NOT HOLD AN INHERITABLE DESCRIPTOR ACROSS THE SPAWN. Measured
                 on PHP 8.3.6 / Linux 6.8.0-138-generic: proc_open()'s own pipe
                 parent-ends already carry O_CLOEXEC and cannot leak into a later
                 child, but a plain fopen() handle, a stream_socket_pair() and the
                 CLI's own script fd are all inheritable. If the long-lived child
                 must exist, the fix lives at whatever is holding those open.
              3. ADD A ROW to ACCOUNTED_FOR with the reason it is acceptable, or
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
     * The same question, asked of every reachable sibling library. E418.
     *
     * SPLIT FROM THE SUGAR-CRUSH ARM RATHER THAN FOLDED INTO IT. The two
     * rosters mean different things - a deferral this package could act on
     * versus a report about somebody else's file - and a single failure
     * message cannot tell a reader which kind they are looking at. They also
     * go red for different reasons: this one reds when a SIBLING changes,
     * which a person resolving a sugar-crush merge would otherwise spend a
     * while blaming on their own diff.
     */
    public function testEveryExposedSpawnInAReachableLibIsAccountedFor(): void
    {
        // Rule 15, in this test rather than a neighbouring one: what follows is
        // an assertion that a set is empty, and an empty set is what a walk
        // over nothing returns just as well as a healthy tree.
        self::assertSame(
            ['knownPositive'],
            \array_column($this->exposedIn(self::KNOWN_POSITIVE), 'function'),
            'the instrument is dead; the absence asserted below is worthless until this passes.',
        );

        $licences = \array_map(static fn (array $row): int => $row['count'], self::ACCOUNTED_FOR_IN_LIBS);

        $unaccounted = [];
        $scanned = 0;
        foreach ($this->libSourceFiles() as $relative => $source) {
            $scanned++;
            foreach ($this->overspent($source, $relative, $licences, true) as $detail) {
                $unaccounted[] = $detail;
            }
        }

        // The walk finding no files at all would satisfy the assertion below
        // perfectly, and is exactly what a renamed vendor directory looks like.
        self::assertGreaterThan(
            100,
            $scanned,
            'only ' . $scanned . ' sibling source files were scanned, which is too few for this '
                . 'closure - the walk is pointed somewhere wrong and every absence below is empty.',
        );

        self::assertSame([], $unaccounted, <<<'TEXT'
            A proc_open() child in a SIBLING LIBRARY outlives the call that spawned
            it and its descriptor spec says nothing about fd 3 and above, so it
            inherits every descriptor this process had open at spawn - E365's shape,
            in a package sugar-crush cannot edit from here.

            YOU ARE PROBABLY RESOLVING A MERGE. This guard reads through
            vendor/sugarcraft, which in the monorepo is a symlink into the tree, so
            a change in candy-pty or candy-core reds THIS suite. That is deliberate
            (E418) and the diff in front of you is very likely not the cause.

            THE RESOLUTION IS ALWAYS BOTH HALVES:

              1. A DATA EDIT to ACCOUNTED_FOR_IN_LIBS here - a new row, or a higher
                 count on the row already there.
              2. A FINDING FILED AGAINST THAT LIBRARY, because a row here records
                 the exposure and fixes nothing. Reaping the child in the function
                 that spawned it is the fix; naming high fds in the spec is NOT one,
                 for the reason testNamingAHighFdDoesNotStopTheInheritance() measures.

            NARROWING LIB_SCOPE IS NOT A RESOLUTION. It is how the defect class got
            to live in five libraries unobserved for the fifty-three rounds before
            this guard could see them.
            TEXT);
    }

    /**
     * No row in {@see ACCOUNTED_FOR_IN_LIBS} may match nothing.
     *
     * Separate from the arm above for the reason its sugar-crush twin is: an
     * assertion that a set is empty cannot notice an instrument that returns
     * nothing, and a row matching nothing is the only thing that can.
     */
    public function testNoReachableLibRowIsStale(): void
    {
        $seen = [];
        foreach ($this->libSourceFiles() as $relative => $source) {
            foreach ($this->exposedIn($source) as $site) {
                $key = $relative . '::' . $site['function'];
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
        }

        $wrong = [];
        foreach (self::ACCOUNTED_FOR_IN_LIBS as $key => $row) {
            self::assertNotSame('', \trim($row['reason']), $key . ' is recorded without a reason.');
            $found = $seen[$key] ?? 0;
            if ($found !== $row['count']) {
                $wrong[] = $key . ': recorded ' . $row['count'] . ', found ' . $found;
            }
        }

        self::assertSame([], $wrong, <<<'TEXT'
            A row about a sibling library no longer matches what the scanner finds
            there.

            FOUND FEWER (0 included): that library fixed it, renamed it, or removed
            it - delete the row and say so. OR the scanner stopped seeing it and
            this row is the only thing that noticed.

            FOUND MORE: the function grew another exposed spawn. Read it before
            raising the number.
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
     * Naming a high fd replaces THAT fd and inherits every other one.
     *
     * THE ONE CLAIM IN THIS FILE THAT IS NOT ABOUT SOURCE TEXT. Everything
     * else here reads tokens and believes what the roster says; this spawns
     * real children and asks the kernel. It exists because the resolution this
     * guard used to recommend first - "NAME THE FDS in the spec so the child
     * cannot inherit them, and this row disappears on its own" - is false, and
     * a false prescription inside a failure message is worse than no message:
     * it is a green button that deletes the finding and leaves the defect.
     *
     * THE GENERATOR, so the figure is a measurement and not a memory. A marker
     * file is opened AFTER a dummy, which is what guarantees it cannot land on
     * fd 3 - the one descriptor the "named" spec below replaces - so the
     * comparison is not a coin flip on whatever PHPUnit happens to have open.
     * Identity is `fstat()`'s dev+ino pair rather than a path or an fd number,
     * because the child is asked whether it can reach the same FILE, which is
     * the property that matters. The child probes fds 3..40 through
     * `php://fd/N`, which is POSIX and does not need procfs. Three specs are
     * compared: bare, one high fd named, and every fd 3..40 named.
     *
     * MEASURED at PHP 8.3.6 on Linux 6.8.0-138-generic, three consecutive
     * takes, identical each time: bare VISIBLE / one named VISIBLE / all named
     * gone. CI runs this package on ubuntu-latest at 8.3 and 8.4 only
     * (`scripts/affected-libs.php` puts sugar-crush in neither WINDOWS_LIBS nor
     * MACOS_LIBS), and the property under test is POSIX descriptor inheritance
     * across `execve`, not a PHP-version behaviour - so 8.4 is not a claim
     * being made from an untested box, it is the same kernel call.
     *
     * THE THIRD CASE IS NOT A RECOMMENDATION. Naming every fd 3..40 does close
     * the marker, and that is exactly why it is here: it shows the mechanism is
     * "replace by number", so the only spec that could be trusted is one that
     * enumerates every descriptor the process holds at the instant of the
     * spawn. That set is a runtime property. A spec written in source cannot
     * know it, which is the reason resolution 1 was never available.
     */
    public function testNamingAHighFdDoesNotStopTheInheritance(): void
    {
        // Opened FIRST so the marker cannot be the fd the "named" spec below
        // replaces. Without this the whole comparison is luck.
        $dummy = \fopen('/dev/null', 'r');
        self::assertIsResource($dummy, 'the probe cannot be set up without a spare descriptor.');

        $marker = (string) \tempnam(\sys_get_temp_dir(), 'sc_r54c_inherit_' . \getmypid() . '_');
        $handle = \fopen($marker, 'r');
        self::assertIsResource($handle);

        $stat = \fstat($handle);
        self::assertIsArray($stat);
        $identity = $stat['dev'] . ':' . $stat['ino'];

        $nullStat = \fstat($dummy);
        self::assertIsArray($nullStat);
        $devNull = $nullStat['dev'] . ':' . $nullStat['ino'];

        try {
            $withBareSpec = $this->descriptorsVisibleToAChild([]);
            $withHighFdNamed = $this->descriptorsVisibleToAChild([3]);
            $withEveryFdNamed = $this->descriptorsVisibleToAChild(\range(3, 40));
        } finally {
            \fclose($handle);
            \fclose($dummy);
            \unlink($marker);
        }

        // THE CONTROL FOR THE CONTROL. Without it the refutation below is
        // vacuous in the one way that matters: if proc_open had ignored the
        // high-fd entry entirely, "the marker is still visible with fd 3
        // named" would be true and would say nothing at all. This asserts the
        // named spec DID take effect - the child's fd 3 is /dev/null and is
        // not what the bare run had there - so the surviving marker is a
        // statement about fd 4 and above rather than about a spec nobody read.
        self::assertSame(
            $devNull,
            $withHighFdNamed[3] ?? 'absent',
            'the spec naming fd 3 did not take effect, so nothing below is a measurement of '
                . 'anything. Re-check the probe before reading the refutation.',
        );
        self::assertNotSame(
            $withHighFdNamed[3] ?? 'absent',
            $withBareSpec[3] ?? 'absent',
            'the bare run and the named run put the SAME thing on fd 3, so the two cases are '
                . 'not actually different and the comparison is empty.',
        );

        self::assertContains(
            $identity,
            $withBareSpec,
            'The premise itself failed: a child spawned with a bare 0,1,2 spec could not reach '
                . 'a file this process holds open. Nothing below means anything if this fails - '
                . 'either the probe is broken or descriptors stopped being inherited, and the '
                . 'second would retire this entire guard.',
        );

        self::assertContains(
            $identity,
            $withHighFdNamed,
            'THE REFUTATION. Naming fd 3 in the spec was this guard\'s first recommended fix '
                . 'and an automatic exemption from it. The marker is open at fd 4 or above, and '
                . 'the child can still reach it, so naming fd 3 changed nothing except which '
                . 'file sits on fd 3. If this ever fails, proc_open has started closing '
                . 'unnamed descriptors - re-measure before believing it, and then this guard '
                . 'gets much smaller.',
        );

        self::assertNotContains(
            $identity,
            $withEveryFdNamed,
            'The positive control for the mechanism: naming fd 3 through 40 DOES take the '
                . 'marker away, which is what proves the two assertions above are about "the '
                . 'spec did not name that fd" rather than about a probe that cannot see '
                . 'anything.',
        );
    }

    /**
     * `dev:ino` of every descriptor a child can reach at fd 3 and above.
     *
     * The child opens `php://fd/N` rather than listing procfs so the probe
     * holds on any POSIX box, and reports `fstat()` identity rather than fd
     * numbers because the caller is asking "can it reach this FILE", to which
     * the number is irrelevant. Opening a descriptor allocates one, so the
     * list is deduplicated and read for membership only, never counted.
     *
     * THE SPEC IS BUILT HERE FROM A LITERAL RATHER THAN TAKEN AS ONE, and the
     * caller passes only the high fds to name. Taking the whole spec as a
     * parameter is what a reader would write first, and
     * {@see ChildStderrCaptureTest} reds on it - correctly: with the spec
     * arriving as an argument, no scanner can see where fd 2 goes, and
     * "unclassified" is that guard refusing to call an unreadable spec a pass.
     * Naming fd 2 in a literal on the line above the spawn keeps it readable
     * to an instrument, and the loop that follows can only ADD descriptors at
     * 3 and above.
     *
     * @param list<int> $highFds fd numbers to point at /dev/null in the child
     * @return array<int, string> child fd number => `dev:ino` of what it reaches
     */
    private function descriptorsVisibleToAChild(array $highFds): array
    {
        $probe = <<<'CHILD'
            $seen = [];
            for ($n = 3; $n <= 40; $n++) {
                $f = @fopen('php://fd/' . $n, 'r');
                if ($f === false) { continue; }
                $s = @fstat($f);
                if (is_array($s)) { $seen[] = $n . '=' . $s['dev'] . ':' . $s['ino']; }
                @fclose($f);
            }
            echo implode(" ", $seen);
            CHILD;

        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        foreach ($highFds as $fd) {
            $spec[$fd] = ['null'];
        }

        $pipes = [];
        $process = \proc_open([\PHP_BINARY, '-r', $probe], $spec, $pipes);
        self::assertIsResource($process, 'the descriptor probe could not be spawned.');

        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($process);

        self::assertSame('', \trim($err), 'the descriptor probe wrote to stderr: ' . $err);

        $reached = [];
        foreach (\array_filter(\explode(' ', \trim($out))) as $pair) {
            [$fd, $identity] = \explode('=', $pair, 2);
            $reached[(int) $fd] = $identity;
        }

        return $reached;
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
                ? $key . ': ' . $site['lifetime'] . ' (' . $site['namedFds'] . ') - ' . $site['reason']
                : $key;
        }

        return $over;
    }

    /**
     * Sites whose child outlives the call, whatever the spec names.
     *
     * WHAT THIS USED TO DO, AND WHY IT NO LONGER DOES IT. It skipped any site
     * whose spec named an fd of 3 or above - `if ($site['highFds'] !== [])
     * continue;` - on the belief, written into this file's failure text as the
     * FIRST recommended resolution and into a fixture named KNOWN_NEGATIVE,
     * that naming fd 3 stops the child inheriting.
     *
     * WHAT IS TRUE NOW, measured rather than reasoned - the generator is
     * {@see testNamingAHighFdDoesNotStopTheInheritance()}, which spawns real
     * children on every run of this suite: `proc_open()` REPLACES the
     * descriptors its spec names and says nothing whatever about the ones it
     * does not. A parent handle sitting at fd 4 is inherited byte-identically
     * whether or not the spec names fd 3. Naming ONE high fd therefore bought
     * no safety at all - it bought an exit from this guard. That is the worst
     * trade available: the exit is one array element away for anyone who wants
     * a row to stop failing, it leaves the leak exactly where it was, and
     * unlike an ACCOUNTED_FOR row it leaves no record that anything was ever
     * wrong.
     *
     * WHY THE PAIR STILL EARNS ITS PLACE. The two-part question the class
     * doc-block poses - does the child outlive the call, and what does the
     * spec say about fd 3+ - is still the right question, and the first part
     * is unchanged. Only the second part's ANSWER was wrong: what the spec
     * says about fd 3+ is diagnostic detail about one descriptor, never a
     * clean bill of health for the rest. So `highFds` is still computed and is
     * now REPORTED on the finding instead of cancelling it.
     *
     * @return list<array{function:string,lifetime:string,reason:string,namedFds:string}>
     */
    private function exposedIn(string $source): array
    {
        $exposed = [];

        foreach (ChildLifetimeScanner::scan($source)['sites'] as $site) {
            if ($site['lifetime'] === ChildLifetimeScanner::LIFETIME_SHORT) {
                continue;
            }

            $exposed[] = [
                'function' => $site['function'],
                'lifetime' => $site['lifetime'],
                'reason' => $site['reason'],
                'namedFds' => $site['fds'] === null
                    ? 'spec unreadable'
                    : 'spec names fd ' . \implode(', ', $site['fds']),
            ];
        }

        return $exposed;
    }

    /**
     * A short verdict that rests on a roster row, and one that does not.
     *
     * BOTH POLARITIES IN ONE PAIR. The first is closed by a rostered helper
     * and must carry provenance; the second is closed by the language itself
     * and must carry none. A scanner that stamped every short site would make
     * the roster below meaningless by filling it with `proc_close()` sites,
     * and one that stamped none would empty it - and an empty roster is what
     * an absence assertion cannot tell from a healthy tree.
     */
    private const KNOWN_SHORT_VIA_HELPER = <<<'PHP'
        <?php
        class Fixture {
            public function viaHelper(array $pipes): void {
                $h = proc_open('x', [2 => ['pipe', 'w']], $pipes);
                ProcessReaper::terminateAndClose($h);
            }
        }
        PHP;

    private const KNOWN_SHORT_VIA_PROC_CLOSE = <<<'PHP'
        <?php
        class Fixture {
            public function viaProcClose(array $pipes): void {
                $h = proc_open('x', [2 => ['pipe', 'w']], $pipes);
                proc_close($h);
            }
        }
        PHP;

    /**
     * Every helper-promoted short verdict has a receipt, and every receipt matches.
     *
     * {@see SHORT_VIA_HELPER} carries the argument; this is the arithmetic.
     */
    public function testEveryShortVerdictThatRestsOnAHelperRowIsRecorded(): void
    {
        $viaHelper = ChildLifetimeScanner::scan(self::KNOWN_SHORT_VIA_HELPER)['sites'];
        self::assertCount(1, $viaHelper, 'the scanner found no site in the helper fixture.');
        self::assertSame(
            ChildLifetimeScanner::LIFETIME_SHORT,
            $viaHelper[0]['lifetime'],
            'the fixture must be SHORT, or it is not exercising the promotion at all.',
        );
        self::assertSame(
            'processreaper::terminateandclose',
            $viaHelper[0]['closedBy'],
            'a short verdict produced by a CLOSING_HELPERS row must name the row. With this '
                . 'null, the roster below can only ever be empty and the assertion over the '
                . 'tree is satisfied by an instrument that reports nothing.',
        );

        $viaProcClose = ChildLifetimeScanner::scan(self::KNOWN_SHORT_VIA_PROC_CLOSE)['sites'];
        self::assertCount(1, $viaProcClose, 'the scanner found no site in the proc_close fixture.');
        self::assertSame(ChildLifetimeScanner::LIFETIME_SHORT, $viaProcClose[0]['lifetime']);
        self::assertNull(
            $viaProcClose[0]['closedBy'],
            'a literal proc_close() is the language ending the child, not a judgement about '
                . 'another file. Stamping it too would fill the roster with sites nobody needs '
                . 'to review and bury the ones who do.',
        );

        // THE ACCOUNTING'S OWN CONTROL, through the SAME helper the tree goes
        // through and in this test. Measured, and it is why this block exists:
        // with the "not recorded at all" arm deleted outright, the assertion
        // over the tree stayed GREEN (mutation M7 SURVIVED) - because the one
        // promotion in src/ today is rostered, so that arm never fires on real
        // input. An arm that only runs when the tree is already broken is an
        // arm nothing has ever executed.
        $fixture = ['fixture.php' => self::KNOWN_SHORT_VIA_HELPER];
        self::assertSame(
            ['fixture.php::viaHelper: not recorded at all, found 1'],
            $this->unrecorded($fixture, []),
            'an UNROSTERED helper promotion must be reported. If this returns [] a new '
                . 'CLOSING_HELPERS row can hide a spawn from this guard with nothing written '
                . 'down anywhere, which is E425 exactly.',
        );
        self::assertSame(
            [],
            $this->unrecorded($fixture, ['fixture.php::viaHelper' => 1]),
            'a receipt for one must cover one, or every real row below reads as a defect.',
        );
        self::assertSame(
            ['fixture.php::viaHelper: recorded 2, found 1'],
            $this->unrecorded($fixture, ['fixture.php::viaHelper' => 2]),
            'a stale receipt must be reported too - a row that outlived its site is how a dead '
                . 'scanner goes unnoticed.',
        );

        foreach (self::SHORT_VIA_HELPER as $key => $row) {
            self::assertNotSame('', \trim($row['reason']), $key . ' has no reason recorded.');
        }

        $wrong = $this->unrecorded(
            $this->sourceFiles(),
            \array_map(static fn (array $row): int => $row['count'], self::SHORT_VIA_HELPER),
        );

        self::assertSame([], $wrong, <<<'TEXT'
            A spawn in src/ is being treated as short-lived - and therefore dropped
            from this guard entirely - on the strength of a
            ChildLifetimeScanner::CLOSING_HELPERS row rather than a literal
            proc_close(). That is allowed. It is not allowed to be invisible.

            NOT RECORDED AT ALL: a CLOSING_HELPERS row was added, or a spawn was
            changed to use one. Read the helper's source and satisfy yourself that
            it really closes on EVERY path out of itself - if it closes only
            sometimes it belongs in BEST_EFFORT_REAPERS instead, which reports the
            site rather than hiding it - then add a row to SHORT_VIA_HELPER saying
            what you checked.

            RECORDED BUT NOT FOUND: the site was fixed, renamed, or switched to a
            literal proc_close() (all good - delete the row), OR the scanner stopped
            stamping provenance and this row is the only thing that noticed. Find
            out which before deleting anything.
            TEXT);
    }

    /**
     * Helper-promoted short sites whose receipts do not match, both directions.
     *
     * ONE FUNCTION FOR THE FIXTURE AND FOR THE TREE, for the reason
     * {@see overspent()} gives: a rule verified against a synthetic pair and
     * then re-implemented inline for the real scan is two rules, and the one
     * that matters is the untested one.
     *
     * @param iterable<string,string> $sources relative path => source
     * @param array<string,int> $receipts key => how many promotions are recorded
     * @return list<string>
     */
    private function unrecorded(iterable $sources, array $receipts): array
    {
        $seen = [];
        foreach ($sources as $relative => $source) {
            foreach (ChildLifetimeScanner::scan($source)['sites'] as $site) {
                if ($site['closedBy'] === null) {
                    continue;
                }
                $key = $relative . '::' . $site['function'];
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
        }

        $wrong = [];
        foreach ($receipts as $key => $count) {
            $found = $seen[$key] ?? 0;
            if ($found !== $count) {
                $wrong[] = $key . ': recorded ' . $count . ', found ' . $found;
            }
            unset($seen[$key]);
        }
        foreach ($seen as $key => $count) {
            $wrong[] = $key . ': not recorded at all, found ' . $count;
        }

        return $wrong;
    }

    /**
     * Every reachable sibling library's `src`, keyed `<lib>/<relative path>`.
     *
     * @return iterable<string, string>
     */
    private function libSourceFiles(): iterable
    {
        $base = \dirname(__DIR__, 2) . '/' . self::LIB_SCOPE;

        // Loud rather than skipped: this suite cannot have loaded without it,
        // so its absence means the walk is being pointed somewhere new - and a
        // skip here would silently retire every assertion that reads it.
        self::assertDirectoryExists($base, self::LIB_SCOPE . ' is missing, so no sibling library can be scanned.');

        $libs = \glob($base . '/*', \GLOB_ONLYDIR) ?: [];
        \sort($libs);

        foreach ($libs as $lib) {
            $dir = $lib . '/src';
            if (!\is_dir($dir)) {
                continue;
            }

            $files = [];
            /** @var \SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            ) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
            \sort($files);

            foreach ($files as $path) {
                yield \basename($lib) . '/' . \substr($path, \strlen($dir) + 1)
                    => (string) \file_get_contents($path);
            }
        }
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

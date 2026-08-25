<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * No `proc_open()` child in this package's `src/`, or in a reachable sibling
 * library's, may outlive the call that spawned it without a row here saying
 * why that is acceptable.
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
 * NO CENSUS OF THE TREE IS ASSERTED IN THIS FILE, deliberately, WITH ONE
 * EXCEPTION THAT IS ALSO DELIBERATE. E366's HIGH list was five sites on the
 * day it was written, and the round that acted on it had four of those files
 * open in another lane. A census pinned to "five" reds on the commit that
 * lands the fix, and the red looks like the fixer's defect rather than the
 * instrument's brittleness. What is asserted is the SHAPE: every exposed spawn
 * is either handled or carries a row here saying why not, and every row still
 * matches something.
 *
 * WHAT THIS PARAGRAPH USED TO SAY, and it is now a trap rather than doctrine:
 * "NO COUNT IS ASSERTED ANYWHERE IN THIS FILE". WHAT IS TRUE NOW:
 * {@see testEveryExposedSpawnInAReachableLibIsAccountedFor()} asserts the
 * sibling walk saw more than a hundred files before believing what it did not
 * find there. WHY THAT ONE EARNS ITS PLACE AND MUST NOT BE DELETED BY SOMEBODY
 * QUOTING THE SENTENCE ABOVE AT IT: it is a LOWER BOUND far beneath what the
 * closure actually holds, not an equality, so no fix and no lane's merge can
 * carry the tree across it. The only thing that can is a walk pointed at
 * nothing, which is the one thing it exists to catch - the arm it guards
 * asserts a set is EMPTY, and a walk over zero files returns an empty set
 * every bit as convincingly as a healthy closure does. A lower bound has none
 * of the brittleness this paragraph is about; an equality would have all of
 * it.
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
     * Spawns whose child outlives the call, and that no other row covers.
     *
     * WHAT THIS LINE USED TO SAY: "...with nothing said about fd 3+". WHAT IS
     * TRUE NOW: {@see exposedIn()} stopped reading the spec as a condition in
     * round 54, so membership here is decided by the child's LIFETIME alone -
     * a row can perfectly well hold a site whose spec names fd 3, fd 9, or
     * nothing at all. WHY THE OLD SENTENCE COULD NOT SIMPLY BE LEFT STANDING:
     * it is still true of all seven rows below, by accident, because no site
     * here happens to name a high fd. A definition that has stopped matching
     * its code but still matches today's data is precisely the kind that
     * survives a careful reading, and the reader it misleads is the one adding
     * the eighth row.
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
     * Every top-level directory in a reachable library that its autoload roots
     * do NOT cover, and whether this walk reads it anyway.
     *
     * E449, AND THE MEASUREMENT IS THE FINDING. The lib walk derives its files
     * from each library's `autoload` section, which is a correct answer to
     * "what can this process load" and a WRONG answer to "what can run with
     * this process's descriptors open". Counted across the reachable closure
     * on this tree by a census whose generator is
     * {@see testEveryFileOutsideAnAutoloadRootIsClassified()} itself: the
     * autoload roots cover well under half of the closure's PHP files, and
     * before this roster every file outside them was invisible to every arm
     * here - so the guard reported CLEAN over a surface it had never looked
     * at, which is rule 15's dead-instrument shape one level up from a dead
     * scanner.
     *
     * ⚠️ NO FIGURE IS WRITTEN IN THIS DOC-BLOCK ON PURPOSE (rule 18): a
     * sibling's merge moves every one of those counts in a sentence no test
     * reads. The partition is re-derived on every run instead, and an
     * unclassified segment reds.
     *
     * `walked => true` MEANS THIS WALK READS IT, and each such row carries the
     * MECHANISM that makes the directory run with our descriptors, verified
     * against the source rather than inferred from the directory's name
     * (rule 8). `walked => false` means the walk deliberately does not, and
     * the row is the argument for why not - which is a thing a reader can
     * disagree with, unlike an absence.
     *
     * THE ROSTER IS THE HORIZON, so a library that grows a SIXTH kind of
     * directory reds {@see testEveryFileOutsideAnAutoloadRootIsClassified()}
     * rather than being skipped in silence. That is rule 14 at directory
     * granularity: the guard goes red on what it cannot classify.
     *
     * `mechanism` IS THE EVIDENCE, AND IT IS WHAT MAKES A ROW FALSIFIABLE.
     * Every `walked => true` row names a file in the closure and a marker in
     * it, and {@see testEveryFileOutsideAnAutoloadRootIsClassified()} asserts
     * the BICONDITIONAL: marker present means the row must be walked, marker
     * gone means it must not be. Without it `walked` is a flag anybody can
     * flip to `false` to make a directory disappear from the walk while the
     * reason beside it still says the code runs in this process - a narrowing
     * with a justification attached that has stopped being true, which is the
     * one shape rule 7 exists for. With it, flipping the flag reds.
     *
     * @var array<string, array{walked:bool, reason:string, mechanism?:array{0:string,1:string}}>
     */
    private const LIB_HORIZON = [
        // WALKED. Not in any autoload section, and executed in THIS process
        // all the same: `candy-core/src/I18n/T.php::load()` spells the load
        // `$data = require $path;` where $path is `<lang dir>/<locale>.php`,
        // and every lib registers its own directory through `T::register()`.
        // A `require` is not an autoload, so no manifest mentions these files
        // and the derivation could never have reached them.
        'lang' => [
            'walked' => true,
            'reason' => 'executed in this process by a runtime require in candy-core '
                . 'I18n\T::load(), not by the autoloader, so no autoload root names it',
            'mechanism' => ['candy-core/src/I18n/T.php', 'require $path'],
        ],

        // WALKED, and for a DIFFERENT reachability - this one is the gap
        // ACCOUNTED_FOR_IN_LIBS' doc-block named and filed rather than fixed.
        // `candy-pty/src/Spawn.php` holds `SHIM_RELATIVE = '/../bin/pty-shim.php'`
        // and prepends `[PHP_BINARY, <shim>]` to the command it spawns, so the
        // shim runs as OUR CHILD and inherits every descriptor we had open;
        // anything it spawned in turn would inherit them again. Loadability
        // was never the right question for it.
        'bin' => [
            'walked' => true,
            'reason' => 'exec\'d as a child of this process - candy-pty Spawn::SHIM_RELATIVE '
                . 'runs bin/pty-shim.php under PHP_BINARY, so it inherits our descriptors',
            'mechanism' => ['candy-pty/src/Spawn.php', "SHIM_RELATIVE = '/../bin/pty-shim.php'"],
        ],

        // NOT WALKED, and this is the one derived reason rather than a
        // measured one: Composer registers a package's `autoload-dev` only
        // when that package is the ROOT of the install, so a sibling's tests
        // cannot be loaded from this process however many spawns they hold -
        // and they hold plenty. This is the largest single class of file
        // outside the horizon by a wide margin.
        'tests' => [
            'walked' => false,
            'reason' => 'a sibling\'s autoload-dev is registered for the ROOT package only, so '
                . 'its tests are not loadable here; they are that library\'s own suite to guard',
        ],

        // NOT WALKED. In no autoload section of any lib in the closure, and
        // nothing in any lib's autoloaded source runs one. MEASURED, and named
        // because the reader who finds them should not conclude the guard
        // missed them: `candy-focus/examples/focus-ring.php` really does hold
        // exposed spawns, and it is a demo a human runs by hand.
        'examples' => [
            'walked' => false,
            'reason' => 'not autoloaded and not exec\'d by any lib\'s own code - a demo a human '
                . 'runs by hand, in its own process, with its own descriptors',
        ],

        // NOT WALKED, and empty of PHP on this tree - which is a measurement
        // rather than a licence, hence the row: the day candy-pty puts a
        // runnable script under docs/, this row is where somebody has to
        // argue that it still cannot reach us.
        'docs' => [
            'walked' => false,
            'reason' => 'prose; the reachable closure has no PHP under any docs/ directory today',
        ],

        // NOT WALKED. A PHP file at a library's own root, which today is one
        // tool config (`.php-cs-fixer.dist.php`). It is loaded by php-cs-fixer
        // in a separate process and by nothing here.
        '' => [
            'walked' => false,
            'reason' => 'a PHP file at the library root - a tool config loaded by that tool in '
                . 'its own process, never by this one',
        ],
    ];

    /**
     * Exposed spawns in reachable SIBLING libraries. E418.
     *
     * WHY THE GUARD WIDENED. Round 53 built this instrument, rostered seven
     * sites, and scoped it to `sugar-crush/src` - and the defect class is not
     * a sugar-crush property. The rows below are what the widening found, and
     * most of them are in candy-pty, which every PTY-driven child in the tree
     * goes through.
     *
     * NO CENSUS FIGURE IS WRITTEN IN THIS DOC-BLOCK, which is a correction
     * rather than an omission. WHAT IT USED TO SAY: "8 spawn sites outside
     * this package, 3 of them exposed". WHAT IS TRUE NOW: that was correct on
     * the day it was written and any sibling's merge invalidates it, in a
     * sentence no test reads. WHY THE INFORMATION IS NOT LOST: this roster IS
     * the figure, and {@see testNoReachableLibRowIsStale()} re-derives it
     * against the tree on every run - which a sentence cannot do.
     *
     * WHAT "REACHABLE" MEANS, TWICE OVER, AND NEITHER HALF IS A CHOICE MADE
     * HERE. Which LIBRARIES: whatever `vendor/sugarcraft` holds, i.e. what
     * this package requires - see {@see LIB_SCOPE}. Which FILES INSIDE ONE:
     * the autoload roots that library's own `composer.json` declares, read by
     * {@see autoloadRoots()} rather than assumed to be `src`. The distinction
     * matters because the second half used to be unstated: the walk went to
     * `<lib>/src` and nothing said why, which is the same shape as the
     * exemption this round removed - a narrowing nobody had argued for.
     *
     * WHAT THAT DERIVATION LEAVES OUT, measured rather than waved at.
     * `autoload-dev` is deliberately not read, and that is what puts a
     * sibling's `tests/` out of scope: Composer registers `autoload-dev` for
     * the ROOT package only, so a lib's own tests cannot be loaded from this
     * process however many spawns they contain. `examples/` appears in no
     * autoload section of any lib in the closure and is unreachable for the
     * same reason - stated rather than left to be inferred, because
     * candy-focus's examples do hold exposed spawns and a reader who found
     * them would otherwise think this guard had missed them.
     *
     * THE GAP THIS ARGUMENT DOES NOT COVER, because "loadable" is not the same
     * as "runs and inherits our descriptors": code a lib EXECS. candy-pty
     * ships `bin/pty-shim.php` and `Spawn.php::wrapInShim()` runs it as a
     * child, so that shim inherits this process's descriptors and anything it
     * spawned would inherit them again - and no arm of this guard reads it.
     * Measured on this tree: the shim mentions `proc_open` twice and both are
     * prose in comments, so the scanner reports no site and no unresolved
     * appearance there. That is a measurement of today, not a guarantee, and
     * it is filed rather than fixed here.
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
    /**
     * The highest fd the child probe looks at, single-sourced.
     *
     * IT IS IN A CONSTANT BECAUSE IT APPEARS IN FOUR PLACES and one of them is
     * a failure message. The child's loop, the "name every fd" spec, and the
     * two messages that tell a reader what was and was not searched all have
     * to mean the same number; spelled four times, the message is the one that
     * rots, and a message naming the wrong window sends the next reader after
     * the wrong cause. Rule 4's shape, one level down from line numbers.
     *
     * WHY 40 AND NOT MORE. The probe OPENS a descriptor per fd it tests, so
     * the ceiling is also a cost. Sampled during a full suite run the process
     * held nothing above the teens, so 40 is roughly double the observed high
     * water mark - loose enough not to be luck, cheap enough to run per test.
     * If the marker ever lands above it the first assertion below reds, which
     * is why that message names the window rather than only the two causes it
     * used to offer.
     */
    private const PROBE_FD_CEILING = 40;

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
     * reported. It is a receipt. Adding a CLOSING_HELPERS row costs a row in a
     * receipt roster too, which is the point: the promotion has to be written
     * down somewhere a reviewer reads.
     *
     * THIS ROSTER IS THIS PACKAGE'S HALF ONLY. WHAT THE SENTENCE ABOVE USED TO
     * SAY: that adding a CLOSING_HELPERS row costs a row HERE. WHAT IS TRUE
     * NOW: E418 widened the exposure arm to the reachable closure and this
     * receipt arm was not widened with it, so for the length of one round a
     * promotion inside a sibling library cost nothing anywhere - E425 reopened
     * at the scope the same round had just created. Measured: an exposed spawn
     * appended to candy-mosaic's src and closed by a rostered helper left this
     * guard green; the byte-identical injection into this package's own src
     * reddened it. WHY THE SENTENCE STILL EARNS ITS PLACE: the argument was
     * always right and only its scope was wrong. The sibling half now lives in
     * {@see SHORT_VIA_HELPER_IN_LIBS}, kept separate for the reason the two
     * exposure rosters are kept separate.
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
     * The same receipt, for a reachable sibling library. E425 at E418's scope.
     *
     * EMPTY BY MEASUREMENT, NOT BY OMISSION, and that distinction is why the
     * constant exists now rather than being invented when the first row is
     * needed. Measured over the reachable closure: no spawn in any sibling
     * library is promoted to short by a CLOSING_HELPERS row today - every
     * short verdict out there rests on a literal proc_close().
     *
     * AN EMPTY ROSTER ASSERTED AGAINST A TREE THAT HAPPENS TO BE EMPTY IS
     * WORTH NOTHING, which is the whole of rule 15 and the reason
     * {@see testEveryHelperPromotedShortVerdictInAReachableLibIsRecorded()}
     * pushes a known-positive fixture through the SAME accounting function in
     * the same test, and refuses to believe the walk until it has seen a
     * closure's worth of files.
     *
     * SEPARATE FROM {@see SHORT_VIA_HELPER} for the reason the two exposure
     * rosters are separate. A row there is a promotion inside code this
     * package owns and could undo. A row here is a promotion inside code it
     * cannot edit, where the CLOSING_HELPERS claim being wrong is somebody
     * else's bug and this package's leak.
     *
     * @var array<string, array{count:int, reason:string}>
     */
    private const SHORT_VIA_HELPER_IN_LIBS = [];

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
     * The rule-14 half, for a reachable sibling library.
     *
     * EMPTY BY MEASUREMENT: the reachable closure holds no appearance of the
     * name that is not a direct global call today. This arm was the last one
     * left reading `src/` only after E418 widened the exposure arm, and an
     * indirectly-reached spawn is the one shape whose descriptor spec nothing
     * can see at all - so leaving it narrow meant the least visible defect
     * class kept the narrowest scope. Its liveness rests on the same fixture
     * control the src twin uses, run in the same test.
     *
     * @var array<string, array{count:int, reason:string}>
     */
    private const NOT_A_SPAWN_IN_LIBS = [];

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
            A proc_open() child here outlives the call that spawned it, and no row
            in ACCOUNTED_FOR covers it. For as long as that child runs it holds
            every descriptor this process had open at the moment of the spawn -
            E365's shape.

            WHAT THE SPEC NAMES IS NOT WHY THE SITE IS LISTED. The bracket in each
            line below reports the fds the spec does name, because it is useful
            detail when you go and read the code. It is detail only: this guard
            stopped testing it in round 54, and no addition to it will take a site
            off this list.

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
        $this->assertLibWalkIsLive($scanned);

        self::assertSame([], $unaccounted, <<<'TEXT'
            A proc_open() child in a SIBLING LIBRARY outlives the call that spawned
            it, and no row in ACCOUNTED_FOR_IN_LIBS covers it. For as long as that
            child runs it holds every descriptor this process had open at the moment
            of the spawn - E365's shape, in a package sugar-crush cannot edit from
            here. What the spec names is reported in the bracket beside the finding
            as detail, never as the reason it is listed.

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

            NARROWING LIB_SCOPE IS NOT A RESOLUTION - widening it is the only reason
            a sibling's spawn is visible from here at all, and every round before
            round 54 had none of them in view.

            DO NOT READ THIS GUARD AS COVERING THE MONOREPO. It covers the REACHABLE
            closure, which is a strict subset of it, and there are exposed spawns in
            libraries outside that closure which no guard anywhere is watching.
            ACCOUNTED_FOR_IN_LIBS' doc-block says what the scope does and does not
            reach.
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
     * The reachability of a FILE is read off a manifest, in both polarities.
     *
     * The lib arms above assert that a walk found nothing wrong, and a walk
     * pointed at the wrong directory finds nothing wrong very reliably. The
     * `> 100` floor catches a walk pointed at nothing at all; this catches the
     * subtler half - a derivation that quietly answers `src` whatever the
     * manifest says, or quietly answers nothing whatever the manifest says.
     * Both directions are pinned because a rule verified in one is half a rule.
     */
    public function testAutoloadRootsAreDerivedFromTheManifest(): void
    {
        self::assertSame(
            ['src'],
            self::autoloadRoots(['autoload' => ['psr-4' => ['SugarCraft\\Pty\\' => 'src/']]]),
            'the ordinary shape every lib in the closure uses today; if this is wrong the walk '
                . 'is scanning the wrong files and every absence it reports is empty.',
        );

        self::assertSame(
            ['bin/boot.php', 'lib', 'map', 'other'],
            self::autoloadRoots(['autoload' => [
                'psr-4' => ['A\\' => 'lib/', 'B\\' => ['lib/', 'other/']],
                'classmap' => ['map/'],
                'files' => ['bin/boot.php'],
            ]]),
            'every autoload kind contributes, a list-valued psr-4 prefix contributes each of its '
                . 'paths, and duplicates collapse. A derivation that reads only psr-4 would miss '
                . 'a classmap, which is a perfectly ordinary way to ship loadable code.',
        );

        self::assertSame(
            [],
            self::autoloadRoots(['autoload-dev' => ['psr-4' => ['A\\Tests\\' => 'tests/']]]),
            'autoload-dev MUST NOT contribute. Composer registers it for the root package only, '
                . "so a sibling's tests/ is not loadable from this process - and that, rather "
                . 'than a hard-coded directory name, is why lib test suites are out of scope. If '
                . "this returns ['tests'] the guard starts reading every sibling's test suite "
                . 'and reds on spawns that cannot reach this process at all.',
        );

        self::assertSame(
            [],
            self::autoloadRoots(['name' => 'sugarcraft/candy-nothing']),
            'a manifest with no autoload section makes nothing loadable; the caller turns this '
                . 'into a loud failure rather than a silent skip.',
        );

        self::assertSame(
            [''],
            self::autoloadRoots(['autoload' => ['psr-4' => ['A\\' => '']]]),
            'a package-root autoload must survive normalisation as an empty string so the '
                . 'caller can refuse it. Dropping it here would turn "walk this whole package, '
                . 'vendor and all" into "walk nothing", silently.',
        );
    }

    /**
     * Where a library-relative path sits with respect to that library's own
     * autoload roots: `null` inside one, otherwise the top-level segment.
     *
     * PURE, AND SEPARATE FROM THE WALK for the same reason
     * {@see autoloadRoots()} is: it is the half of the reachability argument
     * that can be pinned against literals rather than against whatever the
     * closure happens to hold today, which
     * {@see testTheHorizonClassifierReadsTheRoots()} does in both polarities.
     *
     * `str_starts_with($relative, $root)` WOULD BE WRONG AND WOULD LOOK RIGHT.
     * A root of `src` must not swallow `srcx/Foo.php`, so the separator is
     * part of the comparison; without it a whole sibling directory disappears
     * into a root that does not contain it, and disappearing is precisely the
     * failure E449 is about.
     *
     * AN EMPTY ROOT IS THE PACKAGE ROOT and covers everything. It is answered
     * honestly here rather than filtered out, because {@see libSourceFiles()}
     * refuses it loudly and a filter here would turn that refusal into a
     * silent skip.
     *
     * @param list<string> $roots
     */
    private static function horizonSegment(string $relative, array $roots): ?string
    {
        foreach ($roots as $root) {
            if ($root === '' || $relative === $root || \str_starts_with($relative, $root . '/')) {
                return null;
            }
        }

        $slash = \strpos($relative, '/');

        return $slash === false ? '' : \substr($relative, 0, $slash);
    }

    /**
     * The top-level segment of a file that is outside the autoload roots AND
     * outside {@see LIB_HORIZON}, or null when the file is accounted for.
     *
     * THE WHOLE DECISION IN ONE PURE CALL, and that is the point rather than
     * tidiness. Its caller asserts a set is EMPTY, so the roster lookup has to
     * be reachable by a known-positive fixture in the same test - and a lookup
     * spelled inline in the walk is reachable only by a real unclassified file,
     * which by construction the tree does not have. Rule 25: a fixture whose
     * expected value is what a dead instrument returns proves nothing, and
     * `[]` is what an inline `isset()` deleted outright returns too.
     *
     * @param list<string> $roots
     */
    private static function unrosteredSegment(string $relative, array $roots): ?string
    {
        $segment = self::horizonSegment($relative, $roots);

        if ($segment === null || isset(self::LIB_HORIZON[$segment])) {
            return null;
        }

        return $segment;
    }

    /**
     * Every PHP file in the reachable closure is either walked or classified.
     *
     * E449. THIS IS THE ARM THAT MAKES THE GUARD SAY WHAT IT CANNOT SEE.
     * Everything else here asserts that a walk found nothing wrong, and the
     * walk's own horizon was, until this test, an unexamined property of the
     * `autoload` sections it derives files from. A library that starts
     * shipping a runnable `daemon/` or `hooks/` directory was invisible - not
     * flagged, not skipped-with-a-reason, invisible - and the guard went on
     * reporting clean.
     *
     * WHAT IT ASSERTS, in the order it matters:
     *
     *  1. The classifier is ALIVE (rule 15/25). An unclassified segment is
     *     reported for a synthetic path before any absence below is believed,
     *     because "no unclassified segments" is also what a classifier that
     *     always answers `null` returns.
     *  2. Every walked LIB_HORIZON row still matches real files. A widening
     *     that has stopped reaching anything is a widening in name only, and
     *     rule 35: a figure landing where it landed before is a question, not
     *     a confirmation.
     *  3. No PHP file in any reachable library sits outside both the autoload
     *     roots and the roster.
     *
     * NO TOTAL IS ASSERTED (rule 18). The partition's sizes move with every
     * sibling merge; what may not move is whether a file is accounted for.
     */
    public function testEveryFileOutsideAnAutoloadRootIsClassified(): void
    {
        self::assertSame(
            'daemon',
            self::unrosteredSegment('daemon/Run.php', ['src']),
            'the horizon classifier is dead - it cannot see an unrostered directory outside the '
                . 'autoload roots, so the absence asserted below is what it would report for any '
                . 'tree at all.',
        );
        self::assertNull(
            self::unrosteredSegment('lang/en.php', ['src']),
            'and the other polarity, without which the line above is satisfied by a classifier '
                . 'that reports every file in the closure - which would red correct trees and '
                . 'teach the next reader to widen the roster until it stopped.',
        );

        $base = \dirname(__DIR__, 2) . '/' . self::LIB_SCOPE;
        self::assertDirectoryExists($base, self::LIB_SCOPE . ' is missing, so nothing can be classified.');

        $libs = \glob($base . '/*', \GLOB_ONLYDIR) ?: [];
        \sort($libs);

        $unclassified = [];
        $walkedHits = [];
        $seen = 0;

        foreach ($libs as $lib) {
            $name = \basename($lib);
            $decoded = \json_decode((string) \file_get_contents($lib . '/composer.json'), true);
            self::assertIsArray($decoded, $name . '/composer.json did not decode to an array.');
            $roots = self::autoloadRoots($decoded);

            /** @var \SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator((string) \realpath($lib), \FilesystemIterator::SKIP_DOTS),
            ) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = \substr($file->getPathname(), \strlen((string) \realpath($lib)) + 1);

                // A library's own installed dependencies are not the library.
                // Excluded here rather than in the roster because it is not a
                // horizon question at all: those files belong to a third
                // package and are reached, if at all, through ITS manifest.
                if (\str_starts_with($relative, 'vendor/')) {
                    continue;
                }

                $seen++;

                if (self::unrosteredSegment($relative, $roots) !== null) {
                    $unclassified[] = $name . '/' . $relative;

                    continue;
                }

                $segment = self::horizonSegment($relative, $roots);
                if ($segment !== null && (self::LIB_HORIZON[$segment]['walked'] ?? false) === true) {
                    $walkedHits[$segment] = ($walkedHits[$segment] ?? 0) + 1;
                }
            }
        }

        // What libSourceFiles() ACTUALLY yields, keyed by horizon segment. Its
        // horizon keys keep their segment (`candy-pty/bin/pty-shim.php`) while
        // an autoload root is stripped from its own, so the second path
        // component is the segment for exactly the files this is counting.
        $emitted = [];
        foreach ($this->libSourceFiles() as $key => $_source) {
            $parts = \explode('/', $key);
            if (isset($parts[2]) && (self::LIB_HORIZON[$parts[1]]['walked'] ?? false) === true) {
                $emitted[$parts[1]] = ($emitted[$parts[1]] ?? 0) + 1;
            }
        }

        // A closure of empty directories classifies perfectly.
        self::assertGreaterThan(
            100,
            $seen,
            'only ' . $seen . ' PHP files were found across the whole reachable closure, which '
                . 'is far too few - the walk is pointed somewhere wrong and the partition below '
                . 'is empty rather than clean.',
        );

        foreach (self::LIB_HORIZON as $segment => $row) {
            self::assertNotSame('', \trim($row['reason']), $segment . ' is rostered without a reason.');

            if (isset($row['mechanism'])) {
                [$file, $marker] = $row['mechanism'];
                $path = $base . '/' . $file;
                self::assertFileExists($path, $segment . "'s mechanism cites " . $file
                    . ', which is no longer in the closure. The row is unfalsifiable until the '
                    . 'citation is repaired or the row is retired.');

                self::assertSame(
                    $row['walked'],
                    \str_contains((string) \file_get_contents($path), $marker),
                    $segment . " is rostered walked=" . \var_export($row['walked'], true)
                        . ', and ' . $file . ' now says otherwise. THE MARKER IS THE EVIDENCE: '
                        . 'if it is present the directory runs with our descriptors and the walk '
                        . 'must read it; if it is gone the mechanism has been removed and the row '
                        . 'is stale. Do not flip the flag to match - work out which happened.',
                );
            } else {
                self::assertNotSame(
                    true,
                    $row['walked'],
                    $segment . ' is walked with no mechanism recorded. A walked row is a claim '
                        . 'that the directory runs with this process\'s descriptors, and a claim '
                        . 'with no citation cannot go stale visibly.',
                );
            }

            if ($row['walked'] !== true) {
                continue;
            }

            self::assertArrayHasKey($segment, $walkedHits, <<<TEXT
                LIB_HORIZON says this walk reads `{$segment}/`, and there is no such file
                anywhere in the reachable closure any more.

                The widening is dead. Either the directory was renamed in every
                library at once - in which case fix the row - or the classifier has
                started answering `null` for it, in which case those files are being
                counted as autoloaded and every arm here is quietly reading a
                different set than it says it does.
                TEXT);

            // THE HALF THAT WAS MISSING, AND A MUTATION FOUND IT RATHER THAN A
            // READING. Everything above this line is derived from a walk this
            // TEST does over the filesystem. libSourceFiles() - the generator
            // every other arm in this file consumes - is a SEPARATE walk, and
            // nothing tied the two together: making libSourceFiles() skip every
            // horizon segment outright, which is E449's widening reverted in
            // one line, left all 14 tests green. The assertion total moved and
            // no assertion failed, which is a silent narrowing with a roster
            // still describing the wide behaviour.
            //
            // Rule 35's shape: the roster is not evidence that the walk reads
            // what it says. Only the walk's own output is.
            self::assertSame(
                $walkedHits[$segment],
                $emitted[$segment] ?? 0,
                $segment . ' is rostered as walked and libSourceFiles() emitted '
                    . ($emitted[$segment] ?? 0) . ' of its ' . $walkedHits[$segment] . ' files. '
                    . 'The generator every other arm here consumes is reading a different set '
                    . 'from the one this roster describes, so those arms are scanning less than '
                    . 'they claim - which is exactly the invisibility E449 is about, reintroduced '
                    . 'behind a roster that still says otherwise.',
            );
        }

        \sort($unclassified);
        self::assertSame([], $unclassified, <<<'TEXT'
            A PHP file in a reachable library sits outside that library's autoload
            roots AND outside LIB_HORIZON, so no arm of this guard has ever looked
            at it and none of them said so.

            THIS IS NOT AUTOMATICALLY A DEFECT IN THE FILE. It is a directory nobody
            has classified. Add a LIB_HORIZON row for its top-level segment, with
            the MECHANISM measured off the source rather than guessed from the name:

              walked => true   the directory runs with this process's descriptors -
                               it is required at runtime (like lang/), or exec'd as
                               our child (like candy-pty's bin/). The walk then reads
                               it, and a spawn in there needs a roster row like any
                               other.
              walked => false  it cannot reach this process, and the reason is the
                               row. "We do not scan it" is not a reason.

            DELETING THE FILE'S DIRECTORY FROM THE WALK IS NOT A RESOLUTION, and
            neither is narrowing LIB_SCOPE. Before E449 every one of these files was
            invisible, and the guard reported clean over all of them.
            TEXT);
    }

    /**
     * The horizon classifier, pinned against literals in both polarities.
     *
     * The arm above asserts a set is empty, and a classifier that answers
     * `null` for everything empties it perfectly. This one cannot be satisfied
     * that way: half its cases demand a non-null answer and half demand null,
     * so no constant return passes it.
     */
    public function testTheHorizonClassifierReadsTheRoots(): void
    {
        self::assertNull(
            self::horizonSegment('src/Spawn.php', ['src']),
            'a file inside an autoload root is already walked and is not a horizon question.',
        );

        self::assertSame(
            'srcx',
            self::horizonSegment('srcx/Foo.php', ['src']),
            'a root must match a whole path SEGMENT. A prefix test would swallow this entire '
                . 'directory into a root that does not contain it - which is the exact shape of '
                . 'the invisibility E449 is about, one level down.',
        );

        self::assertSame(
            'lang',
            self::horizonSegment('lang/en.php', ['src']),
            'the runtime-required directory the autoload derivation could never reach.',
        );

        self::assertSame(
            '',
            self::horizonSegment('.php-cs-fixer.dist.php', ['src']),
            'a PHP file at the library root has no segment, and the empty string is a roster '
                . 'key like any other rather than a reason to drop it.',
        );

        self::assertSame(
            'tests',
            self::horizonSegment('tests/Deep/Nested/Case.php', ['src', 'lib']),
            'the TOP-level segment, not the deepest - the roster classifies directories.',
        );

        self::assertNull(
            self::horizonSegment('anything/at/all.php', ['']),
            'an empty root is the package root and covers everything; libSourceFiles() refuses '
                . 'it loudly, and answering it dishonestly here would turn that refusal into a '
                . 'silent skip.',
        );
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

        $unaccounted = $this->unaccountedAppearances(
            $this->sourceFiles(),
            \array_map(static fn (array $row): int => $row['count'], self::NOT_A_SPAWN),
        );

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
     * Unreadable descriptor specs in a REACHABLE SIBLING LIBRARY. E447.
     *
     * EMPTY, AND THE EMPTINESS IS THE ROUND'S RESULT RATHER THAN ITS
     * PREMISE. When this arm was written there was exactly one member -
     * `candy-core/Program.php::runExec`, whose spec is
     * `$req->captureOutput ? [...] : [...]` - and E447 offered two ways out:
     * make it readable, or make the unreadability explicit and rostered. It
     * turned out to be the first: the refusal was correct and the DIAGNOSIS
     * was not. Both arms of that ternary carry the integer keys 0, 1 and 2, so
     * which fds the spec names never depended on the condition; only the
     * VALUES did, and this instrument reads keys.
     * {@see ChildLifetimeScanner::ternaryArms()} reads it now and
     * `ChildLifetimeScannerFixtureTest::descriptorSpecs()` pins the shape in
     * both polarities.
     *
     * SO WHY DOES THE ROSTER EXIST AT ALL. Because the src twin's advice -
     * "spell the spec where the call can see it, or widen the scanner" - is
     * advice sugar-crush can act on for its OWN files and cannot act on for a
     * sibling's. A future unreadable spec in candy-pty is not this package's
     * to rewrite, and a guard whose only resolution is an edit the reader
     * cannot make gets weakened instead of used. A row here says: this spec is
     * unreadable, a person looked at it, and widening the scanner was judged
     * the wrong trade.
     *
     * A ROW IS NOT AN EXEMPTION FOR A HIGH FD. An unreadable spec is the one
     * shape about which this guard can make NO statement at all, so a row is
     * strictly a record that the blindness is known.
     *
     * @var array<string, string>
     */
    private const UNREADABLE_IN_LIBS = [];

    /**
     * No spawn in the reachable closure has a spec nothing can read.
     *
     * E447, AND THE ARM ROUND 54 DELIBERATELY DID NOT WIDEN. Its sugar-crush
     * twin has existed for two rounds and stops at this package's `src/`,
     * which left the unreadability question unasked over every sibling - and
     * a sibling is exactly where it could not be answered by reading the diff,
     * because the diff is in another package.
     *
     * SEPARATE FROM THE SRC TWIN rather than a widened loop inside it, for the
     * reason all the lib arms here are separate: this one reds when SOMEBODY
     * ELSE changes a file, and the reader deserves to be told that in the
     * message instead of deducing it.
     */
    public function testNoDescriptorSpecInAReachableLibIsUnreadable(): void
    {
        // Rule 15, in THIS test. What follows asserts a set is empty, and a
        // scanner that answered `fds => [0]` for everything would empty it
        // just as convincingly as a closure with no unreadable spec in it.
        $probe = ChildLifetimeScanner::scan(
            "<?php\nclass F { private \$h; function m(\$p) { \$this->h = proc_open('x', \$this->spec(), \$p); } }\n",
        )['sites'];
        self::assertCount(1, $probe, 'the scanner found no site in the probe at all - it is dead.');
        self::assertNull(
            $probe[0]['fds'],
            'a spec behind a method call must read as unreadable; if it does not, the absence '
                . 'asserted below is what this scanner reports for every tree.',
        );

        $unreadable = [];
        $scanned = 0;
        foreach ($this->libSourceFiles() as $relative => $source) {
            $scanned++;
            foreach (ChildLifetimeScanner::scan($source)['sites'] as $site) {
                $key = $relative . '::' . $site['function'];
                if ($site['fds'] === null && !isset(self::UNREADABLE_IN_LIBS[$key])) {
                    $unreadable[] = $key;
                }
            }
        }

        $this->assertLibWalkIsLive($scanned);

        self::assertSame([], $unreadable, <<<'TEXT'
            A proc_open() in a SIBLING LIBRARY has a descriptor spec
            ChildLifetimeScanner cannot read, so nothing anywhere is checking which
            fds it names - and unlike an exposed spawn, this one cannot even be
            reported accurately, because the instrument has no opinion to report.

            YOU ARE PROBABLY RESOLVING A MERGE. This walk reads through
            vendor/sugarcraft, so a change in candy-core or candy-pty reds THIS
            suite and the diff in front of you is very likely not the cause.

            THE RESOLUTIONS, best first:

              1. WIDEN THE SCANNER, if the spec is readable in principle and this
                 instrument simply cannot spell it yet. That is what E447 turned
                 out to be: a ternary whose two arms name the same fds is not an
                 unreadable spec, it is a shape nobody had taught it. Pin the new
                 shape in ChildLifetimeScannerFixtureTest, in BOTH polarities.
              2. A ROW IN UNREADABLE_IN_LIBS, when widening is the wrong trade.
                 The row is a record that the blindness is known, never an
                 exemption for what the spec might contain.

            ASKING THE SIBLING TO REWRITE ITS SPEC IS RESOLUTION 3, not 1: it is a
            real fix and it is an edit this package cannot make from here.
            TEXT);

        $stale = [];
        foreach (self::UNREADABLE_IN_LIBS as $key => $reason) {
            self::assertNotSame('', \trim($reason), $key . ' is recorded without a reason.');
            $stale[] = $key;
        }

        // Vacuous while the roster is empty, and live the moment it is not:
        // every row must still name a real site, or it is a licence outliving
        // the thing it licensed.
        $found = [];
        if ($stale !== []) {
            foreach ($this->libSourceFiles() as $relative => $source) {
                foreach (ChildLifetimeScanner::scan($source)['sites'] as $site) {
                    if ($site['fds'] === null) {
                        $found[] = $relative . '::' . $site['function'];
                    }
                }
            }
        }

        self::assertSame(
            [],
            \array_values(\array_diff($stale, $found)),
            'a row in UNREADABLE_IN_LIBS matches nothing any more. Either the library made the '
                . 'spec readable, or the scanner learned to read it - in both cases delete the '
                . 'row and say which. A row that matches nothing is a licence outliving the '
                . 'thing it licensed.',
        );
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
     * file is opened AFTER a spacer, which is what guarantees it cannot land
     * on fd 3 - the one descriptor the "named" spec below replaces - so the
     * comparison is not a coin flip on whatever PHPUnit happens to have open.
     * Identity is `fstat()`'s dev+ino pair rather than a path or an fd number,
     * because the child is asked whether it can reach the same FILE, which is
     * the property that matters. The child probes fd 3 up to
     * {@see PROBE_FD_CEILING} through `php://fd/N`, which is POSIX and does
     * not need procfs. Three specs are compared: bare, one high fd named, and
     * every fd in that window named.
     *
     * MEASURED at PHP 8.3.6 on Linux 6.8.0-138-generic, three consecutive
     * takes, identical each time: bare VISIBLE / one named VISIBLE / all named
     * gone. CI runs this package on ubuntu-latest at 8.3 and 8.4 only
     * (`scripts/affected-libs.php` puts sugar-crush in neither WINDOWS_LIBS nor
     * MACOS_LIBS), and the property under test is POSIX descriptor inheritance
     * across `execve`, not a PHP-version behaviour - so 8.4 is not a claim
     * being made from an untested box, it is the same kernel call.
     *
     * THE THIRD CASE IS NOT A RECOMMENDATION. Naming the whole window does close
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
        //
        // WHAT THIS USED TO OPEN: `/dev/null`. WHAT IS TRUE NOW: it opens a
        // real file, because /dev/null is also what the "named" spec puts on
        // the child's fd 3, and that collision is only invisible here by an
        // accident of how the suite is launched. WHY THE CHANGE EARNS ITS
        // PLACE - MEASURED, PHP 8.3.6 on Linux 6.8.0-138-generic, all four
        // cells: the CLI pins the running script at fd 3 when invoked as
        // `php <file>` (which is what `vendor/bin/phpunit` is) and does NOT
        // when invoked as `php -r`. So under phpunit the spacer lands on fd 4
        // and everything below holds; under any runner with no script fd the
        // spacer takes fd 3 itself, the bare child and the named child BOTH
        // see /dev/null there, and the "did the spec take effect" control
        // below reds with a message blaming the comparison rather than the
        // descriptor the spacer took. A spacer that is not /dev/null cannot
        // collide with the spec's /dev/null wherever it lands, so the control
        // stops depending on the launcher. The refutation itself was never at
        // risk in either world - only this control was.
        $spacerPath = (string) \tempnam(\sys_get_temp_dir(), 'sc_r54c_spacer_' . \getmypid() . '_');
        $spacer = \fopen($spacerPath, 'r');
        self::assertIsResource($spacer, 'the probe cannot be set up without a spare descriptor.');

        $marker = (string) \tempnam(\sys_get_temp_dir(), 'sc_r54c_inherit_' . \getmypid() . '_');
        $handle = \fopen($marker, 'r');
        self::assertIsResource($handle);

        $stat = \fstat($handle);
        self::assertIsArray($stat);
        $identity = $stat['dev'] . ':' . $stat['ino'];

        // Stat'd BY PATH rather than read off the spacer, which no longer
        // points at it. This is the identity the named spec is expected to
        // put on the child's fd 3.
        $nullStat = \stat('/dev/null');
        self::assertIsArray($nullStat);
        $devNull = $nullStat['dev'] . ':' . $nullStat['ino'];

        $spacerStat = \fstat($spacer);
        self::assertIsArray($spacerStat);

        // THE PIN ON THE PARAGRAPH ABOVE, and the only assertion here that
        // reds if somebody "simplifies" the spacer back to /dev/null. It is
        // not about descriptors at all - it is about the two identities the
        // fd-3 control compares being distinguishable in the first place.
        self::assertNotSame(
            $devNull,
            $spacerStat['dev'] . ':' . $spacerStat['ino'],
            'the spacer is /dev/null, which is also what the named spec puts on the child\'s '
                . 'fd 3. On a runner whose fd 3 is free at this point the spacer takes fd 3, and '
                . 'the fd-3 control below then compares /dev/null with /dev/null and reds for a '
                . 'reason that has nothing to do with descriptor inheritance. Open the spacer on '
                . 'any real file instead.',
        );

        try {
            $withBareSpec = $this->descriptorsVisibleToAChild([]);
            $withHighFdNamed = $this->descriptorsVisibleToAChild([3]);
            $withEveryFdNamed = $this->descriptorsVisibleToAChild(\range(3, self::PROBE_FD_CEILING));
        } finally {
            \fclose($handle);
            \fclose($spacer);
            \unlink($marker);
            \unlink($spacerPath);
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
                . 'a file this process holds open. Nothing below means anything if this fails. '
                . 'THREE causes, and the cheapest to check is listed first because it is not a '
                . 'defect at all: (1) the marker landed above fd '
                . self::PROBE_FD_CEILING . ', which is the whole window the child searches, so '
                . 'it was never looked for - raise PROBE_FD_CEILING and re-run before reading '
                . 'this as anything; (2) the probe is broken; (3) descriptors stopped being '
                . 'inherited across execve, and that one would retire this entire guard.',
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
            'The positive control for the mechanism: naming fd 3 through '
                . self::PROBE_FD_CEILING . ' DOES take the '
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
            for ($n = 3; $n <= __CEILING__; $n++) {
                $f = @fopen('php://fd/' . $n, 'r');
                if ($f === false) { continue; }
                $s = @fstat($f);
                if (is_array($s)) { $seen[] = $n . '=' . $s['dev'] . ':' . $s['ino']; }
                @fclose($f);
            }
            echo implode(" ", $seen);
            CHILD;

        // The nowdoc above cannot interpolate - its body spells $n, $f and $s,
        // which a heredoc would expand - so the ceiling is substituted, and
        // the substitution is CHECKED. A placeholder that silently failed to
        // match would leave the child scanning a literal that no longer
        // exists, and a probe that scans nothing reports "not inherited",
        // which is this guard's one dangerous answer.
        $probe = \str_replace('__CEILING__', (string) self::PROBE_FD_CEILING, $probe);
        self::assertStringNotContainsString(
            '__CEILING__',
            $probe,
            'the probe ceiling was not substituted into the child source, so the child would '
                . 'scan a window that does not parse. Every verdict below would be vacuous.',
        );

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
     * Appearances of the name that are not calls and that no licence covers.
     *
     * ONE FUNCTION FOR THE FIXTURE AND FOR THE TREE, and now for both scopes,
     * for the reason {@see overspent()} gives: a licence-spending rule
     * verified against a synthetic pair and then re-implemented inline for the
     * real scan is two rules, and the one that matters is the untested one.
     * This was inline in the src arm until the lib arm needed the same rule.
     *
     * @param iterable<string,string> $sources relative path => source
     * @param array<string,int> $licences key => how many appearances the row covers
     * @param ?int $scanned out-param, meaningful only after this returns
     * @return list<string>
     */
    private function unaccountedAppearances(
        iterable $sources,
        array $licences,
        ?int &$scanned = null,
    ): array {
        $scanned = 0;
        $allowance = [];
        $unaccounted = [];

        foreach ($sources as $relative => $source) {
            $scanned++;
            foreach (ChildLifetimeScanner::scan($source)['unresolved'] as $appearance) {
                $key = $relative . '::' . $appearance['function'];
                $allowance[$key] ??= $licences[$key] ?? 0;

                if ($allowance[$key] > 0) {
                    $allowance[$key]--;

                    continue;
                }

                $unaccounted[] = $key . ': ' . $appearance['kind'];
            }
        }

        return $unaccounted;
    }

    /**
     * The sibling walk actually walked something.
     *
     * THE ONE CARDINALITY THIS FILE ASSERTS, and the class doc-block explains
     * why it is the exception rather than a lapse: a LOWER BOUND far beneath
     * what the closure holds cannot be carried across by a fix or a merge,
     * only by a walk pointed at nothing - which is precisely what every lib
     * arm's empty result would otherwise be indistinguishable from. Shared by
     * all three lib arms so there is one place to read and one to change.
     */
    private function assertLibWalkIsLive(int $scanned): void
    {
        self::assertGreaterThan(
            100,
            $scanned,
            'only ' . $scanned . ' sibling source files were scanned, which is too few for this '
                . 'closure - the walk is pointed somewhere wrong and every absence it reports is '
                . 'empty. Check LIB_SCOPE, and check that the libraries still declare the '
                . 'autoload roots libSourceFiles() derives their files from.',
        );
    }

    /**
     * Every helper-promoted short verdict in a sibling library has a receipt.
     *
     * E425 HAD A SCOPE HOLE FOR EXACTLY ONE ROUND AND THIS IS IT. Round 54
     * widened the exposure arm to the reachable closure and left the receipt
     * arm reading `src/` only, so a spawn in a sibling library hidden behind a
     * new CLOSING_HELPERS row was invisible with nothing written down
     * anywhere - which is the sentence E425 was filed to make impossible,
     * reopened at the scope the same round had just created.
     *
     * MEASURED, BOTH DIRECTIONS, BEFORE THIS ARM EXISTED: an exposed spawn
     * appended to candy-mosaic's src and closed by a rostered helper left the
     * guard green at 10 tests; the byte-identical injection into this
     * package's own src reddened it. The asymmetry was the finding.
     *
     * SEPARATE FROM ITS SRC TWIN rather than a widened loop inside it, for the
     * reason the exposure arms are separate: this one reds when SOMEBODY ELSE
     * changes a file, and a reader who has just been handed a red suite needs
     * to be told that in the message rather than work it out.
     */
    public function testEveryHelperPromotedShortVerdictInAReachableLibIsRecorded(): void
    {
        // Rule 15, in THIS test: everything below asserts an absence, and the
        // roster it checks is empty today - so without a known positive pushed
        // through the same function, a dead scanner and a clean closure are
        // the same observation.
        $fixture = ['fixture.php' => self::KNOWN_SHORT_VIA_HELPER];
        self::assertSame(
            ['fixture.php::viaHelper: not recorded at all, found 1'],
            $this->unrecorded($fixture, []),
            'the accounting is dead - an unrostered helper promotion was not reported. Every '
                . 'absence below is worthless until this passes.',
        );
        self::assertSame(
            [],
            $this->unrecorded($fixture, ['fixture.php::viaHelper' => 1]),
            'a receipt for one must cover one, or a real row would read as a defect.',
        );

        $scanned = 0;
        $wrong = $this->unrecorded(
            $this->libSourceFiles(),
            \array_map(static fn (array $row): int => $row['count'], self::SHORT_VIA_HELPER_IN_LIBS),
            $scanned,
        );
        $this->assertLibWalkIsLive($scanned);

        foreach (self::SHORT_VIA_HELPER_IN_LIBS as $key => $row) {
            self::assertNotSame('', \trim($row['reason']), $key . ' has no reason recorded.');
        }

        self::assertSame([], $wrong, <<<'TEXT'
            A spawn in a SIBLING LIBRARY is being treated as short-lived - and so
            dropped from this guard entirely - on the strength of a
            ChildLifetimeScanner::CLOSING_HELPERS row rather than a literal
            proc_close(). That is allowed. It is not allowed to be invisible.

            YOU ARE PROBABLY RESOLVING A MERGE. This arm reads through
            vendor/sugarcraft, so a change in candy-pty or candy-core reds THIS
            suite and the diff in front of you is very likely not the cause.

            NOT RECORDED AT ALL: a CLOSING_HELPERS row was added, or a library
            changed a spawn to use one. Read that helper's source and satisfy
            yourself it closes on EVERY path out of itself - if it closes only
            sometimes it belongs in BEST_EFFORT_REAPERS, which reports the site
            instead of hiding it - then add a row to SHORT_VIA_HELPER_IN_LIBS
            naming the library and what you checked.

            RECORDED BUT NOT FOUND: the library fixed it, renamed it, or switched
            to a literal proc_close() (all good - delete the row), OR the scanner
            stopped stamping provenance and this row is the only thing that
            noticed. Find out which before deleting anything.
            TEXT);
    }

    /**
     * The rule-14 arm, asked of every reachable sibling library.
     *
     * THE LAST ARM LEFT AT THE OLD SCOPE. E418 widened the exposure arm and
     * E425's receipt followed it; this one did not, and it is the arm about
     * the shape whose descriptor spec nothing can read AT ALL - a spawn
     * reached through a variable function, a callable string or a method.
     * Leaving it narrow gave the least visible defect class the narrowest
     * scope, which is the wrong way round.
     *
     * The closure holds no such appearance today, so this arm is green on an
     * empty set - which is why the fixture control below is not optional.
     */
    public function testEveryAppearanceThatIsNotACallInAReachableLibIsAccountedFor(): void
    {
        $fixture = ['fixture.php' => self::KNOWN_POSITIVE_NOT_A_CALL];
        self::assertSame(
            [
                'fixture.php::probe: ' . ChildLifetimeScanner::REF_STRING,
                'fixture.php::probe: ' . ChildLifetimeScanner::REF_METHOD,
            ],
            $this->unaccountedAppearances($fixture, []),
            'the unresolved half of the instrument is dead; the absence asserted below is '
                . 'worthless until this passes.',
        );
        self::assertSame(
            [],
            $this->unaccountedAppearances(['fixture.php' => self::KNOWN_NEGATIVE_PLAIN_CALL], []),
            'a plain global call is a SITE, not an unresolved appearance; reporting it here '
                . 'would make every real call in every library need a row.',
        );

        $scanned = 0;
        $unaccounted = $this->unaccountedAppearances(
            $this->libSourceFiles(),
            \array_map(static fn (array $row): int => $row['count'], self::NOT_A_SPAWN_IN_LIBS),
            $scanned,
        );
        $this->assertLibWalkIsLive($scanned);

        self::assertSame([], $unaccounted, <<<'TEXT'
            The name proc_open appears in a SIBLING LIBRARY as something other than
            a direct global call - a method, a static, a string. It is not counted
            as a spawn and it is not dropped silently either, because an alphabet
            written to match only the cases already known has a hole shaped exactly
            like the next defect.

            YOU ARE PROBABLY RESOLVING A MERGE: this arm reads through
            vendor/sugarcraft, so another library's change reds THIS suite.

            If it really is not a spawn, add a row to NOT_A_SPAWN_IN_LIBS saying
            what it is. If it IS a spawn reached indirectly, then nothing anywhere
            can see its descriptor spec, and that is the finding - file it against
            the library that owns the file.
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
     * @param ?int $scanned out-param: how many sources were consumed, which the
     *                      lib arms need because a walk over nothing satisfies
     *                      an empty result exactly as well as a clean tree does.
     *                      Only meaningful once this function has returned.
     * @return list<string>
     */
    private function unrecorded(iterable $sources, array $receipts, ?int &$scanned = null): array
    {
        $scanned = 0;
        $seen = [];
        foreach ($sources as $relative => $source) {
            $scanned++;
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
     * Every reachable sibling library's loadable sources, keyed `<lib>/<path>`.
     *
     * THE ROOTS ARE READ, NOT ASSUMED. This walk used to go to `<lib>/src`
     * with nothing saying why, which is an unargued narrowing of exactly the
     * kind this round removed elsewhere. It now asks each library's own
     * `composer.json` which directories it autoloads - see
     * {@see autoloadRoots()} and {@see ACCOUNTED_FOR_IN_LIBS}' doc-block for
     * what that reaches and what it does not. At the time of writing every
     * lib in the closure declares `src/` and nothing else, so the file set is
     * the same one the hard-coded directory produced; the difference is that
     * a lib which starts autoloading somewhere else is followed without
     * anybody having to remember this method exists.
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
            $name = \basename($lib);
            $manifest = $lib . '/composer.json';

            // Rule 14, and the reason none of these three are `continue`: a
            // library this package requires but whose roots cannot be derived
            // is a library silently dropped from every absence assertion
            // downstream, which is indistinguishable from a clean one.
            self::assertFileExists(
                $manifest,
                $name . ' is in the reachable closure but has no composer.json, so its autoload '
                    . 'roots cannot be derived and this walk would skip it without saying so.',
            );

            $decoded = \json_decode((string) \file_get_contents($manifest), true);
            self::assertIsArray($decoded, $name . '/composer.json did not decode to an array.');

            $roots = self::autoloadRoots($decoded);
            self::assertNotSame(
                [],
                $roots,
                $name . ' declares no autoload section, so nothing in it is loadable from this '
                    . 'process - which may well be true, but it has never been true here before '
                    . 'and the walk must not decide that quietly.',
            );

            $emitted = [];

            foreach ($roots as $root) {
                self::assertNotSame(
                    '',
                    $root,
                    $name . ' autoloads from its package root. Walking that would descend into '
                        . "the library's own vendor/ directory, so this needs a decision rather "
                        . 'than a default.',
                );

                // An autoload root is stripped from the key, which is why the
                // rosters read `candy-pty/Spawn.php` and not
                // `candy-pty/src/Spawn.php`. Predates this method and is left
                // alone: every roster key in this file is spelled that way.
                foreach (self::phpUnder($lib . '/' . $root) as $relative => $source) {
                    // A root that IS a file keys as the root itself, which is
                    // what the pre-E449 walk did for a `files` autoload entry.
                    $key = $name . '/' . ($relative === '' ? $root : $relative);
                    self::assertArrayNotHasKey($key, $emitted, self::DUPLICATE_KEY);
                    $emitted[$key] = true;

                    yield $key => $source;
                }
            }

            // E449. The autoload roots answer "what can this process LOAD",
            // and this guard's question is "what can RUN with this process's
            // descriptors open" - which is a strictly larger set. The
            // directories where the two differ, and the mechanism that makes
            // each one run, are rostered in LIB_HORIZON; the ones marked
            // walked are read here. Their keys keep the segment
            // (`candy-pty/bin/pty-shim.php`) because unlike an autoload root
            // the segment is not implied by anything.
            foreach (self::LIB_HORIZON as $segment => $row) {
                if ($row['walked'] !== true || $segment === '') {
                    continue;
                }

                foreach (self::phpUnder($lib . '/' . $segment) as $relative => $source) {
                    $key = $name . '/' . $segment . ($relative === '' ? '' : '/' . $relative);
                    self::assertArrayNotHasKey($key, $emitted, self::DUPLICATE_KEY);
                    $emitted[$key] = true;

                    yield $key => $source;
                }
            }
        }
    }

    /**
     * A failure nothing in this tree produces today, which is why it is spelled
     * once rather than reasoned about at two call sites.
     *
     * A key emitted twice is a file SCANNED twice, and every count in this
     * file - every `count` in both rosters, and the walk-is-live floor - is
     * arithmetic over these keys. It is reachable the moment an autoload root
     * and a LIB_HORIZON segment overlap (a lib autoloading `src` that contains
     * a `bin/`, say), and the failure is silent inflation rather than an
     * error, so it goes red instead.
     */
    private const DUPLICATE_KEY = 'the same library file was yielded twice, so every count '
        . 'derived from this walk is inflated. An autoload root and a LIB_HORIZON segment '
        . 'have started naming the same file; disambiguate the key before trusting any figure.';

    /**
     * Every `.php` file at or under a path, keyed by its path relative to it.
     *
     * A FILE path yields ONE entry keyed by the empty string, leaving the
     * caller to spell the key - which is the point: a `files` autoload entry
     * keys as the entry itself, and its basename would silently rename it. A
     * missing path yields nothing, because a root or segment a given library
     * simply does not have is not a finding -
     * {@see testEveryFileOutsideAnAutoloadRootIsClassified()} is what notices
     * a directory nobody has classified.
     *
     * @return iterable<string, string>
     */
    private static function phpUnder(string $path): iterable
    {
        if (\is_file($path)) {
            if (\pathinfo($path, \PATHINFO_EXTENSION) === 'php') {
                yield '' => (string) \file_get_contents($path);
            }

            return;
        }

        if (!\is_dir($path)) {
            return;
        }

        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        \sort($files);

        foreach ($files as $each) {
            yield \substr($each, \strlen($path) + 1) => (string) \file_get_contents($each);
        }
    }

    /**
     * The directories and files a Composer manifest makes loadable.
     *
     * PURE, AND SEPARATE FROM THE WALK ON PURPOSE: it is the one part of the
     * reachability argument that can be pinned against literals instead of
     * against whatever the closure happens to contain today, so
     * {@see testAutoloadRootsAreDerivedFromTheManifest()} can assert both
     * polarities without coupling this file to a sibling's manifest.
     *
     * `autoload-dev` IS NOT READ, and that omission is load-bearing rather
     * than an oversight: Composer registers a package's `autoload-dev` only
     * when that package is the ROOT of the install, so a sibling library's
     * `tests/` is not loadable from this process. That is the derived reason
     * lib test suites are out of this guard's scope, and it is a better reason
     * than "we walk src".
     *
     * An empty path string means the package root; it is returned as-is rather
     * than dropped, because the caller has to refuse it loudly and a filter
     * here would turn that refusal into a silent skip.
     *
     * @param array<string, mixed> $manifest a decoded composer.json
     * @return list<string> normalised, unique, sorted; may be empty
     */
    private static function autoloadRoots(array $manifest): array
    {
        $section = $manifest['autoload'] ?? null;
        if (!\is_array($section)) {
            return [];
        }

        $roots = [];

        foreach (['psr-4', 'psr-0'] as $kind) {
            /** @var mixed $paths */
            foreach ((array) ($section[$kind] ?? []) as $paths) {
                foreach ((array) $paths as $path) {
                    $roots[] = \rtrim(\trim((string) $path), '/');
                }
            }
        }

        foreach (['classmap', 'files'] as $kind) {
            /** @var mixed $path */
            foreach ((array) ($section[$kind] ?? []) as $path) {
                $roots[] = \rtrim(\trim((string) $path), '/');
            }
        }

        $roots = \array_values(\array_unique($roots));
        \sort($roots);

        return $roots;
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

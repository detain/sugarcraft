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
     * @var array<string, string>
     */
    private const ACCOUNTED_FOR = [
        // E366 HIGH. Third-party stdio MCP server, held in `$this->process`
        // for the life of the client; `disconnect()` is fclose + a bare
        // `proc_close()`. The child is not ours and inherits whatever the host
        // had open at spawn.
        'ClaudeCodeMcpClient.php::connect'
            => 'long-lived third-party MCP server; E366 HIGH, fix deferred with the finding recorded',

        // E366 HIGH. Language server, `$this->process`; `stopProcess()`
        // terminates and immediately closes, and there is no `__destruct()`.
        'LSP/LspConnection.php::connect'
            => 'long-lived language server; E366 HIGH, fix deferred with the finding recorded',

        // Reaping here is already the reference implementation - SIGTERM, poll,
        // SIGKILL - which is why E366 called this one the fixed twin. The
        // REAPING being right is not the fd half being right: the child is
        // still long-lived and still inherits fd 3+.
        'MCP/StdioMcpServer.php::start'
            => 'long-lived stdio server; reaping is correct, descriptor inheritance is not addressed',

        // E366 HIGH. Deliberately double-forks into a session daemon, and the
        // only `proc_close()` is on the handshake-timeout branch - the happy
        // path never reaps. The scanner reports `unclassified` for exactly
        // that reason and is right to.
        'Sessions/BackgroundSupervisor.php::spawnSession'
            => 'double-forked session daemon whose happy path never reaps; E366 HIGH',

        // E366 MEDIUM. The handle goes into a local array literal, that array
        // into `$this->processes[$id]`, and the array is returned as well.
        // The scanner will not follow a handle through an array member, and
        // reports `unclassified` rather than guessing short.
        'Agents/ProcessExecutor.php::spawnWorker'
            => 'agent worker held in $this->processes; the handle escapes through an array member',

        // The handle is returned to a caller that drains it from a periodic
        // timer on the event loop, so the child outlives `spawn()` by design.
        'Backend/CommandBackend.php::spawn'
            => 'handle returned for loop-driven draining; child outlives the call by design',
        'Backend/StreamingCommandBackend.php::begin'
            => 'handle returned for loop-driven draining; child outlives the call by design',
    ];

    /**
     * Appearances of the name that are not calls, and what each one is.
     *
     * The rule-14 half. `function_exists('proc_open')` is a capability probe,
     * not a spawn - but an instrument that silently drops what it cannot
     * classify has a hole shaped exactly like the next defect, so the scanner
     * reports these and this roster accounts for them.
     *
     * @var array<string, string>
     */
    private const NOT_A_SPAWN = [
        'Context/EnvironmentBlock.php::gitField'
            => 'function_exists() capability probe for a build with proc_open disabled',
        'Context/EnvironmentBlock.php::gitDiffSection'
            => 'function_exists() capability probe for a build with proc_open disabled',
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

        $unaccounted = [];
        foreach ($this->sourceFiles() as $relative => $source) {
            foreach ($this->exposedIn($source) as $site) {
                $key = $relative . '::' . $site['function'];
                if (!isset(self::ACCOUNTED_FOR[$key])) {
                    $unaccounted[$key] = $site['lifetime'] . ' - ' . $site['reason'];
                }
            }
        }

        self::assertSame([], $unaccounted, <<<'TEXT'
            A proc_open() child here outlives the call and its descriptor spec says
            nothing about fd 3 and above, so it inherits every descriptor this
            process had open at spawn - E365's shape.

            TWO WAYS TO RESOLVE THIS, AND BOTH ARE FINE:

              1. NAME THE FDS in the spec so the child cannot inherit them, and
                 this row disappears on its own.
              2. ADD A ROW to ACCOUNTED_FOR with the reason it is acceptable.
                 A DATA EDIT IN THIS FILE - not a reason to relax the check, and
                 not a reason to make the scanner quieter.

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
                $seen[$relative . '::' . $site['function']] = true;
            }
        }

        $stale = \array_values(\array_diff(\array_keys(self::ACCOUNTED_FOR), \array_keys($seen)));

        self::assertSame([], $stale, <<<'TEXT'
            ACCOUNTED_FOR names a spawn the scanner no longer reports as exposed.

            IF THE SPAWN WAS FIXED OR REMOVED: delete the row. A data edit here.

            IF THE SPAWN IS STILL THERE: the scanner stopped seeing it, and this
            row is the only thing that noticed. Do not delete it - find out what
            the scanner stopped doing.
            TEXT);
    }

    public function testEveryAppearanceThatIsNotACallIsAccountedFor(): void
    {
        $unaccounted = [];
        foreach ($this->sourceFiles() as $relative => $source) {
            foreach (ChildLifetimeScanner::scan($source)['unresolved'] as $appearance) {
                $key = $relative . '::' . $appearance['function'];
                if (!isset(self::NOT_A_SPAWN[$key])) {
                    $unaccounted[$key] = $appearance['kind'];
                }
            }
        }

        self::assertSame([], $unaccounted, <<<'TEXT'
            The name proc_open appears here as something other than a direct global
            call - a method, a static, a declaration, a string. It is not counted as
            a spawn and it is not dropped silently either, because an alphabet
            written to match only the cases already known has a hole shaped exactly
            like the next defect.

            If it really is not a spawn, add a row to NOT_A_SPAWN saying what it is.
            If it IS a spawn reached indirectly, the scanner cannot see its
            descriptor spec at all and that is the finding.
            TEXT);
    }

    public function testNoNotASpawnRowIsStale(): void
    {
        $seen = [];
        foreach ($this->sourceFiles() as $relative => $source) {
            foreach (ChildLifetimeScanner::scan($source)['unresolved'] as $appearance) {
                $seen[$relative . '::' . $appearance['function']] = true;
            }
        }

        self::assertSame(
            [],
            \array_values(\array_diff(\array_keys(self::NOT_A_SPAWN), \array_keys($seen))),
            'NOT_A_SPAWN names an appearance that is no longer there. Delete the row - a data edit here.',
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
        self::assertNull(
            ChildLifetimeScanner::scan(
                "<?php\nclass F { private \$h; function m(\$p) { \$this->h = proc_open('x', \$this->spec(), \$p); } }\n",
            )['sites'][0]['fds'],
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

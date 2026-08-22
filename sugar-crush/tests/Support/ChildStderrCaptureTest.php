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
 * NOT A SILENCER. {@see ChildStderrCaptureScanner} treats `2>/dev/null` as
 * captured because it cannot tell a sink from a file, but the standard this
 * file is defending is "the test can read it": for most of these shapes the
 * stderr line IS the assertion.
 */
final class ChildStderrCaptureTest extends TestCase
{
    /** Path prefix, relative to `tests/`, the rule is enforced under. */
    private const SCOPE = 'Integration/';

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
    }

    public function testNoChildLaunchedInScopeLeavesItsStderrOnTheSuites(): void
    {
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

            foreach (ChildStderrCaptureScanner::scan((string) file_get_contents($file->getPathname())) as $site) {
                if ($site['shape'] === ChildStderrCaptureScanner::SHAPE_CAPTURED) {
                    $captured++;

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
                . 'is a hole in the guard rather than a pass for the site.',
        );
    }
}

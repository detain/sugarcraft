<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;

/**
 * EVERY REACHABLE SUGARCRAFT LIBRARY THAT NAMES DESCRIPTOR 0.
 *
 * The headline used to read "everything reachable from this process", and the
 * scope below has never been that — see "what this scope excludes, measured
 * rather than assumed" at the foot of this doc-block, which is where the
 * difference is written down instead of being left for a reader to discover.
 *
 * This roster is the missing input to E296. Round 49 tried three times to
 * replace the suite's descriptor 0 with `/dev/null`, which is the only repair
 * that closes E212's PREPEND half — PHP has no `dup2`, so replacing fd 0 means
 * `fclose(\STDIN)` and the `\STDIN` constant becomes a closed resource for the
 * rest of the run. Two of those attempts died on a claim of the form "nothing
 * reaches it", and the reason both claims were wrong is the same reason:
 *
 *   THE CENSUS BEHIND THEM WAS SCOPED TO `sugar-crush/`, AND THE READER THAT
 *   MATTERED WAS IN A SIBLING LIBRARY.
 *
 * `SugarCraft\Mosaic\Detect::stdinFd()` is `self::$probeStdin ?? STDIN` and
 * hands the result to `stream_select()` with no `is_resource()` guard. A
 * census's ALPHABET includes the directory it was pointed at, and that one was
 * a package.
 *
 * ## WHAT THAT COST, AND WHY THE NUMBER THIS FILE FIRST CARRIED IS RETIRED
 *
 * WHAT THIS SAID: `9500 tests, 107 errors, rc 2`, presented as the price of
 * option (a).
 *
 * WHAT IS TRUE NOW: that run predated E302, and it was never 107 costs. It was
 * ONE defect multiplied by every test that reached it — `candy-mosaic` naming
 * `Capability::Iterm2Image`, a case candy-palette spells `ITerm2`, in a
 * fallback that nothing entered until fd 0 was closed. The unguarded read
 * below was the TRIGGER; the enum name was the FAULT, and `1a2caebb` fixed it.
 * Verified by symbol: `candy-palette/src/Probe/Capability.php` declares
 * `case ITerm2`, and that commit is an ancestor of this tree. RE-MEASURED
 * here after it, PHP 8.3.6, full suite, with the descriptor replacement
 * applied to `tests/bootstrap.php`: one error and two failures, all three in
 * tests whose entire subject is that repair, and not one `candy-mosaic` error
 * of any kind.
 *
 * WHY THIS ROSTER STILL EARNS ITS PLACE: the price fell, the question did not.
 * Closing the `\STDIN` constant still hands a closed resource to every site
 * below, and "no test noticed" is not the same as "the library is fine" —
 * `Detect` still leaves its normal path by exception on every call. The
 * roster is what says which sites those are.
 *
 * ## What this file asserts, and why it is a roster rather than a count
 *
 * The exact set of (file, spelling) pairs, over `src` and `bin` of this
 * package plus `src` of every reachable sibling library. A cardinality would
 * be stale on the next commit and would say nothing useful when it changed
 * (rule 18); a roster names what appeared, so the diff IS the finding. It is
 * also its own dead-scanner control: the expectation is a NON-EMPTY exact set,
 * so a scanner that matched nothing fails immediately rather than reporting a
 * clean tree (rule 15/E228). The fixture test beside it pushes known answers
 * through the same scanner in both polarities anyway.
 *
 * ## THE ALPHABET IS FOUR SPELLINGS, AND THE FOURTH WAS FOUND BY THE SCANNER
 * ## BEING WRONG
 *
 * The obvious alphabet is the `STDIN` constant, because that is the spelling
 * every known instance used. It cannot express `fopen('php://fd/0')`,
 * `fopen('php://stdin')` or `fopen('/dev/stdin')`, all of which open the same
 * descriptor without naming the constant. So all four are in.
 *
 * AND THE FIRST VERSION OF THIS SCANNER REPORTED ZERO FOR THE STREAM NAMES
 * WHILE `grep` FOUND ONE, which is the part worth keeping. It compared each
 * string token to the name for EQUALITY. A heredoc or nowdoc body arrives from
 * `token_get_all()` as ONE token containing the whole block, so
 * `candy-core/src/WorkerPool.php` — which builds a worker script whose first
 * line is `fopen('php://fd/0', 'rb')` — read as a string that simply was not
 * `php://fd/0`. A confident, green, false zero. The comparison is containment
 * now, and the fixtures below carry a nowdoc case for exactly this reason: a
 * harness written to check a claim can carry the defect the claim is about,
 * and the only thing that catches it is a fixture whose answer you already
 * know.
 *
 * ## What the roster does NOT do
 *
 * It does not classify. Whether a given reader BREAKS on a closed descriptor
 * is a per-site question. Three of the roster's entries are already answered,
 * and they are named rather than counted — a count of a roster the test itself
 * derives rots silently the next time an entry is added (rule 18):
 *
 *  - `candy-mosaic/src/Detect.php` — UNGUARDED, and it is the site that
 *    TRIGGERED round 49's 107 errors rather than the site that caused them:
 *    the fault was the enum case name in the fallback it dropped into, fixed
 *    by E302 (above). Verified by symbol: `stdinFd()` returns `?? STDIN` and
 *    `drainStdin()` passes it straight to `stream_select()`, so this is still
 *    an unguarded read — it just no longer ends in a fatal.
 *  - `candy-core/src/Util/Tty/PosixBackend.php` — one of its two sites is
 *    already guarded, and says so: `TermiosFactory::open((int) STDIN)` sits in
 *    a `try`/`catch (\Throwable)` whose comment reads "STDIN closed (CI
 *    runner): silently no-op". The other is the constructor's `?? STDIN`.
 *  - `candy-core/src/WorkerPool.php` — NOT a reader of this process's fd 0 at
 *    all. The `php://fd/0` is inside a nowdoc that is written to a temp file
 *    and run as a CHILD, whose descriptor 0 is a `proc_open()` pipe. It is in
 *    the roster because the scanner must report what it finds rather than
 *    silently drop what it cannot judge (rule 14), and this line is the
 *    judgement.
 *
 * Every other entry is unclassified on purpose. Classifying them is the work
 * E296's option (a) actually requires, and doing it here — without running the
 * descriptor replacement behind a full suite — would be the same reasoning-
 * instead-of-measuring that killed the first three attempts.
 *
 * ## Scope, stated rather than left to be inferred
 *
 * `src` and `bin` of this package, and `src` of each reachable SUGARCRAFT
 * sibling under `vendor/sugarcraft`. Not a sibling's `tests`, which never
 * execute in this process. Not this package's `tests`: the files there that
 * read the constant do so deliberately and as the subject of their own
 * assertions, and pinning them here would make this file a merge conflict for
 * every lane that adds one while telling nobody anything they did not already
 * assert. No count, on purpose (rule 18) — one taken over `tests/` in a lane
 * worktree is wrong the hour a sibling lane merges. The generator is in this
 * class, so the wider answer is one edit away: add `tests` to
 * {@see PACKAGE_SCOPE} and read what it reports.
 *
 * ## WHAT THIS SCOPE EXCLUDES, MEASURED RATHER THAN ASSUMED
 *
 * The rest of `vendor/` — the third-party packages — is NOT scanned, and that
 * is a choice rather than an oversight, so here is what is out there. Running
 * {@see fd0References()} over every non-`sugarcraft` `vendor/**.php` outside a
 * `tests/` directory (8,247 files, PHP 8.3.6) reports FOUR:
 * `sebastian/environment/src/Console.php` and
 * `phpunit/phpunit/src/TextUI/Command/Commands/GenerateConfigurationCommand.php`
 * (both `STDIN`), `mtdowling/jmespath.php/bin/jp.php` (`STDIN`), and
 * `symfony/yaml/Command/LintCommand.php` (`php://stdin`).
 *
 * The first is the one that matters, because it is inside the runner's OWN
 * dependency tree: `Console::getNumberOfColumns()` is
 * `$this->isInteractive(defined('STDIN') ? STDIN : self::STDIN)`, and
 * `defined('STDIN')` stays TRUE after `fclose(\STDIN)` (measured, 3/3, PHP
 * 8.3.6 — `defined()` true, `is_resource()` false), so under option (a) it
 * hands a CLOSED resource on. Read to the end, though, it is safe:
 * `isInteractive()` opens with `is_resource($fileDescriptor)`, so a closed
 * handle takes the int branch, reaches `@posix_isatty()`, and degrades to "not
 * interactive" — an 80-column answer, not a break. Observed: the full-suite
 * run with the descriptor replacement applied produced no error from it.
 *
 * It stays out of the asserted set for a reason that is not "it is fine".
 * `vendor/` is gitignored and composer-managed, so a roster over it is a
 * roster over content this repository does not version: it would red on an
 * unrelated upstream bump and teach the next reader to widen it away. The four
 * names above are therefore recorded here, with their generator, as a
 * measurement at a point in time — and if option (a) ever ships, that
 * `sebastian/environment` call is the one to re-read first.
 */
final class StdinConstantReaderCensusTest extends TestCase
{
    /** Directories of THIS package that are scanned. */
    private const PACKAGE_SCOPE = ['src', 'bin'];

    /** Where the reachable siblings live, relative to the package root. */
    private const LIB_SCOPE = 'vendor/sugarcraft';

    /**
     * The stream names that open descriptor 0 without naming the constant.
     *
     * Lower-case, and matched case-insensitively: PHP's stream wrappers are
     * not case sensitive, so `PHP://STDIN` opens the same thing.
     */
    private const FD0_STREAM_NAMES = ['php://stdin', 'php://fd/0', '/dev/stdin'];

    /**
     * Every place reachable from this process that names descriptor 0.
     *
     * A new entry here is not automatically a defect — it is a site somebody
     * has to look at before the descriptor can be replaced. Add it with the
     * spelling the scanner reported, and put the judgement in the class
     * doc-block rather than in this array.
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED = [
        'candy-core/src/Program.php' => ['STDIN'],
        'candy-core/src/Util/RawMode.php' => ['STDIN'],
        'candy-core/src/Util/Tty.php' => ['STDIN'],
        'candy-core/src/Util/Tty/EnvDetect.php' => ['STDIN'],
        'candy-core/src/Util/Tty/PosixBackend.php' => ['STDIN'],
        'candy-core/src/Util/Tty/WindowsBackend.php' => ['STDIN'],
        'candy-core/src/WorkerPool.php' => ['php://fd/0'],
        'candy-mosaic/src/Detect.php' => ['STDIN'],
        'sugar-crush/src/Cli/NonInteractive.php' => ['STDIN'],
    ];

    public function testTheReachableDescriptorZeroReaderRosterIsUnchanged(): void
    {
        $found = [];
        foreach (self::sources() as $path => $source) {
            $refs = self::fd0References($source);
            if ($refs !== []) {
                $found[$path] = $refs;
            }
        }
        ksort($found);

        $expected = self::EXPECTED;
        ksort($expected);

        self::assertSame(
            $expected,
            $found,
            "the set of reachable places that name descriptor 0 has changed.\n\n"
                . "This is not automatically a defect. It is the input to E296: replacing the suite's fd 0 "
                . "means closing the \\STDIN constant, and every entry here is a site that has to be checked "
                . "first. A reader in a SIBLING LIBRARY is what cost round 49 two refuted attempts and a "
                . "full suite run, and no census scoped to sugar-crush/ could see it.\n\n"
                . 'Look at the new site, decide whether a closed descriptor 0 degrades or throws there, record '
                . "that judgement in this class's doc-block, and then add the row.",
        );
    }

    /**
     * KNOWN ANSWERS THROUGH THE SAME SCANNER, BOTH POLARITIES.
     *
     * The nowdoc rows are not decoration: the first version of this scanner
     * compared string tokens for equality and therefore reported ZERO for
     * every heredoc body, including a real one in candy-core. These rows are
     * what makes the roster above evidence rather than output.
     *
     * @param list<string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fixtures')]
    public function testTheScannerAnswersCorrectlyOnSourcesWhoseAnswerIsKnown(
        string $source,
        array $expected,
    ): void {
        self::assertSame($expected, self::fd0References($source));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function fixtures(): iterable
    {
        yield 'bare constant' => ['<?php $x = STDIN;', ['STDIN']];
        yield 'root-qualified constant, the spelling src/ uses' => ['<?php $x = \STDIN;', ['STDIN']];
        yield 'a constant merely STARTING with the name is not it' => ['<?php $x = STDINX;', []];
        yield 'in a line comment - not a reference' => ["<?php // STDIN php://stdin\n\$x = 1;", []];
        yield 'in a doc-block - not a reference, and this file is full of them' => [
            "<?php /** reads php://fd/0 and \\STDIN */\n\$x = 1;",
            [],
        ];
        yield 'php://stdin opened by name' => ["<?php \$f = fopen('php://stdin', 'r');", ['php://stdin']];
        yield 'php://fd/0 opened by name' => ["<?php \$f = fopen('php://fd/0', 'rb');", ['php://fd/0']];
        yield '/dev/stdin opened by name' => ["<?php \$f = fopen('/dev/stdin', 'r');", ['/dev/stdin']];
        yield 'wrappers are case-insensitive, so the scanner is too' => [
            "<?php \$f = fopen('PHP://StdIn', 'r');",
            ['php://stdin'],
        ];
        yield 'php://memory is not descriptor 0' => ["<?php \$f = fopen('php://memory', 'r+');", []];
        // THE ROW THAT CAUGHT THE SCANNER'S OWN DEFECT. A nowdoc body is one
        // token, so an equality comparison reported nothing here - which is
        // exactly the shape of the real site in candy-core/src/WorkerPool.php.
        yield 'NOWDOC body carrying a child script that opens fd 0' => [
            "<?php \$code = <<<'X'\n<?php \$fd = fopen('php://fd/0', 'rb');\nX;\n",
            ['php://fd/0'],
        ];
        yield 'HEREDOC body, interpolating form' => [
            "<?php \$code = <<<X\nthe worker reads php://stdin here\nX;\n",
            ['php://stdin'],
        ];
        yield 'a nowdoc with nothing relevant in it' => ["<?php \$c = <<<'X'\nplain text\nX;\n", []];
        yield 'one file naming two different spellings, de-duplicated and ordered by first sight' => [
            "<?php \$a = STDIN; \$b = fopen('php://fd/0', 'rb'); \$c = \\STDIN;",
            ['STDIN', 'php://fd/0'],
        ];
    }

    /**
     * Every spelling of descriptor 0 named in $source, de-duplicated, in order
     * of first appearance.
     *
     * Token-based because the two polarities a regex gets wrong are both live
     * here: `STDIN` appears in prose throughout these files' doc-blocks (this
     * one included), and the stream names appear inside heredocs that build
     * child scripts.
     *
     * @return list<string>
     */
    private static function fd0References(string $source): array
    {
        $found = [];

        foreach (token_get_all($source) as $token) {
            if (!\is_array($token)) {
                continue;
            }

            if ($token[0] === \T_STRING || $token[0] === \T_NAME_FULLY_QUALIFIED) {
                if (ltrim($token[1], '\\') === 'STDIN') {
                    $found[] = 'STDIN';
                }

                continue;
            }

            if ($token[0] !== \T_CONSTANT_ENCAPSED_STRING && $token[0] !== \T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }

            // CONTAINMENT rather than equality, and the fixtures say why: a
            // heredoc/nowdoc body is a single token holding the whole block,
            // so equality answers "no" to a file that plainly has one.
            $literal = strtolower($token[1]);
            foreach (self::FD0_STREAM_NAMES as $name) {
                if (str_contains($literal, $name)) {
                    $found[] = $name;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The scanned files: this package's own scope, then each sibling's `src`.
     *
     * @return iterable<string, string> `<lib>/<path>` => contents
     */
    private static function sources(): iterable
    {
        $root = \dirname(__DIR__);
        $package = basename($root);

        foreach (self::PACKAGE_SCOPE as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                self::fail('package scope "' . $dir . '" does not exist under ' . $root);
            }
            yield from self::phpFilesIn($base, $package . '/' . $dir . '/', $dir === 'bin');
        }

        $libRoot = $root . '/' . self::LIB_SCOPE;
        if (!is_dir($libRoot)) {
            // Loud rather than skipped: this suite cannot have autoloaded
            // without it, so its absence means the walk moved, not that the
            // libraries went away.
            self::fail(self::LIB_SCOPE . ' does not exist under ' . $root);
        }

        foreach (glob($libRoot . '/*', \GLOB_ONLYDIR) ?: [] as $lib) {
            if (!is_dir($lib . '/src')) {
                continue;
            }
            yield from self::phpFilesIn($lib . '/src', basename($lib) . '/src/', false);
        }
    }

    /**
     * @return iterable<string, string>
     */
    private static function phpFilesIn(string $base, string $prefix, bool $extensionless): iterable
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            // `bin/sugarcrush` has no extension and is still PHP.
            if ($file->getExtension() !== 'php' && !$extensionless) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, '<?php')) {
                continue;
            }

            yield $prefix . substr($file->getPathname(), \strlen($base) + 1) => $source;
        }
    }
}

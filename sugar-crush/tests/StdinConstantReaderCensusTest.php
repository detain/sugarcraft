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
 * THIS ROSTER IS NO LONGER AN INPUT TO A DECISION — IT IS THE STANDING
 * CONTRACT OF ONE THAT WAS TAKEN. WHAT THIS SAID: "this roster is the missing
 * input to E296", framed around whether the suite's descriptor 0 COULD be
 * replaced. WHAT IS TRUE NOW: it is. `tests/bootstrap.php` does
 * `fclose(\STDIN)` plus a `/dev/null` handle on the freed fd, so the `\STDIN`
 * constant IS a closed resource for the rest of every non-tty run, and every
 * site below is handed one on each such run. WHY THE ROSTER STILL EARNS ITS
 * PLACE — more than before, not less: it was a list of things to check once,
 * and it is now a list of things that are live. A new entry is a new site
 * receiving a dead handle today.
 *
 * Round 49 tried three times to make that replacement and PHP has no `dup2`,
 * so it always meant closing the constant. Two of those attempts died on a
 * claim of the form "nothing reaches it", and the reason both claims were wrong
 * is the same reason:
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
 * AND THE ONE SITE THAT WAS STILL WRONG AFTER THE PRICE FELL IS FIXED TOO.
 * WHAT THIS SAID: "`Detect` still leaves its normal path by exception on every
 * call", recorded because "no test noticed" is not the same as "the library is
 * fine". WHAT IS TRUE NOW: `Detect::stdinFd()` answers null for a dead handle
 * instead of passing it to `stream_select()`, `drainStdin()` and
 * `readStdinTimed()` treat null as their existing no-answer case, and a closed
 * INJECTED probe stream no longer short-circuits `isInteractiveTty()` to true.
 * Verified by symbol, and driven directly: `Detect::probe()` in a process that
 * had done `fclose(\STDIN)` threw `ValueError: No stream arrays were passed`
 * before and completes after (PHP 8.3.6). WHY THE PARAGRAPH STAYS: the
 * distinction it draws is the one that made anyone look — a library that
 * degrades by exception passes every test that only checks the caller
 * survived.
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
 * AND THE CONSTANT ITSELF IS MATCHED INSIDE STRING BODIES TOO, which is not
 * where a constant normally lives and is the point. Containment was applied
 * only to the three STREAM NAMES at first, so a nowdoc holding a child script
 * that says `feof(STDIN)` scanned CLEAN — the same blind spot the stream-name
 * fix had just closed, left open one spelling over. Widening it found a real
 * site the narrower alphabet could not see:
 * `sugar-crush/src/Agents/ProcessExecutor.php`, whose
 * `createInlineWorkerScript()` returns a nowdoc that reads `STDIN` four times.
 * Rule 11 in one line — a census's alphabet is usually written to match the
 * cases already known.
 *
 * The cost is that a PROSE string naming the constant — an exception message
 * that says "cannot read STDIN" — is reported too, and there is no way to tell
 * it from a worker script without running the code. That is the same trade the
 * `WorkerPool` row below makes: the scanner reports, the doc-block judges, and
 * somebody looks once. What is NOT reported is `Foo::STDIN` or `$o->STDIN` —
 * a `T_STRING` reached through `::` or `->` is a member, not the constant, and
 * the fixtures pin both.
 *
 * AND THE FIRST VERSION OF THIS SCANNER REPORTED ZERO FOR THE STREAM NAMES
 * WHILE `grep` FOUND ONE, which is the part worth keeping.
 *
 * WHAT THAT WAS FIRST WRITTEN DOWN AS: "it compared each string token to the
 * name for EQUALITY, and a heredoc body arrives as ONE token, so the heredoc
 * in `candy-core/src/WorkerPool.php` read as a string that was not
 * `php://fd/0`." That account is narrower than the defect, and it leaves a
 * reader believing a plain `fopen('php://stdin')` was covered.
 *
 * WHAT IS TRUE: equality on the raw token never matched ANY string spelling,
 * because `token_get_all()` hands back the token WITH ITS QUOTES. Measured,
 * PHP 8.3.6, on `<?php $f = fopen('php://stdin', 'r');` — the
 * `T_CONSTANT_ENCAPSED_STRING` is the 13-byte `'php://stdin'`, so
 * `=== 'php://stdin'` is false and `str_contains(...)` is true. Reverting
 * containment to equality today reds 8 of the 15 rows below, not just the
 * heredoc one.
 *
 * WHY IT STILL EARNS ITS PLACE: the heredoc is still the shape that MADE the
 * zero look plausible — a one-token block is where a reader stops suspecting
 * the instrument. The comparison is containment now, and the fixtures below
 * carry nowdoc cases for exactly this reason: a harness written to check a
 * claim can carry the defect the claim is about, and the only thing that
 * catches it is a fixture whose answer you already know.
 *
 * ## What the roster does NOT do
 *
 * It does not classify. Whether a given reader BREAKS on a closed descriptor
 * is a per-site question. Three of the roster's entries are already answered,
 * and they are named rather than counted — a count of a roster the test itself
 * derives rots silently the next time an entry is added (rule 18):
 *
 *  - `candy-mosaic/src/Detect.php` — GUARDED NOW, and it is the site that
 *    TRIGGERED round 49's 107 errors rather than the site that caused them:
 *    the fault was the enum case name in the fallback it dropped into (E302).
 *    WHAT THIS ROW SAID: "UNGUARDED … `stdinFd()` returns `?? STDIN` and
 *    `drainStdin()` passes it straight to `stream_select()`". WHAT IS TRUE NOW:
 *    `stdinFd()` is `$fd = self::$probeStdin ?? STDIN; return is_resource($fd)
 *    ? $fd : null;` and the two consumers treat null as their no-answer case.
 *    WHY THE ROW STAYS: it still NAMES the constant, which is what this roster
 *    tracks, and it is the row a future reader will want the history of.
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
 *  - `sugar-crush/src/Agents/ProcessExecutor.php` — the same judgement, same
 *    reason, different spelling. `createInlineWorkerScript()` returns a nowdoc
 *    that `feof(STDIN)`/`fgets(STDIN)` its way through a startup handshake,
 *    and `spawnWorker()` runs it via `proc_open([$binary, '-r', $script], [0
 *    => ['pipe', 'r'], …])` — verified by symbol — so that `STDIN` is the
 *    CHILD's pipe. Not this process's descriptor 0, and not affected by
 *    closing it.
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
 * measurement at a point in time.
 *
 * OPTION (a) HAS NOW SHIPPED, so the follow-up that sentence asked for is done:
 * OBSERVED, PHP 8.3.6, the full suite with the descriptor replacement in
 * `tests/bootstrap.php` — 9661 tests, and no error from `sebastian/environment`
 * or from any of the other three.
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
        'sugar-crush/src/Agents/ProcessExecutor.php' => ['STDIN'],
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
        // THE ROWS THAT CAUGHT THE ALPHABET'S SECOND HALF. Containment was
        // applied to the three stream names and not to the constant, so a
        // nowdoc worker script reading STDIN scanned clean - which is the real
        // shape in src/Agents/ProcessExecutor.php.
        yield 'NOWDOC body carrying a child script that reads the STDIN CONSTANT' => [
            "<?php \$code = <<<'X'\nwhile (!feof(STDIN)) { \$l = fgets(STDIN); }\nX;\n",
            ['STDIN'],
        ];
        yield 'HEREDOC body naming the root-qualified constant' => [
            "<?php \$code = <<<X\nthe worker reads \\STDIN here\nX;\n",
            ['STDIN'],
        ];
        yield 'a PROSE string naming the constant is reported too, deliberately' => [
            "<?php throw new \\RuntimeException('cannot read STDIN');",
            ['STDIN'],
        ];
        yield 'a word merely CONTAINING the name inside a string is not it' => [
            "<?php \$m = 'MY_STDIN_BUFFER and STDINX';",
            [],
        ];
        // The two spellings that are an identifier reached through an operator,
        // not the global constant. sebastian/environment has both in one file.
        yield 'a class constant named STDIN is not the global one' => [
            '<?php $x = Console::STDIN;',
            [],
        ];
        yield 'a property named STDIN is not the global one' => [
            '<?php $x = $console->STDIN;',
            [],
        ];
        yield 'a nullsafe property named STDIN is not the global one' => [
            '<?php $x = $console?->STDIN;',
            [],
        ];
        yield 'an import of the constant IS a reference' => [
            "<?php\nuse const STDIN;\n",
            ['STDIN'],
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
        $tokens = token_get_all($source);

        foreach ($tokens as $index => $token) {
            if (!\is_array($token)) {
                continue;
            }

            if ($token[0] === \T_STRING || $token[0] === \T_NAME_FULLY_QUALIFIED) {
                if (ltrim($token[1], '\\') === 'STDIN' && !self::isMemberAccess($tokens, $index)) {
                    $found[] = 'STDIN';
                }

                continue;
            }

            if ($token[0] !== \T_CONSTANT_ENCAPSED_STRING && $token[0] !== \T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }

            // CONTAINMENT rather than equality, and the doc-block says why: the
            // token arrives WITH ITS QUOTES, so equality never matched any
            // string spelling at all, and a heredoc/nowdoc body arrives as one
            // token holding the whole block.
            $literal = strtolower($token[1]);
            foreach (self::FD0_STREAM_NAMES as $name) {
                if (str_contains($literal, $name)) {
                    $found[] = $name;
                }
            }

            // AND THE CONSTANT INSIDE A STRING BODY, because a nowdoc holding a
            // child script names it exactly the way a normal file does. Word
            // boundaries so `STDINX` and `MY_STDIN` are not it; case-sensitive,
            // because a constant is.
            if (preg_match('/\bSTDIN\b/', $token[1]) === 1) {
                $found[] = 'STDIN';
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Whether the identifier at $index is reached through `::` or `->`.
     *
     * `Console::STDIN` and `$o->STDIN` are a class constant and a property, not
     * the global constant, and both spell the identifier `T_STRING` exactly the
     * way the real thing does. `sebastian/environment` has both spellings in
     * one file, which is how this came up.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function isMemberAccess(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $t = $tokens[$i];
            if (\is_array($t) && \in_array($t[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return \is_array($t) && \in_array(
                $t[0],
                [\T_DOUBLE_COLON, \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR],
                true,
            );
        }

        return false;
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

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Nothing in this tree builds a raw-mode backend over the process's OWN
 * descriptor 0 with an injected `Termios`.
 *
 * WHY THIS IS A GUARD AND NOT A STYLE RULE. `tests/bootstrap.php` repairs the
 * suite's descriptor 0 by clearing `O_NONBLOCK` on it, so a spawned
 * `bin/sugarcrush` cannot park in `stream_get_contents()` on the runner's
 * stdin (E212's other half). `O_NONBLOCK` lives on the open file DESCRIPTION,
 * which is what makes it reach inherited children — and also what makes it
 * erasable by anything else holding that description. The shape that holds it:
 *
 *   new Tty(null, $injectedTermios)
 *
 * `SugarCraft\Core\Util\Tty::__construct()` is `self::backend($stream ??
 * STDIN, $termios)`, so a null (or omitted) first argument wraps THIS
 * process's descriptor 0. Supplying a `Termios` then makes
 * `PosixBackend::enableRawMode()` take the branch that SKIPS its own
 * `isTty()` guard, so it runs to the end — and the end is
 * `@stream_set_blocking($this->stream, false)`, with `restore()`'s matching
 * `@stream_set_blocking($this->stream, true)` putting the runner's fd 0 back
 * to BLOCKING for the remainder of the run.
 *
 * ## THE ALPHABET IS THE SHAPE, NOT ONE CLASS NAME (round 50)
 *
 * WHAT THIS GUARD SAID: "Exactly one shape in this codebase holds it", and
 * the scanner accepted the short name `Tty` alone. WHAT IS TRUE: `Tty` is a
 * FAÇADE over the backend, and the backend has the same constructor. Reading
 * candy-core rather than inferring from the façade,
 * `SugarCraft\Core\Util\Tty\PosixBackend::__construct()` is
 * `$this->stream = $stream ?? STDIN;` — the identical resolution — and it is
 * the class that actually owns both `stream_set_blocking()` calls. Anyone who
 * wants the backend without the façade's platform dispatch constructs it
 * directly, and this census would have reported the tree clean.
 *
 * MEASURED, PHP 8.3.6, three takes each, in a process whose fd 0 is an open
 * never-written pipe with `O_NONBLOCK` already set (the shape
 * `tests/bootstrap.php` leaves), driving the BACKEND directly with no `Tty`
 * anywhere:
 *
 *   new PosixBackend(null, new PosixTermios($slaveFd))
 *       fd 0 clear -> clear after enableRawMode() -> BLOCKED after restore()   3/3
 *   new PosixBackend($socketPairEnd, new PosixTermios($slaveFd))
 *       fd 0 clear -> clear -> clear                                           3/3
 *
 * — the same erasure, one layer down, so the name is in the alphabet.
 *
 * AND `WindowsBackend` IS DELIBERATELY NOT, which is checked rather than
 * assumed because its constructor looks identical (`$this->stream = $stream ??
 * STDIN;`, an injected `Kernel32Interface` in the second slot). It contains no
 * `stream_set_blocking` call at all — verified by symbol over
 * `candy-core/src/Util/Tty/WindowsBackend.php`, whose `enableRawMode()` and
 * `restore()` go through the console-mode API instead — so it cannot erase the
 * flag this guard exists to protect. It is named here so the next reader does
 * not "complete" the roster by symmetry. (Linux box, PHP 8.3.6: that backend
 * is never selected here, so this is a source claim and not a measurement, and
 * it is the only claim in this doc-block that is not.)
 *
 * The measured instance count is deliberately absent (rule 18): a cardinality
 * over `tests/` is stale the next time one is added, and the assertions below
 * derive their own.
 *
 * MEASURED, PHP 8.3.6, three takes each, in a child whose fd 0 is a pipe:
 *
 *   new Tty(null, new PosixTermios($fd))   fd 0 blocked true -> false, and
 *                                          true again after restore()   3/3
 *   new Tty($socketPairEnd, …)             fd 0's flag never moves       3/3
 *   new Tty()  (no Termios at all)         fd 0's flag never moves       3/3
 *
 * — the third row is why this census requires BOTH conditions rather than
 * flagging every null stream: without an injected `Termios`,
 * `enableRawMode()` returns at `!isTty()` and never reaches the flag.
 *
 * WHY A TOKEN CENSUS AND NOT A GREP, stated because the grep is what failed.
 * The three sites this originally found came from
 * `grep -rn 'new Tty(' src/ tests/ bin/`. A fourth existed —
 * `tests/ChatTest.php`, written `new \SugarCraft\Core\Util\Tty(null, …)` —
 * and that alphabet cannot express a fully-qualified constructor call. The
 * suite went red on a full run with the other three already fixed. A prose
 * generator in a doc-block would have kept the same hole; a tokenizer does
 * not care how a name is spelled.
 *
 * AND IT REPORTS WHAT IT CANNOT READ RATHER THAN DROPPING IT (rule 14): an
 * argument list the walk cannot follow to its closing paren is recorded as
 * `unparsed` and FAILS, because "I could not read it" and "it was fine" must
 * not be spelled the same way.
 *
 * THE ONE RESIDUAL, said plainly rather than buried in a bucket name that
 * sounds conclusive. A first argument that is an EXPRESSION — `$stream`,
 * `$pair[0]`, `f()` — is not provably non-null by any scanner that does not
 * run the code, so `$s = null; new Tty($s, $t);` would pass this census. What
 * it does close is the shape every instance in this tree actually had, which
 * is the literal `null`. A first argument that is a bare CONSTANT is the
 * middle case: it could be defined as null, so the SET of constant names is
 * asserted rather than their shape, and a new one has to be looked at once.
 */
final class TtyStreamArgumentCensusTest extends TestCase
{
    /** Directories scanned, relative to the package root. */
    private const SCOPE = ['src', 'tests', 'bin'];

    /**
     * The class names whose constructor resolves a null stream to the
     * process's own `STDIN` and then writes `stream_set_blocking()` to it.
     *
     * Both halves of that sentence are load-bearing, and the class doc-block
     * carries the measurement for each name. `Tty` is the façade;
     * `PosixBackend` is what the façade builds and what actually owns the two
     * flag writes, so a caller who skips the façade skips a one-name census
     * too. `WindowsBackend` has the same `?? STDIN` and no flag write, so it
     * is excluded on mechanism rather than on platform.
     */
    private const HAZARD_CLASSES = ['Tty', 'PosixBackend'];

    /**
     * Reachable sibling libraries, scanned for the same shape.
     *
     * E296'S LESSON AT LIBRARY SCOPE, and it is the reason this arm exists.
     * Round 49 spent three attempts on the descriptor-0 repair; two were
     * refuted by a claim of the form "nothing reaches X" whose census scope
     * was `sugar-crush/` — and the reader that mattered was in `candy-mosaic`.
     * A census's ALPHABET includes the directory it is pointed at. `src` only:
     * a sibling's own `tests/` never runs in this process, so it cannot touch
     * this runner's descriptor 0.
     */
    private const LIB_SCOPE = 'vendor/sugarcraft';

    /**
     * Nothing in the tree builds the hazardous shape.
     *
     * The known-positive lives in its own test below rather than here, but it
     * runs the SAME scanner over fixtures whose answers are known — because an
     * assertion that a list is empty is also what a scanner that matches
     * nothing returns (rule 15/E228).
     */
    public function testNoTtyIsBuiltWithAnInjectedTermiosAndTheProcessesOwnStdin(): void
    {
        $offenders = [];
        $constants = [];
        $seen = 0;

        foreach (self::sources() as $path => $source) {
            foreach (self::rawModeConstructions($source) as $site) {
                ++$seen;
                if ($site['firstArg'] === 'null' && $site['extraArgs']) {
                    $offenders[] = $path . ': new ' . $site['name'] . '(' . $site['firstArg'] . ', …)';
                }
                if ($site['firstArg'] === 'unparsed') {
                    $offenders[] = $path . ': new ' . $site['name'] . '(<argument list this scanner could '
                        . 'not read to its close>)';
                }
                if (str_starts_with($site['firstArg'], 'constant:')) {
                    $constants[] = $path . ': ' . $site['firstArg'];
                }
            }
        }

        // The census must have found the constructions that DO exist, or the
        // emptiness above is the emptiness of a dead scanner.
        self::assertGreaterThan(
            0,
            $seen,
            'the census found no Tty construction anywhere in ' . implode(', ', self::SCOPE)
                . ' - the scanner is dead, not the tree clean',
        );

        self::assertSame(
            [],
            $offenders,
            "a Tty built with an injected Termios and no stream writes stream_set_blocking() on the RUNNER's "
                . "descriptor 0, which erases tests/bootstrap.php's fd-0 repair for the rest of the run. Pass "
                . "an explicit stream (a stream_socket_pair() end is what the existing sites use, because PHP "
                . "reports a php://memory stream as blocked whatever you set on it):\n  "
                . implode("\n  ", $offenders),
        );

        // A CONSTANT first argument is the one shape this scanner cannot judge
        // on its own - `FOO` could be defined as null. So the SET of them is
        // asserted rather than their shape, which means a new one has to be
        // looked at by a person exactly once.
        self::assertSame(
            ['src/Tui/Renderer.php: constant:STDOUT'],
            $constants,
            'a Tty is built with a CONSTANT as its stream, and a constant can be null. Check that this one '
                . 'is a real stream, then add it here',
        );
    }

    /**
     * AND NO REACHABLE SIBLING LIBRARY BUILDS IT EITHER (E296's lesson).
     *
     * WHY A SECOND ARM RATHER THAN ONE WIDER SCOPE. The two arms assert
     * different things and can be broken by different people. The package arm
     * above is about code this repository writes, and it pins the CONSTANT
     * roster as well because a new constant there is a decision somebody here
     * made. This arm is about code that arrives through `composer`, where the
     * only useful question is whether the hazardous shape appeared — a roster
     * of every sibling's stream constants would red on an unrelated upstream
     * refactor and teach the next reader to widen it away.
     *
     * IT IS HERE BECAUSE THE ROUND-49 REPAIR WAS REFUTED TWICE BY A CENSUS
     * WHOSE SCOPE WAS A DIRECTORY. Both refutations had the form "nothing
     * reaches X", verified over `sugar-crush/`, and both times the thing that
     * reached X was in a sibling library — `SugarCraft\Mosaic\Detect` reading
     * the `\STDIN` constant unguarded, which cost a full suite run and 107
     * errors. The shape this file scans for is a candy-core shape, so the
     * library that is most likely to grow the next instance of it is candy-core
     * and not this package.
     *
     * MEASURED at the commit that added this arm, PHP 8.3.6: the scan sees
     * constructions (so it is not dead) and none is an offender. The count is
     * not written down — it is upstream's to change, and `$seen` is derived.
     *
     * `src` only, and `bin` deliberately not: a sibling's `bin` script is a
     * separate process with its own descriptor 0, so it cannot erase this
     * runner's flag. A sibling's `tests` never execute here at all.
     */
    public function testNoReachableSiblingLibraryBuildsTheHazardousShapeEither(): void
    {
        $offenders = [];
        $constants = [];
        $seen = 0;

        foreach (self::libSources() as $path => $source) {
            foreach (self::rawModeConstructions($source) as $site) {
                ++$seen;
                if ($site['firstArg'] === 'null' && $site['extraArgs']) {
                    $offenders[] = $path . ': new ' . $site['name'] . '(' . $site['firstArg'] . ', …)';
                }
                if ($site['firstArg'] === 'unparsed') {
                    $offenders[] = $path . ': new ' . $site['name'] . '(<argument list this scanner could '
                        . 'not read to its close>)';
                }
                if (str_starts_with($site['firstArg'], 'constant:')) {
                    $constants[] = $path . ': ' . $site['firstArg'];
                }
            }
        }

        // The dead-scanner control, and it is not decorative here: this arm
        // walks a directory that a `composer install` can leave in a shape
        // nobody expected. An empty offender list from an empty walk is the
        // silence of a dead instrument, not evidence (rule 15).
        self::assertGreaterThan(
            0,
            $seen,
            'the census found no Tty/PosixBackend construction anywhere under ' . self::LIB_SCOPE
                . ' - the walk is dead, not the libraries clean. candy-core builds both.',
        );

        self::assertSame(
            [],
            $offenders,
            "a reachable sibling library builds a raw-mode backend over the process's own descriptor 0 with "
                . "an injected Termios. In THIS process that descriptor belongs to the PHPUnit runner, and "
                . "restore() puts O_NONBLOCK back on it - which is tests/bootstrap.php's fd-0 repair, erased "
                . "for every later test in the run:\n  " . implode("\n  ", $offenders),
        );

        self::assertSame(
            [],
            $constants,
            'a sibling library now builds one of these with a CONSTANT as its stream, and a constant can be '
                . "null. Check what that constant holds, then decide whether this arm should name it:\n  "
                . implode("\n  ", $constants),
        );
    }

    /**
     * KNOWN-ANSWER FIXTURES THROUGH THE SAME SCANNER.
     *
     * Every polarity the census depends on, including the two spellings that
     * broke a grep: fully qualified, and imported-short. The `null` cases must
     * be REPORTED and the explicit-stream cases must NOT be, or the assertion
     * above is satisfied by an instrument that answers the same thing to
     * everything.
     *
     * @param list<array{name: string, firstArg: string, extraArgs: bool}> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fixtures')]
    public function testTheScannerAnswersCorrectlyOnFixturesWhoseAnswerIsKnown(
        string $source,
        array $expected,
    ): void {
        self::assertSame($expected, self::rawModeConstructions('<?php ' . $source));
    }

    /** @return iterable<string, array{string, list<array{name: string, firstArg: string, extraArgs: bool}>}> */
    public static function fixtures(): iterable
    {
        yield 'imported short name, null stream, injected termios' => [
            '$t = new Tty(null, new PosixTermios($fd));',
            [['name' => 'Tty', 'firstArg' => 'null', 'extraArgs' => true]],
        ];
        yield 'FULLY QUALIFIED, null stream - the spelling a grep for "new Tty(" cannot see' => [
            '$t = new \SugarCraft\Core\Util\Tty(null, new \SugarCraft\Pty\Posix\PosixTermios($fd));',
            [['name' => '\SugarCraft\Core\Util\Tty', 'firstArg' => 'null', 'extraArgs' => true]],
        ];
        yield 'partially qualified, null stream' => [
            '$t = new Util\Tty(null, $termios);',
            [['name' => 'Util\Tty', 'firstArg' => 'null', 'extraArgs' => true]],
        ];
        yield 'case-insensitive NULL, and whitespace between new and the name' => [
            "\$t = new   Tty(NULL , \$termios);",
            [['name' => 'Tty', 'firstArg' => 'null', 'extraArgs' => true]],
        ];
        yield 'array-access stream expression - NOT an offender' => [
            '$t = new Tty($flagSink[0], new PosixTermios($fd));',
            [['name' => 'Tty', 'firstArg' => 'expression', 'extraArgs' => true]],
        ];
        yield 'plain variable stream - NOT an offender' => [
            '$t = new Tty($stream, $termios);',
            [['name' => 'Tty', 'firstArg' => 'expression', 'extraArgs' => true]],
        ];
        yield 'explicit STDOUT constant - named, not waved through' => [
            '$size = (new Tty(STDOUT))->size();',
            [['name' => 'Tty', 'firstArg' => 'constant:STDOUT', 'extraArgs' => false]],
        ];
        // The FIRST ARGUMENT's own boundary is what this scanner needs to find:
        // a comma at argument depth, or the closing paren. `new Tty($stream,`
        // is readable (the comma is the boundary); `new Tty($stream` is not,
        // and must be reported rather than guessed at.
        yield 'a first argument whose boundary is never reached is REPORTED, not dropped' => [
            '$t = new Tty($stream',
            [['name' => 'Tty', 'firstArg' => 'unparsed', 'extraArgs' => false]],
        ];
        yield 'a truncated list whose FIRST argument did close is read, not reported' => [
            '$t = new Tty($stream, $termios',
            [['name' => 'Tty', 'firstArg' => 'expression', 'extraArgs' => true]],
        ];
        yield 'no arguments at all - null stream, but no injected Termios either' => [
            '$t = new Tty();',
            [['name' => 'Tty', 'firstArg' => 'null', 'extraArgs' => false]],
        ];
        // THE BACKEND, WHICH THE ONE-NAME ALPHABET COULD NOT EXPRESS. Same
        // `$stream ?? STDIN` constructor, and it is the class that owns both
        // `stream_set_blocking()` calls - measured in the class doc-block.
        yield 'PosixBackend built directly, null stream, injected termios' => [
            '$b = new PosixBackend(null, new PosixTermios($fd));',
            [['name' => 'PosixBackend', 'firstArg' => 'null', 'extraArgs' => true]],
        ];
        yield 'PosixBackend FULLY QUALIFIED, null stream' => [
            '$b = new \SugarCraft\Core\Util\Tty\PosixBackend(null, $termios);',
            [['name' => '\SugarCraft\Core\Util\Tty\PosixBackend', 'firstArg' => 'null', 'extraArgs' => true]],
        ];
        yield 'PosixBackend via the Tty sub-namespace, as candy-core spells it' => [
            '$b = new Tty\PosixBackend($stream, $termios);',
            [['name' => 'Tty\PosixBackend', 'firstArg' => 'expression', 'extraArgs' => true]],
        ];
        // NEGATIVE, and deliberately so: WindowsBackend has the same
        // `?? STDIN` and NO `stream_set_blocking` anywhere, so it is out of the
        // alphabet on mechanism. If someone adds it, this fixture reds and they
        // have to read the reason first.
        yield 'WindowsBackend is NOT in the alphabet - it writes no stream flag' => [
            '$b = new WindowsBackend(null, $kernel32);',
            [],
        ];
        yield 'a class merely NAMED like Tty is not one' => [
            '$t = new TtyDetect(null, $x);',
            [],
        ];
        yield 'a class merely NAMED like PosixBackend is not one' => [
            '$b = new PosixBackendFactory(null, $t);',
            [],
        ];
        yield 'a Tty in a comment is not a construction' => [
            '// $t = new Tty(null, $termios);' . "\n" . '$x = 1;',
            [],
        ];
        yield 'two on one line, both counted' => [
            '$a = new Tty(null, $t); $b = new Tty($s, $t);',
            [
                ['name' => 'Tty', 'firstArg' => 'null', 'extraArgs' => true],
                ['name' => 'Tty', 'firstArg' => 'expression', 'extraArgs' => true],
            ],
        ];
    }

    /**
     * The scoped source files, so both tests above read the same tree.
     *
     * @return iterable<string, string> package-relative path => contents
     */
    private static function sources(): iterable
    {
        $root = \dirname(__DIR__);

        foreach (self::SCOPE as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                // Loud rather than skipped: a SCOPE entry that stopped existing
                // silently shrinks this census to whatever is left.
                self::fail('census scope "' . $dir . '" does not exist under ' . $root);
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }
                // `bin/sugarcrush` has no extension and is still PHP.
                if ($file->getExtension() !== 'php' && $dir !== 'bin') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (!str_contains($source, '<?php')) {
                    continue;
                }
                yield substr($file->getPathname(), \strlen($root) + 1) => $source;
            }
        }
    }

    /**
     * The `src` of every reachable sibling library, for the arm above.
     *
     * `vendor/sugarcraft` is the REACHABILITY definition, not the monorepo
     * directory beside this package: a lib nothing requires cannot run in this
     * process whatever it contains, and a lib that IS required is here whether
     * it arrived as a path-repo symlink (the monorepo and CI's injection) or
     * as a Packagist copy (a split-repo clone). Both shapes yield the same
     * files, which is why this arm does not care which one it got.
     *
     * @return iterable<string, string> `<lib>/<relative path>` => contents
     */
    private static function libSources(): iterable
    {
        $base = \dirname(__DIR__) . '/' . self::LIB_SCOPE;
        if (!is_dir($base)) {
            // Loud rather than skipped: this suite cannot have loaded without
            // it, so its absence means the walk is being pointed somewhere new.
            self::fail(self::LIB_SCOPE . ' does not exist under ' . \dirname(__DIR__));
        }

        $libs = glob($base . '/*', \GLOB_ONLYDIR) ?: [];
        foreach ($libs as $lib) {
            $dir = $lib . '/src';
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (!str_contains($source, '<?php')) {
                    continue;
                }
                yield basename($lib) . '/' . substr($file->getPathname(), \strlen($lib) + 1) => $source;
            }
        }
    }

    /**
     * Every `new <…><hazard class>(...)` in $source, first argument classified.
     *
     * The class alphabet is {@see HAZARD_CLASSES} rather than the single name
     * `Tty`, because the façade and the backend it builds have the SAME
     * `$stream ?? STDIN` constructor and the backend is the one that writes
     * the flag. Matching on the SHORT name after the last `\` is what makes
     * every spelling — imported, partially and fully qualified — one case.
     *
     * Token-based on purpose: the class name may be short, partially or fully
     * qualified, and `T_NEW` cannot appear inside a comment or a string, which
     * is what a regex over the same text gets wrong in both directions.
     *
     * @return list<array{name: string, firstArg: string, extraArgs: bool}>
     */
    private static function rawModeConstructions(string $source): array
    {
        $tokens = token_get_all($source);
        $count = \count($tokens);
        $skip = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];
        $nameParts = [\T_STRING, \T_NS_SEPARATOR, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED];
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== \T_NEW) {
                continue;
            }

            $j = $i + 1;
            $name = '';
            while ($j < $count) {
                $t = $tokens[$j];
                if (\is_array($t) && \in_array($t[0], $skip, true)) {
                    $j++;
                    continue;
                }
                if (\is_array($t) && \in_array($t[0], $nameParts, true)) {
                    $name .= $t[1];
                    $j++;
                    continue;
                }
                break;
            }

            $short = str_contains($name, '\\') ? substr($name, strrpos($name, '\\') + 1) : $name;
            if (!\in_array($short, self::HAZARD_CLASSES, true) || $j >= $count || $tokens[$j] !== '(') {
                continue;
            }

            $found[] = ['name' => $name] + self::classifyArguments($tokens, $j, $count, $skip);
        }

        return $found;
    }

    /**
     * Classify the argument list that starts at the `(` at $open.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param list<int>                                $skip
     * @return array{firstArg: string, extraArgs: bool}
     */
    private static function classifyArguments(array $tokens, int $open, int $count, array $skip): array
    {
        $depth = 0;
        $first = [];
        $sawComma = false;
        // Whether the walk reached a real end of the first argument - a comma
        // at argument depth, or the closing paren - rather than running off
        // the end of the token stream.
        $closed = false;

        for ($k = $open; $k < $count; $k++) {
            $t = $tokens[$k];

            if ($t === '(' || $t === '[') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($t === ')' || $t === ']') {
                $depth--;
                if ($depth === 0) {
                    $closed = true;
                    break;
                }
            } elseif ($t === ',' && $depth === 1) {
                $sawComma = true;
                $closed = true;
                break;
            }

            if (\is_array($t) && \in_array($t[0], $skip, true)) {
                continue;
            }
            $first[] = \is_array($t) ? $t[1] : $t;
        }

        // THE ARGUMENT LIST NEVER CLOSED, so the walk fell off the end of the
        // token stream. Reported rather than dropped (rule 14): "I could not
        // read it" must not be spelled the same way as "it was fine".
        if (!$closed) {
            return ['firstArg' => 'unparsed', 'extraArgs' => $sawComma];
        }

        // An omitted first argument IS the null default - that is the whole
        // reason `$stream ?? STDIN` exists.
        if ($first === []) {
            return ['firstArg' => 'null', 'extraArgs' => $sawComma];
        }
        if (\count($first) === 1 && strcasecmp($first[0], 'null') === 0) {
            return ['firstArg' => 'null', 'extraArgs' => $sawComma];
        }
        // A bare identifier is a constant, and a constant COULD hold null - so
        // it is named rather than waved through, and the test below asserts
        // which names are present instead of trusting the shape.
        if (\count($first) === 1 && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $first[0]) === 1) {
            return ['firstArg' => 'constant:' . $first[0], 'extraArgs' => $sawComma];
        }

        // Anything else is an expression: a variable, an array access, a call.
        // NOT provably non-null, and that residual is stated in the doc-block
        // rather than hidden behind a bucket name that sounds conclusive.
        return ['firstArg' => 'expression', 'extraArgs' => $sawComma];
    }
}

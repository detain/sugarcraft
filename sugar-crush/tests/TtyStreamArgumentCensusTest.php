<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Nothing in this tree builds a raw-mode backend over the process's OWN
 * descriptor 0 with an injected `Termios` — neither directly, nor one frame up
 * through `Program`.
 *
 * ## THE FLAG, AND ITS POLARITY, STATED ONCE BECAUSE THIS DOC-BLOCK HAD IT
 * ## BACKWARDS
 *
 * WHAT THIS SAID: that `tests/bootstrap.php` repairs descriptor 0 by
 * "clearing `O_NONBLOCK`", and the measured table below then used "clear" to
 * mean the opposite — a descriptor whose reads return immediately. One word,
 * two opposite jobs, in one doc-block.
 *
 * WHAT IS TRUE. MEASURED, PHP 8.3.6, three takes, reading `/proc/self/fdinfo/0`
 * either side of the call, with fd 0 a pipe:
 *
 *   at startup                        O_NONBLOCK clear (fd 0 BLOCKING)
 *   stream_set_blocking($s, false)    O_NONBLOCK SET   (fd 0 NON-BLOCKING)
 *   stream_set_blocking($s, true)     O_NONBLOCK clear (fd 0 BLOCKING)
 *
 * So `tests/bootstrap.php`'s `stream_set_blocking(\STDIN, false)` SETS
 * `O_NONBLOCK`, and that set flag IS the repair. `PosixBackend::restore()`'s
 * `@stream_set_blocking($this->stream, true)` CLEARS it, and that clearing is
 * what erases the repair. Below, `O_NONBLOCK` is only ever "set" or "cleared",
 * and the descriptor is only ever "blocking" or "non-blocking".
 *
 * WHY THIS STILL EARNS ITS PLACE: the mechanism was right in every measurement
 * and wrong in every sentence, which is the failure that is hardest to see on
 * re-reading — the reader who "corrects" the code to match the prose is the
 * one this paragraph exists for.
 *
 * WHY THIS IS A GUARD AND NOT A STYLE RULE. `tests/bootstrap.php` repairs the
 * suite's descriptor 0 by SETTING `O_NONBLOCK` on it — making it non-blocking
 * — so a spawned `bin/sugarcrush` cannot park in `stream_get_contents()` on
 * the runner's stdin (E212's other half). `O_NONBLOCK` lives on the open file
 * DESCRIPTION, which is what makes it reach inherited children — and also what
 * makes it erasable by anything else holding that description. The shape that
 * holds it:
 *
 *   new Tty(null, $injectedTermios)
 *
 * `SugarCraft\Core\Util\Tty::__construct()` is `self::backend($stream ??
 * STDIN, $termios)`, so a null (or omitted) first argument wraps THIS
 * process's descriptor 0. Supplying a `Termios` then makes
 * `PosixBackend::enableRawMode()` take the branch that SKIPS its own
 * `isTty()` guard, so it runs to the end — and the end is
 * `@stream_set_blocking($this->stream, false)`, which sets the same flag the
 * bootstrap set. That call is not the damage. `restore()`'s matching
 * `@stream_set_blocking($this->stream, true)` is: it CLEARS `O_NONBLOCK`,
 * putting the runner's fd 0 back to BLOCKING for the remainder of the run.
 *
 * MEASURED, PHP 8.3.6, three takes each, in a child whose fd 0 is a pipe:
 *
 *   new Tty(null, new PosixTermios($fd))   fd 0 blocking -> non-blocking, and
 *                                          BLOCKING again after restore()  3/3
 *   new Tty($socketPairEnd, …)             fd 0's flag never moves         3/3
 *   new Tty()  (no Termios at all)         fd 0's flag never moves         3/3
 *
 * — the third row is why this census requires BOTH conditions rather than
 * flagging every null stream: without an injected `Termios`,
 * `enableRawMode()` returns at `!isTty()` and never reaches the flag.
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
 *       fd 0 non-blocking -> still non-blocking after enableRawMode()
 *       -> BLOCKING after restore()                                          3/3
 *   new PosixBackend($socketPairEnd, new PosixTermios($slaveFd))
 *       fd 0 non-blocking throughout                                         3/3
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
 *
 * ## AND THAT RESIDUAL HAD A CONCRETE INSTANCE: `Program` (round 50 review)
 *
 * WHAT THIS SAID: the headline, unqualified — nothing in this tree builds the
 * hazardous shape. WHAT IS TRUE: it was true of the shapes this scanner can
 * DECIDE, and `candy-core/src/Program.php` resolves the stream ONE FRAME
 * EARLIER. Verified by symbol rather than inferred:
 *
 *   $this->input = $options->input ?? STDIN;
 *   …
 *   $this->tty = new Tty($this->input, $options->termios);
 *
 * So `new Program($model, new ProgramOptions(termios: $t))`, with no `input:`,
 * wraps the RUNNER's descriptor 0 with an injected `Termios` — the same
 * hazard, reached through a second façade. At that site the census reads
 * `new Tty(expression, …)` and waves it through, correctly, because by then
 * the `?? STDIN` has already happened. And `ProgramOptions::$termios` is
 * documented in candy-core as a TEST SEAM ("Tests inject a stub Termios"),
 * which is the shape a test is likeliest to reach for.
 *
 * WHY THIS EARNED A THIRD ARM rather than a narrower headline: the join is
 * DECIDABLE without cross-frame analysis, because both fields travel in ONE
 * constructor call. A `new ProgramOptions(...)` that names `termios` and does
 * not name `input` is the shape, and that is a token walk like the others.
 * Measured over the package and every sibling `src`, PHP 8.3.6: no
 * construction names `termios` without also naming `input`. Exactly one names
 * `termios` at all — `ProgramOptionsBuilder::build()`, which names every
 * parameter it has, `input` included — and no other site mentions it. So this
 * was latent like the backend hole above rather than live.
 *
 * WHAT IS STILL OPEN, said plainly rather than implied to be closed. Any
 * `?? STDIN` resolved one frame up and then passed along as a variable is
 * invisible to this instrument by construction; `Program` is the instance of
 * that shape which exists TODAY, not the shape itself. The general guard —
 * flag a construction whose first argument is a variable assigned from a
 * `?? STDIN` in the same constructor — is not built here.
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
     * The options object that carries the hazard ONE FRAME UP.
     *
     * `Program::__construct()` does `$this->input = $options->input ?? STDIN;`
     * and then `new Tty($this->input, $options->termios)`, so the two fields
     * that decide the hazard both arrive in a single `ProgramOptions`
     * construction — which is what makes this decidable by a token walk at
     * all. Short-name matched after the last `\`, like the classes above, so
     * every spelling is one case; `ProgramOptionsBuilder` is a different short
     * name and is deliberately not this one.
     */
    private const OPTIONS_CLASS = 'ProgramOptions';

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
                $offender = self::ttyOffender($path, $site);
                if ($offender !== null) {
                    $offenders[] = $offender;
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
     * WHERE THIS ARM'S ALPHABET ACCEPTANCE ACTUALLY LIVES, because it is not
     * here and a reader should not have to find that out by mutating. This
     * arm's `$seen > 0` control cannot catch a NARROWING of
     * {@see HAZARD_CLASSES}: candy-core builds a `Tty` as well as two
     * `PosixBackend`s, so with the alphabet cut to `['Tty']` the walk still
     * sees a construction and this arm stays green. MEASURED both ways, PHP
     * 8.3.6, at the commit that wrote this paragraph: that narrowing filtered
     * to this method alone SURVIVES; filtered to the whole class it is KILLED,
     * by three of the `PosixBackend` rows in
     * {@see testTheScannerAnswersCorrectlyOnFixturesWhoseAnswerIsKnown()}. The
     * alphabet is pinned by fixtures that spell both names literally, and the
     * two arms share it — which is the right place for it, since a roster of
     * hazard classes derived from a `vendor/` directory this repository does
     * not version would red on an unrelated upstream refactor.
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
                $offender = self::ttyOffender($path, $site);
                if ($offender !== null) {
                    $offenders[] = $offender;
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
                . "restore() CLEARS O_NONBLOCK on it - putting fd 0 back to blocking, which erases "
                . "tests/bootstrap.php's repair (that repair is the flag being SET) for every later test in "
                . "the run:\n  " . implode("\n  ", $offenders),
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
     * ONE FRAME UP: nothing hands `Program` a `Termios` without also handing
     * it an input stream.
     *
     * WHY THIS ARM EXISTS is in the class doc-block: `Program::__construct()`
     * resolves `$options->input ?? STDIN` and then builds
     * `new Tty($this->input, $options->termios)`, so the two arms above see
     * `new Tty(expression, …)` at that site and — correctly, on the evidence
     * they have — wave it through. The `?? STDIN` already happened one frame
     * earlier.
     *
     * The join is decidable because both fields arrive in ONE constructor
     * call: `termios` named and `input` not named IS the hazardous shape, and
     * no cross-frame analysis is needed to see it.
     *
     * BOTH SCOPES IN ONE METHOD, unlike the two arms above, and for a reason:
     * those two differ in what they assert (the package arm pins a roster of
     * stream CONSTANTS, the sibling arm deliberately does not). This one
     * asserts the same single thing about both, and splitting it would produce
     * two tests that differ only in their walk.
     *
     * AND IT REPORTS WHAT IT CANNOT DECIDE (rule 14). A positional argument
     * list is not "fine": `ProgramOptions` takes some two dozen parameters and
     * this scanner cannot tell which slot a positional value landed in, so a
     * positional call is an offender until a person looks at it. Same for an
     * argument list that never closes.
     */
    public function testNoProgramOptionsInjectsATermiosWithoutAlsoNamingItsInput(): void
    {
        $offenders = [];
        $seen = 0;

        foreach ([self::sources(), self::libSources()] as $scope) {
            foreach ($scope as $path => $source) {
                foreach (self::programOptionsConstructions($source) as $site) {
                    ++$seen;
                    $offender = self::optionsOffender($path, $site);
                    if ($offender !== null) {
                        $offenders[] = $offender;
                    }
                }
            }
        }

        // The dead-scanner control (rule 15/E228): an empty offender list is
        // also what a walk that found no ProgramOptions at all returns, and
        // candy-core alone builds two.
        self::assertGreaterThan(
            0,
            $seen,
            'the census found no ProgramOptions construction in ' . implode(', ', self::SCOPE) . ' or under '
                . self::LIB_SCOPE . ' - the scanner is dead, not the tree clean. candy-core builds two.',
        );

        self::assertSame(
            [],
            $offenders,
            "a ProgramOptions carries an injected Termios and names no input, so Program will resolve its "
                . "input to the process's own STDIN and hand BOTH to Tty. In this process that descriptor "
                . "belongs to the PHPUnit runner, and PosixBackend::restore() then CLEARS O_NONBLOCK on it - "
                . "putting fd 0 back to blocking, which erases tests/bootstrap.php's repair (that repair is "
                . "the flag being SET) for every later test in the run. Pass an explicit input: too (a "
                . "stream_socket_pair() end is what the existing sites use):\n  " . implode("\n  ", $offenders),
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
     * The offender line for one `Tty`/`PosixBackend` site, or null if it is
     * fine.
     *
     * SHARED BY BOTH ARMS ABOVE, and extracted for the same reason as
     * {@see optionsOffender()}: MEASURED at the commit that split it out, with
     * the `unparsed` branch replaced by `false &&` in BOTH arms, the whole
     * census stayed green — 41 tests, 46 assertions, OK. The fixtures pin what
     * `rawModeConstructions()` REPORTS; nothing pinned what the arms did with
     * an `unparsed` report, so the rule-14 branch that exists precisely so
     * "I could not read it" is not spelled like "it was fine" was itself
     * spelled like nothing at all.
     *
     * The two conditions are mutually exclusive — `firstArg` cannot be both
     * `null` and `unparsed` — so unlike the options classifier this one has no
     * meaningful order.
     *
     * @param array{name: string, firstArg: string, extraArgs: bool} $site
     */
    private static function ttyOffender(string $path, array $site): ?string
    {
        if ($site['firstArg'] === 'null' && $site['extraArgs']) {
            return $path . ': new ' . $site['name'] . '(' . $site['firstArg'] . ', …)';
        }

        if ($site['firstArg'] === 'unparsed') {
            return $path . ': new ' . $site['name']
                . '(<argument list this scanner could not read to its close>)';
        }

        return null;
    }

    /**
     * KNOWN ANSWERS FOR THE TTY CLASSIFICATION, the half a mutation proved was
     * unpinned in both of the older arms.
     *
     * Two rows must produce an offender and three must produce null. The
     * `null` first argument with NO extra arguments is the row that matters
     * most: `new Tty()` resolves to the same descriptor and is deliberately
     * NOT an offender, because without an injected `Termios`
     * `enableRawMode()` returns at `!isTty()` before it reaches the flag.
     *
     * @param array{name: string, firstArg: string, extraArgs: bool} $site
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ttyOffenderFixtures')]
    public function testTheTtyClassifierAnswersCorrectlyOnSitesWhoseAnswerIsKnown(
        array $site,
        ?string $expected,
    ): void {
        self::assertSame($expected, self::ttyOffender('p.php', $site));
    }

    /**
     * @return iterable<string, array{array{name: string, firstArg: string, extraArgs: bool}, ?string}>
     */
    public static function ttyOffenderFixtures(): iterable
    {
        $site = static fn (array $o): array => $o + ['name' => 'Tty', 'firstArg' => 'null', 'extraArgs' => true];

        yield 'THE HAZARD: a null stream with an injected Termios' => [
            $site([]),
            'p.php: new Tty(null, …)',
        ];
        yield 'an unreadable argument list is an offender (rule 14)' => [
            $site(['firstArg' => 'unparsed', 'extraArgs' => false]),
            'p.php: new Tty(<argument list this scanner could not read to its close>)',
        ];
        yield 'a null stream with NO Termios is clean - enableRawMode() stops at !isTty()' => [
            $site(['extraArgs' => false]),
            null,
        ];
        yield 'an explicit stream expression is clean' => [$site(['firstArg' => 'expression']), null];
        yield 'a constant is clean HERE - the constant roster is a separate assertion' => [
            $site(['firstArg' => 'constant:STDOUT', 'extraArgs' => false]),
            null,
        ];
    }

    /**
     * The offender line for one `ProgramOptions` site, or null if it is fine.
     *
     * WHY THIS IS A METHOD AND NOT THREE `if`s IN THE LOOP ABOVE. It was three
     * `if`s, and dropping the POSITIONAL one changed nothing — the whole
     * census stayed green. The fixtures pin what the SCANNER reports; nothing
     * pinned what the ARM does with it, so two of the three branches were
     * unreachable-by-any-test the moment they were written (rule 2: suspect
     * the assertion's window before you suspect the mutation's relevance).
     * Split out, the classification has known answers of its own below.
     *
     * ORDER IS PART OF THE ANSWER. `unparsed` outranks everything: if the walk
     * could not reach the end of the argument list, the named-argument list it
     * collected is a prefix and "no termios seen" means nothing.
     *
     * @param array{name: string, named: list<string>, positional: bool, unparsed: bool} $site
     */
    private static function optionsOffender(string $path, array $site): ?string
    {
        if ($site['unparsed']) {
            return $path . ': new ' . $site['name']
                . '(<argument list this scanner could not read to its close>)';
        }

        if ($site['positional']) {
            return $path . ': new ' . $site['name']
                . '(<positional arguments - this scanner cannot tell which slot the termios landed in>)';
        }

        if (\in_array('termios', $site['named'], true) && !\in_array('input', $site['named'], true)) {
            return $path . ': new ' . $site['name'] . '(termios: …) with no input:';
        }

        return null;
    }

    /**
     * KNOWN ANSWERS FOR THE CLASSIFICATION, which is the half a mutation
     * proved was unpinned.
     *
     * Three rows must produce an offender and three must produce null. Without
     * the null rows this would be satisfied by a classifier that reports
     * everything; without the offender rows, by one that reports nothing.
     *
     * @param array{name: string, named: list<string>, positional: bool, unparsed: bool} $site
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('optionsOffenderFixtures')]
    public function testTheOptionsClassifierAnswersCorrectlyOnSitesWhoseAnswerIsKnown(
        array $site,
        ?string $expected,
    ): void {
        self::assertSame($expected, self::optionsOffender('p.php', $site));
    }

    /**
     * @return iterable<string, array{array{name: string, named: list<string>, positional: bool, unparsed: bool}, ?string}>
     */
    public static function optionsOffenderFixtures(): iterable
    {
        $site = static fn (array $o): array => $o + [
            'name' => 'ProgramOptions',
            'named' => [],
            'positional' => false,
            'unparsed' => false,
        ];

        yield 'THE HAZARD: termios named, input not' => [
            $site(['named' => ['termios']]),
            'p.php: new ProgramOptions(termios: …) with no input:',
        ];
        yield 'a positional list is an offender even when nothing hazardous is named' => [
            $site(['positional' => true]),
            'p.php: new ProgramOptions(<positional arguments - this scanner cannot tell which slot the '
                . 'termios landed in>)',
        ];
        yield 'an unclosed list is an offender, and OUTRANKS a clean named list' => [
            $site(['named' => ['input'], 'unparsed' => true]),
            'p.php: new ProgramOptions(<argument list this scanner could not read to its close>)',
        ];
        yield 'termios AND input is clean - the builder shape' => [
            $site(['named' => ['input', 'termios']]),
            null,
        ];
        yield 'input alone is clean' => [$site(['named' => ['input']]), null];
        yield 'neither named is clean' => [$site(['named' => ['useAltScreen']]), null];
    }

    /**
     * KNOWN ANSWERS FOR THE ONE-FRAME-UP SCANNER, BOTH POLARITIES.
     *
     * The hazardous row and its near-misses sit side by side on purpose: a
     * scanner that answered "offender" to everything would satisfy the arm
     * above just as well as a correct one, and the rows that must come back
     * CLEAN (termios with input, input with no termios, no arguments at all)
     * are what tell the two apart.
     *
     * @param list<array{name: string, named: list<string>, positional: bool, unparsed: bool}> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('optionsFixtures')]
    public function testTheOptionsScannerAnswersCorrectlyOnFixturesWhoseAnswerIsKnown(
        string $source,
        array $expected,
    ): void {
        self::assertSame($expected, self::programOptionsConstructions('<?php ' . $source));
    }

    /**
     * @return iterable<string, array{string, list<array{name: string, named: list<string>, positional: bool, unparsed: bool}>}>
     */
    public static function optionsFixtures(): iterable
    {
        yield 'THE HAZARD: a termios and no input' => [
            '$o = new ProgramOptions(termios: $t);',
            [['name' => 'ProgramOptions', 'named' => ['termios'], 'positional' => false, 'unparsed' => false]],
        ];
        yield 'termios AND input - not the hazard, and this is the shape the builder ships' => [
            '$o = new ProgramOptions(input: $s, termios: $t);',
            [[
                'name' => 'ProgramOptions',
                'named' => ['input', 'termios'],
                'positional' => false,
                'unparsed' => false,
            ]],
        ];
        yield 'no termios at all - nothing is injected, so nothing skips the isTty() guard' => [
            '$o = new ProgramOptions(useAltScreen: true);',
            [[
                'name' => 'ProgramOptions',
                'named' => ['useAltScreen'],
                'positional' => false,
                'unparsed' => false,
            ]],
        ];
        yield 'no arguments at all' => [
            '$o = new ProgramOptions();',
            [['name' => 'ProgramOptions', 'named' => [], 'positional' => false, 'unparsed' => false]],
        ];
        yield 'FULLY QUALIFIED, the spelling a grep for "new ProgramOptions(" cannot see' => [
            '$o = new \SugarCraft\Core\ProgramOptions(termios: $t);',
            [[
                'name' => '\SugarCraft\Core\ProgramOptions',
                'named' => ['termios'],
                'positional' => false,
                'unparsed' => false,
            ]],
        ];
        // RULE 14: ProgramOptions takes some two dozen parameters, so a
        // positional list is a slot this scanner cannot identify. Reported,
        // never waved through.
        yield 'POSITIONAL arguments are reported, not guessed at' => [
            '$o = new ProgramOptions(true, false);',
            [['name' => 'ProgramOptions', 'named' => [], 'positional' => true, 'unparsed' => false]],
        ];
        yield 'a spread is undecidable and reads as positional' => [
            '$o = new ProgramOptions(...$args);',
            [['name' => 'ProgramOptions', 'named' => [], 'positional' => true, 'unparsed' => false]],
        ];
        yield 'an argument list that never closes is REPORTED, not dropped' => [
            '$o = new ProgramOptions(termios: $t',
            [['name' => 'ProgramOptions', 'named' => ['termios'], 'positional' => false, 'unparsed' => true]],
        ];
        // The three shapes a naive "a colon at argument depth is a label"
        // scanner gets wrong, and none of them is a named argument.
        yield 'a nested call in a named value does not read as positional' => [
            '$o = new ProgramOptions(termios: makeT($a, $b), input: f(1));',
            [[
                'name' => 'ProgramOptions',
                'named' => ['termios', 'input'],
                'positional' => false,
                'unparsed' => false,
            ]],
        ];
        yield 'a ternary value has a colon at argument depth and is not a label' => [
            '$o = new ProgramOptions(input: $a ? $b : $c);',
            [['name' => 'ProgramOptions', 'named' => ['input'], 'positional' => false, 'unparsed' => false]],
        ];
        yield 'an enum case value is not a label' => [
            '$o = new ProgramOptions(mouseMode: Mouse::All);',
            [[
                'name' => 'ProgramOptions',
                'named' => ['mouseMode'],
                'positional' => false,
                'unparsed' => false,
            ]],
        ];
        yield 'a trailing comma does not read as a positional argument' => [
            '$o = new ProgramOptions(termios: $t, input: $s,);',
            [[
                'name' => 'ProgramOptions',
                'named' => ['termios', 'input'],
                'positional' => false,
                'unparsed' => false,
            ]],
        ];
        // NEGATIVE. The builder is a different class with a different short
        // name, and it is the one place that legitimately names every field.
        yield 'ProgramOptionsBuilder is NOT ProgramOptions' => [
            '$b = new ProgramOptionsBuilder(termios: $t);',
            [],
        ];
        yield 'a ProgramOptions in a comment is not a construction' => [
            '// $o = new ProgramOptions(termios: $t);' . "\n" . '$x = 1;',
            [],
        ];
    }

    /**
     * Every `new <…>ProgramOptions(...)` in $source, argument list classified
     * by NAME rather than by position.
     *
     * Token-based for the same reason as the scanner above: the class name may
     * be short, partially or fully qualified, and `T_NEW` cannot appear inside
     * a comment or a string.
     *
     * @return list<array{name: string, named: list<string>, positional: bool, unparsed: bool}>
     */
    private static function programOptionsConstructions(string $source): array
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
            if ($short !== self::OPTIONS_CLASS || $j >= $count || $tokens[$j] !== '(') {
                continue;
            }

            $found[] = ['name' => $name] + self::classifyNamedArguments($tokens, $j, $count, $skip);
        }

        return $found;
    }

    /**
     * Which parameters an argument list names, and whether it also passes
     * anything this scanner cannot attribute to a parameter.
     *
     * A NAMED argument is a `T_STRING` immediately followed by a `:` at the
     * START of an argument. Both halves matter: `Foo::BAR` tokenises the pair
     * as one `T_DOUBLE_COLON`, so a class constant can never be mistaken for a
     * label, and a ternary's `:` is never at an argument's start. Anything
     * else appearing where an argument begins — a literal, a variable, a
     * spread — is positional, which this scanner reports rather than reads.
     *
     * @param list<array{int, string, int}|string> $tokens
     * @param list<int>                            $skip
     *
     * @return array{named: list<string>, positional: bool, unparsed: bool}
     */
    private static function classifyNamedArguments(array $tokens, int $open, int $count, array $skip): array
    {
        $depth = 0;
        $named = [];
        $positional = false;
        $atArgStart = false;
        $closed = false;

        for ($k = $open; $k < $count; $k++) {
            $t = $tokens[$k];

            if ($t === '(' || $t === '[') {
                $depth++;
                if ($depth === 1) {
                    $atArgStart = true;

                    continue;
                }
            } elseif ($t === ')' || $t === ']') {
                $depth--;
                if ($depth === 0) {
                    $closed = true;

                    break;
                }
            } elseif ($t === ',' && $depth === 1) {
                $atArgStart = true;

                continue;
            }

            if (\is_array($t) && \in_array($t[0], $skip, true)) {
                continue;
            }
            if (!$atArgStart || $depth !== 1) {
                continue;
            }

            if (\is_array($t) && $t[0] === \T_STRING) {
                $n = $k + 1;
                while ($n < $count && \is_array($tokens[$n]) && \in_array($tokens[$n][0], $skip, true)) {
                    $n++;
                }
                if ($n < $count && $tokens[$n] === ':') {
                    $named[] = $t[1];
                    $atArgStart = false;
                    $k = $n;

                    continue;
                }
            }

            $positional = true;
            $atArgStart = false;
        }

        // The list never closed, so the walk fell off the end of the token
        // stream (rule 14): "I could not read it" must not be spelled the same
        // way as "it was fine".
        return ['named' => $named, 'positional' => $positional, 'unparsed' => !$closed];
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

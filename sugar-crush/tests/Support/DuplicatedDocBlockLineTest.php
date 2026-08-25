<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A DOC-BLOCK LINE REPEATED VERBATIM DIRECTLY UNDER ITSELF IS COPY-PASTE
 * DAMAGE, AND IN THIS TREE DOC-BLOCK PROSE IS LOAD-BEARING.
 *
 * E579 is the case that produced this file. `Tests\Config\GlobFigureDriftTest`
 * — the tree's designated authority on figures without generators, and a class
 * whose prose is quoted elsewhere — carried one line of its own argument twice
 * in a row, and it had been there for at least three rounds. The half-sentence
 * it interrupted still parsed as English, which is why nobody reading past it
 * stopped: the duplicate reads as a stutter rather than as an error, and the
 * reader who eventually tidies it has to guess which of the two identical lines
 * the rest of the sentence belongs to.
 *
 * WHY A GUARD AND NOT A ONE-LINE EDIT. The edit was one line. The population is
 * what makes it worth an instrument: MEASURED on PHP 8.3.6 over `tests/` and
 * every root in {@see SOURCE_ROOTS}, with NO minimum length and NO exemption of
 * any kind, this scanner reports exactly ONE site — the E579 one, now fixed. A
 * census whose clean state is a true empty needs no allow-list to keep it
 * clean, and an instrument with no exemption row cannot be bought with one
 * (rule 33). The number is not written into any assertion here; it is derived
 * on every run by {@see repeatedDocBlockLinesIn()} over
 * {@see everyTestFile()} and the source roots beside it.
 *
 * WHAT THAT SENTENCE USED TO CLAIM, and why it is worth recording rather than
 * quietly correcting: it said "`tests/`, `src/` and `bin/`" and "the whole
 * package". Neither was true. The walk's file test was the `.php` extension,
 * `bin/` holds one extensionless executable, and so the `bin/` root
 * contributed ZERO files under a sentence naming it — see {@see isPhp()}. And
 * "the whole package" left out `examples/` and `workflows/` entirely. Both
 * halves are now true rather than narrowed: the walk sees `bin/sugarcrush`,
 * the two missing roots are in {@see SOURCE_ROOTS}, and the roots are asked
 * for their contribution ONE AT A TIME so that the next root to fall out of
 * the walk cannot hide inside a total.
 *
 * RULE 15: THE ABSENCE IS NOT THE EVIDENCE. An assertion that this tree
 * contains no repeated line is exactly what a scanner deleted outright also
 * produces. {@see testTheScannerAnswersSourcesWhoseAnswerIsKnown()} pushes a
 * source that DOES carry one through the same method in the same test, so a
 * dead scanner reds there before its silence can be read as cleanliness.
 *
 * RULE 40: THE WALK IS STRUCTURAL, NOT TEXTUAL. The obvious spelling of this
 * check is a line filter for `^\s*\*`, and that spelling reports a duplicated
 * ` * ` line sitting inside a heredoc or a single-quoted string — of which this
 * suite has many, because half its fixtures are PHP source about PHP source.
 * The walk here reads `T_DOC_COMMENT` tokens out of `token_get_all()`, so a
 * doc-block is a doc-block because the LEXER says so and not because a line
 * begins with a star. {@see testAStarredLineInsideAHeredocIsNotADocBlock()}
 * is that distinction's pin, and it fails on the line-filter spelling.
 *
 * WHAT IT DELIBERATELY DOES NOT SEE.
 *
 *   * `T_COMMENT` — `//` and `#` runs, and `/* … *\/` blocks that are not
 *     doc-blocks. Two identical `//` lines in a row are a normal way to write a
 *     commented-out pair of statements. The subject here is PROSE that other
 *     files quote, and in this tree that prose lives in doc-blocks.
 *   * A line repeated NON-adjacently. A doc-block that opens and closes an
 *     argument with the same sentence is a rhetorical device, not damage, and
 *     several in this suite do it deliberately.
 *   * A repeat that differs by so much as one byte of trailing whitespace,
 *     because the bodies are compared after `trim()`. That is the right
 *     direction: it makes the check catch MORE, not less.
 *
 * @see \SugarCraft\Crush\Tests\Config\GlobFigureDriftTest the site that
 *      produced this guard
 */
final class DuplicatedDocBlockLineTest extends TestCase
{
    use TestFileWalkTrait;

    /**
     * The package roots walked beside `tests/`, which
     * {@see TestFileWalkTrait::everyTestFile()} supplies.
     *
     * NAMED AS A CONSTANT SO THE POPULATION ASSERTION CAN ITERATE IT. The
     * roots used to be a literal inside the walk, which left the test above
     * unable to ask each one for a contribution and reduced to a single count
     * over all of them — the shape a whole root can vanish through.
     *
     * `examples` AND `workflows` ARE HERE BECAUSE THE PROSE ALREADY CLAIMED
     * THEM. This file's argument is about the whole package, and those two
     * roots hold five `.php` files that no census here has ever read.
     * Measured on PHP 8.3.6 before adding them: both are clean, so widening
     * the walk changed no answer — which is the only kind of widening worth
     * doing without a lane owning the files it reaches.
     */
    private const SOURCE_ROOTS = ['src', 'bin', 'examples', 'workflows'];

    public function testNoDocBlockInThisPackageRepeatsALineUnderItself(): void
    {
        $sources = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $sources['tests/' . $relative] = (string) file_get_contents($path);
        }
        foreach (self::everySourceFile() as $relative => $path) {
            $sources[$relative] = (string) file_get_contents($path);
        }

        // RULE 15, IN THIS TEST RATHER THAN BESIDE IT. The absence below is
        // only worth reading if the scanner that computed it still answers a
        // source whose answer is known, and if the population it walked is not
        // empty.
        //
        // WHAT THIS SAID: one `assertGreaterThan(400, count($sources))`,
        // offered as the proof that the walk collected something. WHAT IS TRUE
        // NOW, measured on PHP 8.3.6: {@see everyTestFile()} ALONE returns 467
        // files, so that floor sat BELOW one of the two halves it was meant to
        // be guarding, and the entire source side could vanish without
        // reddening it — mutation-checked, replacing this walk's root list
        // with an empty one left the census green. WHY A PER-ROOT SHAPE AND
        // NOT A BIGGER NUMBER: a bigger number is a cardinality over `tests/`
        // written into an assertion, and the next merge invalidates it
        // (rule 18). Asking each root for a contribution is structural, cannot
        // drift, and reds on exactly the failure a single scalar cannot see —
        // one root silently contributing nothing at all.
        //
        // IT IS ALSO WHAT PINS {@see isPhp()}. `bin/` holds exactly one file
        // and that file has no `.php` extension, so the shebang arm dying is
        // indistinguishable from `bin/` being empty — and both red here.
        foreach (['tests', ...self::SOURCE_ROOTS] as $root) {
            $contributed = array_filter(
                array_keys($sources),
                static fn (string $where): bool => str_starts_with($where, $root . '/'),
            );
            $this->assertNotSame(
                [],
                $contributed,
                'the walk collected NOTHING from `' . $root . '/`, so the emptiness asserted '
                    . 'below is a fact about the walk and not about that root. A directory that '
                    . 'has moved, a root dropped from the walk, and a file-type test that cannot '
                    . 'express what lives there all produce this, and all three are invisible to '
                    . 'a population count taken over every root at once.',
            );
        }
        $control = self::repeatedDocBlockLinesIn(self::sourceCarryingARepeatedLine());
        $this->assertCount(
            1,
            $control,
            'the scanner no longer reports a doc-block line repeated directly under itself, so '
                . 'the census below cannot be read as evidence of anything',
        );

        $found = [];
        foreach ($sources as $where => $source) {
            foreach (self::repeatedDocBlockLinesIn($source) as $repeat) {
                $found[] = $where . ':' . $repeat['line'] . ' — ' . $repeat['text'];
            }
        }

        $this->assertSame(
            [],
            $found,
            "a doc-block repeats a line verbatim directly under itself. That is copy-paste "
                . "damage in prose this tree treats as load-bearing, and the reader who tidies "
                . "it later has to guess which of the two identical lines the surrounding "
                . "sentence belongs to. DELETE ONE OF THE TWO and check that the sentence it "
                . "was interrupting still reads. Do NOT add an exemption: this scanner has none "
                . "and its clean state is a true empty (E579)",
        );
    }

    /**
     * Sources whose answer is known, pushed through the same method the census
     * uses.
     *
     * RULE 26: THE OFFENDING SHAPE IS NEVER SPELLED LITERALLY IN THIS FILE.
     * Every fixture below builds its duplicate by repeating ONE variable, so a
     * future blanket pass over this pattern cannot eat the fixture that proves
     * the pattern is caught — which is the exact damage the round-48 id
     * renumber and the `uniqid` sweep both did to the guards documenting them.
     */
    public function testTheScannerAnswersSourcesWhoseAnswerIsKnown(): void
    {
        $line = ' * derives the TOOL SET the glob leaves. It holds the glob as a constant';

        $repeated = self::repeatedDocBlockLinesIn(
            "<?php\n/**\n * A doc-block.\n" . $line . "\n" . $line . "\n * and on it goes.\n */\nclass A {}\n",
        );
        $this->assertCount(1, $repeated, 'a line repeated directly under itself was not reported');
        $this->assertSame(5, $repeated[0]['line'], 'the SECOND of the two lines is the one to delete, and its number is wrong');
        $this->assertSame(trim(substr(trim($line), 1)), $repeated[0]['text'], 'the report does not carry the repeated text, so the reader cannot find it');

        $this->assertSame(
            [],
            self::repeatedDocBlockLinesIn("<?php\n/**\n" . $line . "\n * a different line entirely.\n" . $line . "\n */\nclass A {}\n"),
            'a line repeated NON-adjacently was reported, which makes a deliberate rhetorical '
                . 'bookend indistinguishable from copy-paste damage',
        );

        $this->assertSame(
            [],
            self::repeatedDocBlockLinesIn("<?php\n/**\n * one line.\n * another line.\n */\nclass A {}\n"),
            'a clean doc-block was reported, so every file in this package is about to be',
        );

        // A BLANK CONTINUATION RESETS. Two paragraphs that happen to open with
        // the same sentence, separated by a bare ` *`, are not a stutter.
        $this->assertSame(
            [],
            self::repeatedDocBlockLinesIn("<?php\n/**\n" . $line . "\n *\n" . $line . "\n */\nclass A {}\n"),
            'a blank continuation line no longer separates two paragraphs',
        );

        // ── THE THREE RESET ARMS, EACH REACHED BY ITS OWN FIXTURE. Every one
        // of these was added because a mutation of the arm SURVIVED the rows
        // above it: the "blank continuation" and "non-adjacent" rows were
        // written from the shape of the DEFECT and reached none of the code
        // that prevents a FALSE report (rule 2 — the mutation was relevant and
        // the window was wrong, twice in a row).

        // (1) TWO BLANK CONTINUATIONS IN A ROW. Without the empty-body reset
        // both carry the body `''`, the second equals the first, and every
        // doc-block in this package with a double blank line is reported with
        // an empty `text`. This is the arm's real job; separating two
        // paragraphs is a consequence of it.
        $this->assertSame(
            [],
            self::repeatedDocBlockLinesIn("<?php\n/**\n * a line.\n *\n *\n * another line.\n */\nclass A {}\n"),
            'two blank continuation lines in a row were reported as a repeat, so a doc-block '
                . 'with a double blank line now reds with an empty message',
        );

        // (2) A LONE SLASH ON TWO CONSECUTIVE LINES. The closing ` */` has the
        // body `/` after the star is stripped, so without the `'/'` reset a
        // doc-block whose last prose line is a bare slash repeats it.
        $this->assertSame(
            [],
            self::repeatedDocBlockLinesIn("<?php\n/**\n * /\n */\nclass A {}\n"),
            'the closing delimiter was compared against the prose line above it, so a doc-block '
                . 'ending in a lone slash is reported as repeating itself',
        );

        // (3) A CONTINUATION LINE WITH NO STAR breaks adjacency.
        //
        // WHAT THIS SAID: "Doc-blocks in this package really do carry them —
        // five lines in five files" — offered as the proof that the real census
        // reaches this arm. WHAT IS TRUE NOW, re-measured on PHP 8.3.6 with a
        // known-positive control in both polarities: the COUNT was right and
        // the DESCRIPTION was not. Every one of those five lines is FULLY
        // EMPTY. There is no un-starred PROSE continuation anywhere in
        // `tests/`, `src/` or `bin/`. WHY THE ARM STILL EARNS ITS PLACE: an
        // empty line takes it too, because `ltrim('')` does not begin with a
        // star and so never reaches the empty-body reset in arm (1) — the arm
        // IS exercised by the real census, just not by the shape the sentence
        // named. The starless-PROSE fixture below is therefore synthetic-only,
        // which is E363's shape and correct rather than a compromise. No count
        // is written here on purpose (rule 18): derive it by walking
        // `T_DOC_COMMENT` bodies and tallying continuation lines whose
        // `ltrim()` does not start with `*`, keeping the empty ones apart from
        // the rest — the two answers are different questions.
        $starless = 'a fenced example line, written without a leading star';
        $this->assertSame(
            [],
            self::repeatedDocBlockLinesIn("<?php\n/**\n" . $line . "\n   " . $starless . "\n" . $line . "\n */\nclass A {}\n"),
            'a starless continuation line no longer separates the two identical lines around it',
        );

        // (3b) AND THE SHAPE THE REAL POPULATION ACTUALLY HAS. A FULLY EMPTY
        // line takes arm (3) and not arm (1): `ltrim('')` does not begin with a
        // star, so it never reaches the empty-body reset. Every doc-block line
        // in this package that exercises arm (3) is of this shape and none is
        // starless prose, so without this row the arm's real-population
        // reachability rests on the paragraph above it rather than on a test.
        $this->assertSame(
            [],
            self::repeatedDocBlockLinesIn("<?php\n/**\n" . $line . "\n\n" . $line . "\n */\nclass A {}\n"),
            'a fully empty continuation line no longer separates the two identical lines around '
                . 'it, which is the shape every arm-(3) site in this package really has',
        );
        // RULE 25: THE POSITIVE HALF, so the row above is not an emptiness a
        // dead scanner also produces. Same two lines, same builder, separator
        // removed — the pair must be REPORTED, which is what makes the `[]`
        // above a statement about the empty line rather than about the walk.
        $this->assertCount(
            1,
            self::repeatedDocBlockLinesIn("<?php\n/**\n" . $line . "\n" . $line . "\n */\nclass A {}\n"),
            'with the empty separator removed the same pair is still not reported, so the row '
                . 'above proves nothing about what an empty line does',
        );

        // AND A `//` RUN IS OUT OF SCOPE BY CONSTRUCTION, not by a text test.
        $slashes = '// $this->assertSame(1, 1);';
        $this->assertSame(
            [],
            self::repeatedDocBlockLinesIn("<?php\n" . $slashes . "\n" . $slashes . "\nclass A {}\n"),
            'a repeated `//` line was reported; commenting out a pair of identical statements is '
                . 'normal and is not this guard\'s subject',
        );
    }

    /**
     * RULE 40'S PIN, AND IT FAILS ON THE OBVIOUS SPELLING OF THIS CHECK.
     *
     * The line-filter version of this scanner — anything matching `^\s*\*` is a
     * doc-block continuation — reports the heredoc below, because this suite is
     * full of fixtures that are PHP source about PHP source and several of them
     * contain doc-blocks as DATA. Reading `T_DOC_COMMENT` out of the lexer is
     * what makes the difference, and this is the test that says so.
     */
    public function testAStarredLineInsideAHeredocIsNotADocBlock(): void
    {
        $line = ' * derives the TOOL SET the glob leaves. It holds the glob as a constant';

        $inHeredoc = "<?php\n\$fixture = <<<'PHP'\n/**\n" . $line . "\n" . $line . "\n */\nPHP;\n";
        $this->assertSame(
            [],
            self::repeatedDocBlockLinesIn($inHeredoc),
            'a repeated starred line inside a NOWDOC was reported as a doc-block, so this walk '
                . 'is reading lines rather than tokens and every source-about-source fixture in '
                . 'this suite is a false positive waiting to happen',
        );

        $inString = "<?php\n\$fixture = \"/**\\n" . addslashes($line) . "\\n" . addslashes($line) . "\\n */\";\n";
        $this->assertSame([], self::repeatedDocBlockLinesIn($inString), 'a repeated starred line inside a double-quoted string was reported');

        // THE OTHER HALF OF THE SAME CLAIM. A real doc-block sitting in the
        // same file as that heredoc is still found, so the exclusion above is
        // the lexer distinguishing two things and not the scanner having died.
        $both = "<?php\n/**\n" . $line . "\n" . $line . "\n */\nclass A {}\n\$fixture = <<<'PHP'\n/**\n" . $line . "\n" . $line . "\n */\nPHP;\n";
        $this->assertCount(
            1,
            self::repeatedDocBlockLinesIn($both),
            'a real doc-block beside a heredoc carrying the same shape was not reported, so the '
                . 'heredoc exclusion above is the scanner answering nothing at all',
        );
    }

    /**
     * RULE 14: A SOURCE THIS CANNOT LEX IS A REFUSAL, NOT A CLEAN FILE.
     *
     * `token_get_all()` on a source with a syntax error emits a PHP warning and
     * returns whatever it managed, which for a truncated doc-block is a clean
     * answer arrived at by not looking. A file this cannot parse is reported as
     * a problem rather than counted as compliant — the hole otherwise is shaped
     * exactly like the next offender.
     */
    public function testASourceThatCannotBeLexedIsRefusedRatherThanCalledClean(): void
    {
        $this->assertSame(
            [['line' => 0, 'text' => 'this source could not be lexed, so nothing here has been checked']],
            self::repeatedDocBlockLinesIn("<?php\nclass A { function b( {\n"),
            'an unlexable source was quietly reported as carrying no repeats, which is the same '
                . 'answer a clean file gives',
        );
    }

    /**
     * Every doc-block line that repeats the line directly above it.
     *
     * @return list<array{line:int,text:string}> `line` is the 1-based line of
     *         the SECOND of the two, which is the one to delete
     */
    private static function repeatedDocBlockLinesIn(string $source): array
    {
        // RULE 39: THE NARROW CLASS, NOT `\Throwable`. `TOKEN_PARSE` raises a
        // `ParseError` on a source it cannot parse, and that is the ONLY thing
        // this arm exists to convert into a report row. A `\Throwable` here
        // would also swallow PHPUnit's own `ExpectationFailedException` if this
        // method ever grew an assertion, which is how ten sites in eight files
        // came to assert nothing (E521's family).
        try {
            $tokens = \token_get_all($source, \TOKEN_PARSE);
        } catch (\ParseError) {
            return [['line' => 0, 'text' => 'this source could not be lexed, so nothing here has been checked']];
        }

        $found = [];
        foreach ($tokens as $token) {
            if (!\is_array($token) || $token[0] !== \T_DOC_COMMENT) {
                continue;
            }

            $previous = null;
            foreach (explode("\n", $token[1]) as $offset => $raw) {
                $trimmed = ltrim($raw);
                if (!str_starts_with($trimmed, '*')) {
                    $previous = null;

                    continue;
                }
                $body = trim(substr($trimmed, 1));
                if ($body === '' || $body === '/') {
                    $previous = null;

                    continue;
                }
                if ($previous === $body) {
                    $found[] = ['line' => $token[2] + $offset, 'text' => $body];
                }
                $previous = $body;
            }
        }

        return $found;
    }

    /** Built by repetition so the offending shape is never literal here (rule 26). */
    private static function sourceCarryingARepeatedLine(): string
    {
        $line = ' * a sentence long enough to be prose rather than punctuation.';

        return "<?php\n/**\n" . $line . "\n" . $line . "\n */\nclass A {}\n";
    }

    /**
     * @return array<string,string> path relative to the package => absolute path
     */
    private static function everySourceFile(): array
    {
        $package = \dirname(__DIR__, 2);
        $found = [];

        foreach (self::SOURCE_ROOTS as $root) {
            $directory = $package . '/' . $root;
            if (!is_dir($directory)) {
                // SILENT HERE AND CAUGHT THERE, deliberately. A root that has
                // been renamed away contributes nothing, and nothing is what
                // the per-root assertion in
                // {@see testNoDocBlockInThisPackageRepeatsALineUnderItself()}
                // reds on. Throwing here would move the diagnosis into the
                // walker and leave the test that makes the claim unable to
                // state which root went missing.
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || !self::isPhp($file->getPathname())) {
                    continue;
                }
                $found[substr($file->getPathname(), \strlen($package) + 1)] = $file->getPathname();
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * Whether $path holds PHP source — asked of the FILE, not of its name.
     *
     * WHY NOT `str_ends_with($path, '.php')`, which is what stood here. That
     * test reported ZERO files for the `bin/` root while the doc-block above
     * claimed the census covered it: measured on PHP 8.3.6, `bin/` holds
     * exactly one entry, `bin/sugarcrush`, and it is 431 lines of PHP behind a
     * `#!/usr/bin/env php` line with no extension at all. The root was in the
     * walk, the walk was in the prose, and the file was in neither — rule 11 at
     * its plainest, the alphabet here being the file extension and the one file
     * it could not express being the package's own executable.
     *
     * The shebang is read from the file rather than guessed from the path, so
     * a second extensionless entry point arrives covered instead of arriving
     * uncounted.
     */
    private static function isPhp(string $path): bool
    {
        if (str_ends_with($path, '.php')) {
            return true;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $first = (string) fgets($handle, 256);
        fclose($handle);

        return str_starts_with($first, '#!') && str_contains($first, 'php');
    }
}

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
 * what makes it worth an instrument: MEASURED on PHP 8.3.6 over `tests/`,
 * `src/` and `bin/`, with NO minimum length and NO exemption of any kind, this
 * scanner reports exactly ONE site in the whole package — the E579 one, now
 * fixed. A census whose clean state is a true empty needs no allow-list to keep
 * it clean, and an instrument with no exemption row cannot be bought with one
 * (rule 33). The number is not written into any assertion here; it is derived
 * on every run by {@see repeatedDocBlockLinesIn()} over
 * {@see everyTestFile()} and the two source roots beside it.
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
        $this->assertGreaterThan(
            400,
            \count($sources),
            'the file walk collected almost nothing, so the emptiness asserted below is a fact '
                . 'about the walk and not about the tree',
        );
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

        foreach (['src', 'bin'] as $root) {
            $directory = $package . '/' . $root;
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $found[substr($file->getPathname(), \strlen($package) + 1)] = $file->getPathname();
            }
        }
        ksort($found);

        return $found;
    }
}

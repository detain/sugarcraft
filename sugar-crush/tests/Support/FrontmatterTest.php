<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\Frontmatter;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The shared frontmatter reader, pinned at the unit where it lives.
 *
 * Two halves, and they pull in opposite directions, which is why both are
 * asserted here rather than left to the call sites:
 *
 *  1. A plain scalar the ecosystem writes every day but YAML rejects -- prose
 *     with a colon in it, most often a `description:` -- must survive whole.
 *  2. Everything YAML already accepts must keep the meaning Symfony gives it,
 *     to the byte. The repair pass is not a lenient dialect and must not
 *     become one: a block scalar's body, a flow sequence, a quoted string and
 *     an integer are all things a lenient rewriter would quietly ruin.
 *
 * The malformed-input tests are the load-bearing ones for (2)'s converse: a
 * file that is genuinely broken must still throw, or a syntax error the author
 * needs to see turns into silently-empty metadata.
 */
final class FrontmatterTest extends TestCase
{
    /**
     * The exact shape the six SKILL.md files on this machine were rejected
     * for. `description: Foo: bar baz` is a YAML syntax error and the single
     * most common thing an agent-tool description contains.
     */
    public function testColonBearingDescriptionRoundTripsWhole(): void
    {
        $parsed = Frontmatter::parse("name: probe\ndescription: Foo: bar baz\n");

        $this->assertSame(['name' => 'probe', 'description' => 'Foo: bar baz'], $parsed);
    }

    /**
     * Single quotes are the wrapper, so an apostrophe in the prose is the one
     * character that can re-break the repaired line. YAML doubles it.
     */
    public function testApostropheInARepairedValueIsEscaped(): void
    {
        $parsed = Frontmatter::parse("description: Edits X: the user's own file\n");

        $this->assertSame(["description" => "Edits X: the user's own file"], $parsed);
    }

    public function testConsecutiveApostrophesInARepairedValueSurvive(): void
    {
        $parsed = Frontmatter::parse("description: Quotes X: ''double'' marks\n");

        $this->assertSame(['description' => "Quotes X: ''double'' marks"], $parsed);
    }

    /**
     * ` #` opens a comment in plain YAML, so a `#` is normally lost from the
     * tail of a description. Inside a REPAIRED line it is inside quotes and
     * therefore kept -- which is what a reader expects and what the strict
     * parse could never give them.
     */
    public function testHashSurvivesInsideARepairedValue(): void
    {
        $parsed = Frontmatter::parse("description: Tags X: use #beta and #rc\n");

        $this->assertSame(['description' => 'Tags X: use #beta and #rc'], $parsed);
    }

    /**
     * Prose that opens with `[` or `{` reads to YAML as a flow collection and
     * fails as one. Repairing it is safe precisely because the discriminator
     * is YAML's own verdict on the line -- a REAL flow collection parses, so
     * it is never reached (see {@see testFlowCollectionsAreNotQuoted()}).
     */
    public function testLeadingBracketProseIsRepaired(): void
    {
        $this->assertSame(
            ['description' => '[beta] Adds a thing'],
            Frontmatter::parse("description: [beta] Adds a thing\n"),
        );
    }

    public function testLeadingBraceProseIsRepaired(): void
    {
        $this->assertSame(
            ['description' => '{draft} Adds a thing'],
            Frontmatter::parse("description: {draft} Adds a thing\n"),
        );
    }

    /**
     * A trailing colon is legal plain YAML on its own, so the repair pass must
     * not touch it even while it is repairing the line below.
     */
    public function testTrailingColonIsValidAndLeftAlone(): void
    {
        $parsed = Frontmatter::parse("hint: Use when the user says add:\ndescription: Does X: and Y\n");

        $this->assertSame(
            ['hint' => 'Use when the user says add:', 'description' => 'Does X: and Y'],
            $parsed,
        );
    }

    public function testColonInsideBackticksIsRepaired(): void
    {
        $this->assertSame(
            ['description' => 'Run `foo: bar` before shipping'],
            Frontmatter::parse("description: Run `foo: bar` before shipping\n"),
        );
    }

    /**
     * Rule one: when strict YAML succeeds, the strict result is what comes
     * back. Lists, nested maps, quoted strings and `true`/`123`/`null` typing
     * all keep Symfony's semantics, byte for byte.
     */
    public function testValidYamlParsesIdenticallyToStrictSymfony(): void
    {
        $block = <<<'YAML'
            name: probe
            description: "A quoted: description"
            maxTurns: 12
            background: true
            model: null
            tools:
              - Read
              - Edit
            paths: [a, b]
            nested:
              inner:
                leaf: 3.5
            YAML;

        $this->assertSame(Yaml::parse($block), Frontmatter::parse($block));
    }

    /**
     * The converse of the two bracket-prose tests, and the reason they are
     * safe. `[beta] Adds a thing` closes its bracket, so the parse fails on
     * the prose after it and quoting recovers what the author wrote. A value
     * that opens a bracket and never closes it is a TRUNCATED COLLECTION, and
     * quoting it would launder a broken list into a plausible string --
     * exactly the fail-closed contract
     * {@see \SugarCraft\Crush\Tests\Commands\CommandLoaderTest::testMalformedFrontmatterIsSkippedWithoutLosingSiblingCommands()}
     * asserts on user-controlled input.
     */
    public function testUnterminatedFlowCollectionsStayHardErrors(): void
    {
        foreach (["tools: [a, b\n", "paths: {a\n", "description: [unclosed\n"] as $block) {
            try {
                Frontmatter::parse($block);
                $this->fail('repaired a truncated flow collection into a string: ' . $block);
            } catch (ParseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFlowCollectionsAreNotQuoted(): void
    {
        $parsed = Frontmatter::parse("paths: [src, tests]\ndescription: Does X: and Y\n");

        $this->assertSame(['src', 'tests'], $parsed['paths']);
    }

    /**
     * A `|` block's body is arbitrary text and routinely contains
     * `key: value` lines. It is not top-level, it is not a mapping, and
     * quoting anything in it would corrupt the prompt an agent preset carries.
     * The sibling broken line is what forces the repair pass to actually run
     * over this block rather than short-circuiting at the strict parse.
     */
    public function testBlockScalarBodyIsNeverRepaired(): void
    {
        $block = "description: Does X: and Y\nprompt: |\n  You are an agent.\n  rule: never do this: ever\n  - not a list item\n";

        $parsed = Frontmatter::parse($block);

        $this->assertSame('Does X: and Y', $parsed['description']);
        $this->assertSame("You are an agent.\nrule: never do this: ever\n- not a list item\n", $parsed['prompt']);
    }

    public function testFoldedBlockScalarWithChompingIndicatorIsNeverRepaired(): void
    {
        $block = "description: Does X: and Y\nprompt: >-\n  first: line\n  second line\n";

        $parsed = Frontmatter::parse($block);

        $this->assertSame('Does X: and Y', $parsed['description']);
        $this->assertSame('first: line second line', $parsed['prompt']);
    }

    public function testTopLevelListItemsAndCommentsAreLeftAlone(): void
    {
        $block = "# a leading comment: with a colon\ndescription: Does X: and Y\ntags:\n  - one\n  - two\n";

        $parsed = Frontmatter::parse($block);

        $this->assertSame('Does X: and Y', $parsed['description']);
        $this->assertSame(['one', 'two'], $parsed['tags']);
    }

    public function testAlreadyQuotedValuesAreNotDoubleQuoted(): void
    {
        $block = "single: 'already: quoted'\ndouble: \"also: quoted\"\ndescription: Does X: and Y\n";

        $this->assertSame(
            ['single' => 'already: quoted', 'double' => 'also: quoted', 'description' => 'Does X: and Y'],
            Frontmatter::parse($block),
        );
    }

    public function testCarriageReturnsSurviveTheRepairPass(): void
    {
        $parsed = Frontmatter::parse("name: probe\r\ndescription: Does X: and Y\r\n");

        $this->assertSame(['name' => 'probe', 'description' => 'Does X: and Y'], $parsed);
    }

    /**
     * `Yaml::parse()` answers null for a block with nothing in it, and several
     * call sites branch on exactly that. The helper does not normalise it away.
     */
    public function testEmptyAndCommentOnlyBlocksStillReturnNull(): void
    {
        $this->assertNull(Frontmatter::parse(''));
        $this->assertNull(Frontmatter::parse("\n  \n"));
        $this->assertNull(Frontmatter::parse("# nothing but a comment: here\n"));
    }

    /**
     * A no-regression pin, not an endorsement: in a line YAML ACCEPTS, ` #`
     * still opens a comment and still truncates the value. Repairing that
     * would change the meaning of files that parse today, which rule one
     * forbids.
     */
    public function testHashStillOpensACommentInAnOtherwiseValidLine(): void
    {
        $this->assertSame(
            Yaml::parse("description: Adds a thing #beta\n"),
            Frontmatter::parse("description: Adds a thing #beta\n"),
        );
        $this->assertSame(['description' => 'Adds a thing'], Frontmatter::parse("description: Adds a thing #beta\n"));
    }

    /**
     * @dataProvider malformedBlocks
     */
    public function testGenuinelyMalformedFrontmatterStillThrows(string $label, string $block): void
    {
        $this->expectException(ParseException::class);

        Frontmatter::parse($block);

        $this->fail($label . ' was accepted');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function malformedBlocks(): iterable
    {
        yield 'inconsistent list indentation' => ['inconsistent list indentation', "name: x\ntools:\n     - a\n   - b\n"];
        yield 'tab indentation' => ['tab indentation', "name: x\nnested:\n\tkey: v\n"];
        yield 'unterminated double quote' => ['unterminated double quote', "name: x\ndescription: \"unterminated\n"];
    }

    /**
     * A colon-bearing plain scalar that CONTINUES onto an indented line is out
     * of scope on purpose: quoting only its first line would sever the
     * continuation, so the line is skipped, nothing is rewritten, and the
     * strict failure stands. Documented here because "we could repair this and
     * chose not to" is invisible from the outside.
     */
    public function testMultiLinePlainScalarWithAColonIsNotRepaired(): void
    {
        $this->expectException(ParseException::class);

        Frontmatter::parse("description: Does X: and Y\n  continued on the next line\n");
    }

    /**
     * The exception a caller sees names the defect the AUTHOR has to fix, not
     * a line number inside the repaired text, which exists only inside the
     * helper.
     */
    public function testTheRethrownExceptionIsTheStrictOne(): void
    {
        $block = "name: x\nnested:\n\tkey: v\n";

        $strict = null;

        try {
            Yaml::parse($block);
        } catch (ParseException $e) {
            $strict = $e->getMessage();
        }

        $this->assertNotNull($strict, 'the fixture is no longer malformed');

        try {
            Frontmatter::parse($block);
            $this->fail('malformed block was accepted');
        } catch (ParseException $e) {
            $this->assertSame($strict, $e->getMessage());
        }
    }
}

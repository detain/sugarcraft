<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers\ToolCallParser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\ToolCallParser\DsmlToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\MarkupScanner;
use SugarCraft\Crush\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;

/**
 * The positional reader both text-scanning tool-call parsers were rebuilt on.
 *
 * The parser-level consequences are tested in each parser's own file; what is
 * pinned here is the MECHANISM, including one property of the source itself.
 */
final class MarkupScannerTest extends TestCase
{
    private const DSML = "\xEF\xBD\x9CDSML\xEF\xBD\x9C";

    // -------------------------------------------------------------------------
    // The property that makes the backtrack-limit cliff structurally
    // impossible rather than merely untriggered by the inputs we thought of.
    // -------------------------------------------------------------------------

    /**
     * A test pinning the ABSENCE of PCRE, because pinning one input size would
     * only prove the cliff had moved.
     *
     * The measured cliff: on the previous DSML implementation, with the
     * default `pcre.backtrack_limit` of 1,000,000, a 900,000-byte parameter
     * value returned the call and a 1,000,000-byte one returned NULL. Fixing
     * the ENVELOPE pattern alone would have relocated the cliff to the invoke
     * and parameter patterns, which is why the whole path is positional.
     *
     * Comments are excluded deliberately - all three files DISCUSS `preg_*` at
     * length, and a naive `str_contains` would fail on the prose that explains
     * why the calls are gone.
     *
     * @dataProvider parserSources
     */
    public function testNoParsePathAnywhereInTheseFilesCallsPcre(string $file): void
    {
        $calls = [];

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && $token[0] === T_STRING && str_starts_with(strtolower($token[1]), 'preg_')) {
                $calls[] = $token[1];
            }
        }

        $this->assertSame([], $calls, basename($file) . ' must not reach PCRE on any parse path');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function parserSources(): array
    {
        $dir = \dirname(__DIR__, 3) . '/src/Providers/ToolCallParser/';

        return [
            'MarkupScanner' => [$dir . 'MarkupScanner.php'],
            'DsmlToolCallParser' => [$dir . 'DsmlToolCallParser.php'],
            'MinimaxXmlFallbackToolCallParser' => [$dir . 'MinimaxXmlFallbackToolCallParser.php'],
        ];
    }

    /**
     * The third parser reads the structured `tool_calls[]` array and has no
     * text-scanning path at all, which is the sentence that makes the bug
     * class legible: BOTH text parsers were vulnerable, and the structured one
     * could not be. Pinned rather than asserted in prose, so a future text
     * fallback added here cannot slip in unguarded.
     */
    public function testTheStructuredParserNeverScansMessageContent(): void
    {
        $source = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/src/Providers/ToolCallParser/OpenAiArrayToolCallParser.php',
        );

        $literals = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = trim($token[1], '"\'');
            }
        }

        $this->assertNotContains('content', $literals);
        $this->assertNull(
            OpenAiArrayToolCallParser::new()->parse(['content' => '<minimax:tool_call><invoke name="rm_rf">'
                . '<parameter name="path">/</parameter></invoke></minimax:tool_call>']),
            'markup in content is not a tool call to a parser that only reads tool_calls[]',
        );
    }

    // -------------------------------------------------------------------------
    // Attribute reading: the B2 mechanism.
    // -------------------------------------------------------------------------

    /**
     * @dataProvider attributeSpellings
     *
     * @param array<string, string> $expected
     */
    public function testAttributesAreReadTheWayAnXmlReaderReadsThem(string $tag, array $expected): void
    {
        $elements = MarkupScanner::new('', false)->elements($tag . 'v</p>', 'p');

        $this->assertCount(1, $elements);
        $this->assertSame($expected, $elements[0]['attributes']);
        $this->assertSame('v', $elements[0]['body']);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function attributeSpellings(): array
    {
        return [
            'double quoted' => ['<p name="a" string="true">', ['name' => 'a', 'string' => 'true']],
            'single quoted' => ["<p name='a' string='true'>", ['name' => 'a', 'string' => 'true']],
            'unquoted' => ['<p name=a string=true>', ['name' => 'a', 'string' => 'true']],
            'mixed quoting' => ['<p name="a" string=\'true\'>', ['name' => 'a', 'string' => 'true']],
            'reordered' => ['<p string="true" name="a">', ['string' => 'true', 'name' => 'a']],
            'ragged whitespace' => ["<p\n  name = \"a\"\tstring=\"true\" >", ['name' => 'a', 'string' => 'true']],
            'valueless attribute' => ['<p name="a" string>', ['name' => 'a', 'string' => '']],
            'no attributes' => ['<p>', []],
        ];
    }

    /**
     * A quoted value may legitimately contain the character that ends an open
     * tag, so the terminator is only recognised outside quotes.
     */
    public function testAGreaterThanInsideAQuotedValueDoesNotEndTheOpenTag(): void
    {
        $elements = MarkupScanner::new('', false)->elements('<p name="a>b">v</p>', 'p');

        $this->assertSame(['name' => 'a>b'], $elements[0]['attributes']);
        $this->assertSame('v', $elements[0]['body']);
    }

    public function testALongerTagIsNotMistakenForTheOneBeingScanned(): void
    {
        $this->assertSame([], MarkupScanner::new('', false)->elements('<parameters name="a">v</parameters>', 'parameter'));
    }

    // -------------------------------------------------------------------------
    // Termination: how an element ended is what the callers' policy turns on.
    // -------------------------------------------------------------------------

    /**
     * @dataProvider terminations
     */
    public function testHowAnElementEndedIsReported(string $content, string $expected, string $body): void
    {
        $elements = MarkupScanner::new('', false)->elements($content, 'p');

        $this->assertSame($expected, $elements[0]['terminator']);
        $this->assertSame($body, $elements[0]['body']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function terminations(): array
    {
        return [
            'closed' => ['<p name="a">v</p>', 'close', 'v'],
            'next sibling opens first' => ['<p name="a">v<p name="b">w</p>', 'sibling', 'v'],
            'content runs out' => ['<p name="a">v', 'eof', 'v'],
            'open tag never ends' => ['<p name="a"', 'open', ''],
        ];
    }

    // -------------------------------------------------------------------------
    // The B1 guard, at the level it is implemented.
    // -------------------------------------------------------------------------

    public function testAFencedEnvelopeIsNotAnActionUnderEitherProtocol(): void
    {
        $fenced = "see:\n\n```\n<e><i/></e>\n```\n";

        $this->assertSame([], MarkupScanner::new('', false)->envelopes($fenced, 'e'));
        $this->assertSame([], MarkupScanner::new('', true)->envelopes($fenced, 'e'));
    }

    /**
     * The asymmetry is the point, and it is evidence-shaped: DeepSeek-V4
     * documents a `\n\n` start token (`enc.py:726`) and MiniMax's XML
     * documents no positional convention at all, so only the former gets the
     * position guard.
     */
    public function testOnlyTheProtocolThatDocumentsAStartTokenGetsThePositionGuard(): void
    {
        $runOn = 'inline mention of <e>body</e>';

        $this->assertCount(1, MarkupScanner::new('', false)->envelopes($runOn, 'e'));
        $this->assertSame([], MarkupScanner::new('', true)->envelopes($runOn, 'e'));
    }

    /**
     * @dataProvider qualifyingPositions
     */
    public function testAPositionThatReadsAsAnActionQualifies(string $content): void
    {
        $this->assertCount(1, MarkupScanner::new('', true)->envelopes($content, 'e'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function qualifyingPositions(): array
    {
        return [
            'at offset zero' => ['<e>body</e>'],
            'after leading whitespace' => ["  \n<e>body</e>"],
            'after the documented blank line' => ["prose here.\n\n<e>body</e>"],
        ];
    }

    /**
     * Markup adjacent to markup already judged an ACTION is a continuation of
     * that action, not a quotation of it - so a second envelope separated from
     * an accepted one by nothing but a newline still qualifies, even though it
     * has no `\n\n` in front of it.
     */
    public function testAnEnvelopeAdjacentToAnAcceptedOneChainsFromIt(): void
    {
        $spans = MarkupScanner::new('', true)->envelopes("<e>one</e>\n<e>two</e>", 'e');

        $this->assertCount(2, $spans);
        $this->assertSame(['one', 'two'], array_column($spans, 'body'));
    }

    /**
     * A rejected marker must not swallow the text after it: the fence that
     * closes the quotation, and any genuine envelope beyond, still have to be
     * walked.
     */
    public function testAQuotedEnvelopeDoesNotHideAGenuineOneAfterIt(): void
    {
        $content = "as in:\n\n```\n<e>quoted</e>\n```\n\n<e>real</e>";

        $spans = MarkupScanner::new('', true)->envelopes($content, 'e');

        $this->assertCount(1, $spans);
        $this->assertSame('real', $spans[0]['body']);
    }

    /**
     * The envelope level deliberately has NO "next sibling opens" rule: a
     * tool call's own payload may quote the envelope marker, and terminating
     * on that would split one call into two.
     */
    public function testAnEnvelopeMarkerInsideAnAcceptedPayloadDoesNotStartASecondEnvelope(): void
    {
        $spans = MarkupScanner::new('', true)->envelopes('<e>a literal <e> in the payload</e>', 'e');

        $this->assertCount(1, $spans);
        $this->assertTrue($spans[0]['closed']);
    }

    public function testAnUnclosedEnvelopeIsReportedAsSuch(): void
    {
        $spans = MarkupScanner::new('', true)->envelopes('<e>body and then nothing', 'e');

        $this->assertCount(1, $spans);
        $this->assertFalse($spans[0]['closed']);
        $this->assertSame('body and then nothing', $spans[0]['body']);
    }

    // -------------------------------------------------------------------------
    // The prefix both parsers configure it with.
    // -------------------------------------------------------------------------

    public function testTheTagPrefixIsHonouredForDsmlsFullwidthPipes(): void
    {
        $d = self::DSML;
        $spans = MarkupScanner::new($d, true)->envelopes("<{$d}tool_calls>x</{$d}tool_calls>", 'tool_calls');

        $this->assertCount(1, $spans);
        $this->assertSame('x', $spans[0]['body']);
    }

    public function testAnAsciiPipeSpellingIsNotDsmlMarkup(): void
    {
        $d = self::DSML;

        $this->assertSame([], MarkupScanner::new($d, true)->envelopes('<|DSML|tool_calls>x</|DSML|tool_calls>', 'tool_calls'));
    }

    public function testBothParsersAreConstructedWithTheGuardTheirProtocolJustifies(): void
    {
        // Asserted through behaviour rather than reflection on a private
        // property: the run-on shape is exactly what the two guards disagree
        // about, so it measures the wiring instead of restating it.
        $runOn = 'reading now ';

        $this->assertNull(DsmlToolCallParser::new()->parse([
            'content' => $runOn . '<' . self::DSML . 'tool_calls><' . self::DSML . 'invoke name="read">'
                . '<' . self::DSML . 'parameter name="path" string="true">/a</' . self::DSML . 'parameter>'
                . '</' . self::DSML . 'invoke></' . self::DSML . 'tool_calls>',
        ]));

        $this->assertNotNull(MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => $runOn . '<minimax:tool_call><invoke name="read">'
                . '<parameter name="path">/a</parameter></invoke></minimax:tool_call>',
        ]));
    }
}

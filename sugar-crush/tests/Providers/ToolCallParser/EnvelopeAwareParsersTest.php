<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers\ToolCallParser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\ToolCallParser\DsmlToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\EnvelopeAware;
use SugarCraft\Crush\Providers\ToolCallParser\MarkupScanner;
use SugarCraft\Crush\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;

/**
 * Audit 15a A8: the two text-scanning parsers report which literals open
 * their envelopes and where the envelopes they recovered calls from sit, so
 * the provider can cut exactly that markup out of the assistant's text.
 */
final class EnvelopeAwareParsersTest extends TestCase
{
    private const DSML = "\xEF\xBD\x9CDSML\xEF\xBD\x9C";

    private string $logFile;

    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        // Refusals are logged; keep them out of the suite's output.
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'envelope-aware-log');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    private static function dsmlEnvelope(string $invokeName = 'read'): string
    {
        $d = self::DSML;

        return "<{$d}tool_calls>\n<{$d}invoke name=\"{$invokeName}\">\n"
            . "<{$d}parameter name=\"path\" string=\"true\">a.php</{$d}parameter>\n"
            . "</{$d}invoke>\n</{$d}tool_calls>";
    }

    private static function minimaxEnvelope(): string
    {
        return "<minimax:tool_call>\n<invoke name=\"read\">\n<parameter name=\"path\">a.php</parameter>\n"
            . "</invoke>\n</minimax:tool_call>";
    }

    public function testTheDefaultParserIsNotEnvelopeAware(): void
    {
        $this->assertNotInstanceOf(EnvelopeAware::class, OpenAiArrayToolCallParser::new());
    }

    public function testEachParserNamesItsOwnMarkerThenItsDelegateChains(): void
    {
        $dsml = '<' . self::DSML . 'tool_calls';

        $this->assertSame([$dsml], DsmlToolCallParser::new()->envelopeMarkers());
        $this->assertSame(['<minimax:tool_call'], MinimaxXmlFallbackToolCallParser::new()->envelopeMarkers());
        $this->assertSame(
            [$dsml, '<minimax:tool_call'],
            DsmlToolCallParser::new(MinimaxXmlFallbackToolCallParser::new())->envelopeMarkers(),
        );
    }

    public function testDsmlRecoverySpansTheEnvelope(): void
    {
        $prose = "Let me read it.\n\n";
        $content = $prose . self::dsmlEnvelope();

        $recovery = DsmlToolCallParser::new()->recover(['content' => $content]);

        $this->assertCount(1, $recovery->calls() ?? []);
        $this->assertSame([[\strlen($prose), \strlen($content)]], $recovery->spans());
        $this->assertSame('Let me read it.', $recovery->contentWithoutEnvelopes($content));
    }

    public function testMinimaxRecoverySpansTheEnvelope(): void
    {
        $prose = "Reading.\n";
        $content = $prose . self::minimaxEnvelope() . "\nAfter.";

        $recovery = MinimaxXmlFallbackToolCallParser::new()->recover(['content' => $content]);

        $this->assertCount(1, $recovery->calls() ?? []);
        $this->assertSame([[\strlen($prose), \strlen($prose . self::minimaxEnvelope())]], $recovery->spans());
        $this->assertSame("Reading.\nAfter.", $recovery->contentWithoutEnvelopes($content));
    }

    /**
     * parse() is recover()'s calls, so the two can never disagree.
     */
    public function testParseReturnsTheSameCallsAsRecover(): void
    {
        $content = "x\n\n" . self::dsmlEnvelope();
        $parser = DsmlToolCallParser::new();

        $parsed = $parser->parse(['content' => $content]);
        $recovered = $parser->recover(['content' => $content])->calls();

        $this->assertNotNull($parsed);
        $this->assertEquals($parsed, $recovered);
    }

    /**
     * A stacked chain hands back the spans of whichever parser produced the
     * calls - here the inner MiniMax one, since the content holds no DSML.
     */
    public function testAChainReturnsTheDelegatesSpansWhenTheDelegateRecovered(): void
    {
        $content = self::minimaxEnvelope();

        $recovery = DsmlToolCallParser::new(MinimaxXmlFallbackToolCallParser::new())->recover(['content' => $content]);

        $this->assertSame('minimax_xml_call_0', ($recovery->calls() ?? [])[0]->id());
        $this->assertSame([[0, \strlen($content)]], $recovery->spans());
    }

    /**
     * An envelope whose every invoke was refused produced no call, so its
     * markup is not listed: it stays visible next to the notice that says the
     * call was dropped.
     */
    public function testARefusedEnvelopeIsNotSpanned(): void
    {
        $content = "Good.\n\n" . self::dsmlEnvelope() . "\n\n" . self::dsmlEnvelope('');

        $recovery = DsmlToolCallParser::new()->recover(['content' => $content]);

        $this->assertCount(1, $recovery->calls() ?? []);
        $this->assertCount(1, $recovery->spans());
        $this->assertStringContainsString('invoke name=""', $recovery->contentWithoutEnvelopes($content));
    }

    public function testNoRecoveryMeansNoSpans(): void
    {
        $fenced = "Like this:\n\n```\n" . self::dsmlEnvelope() . "\n```";

        foreach ([
            DsmlToolCallParser::new()->recover(['content' => $fenced]),
            MinimaxXmlFallbackToolCallParser::new()->recover(['content' => 'no markup at all']),
            DsmlToolCallParser::new()->recover(['content' => self::dsmlEnvelope(), 'tool_calls' => []]),
        ] as $recovery) {
            $this->assertSame([], $recovery->spans());
        }
    }

    public function testTheScannerReportsWhereEachEnvelopeEnds(): void
    {
        $scanner = MarkupScanner::new('', false);

        $closed = $scanner->envelopes('ab<e>body</e>cd', 'e');
        $unclosed = $scanner->envelopes('ab<e>body', 'e');

        $this->assertSame(2, $closed[0]['offset']);
        $this->assertSame(\strlen('ab<e>body</e>'), $closed[0]['end']);
        $this->assertSame(\strlen('ab<e>body'), $unclosed[0]['end']);
    }
}

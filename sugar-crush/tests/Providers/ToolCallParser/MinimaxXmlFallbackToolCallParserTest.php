<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers\ToolCallParser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\ToolCallParserInterface;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * W1.A5 (crush_feat.md §12 D6): the last-resort raw-XML strategy for a server
 * launched without `--tool-call-parser minimax-m2`. Every XML case below is a
 * tool call that the OpenAI array parser drops on the floor entirely.
 */
final class MinimaxXmlFallbackToolCallParserTest extends TestCase
{
    private string $logFile = '';

    /** @var string|false */
    private $previousErrorLog = false;

    protected function setUp(): void
    {
        // Same error_log capture pattern as SglangProviderTruncationGuardTest:
        // the truncation warnings below are the observable half of §12 D5.
        $this->logFile = sys_get_temp_dir() . '/minimax-xml-' . uniqid('', true) . '.log';
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);
    }

    private function capturedLog(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    public function testNewReturnsInstanceOfTheInterface(): void
    {
        $this->assertInstanceOf(ToolCallParserInterface::class, MinimaxXmlFallbackToolCallParser::new());
    }

    public function testServerParsedToolCallsTakePrecedenceOverAnyXmlInContent(): void
    {
        $calls = MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => '<minimax:tool_call><invoke name="ignored"></invoke></minimax:tool_call>',
            'tool_calls' => [
                ['id' => 'call_1', 'function' => ['name' => 'read', 'arguments' => '{"path":"/tmp/a"}']],
            ],
        ]);

        $this->assertNotNull($calls);
        $this->assertCount(1, $calls);
        $this->assertSame('read', $calls[0]->name());
        $this->assertSame('call_1', $calls[0]->id());
    }

    public function testPlainContentWithoutTheEnvelopeMarkerIsDelegated(): void
    {
        $this->assertNull(MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => 'I could call a tool here, but I will just talk about <invoke name="x"> instead.',
        ]));
    }

    public function testRawXmlToolCallIsRecoveredWhenToolCallsIsAbsent(): void
    {
        $calls = MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => "Sure.\n<minimax:tool_call>\n<invoke name=\"read\">\n"
                . "<parameter name=\"path\">/etc/hosts</parameter>\n"
                . "</invoke>\n</minimax:tool_call>",
        ]);

        // Against the plain OpenAI array parser this message yields null and
        // the tool call is lost; that is the failure mode D6 defends against.
        $this->assertNull(OpenAiArrayToolCallParser::new()->parse(['content' => 'x']));

        $this->assertNotNull($calls);
        $this->assertCount(1, $calls);
        $this->assertInstanceOf(ToolCall::class, $calls[0]);
        $this->assertSame('read', $calls[0]->name());
        $this->assertSame(['path' => '/etc/hosts'], $calls[0]->arguments());
    }

    public function testSynthesisedIdsArePositionalAndDistinct(): void
    {
        $calls = MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => '<minimax:tool_call>'
                . '<invoke name="a"></invoke>'
                . '<invoke name="b"></invoke>'
                . '</minimax:tool_call>',
        ]);

        $this->assertNotNull($calls);
        $this->assertSame(
            ['minimax_xml_call_0', 'minimax_xml_call_1'],
            array_map(static fn (ToolCall $c): string => $c->id(), $calls),
        );
    }

    public function testParametersAreScopedToTheirOwnInvoke(): void
    {
        $calls = MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => '<minimax:tool_call>'
                . '<invoke name="first"><parameter name="p">1</parameter></invoke>'
                . '<invoke name="second"><parameter name="q">2</parameter></invoke>'
                . '</minimax:tool_call>',
        ]);

        $this->assertNotNull($calls);
        $this->assertSame(['p' => '1'], $calls[0]->arguments());
        $this->assertSame(['q' => '2'], $calls[1]->arguments());
    }

    public function testMultipleEnvelopesAreAllParsed(): void
    {
        $calls = MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => '<minimax:tool_call><invoke name="a"></invoke></minimax:tool_call>'
                . 'and then '
                . '<minimax:tool_call><invoke name="b"></invoke></minimax:tool_call>',
        ]);

        $this->assertNotNull($calls);
        $this->assertSame(['a', 'b'], array_map(static fn (ToolCall $c): string => $c->name(), $calls));
    }

    public function testOnlyArrayShapedParameterValuesAreDecoded(): void
    {
        $calls = MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => '<minimax:tool_call><invoke name="grep">'
                . '<parameter name="opts">{"i":true}</parameter>'
                . '<parameter name="globs">["*.php","*.md"]</parameter>'
                . '<parameter name="limit">5</parameter>'
                . '<parameter name="recursive">true</parameter>'
                . '</invoke></minimax:tool_call>',
        ]);

        $this->assertNotNull($calls);
        $this->assertSame(
            [
                'opts' => ['i' => true],
                'globs' => ['*.php', '*.md'],
                // Scalars keep their text: the raw XML can express those
                // perfectly well already, so a JSON-shaped scalar is ambiguous
                // rather than informative.
                'limit' => '5',
                'recursive' => 'true',
            ],
            $calls[0]->arguments(),
        );
    }

    /**
     * A string-typed argument whose text happens to be JSON-shaped must not
     * change PHP type: `Edit` calls `substr_count($content, $oldString)` under
     * `declare(strict_types=1)`, so an int there is an uncaught TypeError that
     * kills the tool loop instead of an isError ToolResult.
     */
    public function testJsonShapedScalarsKeepTheirStringType(): void
    {
        $calls = MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => '<minimax:tool_call><invoke name="edit">'
                . '<parameter name="old_string">1</parameter>'
                . '<parameter name="new_string">null</parameter>'
                . '<parameter name="ratio">1.5</parameter>'
                . '</invoke></minimax:tool_call>',
        ]);

        $this->assertNotNull($calls);
        $this->assertSame(
            ['old_string' => '1', 'new_string' => 'null', 'ratio' => '1.5'],
            $calls[0]->arguments(),
        );
    }

    public function testNonJsonParameterValuesStayStrings(): void
    {
        $calls = MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => '<minimax:tool_call><invoke name="bash">'
                . '<parameter name="command">ls -la /tmp</parameter>'
                . '</invoke></minimax:tool_call>',
        ]);

        $this->assertNotNull($calls);
        $this->assertSame(['command' => 'ls -la /tmp'], $calls[0]->arguments());
    }

    public function testOnlyTheFramingNewlinesAreStrippedFromAMultiLineValue(): void
    {
        $calls = MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => "<minimax:tool_call><invoke name=\"write\">"
                . "<parameter name=\"content\">\nline one\n    indented\n</parameter>"
                . '</invoke></minimax:tool_call>',
        ]);

        $this->assertNotNull($calls);
        $this->assertSame("line one\n    indented", $calls[0]->arguments()['content']);
    }

    public function testTruncatedEnvelopeStillRecoversTheInvokeItCarried(): void
    {
        // The §12 D5 `</parameter>` bug cuts the envelope short; recovering the
        // call beats discarding the turn.
        $calls = MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => '<minimax:tool_call><invoke name="read">'
                . '<parameter name="path">/tmp/a</parameter>',
        ]);

        $this->assertNotNull($calls);
        $this->assertSame('read', $calls[0]->name());
        $this->assertSame(['path' => '/tmp/a'], $calls[0]->arguments());
        $this->assertStringContainsString('possible MiniMax XML-delimiter truncation', $this->capturedLog());
    }

    /**
     * The mixed case: one well-formed envelope followed by a truncated one.
     * Complete-envelope matches are non-zero here, so a recovery branch gated
     * on "zero matches" skips it and silently drops the trailing call.
     */
    public function testCompleteEnvelopeFollowedByATruncatedOneRecoversBoth(): void
    {
        $calls = MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => '<minimax:tool_call><invoke name="read">'
                . '<parameter name="path">/tmp/a</parameter></invoke></minimax:tool_call>'
                . 'then '
                . '<minimax:tool_call><invoke name="write">'
                . '<parameter name="path">/tmp/b</parameter>',
        ]);

        $this->assertNotNull($calls);
        $this->assertSame(['read', 'write'], array_map(static fn (ToolCall $c): string => $c->name(), $calls));
        $this->assertSame(['path' => '/tmp/b'], $calls[1]->arguments());
        $this->assertStringContainsString('possible MiniMax XML-delimiter truncation', $this->capturedLog());
    }

    /**
     * A marker that merely sits inside an already-consumed envelope body must
     * not be re-scanned, or the recovery path would duplicate tool calls.
     */
    public function testMarkerInsideAConsumedEnvelopeDoesNotDuplicateCalls(): void
    {
        $calls = MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => '<minimax:tool_call><invoke name="echo">'
                . '<parameter name="text">literal <minimax:tool_call> in the payload</parameter>'
                . '</invoke></minimax:tool_call>',
        ]);

        $this->assertNotNull($calls);
        $this->assertCount(1, $calls);
        $this->assertSame('echo', $calls[0]->name());
        $this->assertStringNotContainsString('truncation', $this->capturedLog());
    }

    public function testWellFormedContentLogsNoTruncationWarning(): void
    {
        MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => '<minimax:tool_call><invoke name="a"></invoke></minimax:tool_call>',
        ]);

        $this->assertSame('', $this->capturedLog());
    }

    /**
     * `preg_match_all` returning false (backtrack limit exhausted on a very
     * large content) is a different animal from returning 0, and D5 wants it
     * observable instead of an indistinguishable silent null.
     */
    public function testPcreScanFailureIsReportedRatherThanReturningNullSilently(): void
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '2');

        try {
            $calls = MinimaxXmlFallbackToolCallParser::new()->parse([
                'content' => '<minimax:tool_call><invoke name="read">'
                    . '<parameter name="path">' . str_repeat('a', 5000) . '</parameter>'
                    . '</invoke></minimax:tool_call>',
            ]);
        } finally {
            ini_set('pcre.backtrack_limit', $previous === false ? '1000000' : $previous);
        }

        $this->assertNull($calls);
        $this->assertStringContainsString('possible MiniMax XML-delimiter truncation', $this->capturedLog());
        $this->assertStringContainsString('PCRE scan', $this->capturedLog());
    }

    public function testEnvelopeWithNoRecognisableInvokeReturnsNull(): void
    {
        $this->assertNull(MinimaxXmlFallbackToolCallParser::new()->parse([
            'content' => '<minimax:tool_call>nothing useful here</minimax:tool_call>',
        ]));
    }

    public function testNonStringContentIsDelegated(): void
    {
        $this->assertNull(MinimaxXmlFallbackToolCallParser::new()->parse(['content' => null]));
    }

    public function testCustomDelegateIsUsedForTheServerParsedPath(): void
    {
        $delegate = new class implements ToolCallParserInterface {
            public function parse(array $message): ?array
            {
                return [new ToolCall('delegated', 'sentinel', [])];
            }
        };

        $calls = MinimaxXmlFallbackToolCallParser::new($delegate)->parse(['tool_calls' => []]);

        $this->assertNotNull($calls);
        $this->assertSame('sentinel', $calls[0]->name());
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\ToolCallParser\DsmlToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\ToolCallParserInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit 15a A8: a tool call recovered from DSML / MiniMax-XML markup in the
 * streamed text used to leave that markup in the text. It was painted to the
 * user as it streamed, stored as the assistant message's content next to the
 * recovered `tool_calls`, and so sent to the model twice on the next request -
 * once as text, once as the structured call the chat template renders back
 * into the same markup.
 *
 * {@see SglangProvider::completeStream()} now holds text back from the first
 * byte that could open an envelope, cuts a recovered envelope out, and
 * releases anything else unchanged at the end of the stream.
 */
final class SglangProviderEnvelopeHoldBackTest extends TestCase
{
    private const DSML = "\xEF\xBD\x9CDSML\xEF\xBD\x9C";

    private const MODEL = 'deepseek-ai/DeepSeek-V4-Flash-0731';

    private string $logFile;

    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        // The parsers log refusals and quoted markers; keep them off the
        // suite's output.
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'a8-holdback-log');
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

    private static function dsmlEnvelope(): string
    {
        $d = self::DSML;

        return "<{$d}tool_calls>\n<{$d}invoke name=\"read\">\n"
            . "<{$d}parameter name=\"path\" string=\"true\">a.php</{$d}parameter>\n"
            . "</{$d}invoke>\n</{$d}tool_calls>";
    }

    private static function minimaxEnvelope(): string
    {
        return "<minimax:tool_call>\n<invoke name=\"read\">\n<parameter name=\"path\">a.php</parameter>\n"
            . "</invoke>\n</minimax:tool_call>";
    }

    /**
     * @param list<string> $fragments
     * @param list<array<string, mixed>> $extraFrames raw `choices[0]` objects appended before the finish frame
     */
    private static function sse(array $fragments, string $finishReason = 'stop', array $extraFrames = []): string
    {
        $body = '';

        foreach ($fragments as $fragment) {
            $body .= 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => $fragment], 'finish_reason' => null]]]) . "\n\n";
        }

        foreach ($extraFrames as $choice) {
            $body .= 'data: ' . json_encode(['choices' => [$choice]]) . "\n\n";
        }

        return $body
            . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => $finishReason]]]) . "\n\n"
            . "data: [DONE]\n\n";
    }

    /**
     * @param list<Response> $responses
     * @param array<int, array<string, mixed>> $history filled with every request sent
     */
    private static function provider(array $responses, ToolCallParserInterface $parser, array &$history = []): SglangProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new SglangProvider(
            'http://sglang.test',
            self::MODEL,
            null,
            new Client(['handler' => $stack, 'base_uri' => 'http://sglang.test/']),
            $parser,
        );
    }

    /**
     * @param list<string> $fragments
     * @return list<CompleteResponse>
     */
    private static function stream(array $fragments, ToolCallParserInterface $parser, string $finishReason = 'stop', array $extraFrames = []): array
    {
        $provider = self::provider([new Response(200, [], self::sse($fragments, $finishReason, $extraFrames))], $parser);

        return iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::MODEL,
            messages: [new UserMessage('read a.php')],
        )), false);
    }

    /**
     * @param list<CompleteResponse> $chunks
     */
    private static function text(array $chunks): string
    {
        return implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $chunks));
    }

    /**
     * @param list<CompleteResponse> $chunks
     * @return list<\SugarCraft\Crush\Tools\ToolCall>
     */
    private static function calls(array $chunks): array
    {
        $calls = [];

        foreach ($chunks as $chunk) {
            foreach ($chunk->toolCalls ?? [] as $call) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    // -------------------------------------------------------------------------
    // The audit's scenario, end to end through Runtime.
    // -------------------------------------------------------------------------

    /**
     * What the user sees, what the transcript keeps, and what the model is
     * sent next: the prose once, the call once, the markup never.
     */
    public function testARecoveredDsmlCallIsNeitherPaintedNorReplayedAsText(): void
    {
        $envelope = self::dsmlEnvelope();
        $history = [];
        $provider = self::provider([
            new Response(200, [], self::sse(array_merge(['Let me read it.', "\n\n"], mb_str_split($envelope, 7)))),
            new Response(200, [], self::sse(['Done.'])),
        ], DsmlToolCallParser::new(), $history);

        $read = new class implements Tool {
            public function name(): string { return 'read'; }
            public function description(): string { return 'reads a file'; }
            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]];
            }
            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: 'read', content: 'contents of ' . ($args['path'] ?? '?'));
            }
        };

        $painted = '';
        $paint = static function (string $token) use (&$painted): void {
            $painted .= $token;
        };
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $app = App::new($provider, self::MODEL)->withTools([$read]);
        $transcript = [new UserMessage('read a.php')];

        // Runtime::run() is one step - the assistant turn plus its tool
        // results; the caller drives the loop, so the second step is run
        // here on the transcript the first one produced.
        $results = iterator_to_array($runtime->run($app->withMessages($transcript), null, null, $paint), false);
        iterator_to_array($runtime->run($app->withMessages([...$transcript, ...$results]), null, null, $paint), false);

        $this->assertStringNotContainsString(self::DSML, $painted, 'no markup reaches the screen');
        $this->assertSame('Let me read it.Done.', $painted);

        $assistant = array_values(array_filter($results, static fn (mixed $m): bool => $m instanceof AssistantMessage));
        $this->assertSame('Let me read it.', $assistant[0]->content(), 'the transcript keeps the prose only');
        $this->assertCount(1, $assistant[0]->toolCalls() ?? []);

        $this->assertCount(2, $history, 'the tool ran and the turn continued');
        $replayed = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertStringNotContainsString(self::DSML, json_encode($replayed['messages'], JSON_UNESCAPED_UNICODE));

        $assistantRows = array_values(array_filter($replayed['messages'], static fn (array $m): bool => $m['role'] === 'assistant'));
        $this->assertCount(1, $assistantRows);
        $this->assertSame('Let me read it.', $assistantRows[0]['content']);
        $this->assertCount(1, $assistantRows[0]['tool_calls'], 'the call reaches the wire exactly once');
        $this->assertSame('read', $assistantRows[0]['tool_calls'][0]['function']['name']);
    }

    // -------------------------------------------------------------------------
    // The provider seam.
    // -------------------------------------------------------------------------

    public function testAMinimaxEnvelopeIsCutTheSameWay(): void
    {
        $chunks = self::stream(["I'll look.\n", self::minimaxEnvelope()], MinimaxXmlFallbackToolCallParser::new());

        $this->assertSame("I'll look.", self::text($chunks));
        $this->assertCount(1, self::calls($chunks));
        $this->assertStringNotContainsString('minimax:tool_call', self::text($chunks));
    }

    /**
     * Prose the model writes after its call was held with the envelope; it
     * rides out on the recovery chunk once the envelope is cut.
     */
    public function testProseAfterTheEnvelopeSurvives(): void
    {
        $chunks = self::stream(
            ["I'll read it.\n\n", self::dsmlEnvelope(), "\n\nThen I'll summarise."],
            DsmlToolCallParser::new(),
        );

        $this->assertSame("I'll read it.\n\nThen I'll summarise.", self::text($chunks));
        $recovery = array_values(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->toolCalls !== null));
        $this->assertCount(1, $recovery);
        $this->assertSame("\n\nThen I'll summarise.", $recovery[0]->content);
    }

    /**
     * The marker split mid-token across two deltas: the first delta's partial
     * marker is never painted.
     */
    public function testAMarkerSplitAcrossDeltasIsHeldFromItsFirstByte(): void
    {
        $envelope = self::dsmlEnvelope();
        $splitAt = \strlen('<' . "\xEF\xBD\x9C" . 'DS');

        $chunks = self::stream(
            ["Let me read it.\n\n" . substr($envelope, 0, $splitAt), substr($envelope, $splitAt)],
            DsmlToolCallParser::new(),
        );

        $this->assertSame('Let me read it.', $chunks[0]->content);
        $this->assertSame('Let me read it.', self::text($chunks));
        $this->assertCount(1, self::calls($chunks));
    }

    /**
     * Prose that only QUOTES the markup recovers nothing, so everything held
     * is released at the end, byte for byte.
     */
    public function testQuotedMarkupIsReleasedVerbatim(): void
    {
        $fragments = ["The format is:\n\n```\n", self::dsmlEnvelope(), "\n```\n\nThat is all."];

        $chunks = self::stream($fragments, DsmlToolCallParser::new());

        $this->assertSame(implode('', $fragments), self::text($chunks));
        $this->assertSame([], self::calls($chunks));
    }

    public function testARunOnEnvelopeThatIsNotRecoveredIsReleasedVerbatim(): void
    {
        $fragments = ['Let me read that.', self::dsmlEnvelope()];

        $chunks = self::stream($fragments, DsmlToolCallParser::new());

        $this->assertSame(implode('', $fragments), self::text($chunks));
        $this->assertSame([], self::calls($chunks));
    }

    /**
     * A `<` that opened nothing is held for one chunk, then released in order.
     */
    public function testAHeldAngleBracketIsReleasedByTheNextChunk(): void
    {
        $chunks = self::stream(['use a <', 'div> here'], DsmlToolCallParser::new());

        $this->assertSame('use a ', $chunks[0]->content);
        $this->assertSame('<div> here', $chunks[1]->content);
        $this->assertSame('use a <div> here', self::text($chunks));
    }

    /**
     * Once the server's structured `tool_calls` arrive, nothing in the text
     * can be recovered, so the held text is released at once - in order,
     * ahead of that chunk's own text.
     */
    public function testAStructuredCallReleasesWhatWasHeld(): void
    {
        $chunks = self::stream(
            ["Calling.\n\n"],
            DsmlToolCallParser::new(),
            'tool_calls',
            [[
                'index' => 0,
                'delta' => ['content' => 'x', 'tool_calls' => [[
                    'index' => 0,
                    'id' => 'call_1',
                    'function' => ['name' => 'read', 'arguments' => '{"path":"a.php"}'],
                ]]],
                'finish_reason' => 'tool_calls',
            ]],
        );

        $this->assertSame('Calling.', $chunks[0]->content);
        $this->assertSame("\n\nx", $chunks[1]->content);
        $this->assertSame("Calling.\n\nx", self::text($chunks));
        $this->assertSame(['call_1'], array_map(static fn ($c): string => $c->id(), self::calls($chunks)));
    }

    /**
     * The default parser reads no text, so its stream is not held at all:
     * every fragment, trailing blank lines and markup included, is yielded
     * as it arrived.
     */
    public function testTheOpenAiParserHoldsNothingBack(): void
    {
        $fragments = ["Let me read it.\n\n", '<', self::dsmlEnvelope()];

        $chunks = self::stream($fragments, OpenAiArrayToolCallParser::new());

        $this->assertSame($fragments, array_slice(array_map(static fn (CompleteResponse $c): string => $c->content, $chunks), 0, 3));
    }

    /**
     * Batch half: complete() cuts the recovered envelope out of the message
     * content before the response is built.
     */
    public function testTheBatchPathCutsTheEnvelopeToo(): void
    {
        $content = "Let me read it.\n\n" . self::dsmlEnvelope();
        $provider = self::provider([new Response(200, [], (string) json_encode([
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        ]))], DsmlToolCallParser::new());

        $response = $provider->complete(new CompleteRequest(model: self::MODEL, messages: [new UserMessage('read a.php')]));

        $this->assertSame('Let me read it.', $response->content);
        $this->assertCount(1, $response->toolCalls ?? []);
    }

    public function testTheBatchPathLeavesUnrecoveredMarkupAlone(): void
    {
        $content = "The format is:\n\n```\n" . self::dsmlEnvelope() . "\n```";
        $provider = self::provider([new Response(200, [], (string) json_encode([
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        ]))], DsmlToolCallParser::new());

        $response = $provider->complete(new CompleteRequest(model: self::MODEL, messages: [new UserMessage('how?')]));

        $this->assertSame($content, $response->content);
        $this->assertNull($response->toolCalls);
    }
}

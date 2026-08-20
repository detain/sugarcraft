<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\ToolCallParser\DsmlToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;

/**
 * Closes the §12 D2 gap on the path that actually matters.
 *
 * {@see SglangProvider::supportsStreaming()} returns true and both production
 * consumers branch on it, so the live TUI chat loop takes `completeStream()`.
 * Until {@see SglangProvider::recoverTextualToolCalls()} existed, the injected
 * tool-call parser was consulted ONLY by the non-streaming
 * `parseResponse()` - so selecting a text-scanning fallback armed it on the
 * one path nobody takes, and a DSML tool call in the live chat was still lost
 * in silence.
 *
 * THE DECISIVE CASE is
 * {@see testADsmlEnvelopeSplitAcrossChunkBoundariesIsRecovered}: the envelope
 * is deliberately cut mid-token across two SSE deltas, which is exactly what
 * no per-chunk parser can see and what makes the reassembly seam necessary
 * rather than merely convenient.
 */
final class SglangProviderDsmlStreamingTest extends TestCase
{
    private const DSML = "\xEF\xBD\x9CDSML\xEF\xBD\x9C";

    /**
     * A complete, well-formed DSML envelope requesting `read('/etc/hosts', 3)`.
     */
    private static function envelope(): string
    {
        $d = self::DSML;

        return "<{$d}tool_calls>\n<{$d}invoke name=\"read\">\n"
            . "<{$d}parameter name=\"path\" string=\"true\">/etc/hosts</{$d}parameter>\n"
            . "<{$d}parameter name=\"limit\" string=\"false\">3</{$d}parameter>\n"
            . "</{$d}invoke>\n</{$d}tool_calls>";
    }

    /**
     * Builds an SSE body whose successive `data:` frames carry the given
     * content fragments, then a terminal frame carrying `finish_reason`.
     *
     * @param array<int, string> $contentFragments
     */
    private static function sseBody(array $contentFragments, string $finishReason = 'stop'): string
    {
        $lines = '';

        foreach ($contentFragments as $fragment) {
            $lines .= 'data: ' . json_encode([
                'choices' => [['delta' => ['content' => $fragment]]],
            ]) . "\n";
        }

        $lines .= 'data: ' . json_encode([
            'choices' => [['delta' => ['content' => ''], 'finish_reason' => $finishReason]],
        ]) . "\n";

        return $lines;
    }

    private function providerStreaming(string $body, string $model, mixed $parser): SglangProvider
    {
        $stream = $this->getMockBuilder(Stream::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['eof', 'read'])
            ->getMock();
        $stream->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $stream->method('read')->willReturn($body);

        $client = $this->createMock(Client::class);
        $client->method('post')->willReturn(new Response(200, [], $stream));

        return new SglangProvider('https://api.example.com', $model, null, $client, $parser);
    }

    /**
     * @return array<int, \SugarCraft\Crush\Providers\CompleteResponse>
     */
    private function drain(SglangProvider $provider): array
    {
        return iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'deepseek-ai/DeepSeek-V4-Flash-0731',
            messages: [new UserMessage('read /etc/hosts')],
        )), false);
    }

    // -------------------------------------------------------------------------

    /**
     * THE DELIVERABLE for Part B.
     *
     * The envelope is split so that the cut falls INSIDE the `｜DSML｜invoke`
     * token - one delta ends mid-multi-byte-tag and the next completes it. No
     * per-chunk parser can recover this; only the reassembled content can.
     */
    public function testADsmlEnvelopeSplitAcrossChunkBoundariesIsRecovered(): void
    {
        $envelope = self::envelope();
        $cut = (int) (strlen($envelope) / 2);

        $provider = $this->providerStreaming(
            self::sseBody(["Let me read that.\n\n", substr($envelope, 0, $cut), substr($envelope, $cut)]),
            'deepseek-ai/DeepSeek-V4-Flash-0731',
            DsmlToolCallParser::new(),
        );

        $chunks = $this->drain($provider);

        $withCalls = array_values(array_filter($chunks, static fn ($c): bool => $c->toolCalls !== null));

        $this->assertCount(1, $withCalls, 'exactly one chunk carries the recovered call');
        $this->assertCount(1, $withCalls[0]->toolCalls);
        $this->assertSame('read', $withCalls[0]->toolCalls[0]->name());
        $this->assertSame('/etc/hosts', $withCalls[0]->toolCalls[0]->arguments()['path']);
        $this->assertSame(
            3,
            $withCalls[0]->toolCalls[0]->arguments()['limit'],
            'the string="false" flag survives reassembly, so the tool gets an int',
        );
    }

    /**
     * The regression this whole bundle exists to prevent: with the OpenAI-only
     * parser - today's behaviour before the DSML work - the identical stream
     * yields no tool call whatsoever. The agent silently does nothing.
     */
    public function testTheSameStreamYieldsNoToolCallUnderTheOpenAiOnlyParser(): void
    {
        $provider = $this->providerStreaming(
            self::sseBody(["Let me read that.\n\n", self::envelope()]),
            'deepseek-ai/DeepSeek-V4-Flash-0731',
            OpenAiArrayToolCallParser::new(),
        );

        foreach ($this->drain($provider) as $chunk) {
            $this->assertNull($chunk->toolCalls);
        }
    }

    /**
     * The streamed-token UX must be untouched: every content chunk is still
     * yielded, in wire order, byte-for-byte, and the recovery chunk adds no
     * content of its own (Runtime::runStreaming() appends every chunk's
     * content to the transcript buffer, so any content here would duplicate
     * the turn).
     */
    public function testContentStillStreamsIncrementallyAndTheRecoveryChunkAddsNone(): void
    {
        $fragments = ['Let me ', "read that.\n\n", self::envelope()];

        $provider = $this->providerStreaming(
            self::sseBody($fragments),
            'deepseek-ai/DeepSeek-V4-Flash-0731',
            DsmlToolCallParser::new(),
        );

        $chunks = $this->drain($provider);
        $streamed = array_map(static fn ($c): string => $c->content, $chunks);

        // Every fragment arrives as its own chunk, in order.
        $this->assertSame($fragments, array_slice($streamed, 0, 3));

        $recovery = $chunks[array_key_last($chunks)];
        $this->assertNotNull($recovery->toolCalls);
        $this->assertSame('', $recovery->content, 'the recovery chunk must not repeat the turn');
        $this->assertSame(0, $recovery->tokensUsed, 'and must not perturb the usage total');
        $this->assertSame(0.0, $recovery->costUsd);
    }

    /**
     * NO DOUBLE-EMISSION. When the server DID decode the call (the normal,
     * correctly-configured case), the structured `delta.tool_calls[]` result
     * stands alone - even though the content also happens to carry a DSML
     * envelope the scan could have matched.
     */
    public function testAStructuredStreamedToolCallIsNotDuplicatedByTheTextScan(): void
    {
        $body = 'data: ' . json_encode([
            'choices' => [['delta' => ['content' => self::envelope()]]],
        ]) . "\n"
            . 'data: ' . json_encode([
                'choices' => [[
                    'delta' => ['tool_calls' => [[
                        'index' => 0,
                        'id' => 'call_server_decoded',
                        'function' => ['name' => 'read', 'arguments' => '{"path":"/etc/hosts"}'],
                    ]]],
                    'finish_reason' => 'tool_calls',
                ]],
            ]) . "\n";

        $provider = $this->providerStreaming(
            $body,
            'deepseek-ai/DeepSeek-V4-Flash-0731',
            DsmlToolCallParser::new(),
        );

        $calls = [];

        foreach ($this->drain($provider) as $chunk) {
            foreach ($chunk->toolCalls ?? [] as $call) {
                $calls[] = $call;
            }
        }

        $this->assertCount(1, $calls, 'the structured path already produced it; the scan must stay out');
        $this->assertSame('call_server_decoded', $calls[0]->id());
    }

    /**
     * A plain prose turn produces no extra chunk at all, so a deployment on
     * the default parser sees no behavioural change.
     */
    public function testAProseOnlyStreamGainsNoExtraChunk(): void
    {
        $provider = $this->providerStreaming(
            self::sseBody(['Hello', ' world']),
            'deepseek-ai/DeepSeek-V4-Flash-0731',
            DsmlToolCallParser::new(),
        );

        $chunks = $this->drain($provider);

        $this->assertCount(3, $chunks, 'two content frames plus the terminal finish_reason frame');

        foreach ($chunks as $chunk) {
            $this->assertNull($chunk->toolCalls);
        }
    }

    /**
     * END TO END through the factory, with NO `toolCallParser` key: the
     * DeepSeek-V4 default model must arm DSML by itself. This is the
     * assertion that would catch the parser being correct but never selected.
     */
    public function testTheFactoryDefaultArmsDsmlRecoveryOnTheStreamingPath(): void
    {
        $factory = new ProviderFactory();
        $config = $factory->defaultConfig('sglang');

        $this->assertNull($config['toolCallParser'], 'no parser is named in the default config');

        $parser = (new \ReflectionProperty(SglangProvider::class, 'toolCallParser'))
            ->getValue($factory->create($config));

        $this->assertInstanceOf(
            DsmlToolCallParser::class,
            $parser,
            'the sglang default model is DeepSeek-V4, so DSML must be the derived default',
        );

        // And it actually recovers, rather than merely being the right class.
        $provider = $this->providerStreaming(
            self::sseBody([self::envelope()]),
            SglangProvider::DEFAULT_MODEL,
            $parser,
        );

        $calls = [];

        foreach ($this->drain($provider) as $chunk) {
            foreach ($chunk->toolCalls ?? [] as $call) {
                $calls[] = $call;
            }
        }

        $this->assertCount(1, $calls);
        $this->assertSame('read', $calls[0]->name());
    }

    // -------------------------------------------------------------------------
    // The B1 guard reaches this seam too, and its cost is recorded here rather
    // than discovered later.
    // -------------------------------------------------------------------------

    /**
     * WHAT THE STRICT GUARD COSTS, WRITTEN DOWN.
     *
     * Detection is positional now, not `str_contains` - a message that merely
     * QUOTES the markup used to fabricate a real call from it. The guard that
     * fixed that also means an envelope run on from prose with NO blank line
     * before it is not treated as an action, on this path as on the batch one.
     *
     * That is a deliberate lean, justified by `enc.py:726`: DeepSeek-V4's start
     * token is `f"\n\n<{dsml_token}{tool_calls_block_name}"`, so the two
     * newlines are part of what the model is trained to emit, and this parser
     * only ever runs on text the server did NOT decode - i.e. on exactly the
     * bytes the model generated. The fixtures above were changed to carry them
     * for that reason: they were synthetic and had simply omitted the token.
     *
     * The lean is not silent. A marker present with nothing qualifying is
     * reported, which is what separates "armed and chose not to fire" from the
     * silent loss this whole seam exists to eliminate.
     */
    public function testAnEnvelopeRunOnFromProseIsNotRecoveredAndSaysSo(): void
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'dsml-stream-log');
        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $chunks = $this->drain($this->providerStreaming(
                // No blank line: the envelope is glued to the sentence.
                self::sseBody(['Let me read that.', self::envelope()]),
                'deepseek-ai/DeepSeek-V4-Flash-0731',
                DsmlToolCallParser::new(),
            ));

            foreach ($chunks as $chunk) {
                $this->assertNull($chunk->toolCalls);
            }

            $this->assertStringContainsString(
                'no occurrence of it is positioned like an action',
                (string) file_get_contents($logFile),
                'a guard that can drop a genuine call must not do it silently',
            );
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);

            if (is_file($logFile)) {
                unlink($logFile);
            }
        }
    }
}

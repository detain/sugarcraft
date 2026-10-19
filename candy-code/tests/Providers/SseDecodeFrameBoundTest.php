<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\GeminiProvider;
use SugarCraft\Code\Providers\ProviderFactory;

/**
 * End-to-end transport drives for the shared SSE frame bound (rev-w3j3 CRITICAL-1).
 *
 * The bound lives in the ONE decoder both provider wires share
 * ({@see \SugarCraft\Code\Providers\Concerns\AnthropicMessagesWire::decodeSseEvents()}),
 * so the proof must ride a real Guzzle transport fed in 8192-byte reads - the
 * worst case a live socket offers - rather than a synthetic array into the
 * decoder. These pins cover:
 *
 *   - a terminated frame one byte over the bound raises on BOTH wires (the
 *     refusal is shared, not Gemini-local);
 *   - a producer that never terminates its line is refused MID-stream by the
 *     accumulation leg, so a stalled socket cannot grow the buffer unbounded;
 *   - frames whose wire line is exactly 8192 bytes (the legal maximum) survive
 *     intact, at scale (1025 of them), proving the bound never eats legitimate
 *     traffic.
 */
final class SseDecodeFrameBoundTest extends TestCase
{
    /** Byte length of a wire line that sits exactly on the bound (terminator excluded). */
    private const LEGAL_WIRE_LINE_BYTES = 8192;

    public function testAFatTerminatedFrameRaisesTheFrameBoundOnTheGeminiTransport(): void
    {
        $fat = 'data: ' . str_repeat('x', self::LEGAL_WIRE_LINE_BYTES - 5) . "\n\n";
        $this->assertSame(8193, strlen(rtrim($fat, "\n")), 'fixture must be one byte over the bound');

        $provider = $this->geminiOverMockStream($fat);

        $frames = iterator_to_array($provider->completeStream($this->request()), false);
        $this->assertCount(1, $frames, 'the refused stream surfaces only its error frame');
        $this->assertTrue($frames[0]->isError);
        $this->assertStringContainsString('8192-byte bound', (string) $frames[0]->errorMessage);
        $this->assertStringContainsString('8193 bytes before the line terminator', (string) $frames[0]->errorMessage);
        $this->assertSame('', $frames[0]->content, 'the offending document must never reach a parser');
    }

    public function testAFatTerminatedFrameRaisesTheSameSharedBoundOnTheAnthropicTransport(): void
    {
        // The refusal is decoder-local, not wire-local: the identical over-bound
        // line must surface as the same bound error on the sibling transport that
        // shares the decode path.
        $fat = 'data: ' . str_repeat('x', self::LEGAL_WIRE_LINE_BYTES - 5) . "\n\n";

        $provider = $this->anthropicOverMockStream($fat);

        $sawBoundError = false;
        foreach ($provider->completeStream($this->request()) as $frame) {
            if ($frame->isError && str_contains((string) $frame->errorMessage, '8192-byte bound')) {
                $sawBoundError = true;
            }
        }
        $this->assertTrue($sawBoundError, 'the bound is shared across both wires');
    }

    public function testAStalledProducerWithoutALineTerminatorIsRefusedMidStream(): void
    {
        // 9000 bytes and no newline anywhere: the first 8192-byte read leaves the
        // buffer exactly AT the bound (legal), the second read pushes it over -
        // the accumulation leg must fire before eof, not wait for a terminator
        // that never arrives.
        $stalled = 'data: ' . str_repeat('y', 8994);
        $this->assertSame(9000, strlen($stalled));

        $provider = $this->geminiOverMockStream($stalled);

        $frames = iterator_to_array($provider->completeStream($this->request()), false);
        $this->assertCount(1, $frames);
        $this->assertTrue($frames[0]->isError);
        $this->assertStringContainsString('accumulated without a line terminator', (string) $frames[0]->errorMessage);
    }

    public function testFramesAtTheCapSurviveTheFullGeminiStream(): void
    {
        // Largest legal wire line: 'data: ' + 8186-byte document = 8192 exactly.
        $shell = '{"candidates":[{"content":{"parts":[{"text":"PAD"}]},"finishReason":"STOP"}]}';
        $pad = self::LEGAL_WIRE_LINE_BYTES - 6 - strlen($shell) + strlen('PAD');
        $doc = str_replace('PAD', str_repeat('z', $pad), $shell);
        $this->assertSame(8186, strlen($doc), 'fixture document must sit at the cap minus the data: prefix');

        $body = str_repeat('data: ' . $doc . "\n\n", 1025)
            . "data: {\"usageMetadata\":{\"promptTokenCount\":3,\"candidatesTokenCount\":4,\"totalTokenCount\":7}}\n\n";

        $provider = $this->geminiOverMockStream($body);

        $texts = 0;
        $usageFrames = 0;
        foreach ($provider->completeStream($this->request()) as $frame) {
            $this->assertFalse($frame->isError, 'at-cap frames must not be refused: ' . (string) $frame->errorMessage);
            if ($frame->content !== '') {
                $texts++;
            }
            if ($frame->usage !== null) {
                $usageFrames++;
                $this->assertSame(7, $frame->usage->totalTokens, 'the trailing usage document rides the same decoder pass');
            }
        }
        $this->assertSame(1025, $texts, 'every at-cap frame must survive 8192-byte reads');
        $this->assertSame(1, $usageFrames);
    }

    private function request(): CompleteRequest
    {
        return new CompleteRequest(
            model: 'gemini-2.5-flash',
            messages: [new UserMessage('hi')],
        );
    }

    /** Build the factory's real gemini provider, then swap its handler for canned bytes. */
    private function geminiOverMockStream(string $body): GeminiProvider
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'gemini',
            'apiKey' => 'key-not-a-glob',
            'model' => 'gemini-2.5-flash',
        ]);
        $this->assertInstanceOf(GeminiProvider::class, $provider);

        $client = (new ReflectionProperty(GeminiProvider::class, 'httpClient'))->getValue($provider);
        $this->assertInstanceOf(Client::class, $client);
        $stack = $client->getConfig('handler');
        $this->assertInstanceOf(HandlerStack::class, $stack);
        $stack->setHandler(new MockHandler([new Response(200, ['Content-Type' => 'text/event-stream'], $body)]));

        return $provider;
    }

    private function anthropicOverMockStream(string $body): AnthropicProvider
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'anthropic',
            'apiKey' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-6',
        ]);
        $this->assertInstanceOf(AnthropicProvider::class, $provider);

        $client = (new ReflectionProperty(AnthropicProvider::class, 'httpClient'))->getValue($provider);
        $this->assertInstanceOf(Client::class, $client);
        $stack = $client->getConfig('handler');
        $this->assertInstanceOf(HandlerStack::class, $stack);
        $stack->setHandler(new MockHandler([new Response(200, ['Content-Type' => 'text/event-stream'], $body)]));

        return $provider;
    }
}

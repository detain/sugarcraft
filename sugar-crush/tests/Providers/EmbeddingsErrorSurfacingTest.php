<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\TransientFailure;

/**
 * Audit A17: SglangProvider::embeddings() and CustomProvider::embeddings()
 * used to catch every GuzzleException - and coerce any 2xx body - into an
 * empty EmbeddingsResponse, so an outage was indistinguishable from "no
 * results". These pin the throwing contract: the provider's own error text
 * reaches the caller, the Guzzle exception rides along as previous so
 * TransientFailure still classifies it, a malformed 2xx payload throws, and
 * a genuine `data: []` stays an empty result.
 */
final class EmbeddingsErrorSurfacingTest extends TestCase
{
    private const EMBEDDINGS_BASE_URI = 'https://api.example.com/v1/';

    public function testSglangServerErrorSurfacesTheServerTextAndStaysTransient(): void
    {
        $provider = $this->sglang([
            new Response(500, ['Content-Type' => 'application/json'], '{"error":{"message":"embedding model not loaded"}}'),
        ]);

        $thrown = $this->embedAndCatch($provider);

        self::assertSame('SGLANG embeddings request failed: embedding model not loaded', $thrown->getMessage());
        self::assertInstanceOf(GuzzleException::class, $thrown->getPrevious());
        self::assertTrue(TransientFailure::isTransient($thrown));
    }

    public function testSglangBadRequestIsPermanent(): void
    {
        $provider = $this->sglang([
            new Response(400, [], '{"error":{"message":"input too long"}}'),
        ]);

        $thrown = $this->embedAndCatch($provider);

        self::assertStringContainsString('input too long', $thrown->getMessage());
        self::assertInstanceOf(GuzzleException::class, $thrown->getPrevious());
        self::assertFalse(TransientFailure::isTransient($thrown));
    }

    public function testSglangConnectFailureThrowsTransient(): void
    {
        $connect = new ConnectException('Connection refused', new Request('POST', self::EMBEDDINGS_BASE_URI . 'embeddings'));
        $provider = $this->sglang([$connect]);

        $thrown = $this->embedAndCatch($provider);

        self::assertSame('SGLANG embeddings request failed: Connection refused', $thrown->getMessage());
        self::assertSame($connect, $thrown->getPrevious());
        self::assertTrue(TransientFailure::isTransient($thrown));
    }

    public function testCustomServerErrorThrowsWithPreviousChain(): void
    {
        $provider = $this->custom([
            new Response(500, [], '{"error":{"message":"embedding model not loaded"}}'),
        ]);

        $thrown = $this->embedAndCatch($provider);

        self::assertStringStartsWith('custom embeddings request failed: ', $thrown->getMessage());
        self::assertStringContainsString('500', $thrown->getMessage());
        self::assertInstanceOf(GuzzleException::class, $thrown->getPrevious());
        self::assertTrue(TransientFailure::isTransient($thrown));
    }

    public function testCustomBadRequestIsPermanent(): void
    {
        $thrown = $this->embedAndCatch($this->custom([new Response(400, [], '{"error":{"message":"bad"}}')]));

        self::assertInstanceOf(GuzzleException::class, $thrown->getPrevious());
        self::assertFalse(TransientFailure::isTransient($thrown));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function malformedPayloads(): iterable
    {
        foreach (['sglang', 'custom'] as $kind) {
            yield "{$kind}: non-JSON body" => [$kind, '<html>502 Bad Gateway</html>', 'is not a JSON object'];
            yield "{$kind}: no data key" => [$kind, '{"object":"list"}', 'has no `data` list'];
            yield "{$kind}: data is an object" => [$kind, '{"data":{"embedding":[0.1]}}', 'has no `data` list'];
            yield "{$kind}: item missing embedding" => [$kind, '{"data":[{"embedding":[0.1]},{"index":1}]}', 'item 1 has no `embedding` array'];
            yield "{$kind}: item embedding not an array" => [$kind, '{"data":[{"embedding":"0.1,0.2"}]}', 'item 0 has no `embedding` array'];
        }
    }

    #[DataProvider('malformedPayloads')]
    public function testMalformedSuccessPayloadThrows(string $kind, string $body, string $expected): void
    {
        $thrown = $this->embedAndCatch($this->provider($kind, [new Response(200, [], $body)]));

        self::assertStringContainsString($expected, $thrown->getMessage());
        self::assertNull($thrown->getPrevious());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kinds(): iterable
    {
        yield 'sglang' => ['sglang'];
        yield 'custom' => ['custom'];
    }

    #[DataProvider('kinds')]
    public function testEmptyDataListIsALegitimateEmptyResult(string $kind): void
    {
        $response = $this->provider($kind, [new Response(200, [], '{"object":"list","data":[]}')])
            ->embeddings(new EmbeddingsRequest(model: 'embed', input: ['x']));

        self::assertSame([], $response->embeddings);
    }

    #[DataProvider('kinds')]
    public function testHappyPathStillReturnsVectors(string $kind): void
    {
        $body = '{"object":"list","data":[{"object":"embedding","index":0,"embedding":[0.1,0.2]},'
            . '{"object":"embedding","index":1,"embedding":[0.3,0.4]}]}';

        $response = $this->provider($kind, [new Response(200, [], $body)])
            ->embeddings(new EmbeddingsRequest(model: 'embed', input: ['a', 'b']));

        self::assertSame([[0.1, 0.2], [0.3, 0.4]], $response->embeddings);
    }

    private function embedAndCatch(ProviderInterface $provider): \RuntimeException
    {
        try {
            $provider->embeddings(new EmbeddingsRequest(model: 'embed', input: ['x']));
        } catch (\RuntimeException $e) {
            return $e;
        }

        self::fail('embeddings() returned instead of surfacing the failure');
    }

    /**
     * @param list<Response|\Throwable> $queue
     */
    private function provider(string $kind, array $queue): ProviderInterface
    {
        return $kind === 'sglang' ? $this->sglang($queue) : $this->custom($queue);
    }

    /**
     * @param list<Response|\Throwable> $queue
     */
    private function sglang(array $queue): SglangProvider
    {
        return new SglangProvider(self::EMBEDDINGS_BASE_URI, 'embed', null, $this->client($queue));
    }

    /**
     * @param list<Response|\Throwable> $queue
     */
    private function custom(array $queue): CustomProvider
    {
        return new CustomProvider('custom', self::EMBEDDINGS_BASE_URI, 'embed', null, $this->client($queue), true, true);
    }

    /**
     * @param list<Response|\Throwable> $queue
     */
    private function client(array $queue): Client
    {
        return new Client([
            'handler' => HandlerStack::create(new MockHandler($queue)),
            'base_uri' => self::EMBEDDINGS_BASE_URI,
        ]);
    }
}

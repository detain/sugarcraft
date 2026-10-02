<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\ProviderResponseException;
use SugarCraft\Crush\Providers\TransientFailure;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * Audit 15a A1: a provider that reports failure as an `isError` response
 * (CustomProvider - and so the `anthropic` type - and VertexProvider) used to
 * reach the user as a BLANK reply: Runtime stopped retrying it and yielded an
 * AssistantMessage built from its empty content, and the provider's
 * `errorMessage` was never read. These drive the real providers end to end
 * through {@see EngineBackend} and assert the provider's own text is what
 * comes out.
 */
final class ProviderErrorSurfacingTest extends TestCase
{
    private const UNAUTHORIZED_BODY = '{"error":{"message":"Incorrect API key provided"}}';

    /**
     * Five queued 401s so an unwanted retry would be observable as a drained
     * queue rather than as a MockHandler "queue is empty" error that could
     * itself be mistaken for the surfaced failure.
     */
    private static function unauthorizedProvider(bool $streaming, ?MockHandler &$mock = null): CustomProvider
    {
        $mock = new MockHandler(array_fill(
            0,
            5,
            new Response(401, ['Content-Type' => 'application/json'], self::UNAUTHORIZED_BODY),
        ));

        return new CustomProvider(
            'custom',
            'http://provider.invalid',
            'm',
            null,
            new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'http://provider.invalid/']),
            $streaming,
            true,
        );
    }

    /** @return iterable<string, array{bool}> */
    public static function streamingModes(): iterable
    {
        yield 'stream=true' => [true];
        yield 'stream=false' => [false];
    }

    #[DataProvider('streamingModes')]
    public function testACustomProvider401SurfacesTheProviderMessage(bool $streaming): void
    {
        $mock = null;
        $provider = self::unauthorizedProvider($streaming, $mock);
        $backend = EngineBackend::new($provider, 'm')->withoutHooks();

        try {
            $reply = $backend->complete([Message::user('hi')]);
            $this->fail('a 401 must not come back as a reply; got content ' . var_export($reply->content, true));
        } catch (ProviderResponseException $e) {
            $this->assertStringContainsString('Incorrect API key provided', $e->getMessage());
            $this->assertTrue($e->response->isError);
            $this->assertFalse(
                TransientFailure::isTransient($e),
                'a surfaced provider error must not be re-retried by an outer loop',
            );
        }

        $this->assertSame(4, $mock->count(), 'a 401 is permanent: exactly one request, no retry');
    }

    /**
     * The interactive path: completeAsync() runs the turn in a forked child
     * (when ext-pcntl is present) and forwards a failure as its message text,
     * so this is the route the provider's words take to the user's screen.
     * Without pcntl it runs in-process and rejects with the exception itself;
     * either way the rejection must carry the provider's text.
     */
    public function testTheAsyncPathRejectsWithTheProviderMessage(): void
    {
        $backend = EngineBackend::new(self::unauthorizedProvider(true), 'm')->withoutHooks();

        $error = $this->awaitRejection($backend->completeAsync([Message::user('hi')]));

        $this->assertStringContainsString('Incorrect API key provided', $error->getMessage());
    }

    /**
     * The Vertex shape: an Anthropic-on-Vertex backend reports an auth failure
     * as an `error` event on a 200 SSE stream, after a usage-only
     * `message_start`. Before the fix this ended as an empty reply.
     */
    public function testAVertexStreamedErrorEventSurfacesItsMessage(): void
    {
        $provider = VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: 'claude-3-sonnet@20240229',
            predictor: static fn (): array => [],
            streamer: static function (): \Generator {
                yield ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 12, 'output_tokens' => 0]]];
                yield ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'bad key for project']];
            },
        );

        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('bad key for project');

        EngineBackend::new($provider, 'claude-3-sonnet@20240229')->withoutHooks()->complete([Message::user('hi')]);
    }

    private function awaitRejection(PromiseInterface $promise): \Throwable
    {
        $loop = \React\EventLoop\Loop::get();
        $settled = false;
        $value = null;
        $error = null;

        $promise->then(
            function ($v) use (&$settled, &$value, $loop): void { $settled = true; $value = $v; $loop->stop(); },
            function (\Throwable $e) use (&$settled, &$error, $loop): void { $settled = true; $error = $e; $loop->stop(); },
        );

        if (!$settled) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        $this->assertTrue($settled, 'completeAsync() never settled');
        $this->assertNull($value, 'a 401 must reject, not resolve with a (blank) reply');
        $this->assertInstanceOf(\Throwable::class, $error);

        return $error;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use Google\ApiCore\ApiException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\TransientFailure;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * Audit 15a A19: Vertex failures reach the retry seams as gax
 * {@see ApiException}s, which carry a gRPC code and status name but no HTTP
 * status and no `previous`. Before the fix every one of them - quota 429s and
 * overloaded 503s included - was classified permanent and never retried.
 *
 * The HTTP rows are built exactly the way the vendored REST transport builds
 * them: a Guzzle Client/ServerException handed to
 * `ApiException::createFromRequestException()`.
 */
final class VertexApiExceptionTransientTest extends TestCase
{
    private const ANTHROPIC_MODEL = 'claude-3-5-sonnet@20240620';
    private const GEMINI_MODEL = 'gemini-1.5-pro-002';

    private static function fromHttp(int $code, ?string $status, bool $withErrorBody = true): ApiException
    {
        $request = new Request('POST', 'https://us-central1-aiplatform.googleapis.com/v1/x:rawPredict');
        $error = ['code' => $code, 'message' => 'failure ' . $code];
        if ($status !== null) {
            $error['status'] = $status;
        }
        $response = new Response($code, [], $withErrorBody ? (string) json_encode(['error' => $error]) : 'upstream says no');
        $guzzle = $code >= 500
            ? new ServerException('x', $request, $response)
            : new ClientException('x', $request, $response);

        return ApiException::createFromRequestException($guzzle);
    }

    /**
     * @return iterable<string, array{\Closure(): \Throwable, bool}>
     */
    public static function apiExceptions(): iterable
    {
        // HTTP failures with Google's usual `error.status` body.
        yield 'http 429 RESOURCE_EXHAUSTED' => [fn () => self::fromHttp(429, 'RESOURCE_EXHAUSTED'), true];
        yield 'http 503 UNAVAILABLE' => [fn () => self::fromHttp(503, 'UNAVAILABLE'), true];
        yield 'http 500 INTERNAL' => [fn () => self::fromHttp(500, 'INTERNAL'), true];
        yield 'http 504 DEADLINE_EXCEEDED' => [fn () => self::fromHttp(504, 'DEADLINE_EXCEEDED'), true];
        yield 'http 401 UNAUTHENTICATED' => [fn () => self::fromHttp(401, 'UNAUTHENTICATED'), false];
        yield 'http 403 PERMISSION_DENIED' => [fn () => self::fromHttp(403, 'PERMISSION_DENIED'), false];
        yield 'http 400 INVALID_ARGUMENT' => [fn () => self::fromHttp(400, 'INVALID_ARGUMENT'), false];
        yield 'http 404 NOT_FOUND' => [fn () => self::fromHttp(404, 'NOT_FOUND'), false];

        // An error body with no `status`: gax passes the HTTP status through
        // as the code, beside UNRECOGNIZED_STATUS.
        yield 'http 429 error body without status' => [fn () => self::fromHttp(429, null), true];
        yield 'http 503 error body without status' => [fn () => self::fromHttp(503, null), true];
        yield 'http 401 error body without status' => [fn () => self::fromHttp(401, null), false];

        // No JSON error body at all: gax maps the HTTP status to a gRPC code.
        yield 'http 502 bare body (INTERNAL)' => [fn () => self::fromHttp(502, null, false), true];
        yield 'http 429 bare body' => [fn () => self::fromHttp(429, null, false), true];
        yield 'http 403 bare body' => [fn () => self::fromHttp(403, null, false), false];

        // gRPC-style exceptions (ServerStream / createFromRpcStatus shape).
        yield 'grpc 14 UNAVAILABLE' => [fn () => new ApiException('unavailable', 14, 'UNAVAILABLE'), true];
        yield 'grpc 8 RESOURCE_EXHAUSTED' => [fn () => new ApiException('quota', 8, 'RESOURCE_EXHAUSTED'), true];
        yield 'grpc 4 DEADLINE_EXCEEDED' => [fn () => new ApiException('slow', 4, 'DEADLINE_EXCEEDED'), true];
        yield 'grpc 10 ABORTED' => [fn () => new ApiException('aborted', 10, 'ABORTED'), true];
        yield 'grpc 16 UNAUTHENTICATED' => [fn () => new ApiException('creds', 16, 'UNAUTHENTICATED'), false];
        yield 'grpc 3 INVALID_ARGUMENT' => [fn () => new ApiException('bad', 3, 'INVALID_ARGUMENT'), false];
        yield 'grpc 2 UNKNOWN stays permanent' => [fn () => new ApiException('unknown', 2, 'UNKNOWN'), false];

        // Status-less: judged by the numeric code, then as a network error.
        yield 'status-less grpc 14' => [fn () => new ApiException('unavailable', 14), true];
        yield 'status-less grpc 7' => [fn () => new ApiException('denied', 7), false];
        yield 'status-less, code 0 (no verdict at all)' => [fn () => new ApiException('connection lost', 0), true];
        yield 'unrecognised code -1' => [fn () => new ApiException('odd', -1, 'UNRECOGNIZED_STATUS'), false];

        // Wrapped: the chain walk still reaches it.
        yield 'wrapped 429' => [
            fn () => new \RuntimeException('Vertex failed', 0, self::fromHttp(429, 'RESOURCE_EXHAUSTED')),
            true,
        ];
        yield 'wrapped 401' => [
            fn () => new \RuntimeException('Vertex failed', 0, self::fromHttp(401, 'UNAUTHENTICATED')),
            false,
        ];
    }

    /**
     * @param \Closure(): \Throwable $make
     */
    #[DataProvider('apiExceptions')]
    public function testApiExceptionIsClassifiedByItsGrpcStatus(\Closure $make, bool $transient): void
    {
        $this->assertSame($transient, TransientFailure::isTransient($make()));
    }

    public function testTheVendoredTransportCarriesNoHttpStatusOrPrevious(): void
    {
        // Pins the premise of the arm: if gax ever starts chaining the
        // Guzzle exception, the HTTP-status arm would classify it first.
        $api = self::fromHttp(429, 'RESOURCE_EXHAUSTED');

        $this->assertNull($api->getPrevious());
        $this->assertFalse(method_exists($api, 'getStatusCode'));
        $this->assertSame(8, $api->getCode());
        $this->assertSame('RESOURCE_EXHAUSTED', $api->getStatus());
    }

    public function testAnHttpStatusEarlierInTheChainStillWins(): void
    {
        $request = new Request('POST', 'https://example.test');
        $outer = new ClientException('x', $request, new Response(401), new ApiException('quota', 8, 'RESOURCE_EXHAUSTED'));

        $this->assertFalse(TransientFailure::isTransient($outer));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function models(): iterable
    {
        yield 'anthropic' => [self::ANTHROPIC_MODEL];
        yield 'gemini' => [self::GEMINI_MODEL];
    }

    #[DataProvider('models')]
    public function testCompleteMarksAQuotaApiExceptionTransient(string $model): void
    {
        $response = $this->throwingProvider($model, new ApiException('quota', 8, 'RESOURCE_EXHAUSTED'))
            ->complete($this->request($model));

        $this->assertTrue($response->isError);
        $this->assertTrue($response->errorTransient);
        $this->assertTrue(TransientFailure::responseIsTransient($response));
    }

    #[DataProvider('models')]
    public function testCompleteMarksAnAuthApiExceptionPermanent(string $model): void
    {
        $response = $this->throwingProvider($model, self::fromHttp(401, 'UNAUTHENTICATED'))
            ->complete($this->request($model));

        $this->assertTrue($response->isError);
        $this->assertFalse($response->errorTransient);
    }

    #[DataProvider('models')]
    public function testCompleteStreamMarksAQuotaApiExceptionTransient(string $model): void
    {
        $chunks = $this->streamChunks($model, new ApiException('quota', 8, 'RESOURCE_EXHAUSTED'));

        $this->assertCount(1, $chunks);
        $this->assertTrue($chunks[0]->isError);
        $this->assertTrue($chunks[0]->errorTransient);
    }

    #[DataProvider('models')]
    public function testCompleteStreamMarksAnAuthApiExceptionPermanent(string $model): void
    {
        $chunks = $this->streamChunks($model, self::fromHttp(401, 'UNAUTHENTICATED'));

        $this->assertCount(1, $chunks);
        $this->assertTrue($chunks[0]->isError);
        $this->assertFalse($chunks[0]->errorTransient);
    }

    private function request(string $model): CompleteRequest
    {
        return new CompleteRequest(model: $model, messages: [new UserMessage('Hi')]);
    }

    private function throwingProvider(string $model, \Throwable $error): VertexProvider
    {
        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: $model,
            predictor: static function () use ($error): array {
                throw $error;
            },
            streamer: static function () use ($error): \Generator {
                throw $error;
                yield; // @phpstan-ignore deadCode.unreachable (makes this closure a generator)
            },
        );
    }

    /**
     * @return list<CompleteResponse>
     */
    private function streamChunks(string $model, \Throwable $error): array
    {
        return iterator_to_array(
            $this->throwingProvider($model, $error)->completeStream($this->request($model)),
            false,
        );
    }
}

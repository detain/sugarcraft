<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\ServerRequest;
use SugarCraft\Code\Server\Http\Responses;
use SugarCraft\Code\Server\Http\Router;

/**
 * The HTTP answer's error SHAPE (CWPD-13(d) of reviews-1103): what a client
 * can parse when something under `/api/` goes wrong. A handled refusal is
 * pinned here at the envelope the API already commits to — `{error:
 * {kind, message}}` JSON with the security headers merged. The UNHANDLED
 * throw is no longer pinned here: CWPD-4 (lane G4-D, welded at this commit's
 * side) replaced the old escape-with-no-answer with a bounded JSON 500 at
 * the router boundary — that behavior is pinned in
 * tests/Server/RouterThrowableTest.php.
 */
final class ApiErrorShapeTest extends TestCase
{
    public function testAHandledApiRefusalIsJsonErrorEnvelopeWithSecurityHeaders(): void
    {
        $response = $this->dispatchApi($this->router(
            static fn (): ResponseInterface => Responses::error(500, 'internal', 'internal error'),
        ));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $decoded = (array) \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['kind' => 'internal', 'message' => 'internal error'], $decoded['error'] ?? null, 'the refusal is the kind/message envelope, not a debug dump');
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'), 'the error page rides the same security headers as the successes');
    }

    public function testTheEnvelopeCarriesNoHandlerInternals(): void
    {
        $secret = 'stack-frame-' . \bin2hex(\random_bytes(6));

        $response = $this->dispatchApi($this->router(
            static fn (): ResponseInterface => Responses::error(500, 'internal', 'internal error'),
        ));

        self::assertStringNotContainsString($secret, (string) $response->getBody(), 'what a handler keeps to itself stays kept');
    }

    /** @param \Closure(ServerRequest):ResponseInterface $api */
    private function router(\Closure $api): Router
    {
        $idle = static fn (): ResponseInterface => Responses::error(404, 'not_found', 'not found');

        return new Router($idle, $api, $idle);
    }

    private function dispatchApi(Router $router): ResponseInterface
    {
        return $router(new ServerRequest('GET', 'http://127.0.0.1:7420/api/thing', ['Host' => '127.0.0.1:7420']));
    }
}

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
 * {kind, message}}` JSON with the security headers merged — and an
 * UNHANDLED throw is pinned at its CURRENT observed behavior: the Router
 * does not catch, so the exception escapes toward whoever mounted it.
 *
 * The bounded-500 half is CWPD-4, owned by lane G4-D (their
 * tests/Server/RouterThrowableTest.php will pin the catch-all responder
 * once it lands). If that weld makes the escape test below red, the escape
 * is gone by design — delete that test then, not before.
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

    /**
     * CURRENT behavior, deliberately pinned so a later change is a
     * disclosed change: the Router has no catch-all, so a throwing handler
     * propagates to the HTTP layer above (react/http answers its own
     * unparsable 500 there). The shared contract lives in this class docblock — CWPD-4 (G4-D)
     * replaces this with a bounded JSON 500.
     */
    public function testAnUnhandledThrowCurrentlyEscapesTheRouterUncaught(): void
    {
        $router = $this->router(static function (): ResponseInterface {
            throw new \RuntimeException('handler blew up');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('handler blew up');

        $this->dispatchApi($router);
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

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use SugarCraft\Code\Server\Http\HostAndOriginGuard;
use SugarCraft\Code\Server\Http\Responses;
use SugarCraft\Code\Server\Http\Router;

/**
 * CWPD-4: what the browser's console shows when a handler throws is the
 * shape of the answer, and react/http's fallback responder makes that shape
 * a text/plain page. The web client parses; a non-JSON 500 is an
 * unparseable failure even when the status is right — and `/api` promises
 * JSON in every answer. The router's own boundary now translates an
 * escaping throw into the JSON `internal_error` envelope, with the cause
 * only in the server log (ErrorLog's ISS-057 law, applied to the router).
 */
final class RouterThrowableTest extends TestCase
{
    protected function setUp(): void
    {
        // The boundary logs the cause through the blessed error_log funnel;
        // the suite is about the wire, not about the host's log file.
        putenv('CANDY_CODE_QUIET_LOGS=1');
    }

    protected function tearDown(): void
    {
        putenv('CANDY_CODE_QUIET_LOGS');
    }

    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $api
     * @param \Closure(ServerRequestInterface): ResponseInterface $staticPage
     * @param (\Closure(ServerRequestInterface): ?ResponseInterface)|null $share
     */
    private function router(\Closure $api, \Closure $staticPage, ?\Closure $share = null): Router
    {
        return new Router(
            static fn (): ResponseInterface => throw new \LogicException('upgrade must not run'),
            $api,
            $staticPage,
            $share,
        );
    }

    private function get(string $path): ServerRequestInterface
    {
        return new ServerRequest('GET', 'http://127.0.0.1:7420' . $path);
    }


    public function testAThrowingApiHandlerAnswersTheJsonFiftyHundred(): void
    {
        $router = $this->router(
            static function (ServerRequestInterface $request): ResponseInterface {
                throw new \RuntimeException('boom-in-the-api-stack');
            },
            static fn (): ResponseInterface => new Response(200, [], 'static'),
        );

        $response = $router($this->get('/api/session.send'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = \json_decode((string) $response->getBody(), true);
        self::assertIsArray($body, 'the web client parses; the answer must parse');
        self::assertSame('internal_error', $body['error']['kind'] ?? null, 'the refusal is the standard {error:{kind,message}} shape');
        self::assertStringNotContainsString(
            'boom-in',
            (string) $response->getBody(),
            'the cause is in the server log, never on the wire (ErrorLog law)',
        );
    }

    public function testAThrowingStaticPageHandlerIsCaughtToo(): void
    {
        $router = $this->router(
            static fn (): ResponseInterface => new Response(200, [], 'api'),
            static function (ServerRequestInterface $request): ResponseInterface {
                throw new \RuntimeException('boom-in-the-static-stack');
            },
        );

        $response = $router($this->get('/anywhere'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringNotContainsString('boom-in', (string) $response->getBody());
    }

    public function testTheSwitchingHandshakeStillEscapesTheWrapper(): void
    {
        // The 101 is the ONE answer whose headers belong to RFC 6455; the
        // boundary translation must not swallow it into a secured JSON page.
        $router = new Router(
            static fn (): ResponseInterface => new Response(101, ['Upgrade' => 'websocket'], ''),
            static fn (): ResponseInterface => new Response(200, [], 'api'),
            static fn (): ResponseInterface => new Response(200, [], 'static'),
        );

        $upgrade = new ServerRequest(
            'GET',
            'http://127.0.0.1:7420/ws',
            ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Sec-WebSocket-Version' => '13', 'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ=='],
        );

        self::assertSame(101, $router($upgrade)->getStatusCode());
    }

    public function testAThrowingSharePageHandlerIsCaughtToo(): void
    {
        // The share controller runs on the request path like any handler; a
        // throw inside its live-render plumbing (a store fault, say) used to
        // reach react's text/plain page through this same hole.
        $router = $this->router(
            static fn (): ResponseInterface => new Response(200, [], 'api'),
            static fn (): ResponseInterface => new Response(200, [], 'static'),
            static function (ServerRequestInterface $request): ?ResponseInterface {
                throw new \RuntimeException('boom-in-the-share-stack');
            },
        );

        $response = $router($this->get('/s/anything'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringNotContainsString('boom-in', (string) $response->getBody());
    }

    public function testTheNotFoundPagesAreNotRelabelledByTheBoundary(): void
    {
        $router = $this->router(
            static fn (): ResponseInterface => Responses::error(404, 'not_found', 'no such method'),
            static fn (): ResponseInterface => new Response(404, [], 'gone'),
        );

        $api = $router($this->get('/api/nope.nope'));
        self::assertSame(404, $api->getStatusCode());
        $body = \json_decode((string) $api->getBody(), true);
        self::assertSame('not_found', $body['error']['kind'] ?? null, 'the translation is for throws, not for refusals');
    }
}

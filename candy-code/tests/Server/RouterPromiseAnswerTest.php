<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Server\Http\Router;

/**
 * An API answer that settles later (a webhook launch) still leaves with the
 * same security headers a synchronous answer gets: the router applies them to
 * the response the promise settles to, not to the promise itself.
 */
final class RouterPromiseAnswerTest extends TestCase
{
    public function testADeferredApiAnswerSettlesWithTheSecurityHeaders(): void
    {
        $later = new Deferred();
        $router = new Router(
            static fn (): ResponseInterface => new Response(101, [], ''),
            static fn (ServerRequestInterface $request): PromiseInterface => $later->promise(),
            static fn (): ResponseInterface => new Response(200, [], 'page'),
        );

        $answer = $router(new ServerRequest('POST', 'http://127.0.0.1/api/hooks/deploy'));
        self::assertInstanceOf(PromiseInterface::class, $answer, 'the router passes a pending answer through');

        $later->resolve(new Response(200, [], 'answered'));

        $settled = null;
        $answer->then(static function (ResponseInterface $response) use (&$settled): void {
            $settled = $response;
        });
        self::assertNotNull($settled);
        self::assertSame('answered', (string) $settled->getBody());
        self::assertTrue($settled->hasHeader('X-Content-Type-Options'), 'the security headers ride the settled answer');
    }
}

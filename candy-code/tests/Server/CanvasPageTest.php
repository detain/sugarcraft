<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\Http\Message\ServerRequest;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Http\AuthMiddleware;
use SugarCraft\Code\Server\Http\CanvasLinks;
use SugarCraft\Code\Server\Http\CanvasPageController;
use SugarCraft\Code\Server\Http\Responses;
use SugarCraft\Code\Server\Http\Router;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * `/canvas/{token}` and `POST /api/canvas` through the real Router
 * (C-TOOL-6.g): the mint sits behind the auth middleware's own predicate,
 * the page is public like a share page, every death — unknown, expired,
 * spent, a retired artifact, a non-HTML id — answers ONE byte-identical
 * 404 on both doors, the single-use token is consumed before anything is
 * read, and the served page carries the sandbox CSP verbatim (the design's
 * step-local defect class: a missing or over-broad directive). The access
 * log is driven through a REAL listener, mirroring the share-page lesson:
 * the path itself carries the capability.
 */
final class CanvasPageTest extends TestCase
{
    use HomeSandboxTrait;

    private const PAGE = "<!DOCTYPE html>\n<canvas id=c></canvas>";

    private const CSP = "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline';"
        . " style-src 'unsafe-inline'; img-src data: blob:; font-src data:; media-src data: blob:;"
        . " connect-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'self'";

    private string $sessionId = '';

    protected function setUp(): void
    {
        $this->sessionId = 'canvas-' . \uniqid('s', true);
    }

    public function testTheMintSitsBehindTheAuthMiddlewareAndThePageStandsOutsideIt(): void
    {
        // The mint's publicness is AuthMiddleware's OWN predicate — if /api/
        // ever grows an exemption list that swallows /api/canvas, this
        // reddens and the page's security notes have to be re-read.
        self::assertTrue(AuthMiddleware::isProtected('/api/canvas'));
        self::assertFalse(AuthMiddleware::isProtected('/canvas/' . \str_repeat('0f', 32)));
        self::assertTrue(AuthMiddleware::isProtected('/api/sessions'), 'sanity: the guard still guards');
    }

    public function testAMintedLinkServesTheDocumentExactlyOnceUnderTheSandboxHeaders(): void
    {
        $artifact = $this->storeDocument(self::PAGE);
        $controller = $this->controller();
        $router = $this->routerWith($controller);

        $mint = $controller($this->canvasRequest('POST', '/api/canvas', [
            'sessionId' => $this->sessionId,
            'artifactId' => $artifact,
        ]));
        self::assertSame(200, $mint->getStatusCode());
        $answer = (array) json_decode((string) $mint->getBody(), true);
        self::assertIsString($answer['url'] ?? null);
        self::assertMatchesRegularExpression('/^\/canvas\/[0-9a-f]{64}$/', (string) $answer['url']);
        self::assertSame(60, $answer['expiresInSeconds']);

        $served = $router($this->canvasRequest('GET', (string) $answer['url']));
        self::assertSame(200, $served->getStatusCode());
        self::assertSame(self::PAGE, (string) $served->getBody());
        self::assertSame(self::CSP, $served->getHeaderLine('Content-Security-Policy'));
        self::assertSame('SAMEORIGIN', $served->getHeaderLine('X-Frame-Options'), 'the canvas frames in its own client — the DENY default must not ride here');
        self::assertSame('no-store', $served->getHeaderLine('Cache-Control'));
        self::assertSame('text/html; charset=utf-8', $served->getHeaderLine('Content-Type'));
        // The Router's shared merge still adds the free hygiene headers.
        self::assertSame('nosniff', $served->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-referrer', $served->getHeaderLine('Referrer-Policy'));

        $spent = $router($this->canvasRequest('GET', (string) $answer['url']));
        self::assertSame(404, $spent->getStatusCode(), 'a link is a capability consumed on first sight');
    }

    public function testEveryDeathOnBothDoorsIsOneIndistinguishableAnswer(): void
    {
        $controller = $this->controller();
        $png = $this->storeDocument('PNGBYTES', 'png');

        $deaths = [
            'unknown-token' => $controller($this->canvasRequest('GET', '/canvas/' . \str_repeat('ab', 32))),
            'empty-token' => $controller($this->canvasRequest('GET', '/canvas/')),
            'nested-token' => $controller($this->canvasRequest('GET', '/canvas/deadbeef/extra')),
            'unknown-artifact-mint' => $controller($this->canvasRequest('POST', '/api/canvas', [
                'sessionId' => $this->sessionId,
                'artifactId' => 'no-such-artifact',
            ])),
            'separator-artifact-mint' => $controller($this->canvasRequest('POST', '/api/canvas', [
                'sessionId' => $this->sessionId,
                'artifactId' => '../escape',
            ])),
            'non-html-artifact-mint' => $controller($this->canvasRequest('POST', '/api/canvas', [
                'sessionId' => $this->sessionId,
                'artifactId' => $png,
            ])),
        ];
        $reference = (string) $deaths['unknown-token']->getBody();
        foreach ($deaths as $label => $response) {
            self::assertSame(404, $response->getStatusCode(), $label);
            self::assertSame($reference, (string) $response->getBody(), $label . ' body differs — the no-oracle law');
            self::assertSame('not_found', $this->refusalKind($response), $label);
        }
    }

    public function testALinkPastItsTtlAndARetiredArtifactBothAnswerTheSharedDeath(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $artifact = $this->storeDocument(self::PAGE);
        $expiredDoor = $this->controller(CanvasLinks::new($clock));
        $url = $this->mintUrl($expiredDoor, $artifact);
        $now += 61.0;
        self::assertSame(404, $expiredDoor($this->canvasRequest('GET', $url))->getStatusCode());

        $liveDoor = $this->controller();
        $retired = $this->mintUrl($liveDoor, $artifact);
        @\unlink($this->documentPath($artifact));
        self::assertSame(404, $liveDoor($this->canvasRequest('GET', $retired))->getStatusCode(), 'a minted link cannot outlive the artifact it names');
    }

    public function testMalformedMintBodiesAnswerBadRequestNotTheSharedDeath(): void
    {
        $controller = $this->controller();

        $notJson = $controller($this->canvasRequest('POST', '/api/canvas', '{oops'));
        self::assertSame(400, $notJson->getStatusCode());
        self::assertSame('bad_request', $this->refusalKind($notJson));

        foreach ([
            'no ids' => [],
            'no artifact' => ['sessionId' => $this->sessionId],
            'no session' => ['artifactId' => 'whatever'],
            'numeric ids' => ['sessionId' => 7, 'artifactId' => 'x'],
        ] as $label => $body) {
            $response = $controller($this->canvasRequest('POST', '/api/canvas', $body));
            self::assertSame(400, $response->getStatusCode(), $label);
            self::assertSame('bad_request', $this->refusalKind($response), $label);
        }
    }

    public function testTheWrongMethodsAnswerWithTheirAllow(): void
    {
        $controller = $this->controller();

        $postToPage = $controller($this->canvasRequest('POST', '/canvas/' . \str_repeat('ab', 32)));
        self::assertSame(405, $postToPage->getStatusCode());
        self::assertSame('GET, HEAD', $postToPage->getHeaderLine('Allow'));

        $getMint = $controller($this->canvasRequest('GET', '/api/canvas'));
        self::assertSame(405, $getMint->getStatusCode());
        self::assertSame('POST', $getMint->getHeaderLine('Allow'));
    }

    public function testWithoutACanvasHandlerThePathsFallThroughUnharmed(): void
    {
        $router = new Router(
            static fn(): ResponseInterface => Responses::error(500, 'no', 'upgrade must not run'),
            static fn(): ResponseInterface => Responses::error(418, 'api', 'the api answered'),
            static fn(): ResponseInterface => Responses::json(200, ['ui' => true]),
        );

        $page = $router($this->canvasRequest('GET', '/canvas/' . \str_repeat('0f', 32)));
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('ui', (string) $page->getBody());
        self::assertSame('api', $this->refusalKind($router($this->canvasRequest('POST', '/api/canvas'))));
    }

    public function testTheCanvasBranchSitsAheadOfTheApiAndStaticArms(): void
    {
        $router = new Router(
            static fn(): ResponseInterface => Responses::error(500, 'no', 'upgrade must not run'),
            static fn(): ResponseInterface => Responses::error(418, 'api', 'the api answered'),
            static fn(): ResponseInterface => Responses::error(418, 'static', 'the ui answered'),
            null,
            $this->controller()(...),
        );

        // The mint is not an RPC method and the page is not the SPA shell:
        // both must be answered by the canvas arm, not the fall-throughs.
        self::assertSame('not_found', $this->refusalKind($router($this->canvasRequest('GET', '/canvas/' . \str_repeat('ab', 32)))));
        self::assertSame('bad_request', $this->refusalKind($router($this->canvasRequest('POST', '/api/canvas', 'not json'))));
        self::assertSame('api', $this->refusalKind($router($this->canvasRequest('GET', '/api/sessions'))), 'the rest of /api/ still belongs to the RPC door');
    }

    public function testTheAccessLogRedactsTheCanvasTokenItJustServed(): void
    {
        $artifact = $this->storeDocument(self::PAGE);
        $links = CanvasLinks::new();
        $token = $links->mint($this->sessionId, $artifact);

        $lines = [];
        $server = Server::new(
            ServerConfig::new('/nonexistent')->withPort(0),
            AuthContext::new(TokenStore::new('/nonexistent')->withOverride(\str_repeat('7', 64))),
            null,
            null,
            Loop::get(),
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            null,
            null,
            null,
            $this->controller($links)(...),
        );
        $server->start();
        $port = (int) $server->port();
        $socket = null;
        try {
            $socket = @\stream_socket_client('127.0.0.1:' . $port, $errno, $errstr, 5.0);
            self::assertNotFalse($socket, 'connect: ' . $errstr);
            $bytes = "GET /canvas/{$token} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\n\r\n";
            self::assertSame(\strlen($bytes), @\fwrite($socket, $bytes));
            $this->pumpLoop(0.25);
            self::assertStringStartsWith('HTTP/1.1 200', $this->drainSocket($socket), 'sanity: the page was served — this is about what the log kept');

            self::assertContains('GET /canvas/' . \substr($token, 0, 4) . '… 200', $lines, 'the line names the page without holding the key');
            foreach ($lines as $line) {
                self::assertStringNotContainsString($token, $line, 'the capability never reaches the log whole');
            }
        } finally {
            if (\is_resource($socket)) {
                @\fclose($socket);
            }
            $server->stop();
            $reap = Loop::addTimer(0.05, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($reap);
        }
    }

    private function controller(?CanvasLinks $links = null): CanvasPageController
    {
        return new CanvasPageController($links ?? CanvasLinks::new());
    }

    private function routerWith(CanvasPageController $controller): Router
    {
        return new Router(
            static fn(): ResponseInterface => Responses::error(500, 'no', 'upgrade must not run'),
            static fn(): ResponseInterface => Responses::error(500, 'no', 'api must not run'),
            static fn(): ResponseInterface => Responses::error(500, 'no', 'static must not run'),
            null,
            $controller(...),
        );
    }

    private function mintUrl(CanvasPageController $controller, string $artifact): string
    {
        $mint = $controller($this->canvasRequest('POST', '/api/canvas', [
            'sessionId' => $this->sessionId,
            'artifactId' => $artifact,
        ]));
        self::assertSame(200, $mint->getStatusCode(), 'sanity: the mint under test succeeded');
        $answer = (array) json_decode((string) $mint->getBody(), true);

        return (string) $answer['url'];
    }

    private function storeDocument(string $bytes, string $extension = 'html'): string
    {
        $path = MediaStore::forSession($this->sessionId)->putDocument($bytes, $extension);

        return MediaStore::idOf(\basename($path));
    }

    private function documentPath(string $artifactId): string
    {
        return (string) MediaStore::forSession($this->sessionId)->pathFor($artifactId);
    }

    private function canvasRequest(string $method, string $path, mixed $body = null): ServerRequest
    {
        if (\is_array($body)) {
            $body = \json_encode($body);
        }

        return new ServerRequest($method, 'http://127.0.0.1:7420' . $path, [], $body ?? 'php://input');
    }

    /** @return string the refusal `kind`, for pinning which arm answered */
    private function refusalKind(ResponseInterface $response): string
    {
        $body = (array) json_decode((string) $response->getBody(), true);

        return (string) $body['error']['kind'];
    }

    /** Hand the shared loop $seconds of runtime, then take control back. */
    private function pumpLoop(float $seconds): void
    {
        $timer = Loop::addTimer($seconds, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
    }

    /** Read whatever the answered socket still holds, then stop waiting. */
    private function drainSocket($socket): string
    {
        \stream_set_blocking($socket, false);
        $bytes = '';
        while (false !== ($chunk = \fread($socket, 65536))) {
            $bytes .= $chunk;
            if ('' === $chunk) {
                break;
            }
        }

        return $bytes;
    }
}

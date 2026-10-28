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
use SugarCraft\Code\Server\Http\Responses;
use SugarCraft\Code\Server\Http\Router;
use SugarCraft\Code\Server\Http\SharePageController;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Share\ShareStore;

/**
 * `/s/{token}` through the real Router (SES-3, COMPARISON §6.5): public by
 * the auth middleware's own rule, dead tokens one indistinguishable 404,
 * expiry and revocation enforced per request (every test re-answers the
 * SAME controller — nothing here caches), and the static UI kept behind the
 * share branch instead of in front of it.
 */
final class SharePageTest extends TestCase
{
    private string $dir = '';

    private string $dbPath = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/sc-sharepage-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o700);
        $this->dbPath = $this->dir . '/session.db';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);
    }

    public function testSharePagesAreNotProtectedByTheAuthMiddleware(): void
    {
        // The controller's publicness is AuthMiddleware's OWN predicate —
        // if /api/ ever widens to cover /s/, this reddens and the page's
        // security notes have to be re-read, not silently broken.
        self::assertFalse(AuthMiddleware::isProtected('/s/' . str_repeat('0f', 16)));
        self::assertTrue(AuthMiddleware::isProtected('/api/sessions'), 'sanity: the guard still guards');
    }

    public function testALiveTokenServesTheSnapshotWithItsContentTypeAndNoStore(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-1', 'html', "<!DOCTYPE html>\n<p>served</p>", 7);

        $response = $this->route($store, $record->path());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'), 'a revoke must land on the NEXT request');
        self::assertStringContainsString('<p>served</p>', (string) $response->getBody());
        // Router::secured applies on this path too: the snapshot never frames.
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    /**
     * C-SES-3.d: a LIVE row is not a frozen page — the controller asks its
     * renderer for the session's current transcript on EVERY request, and
     * the stored publish-time HTML is only the fallback for a serve built
     * without a renderer. The same public surface, the same headers: the
     * live page answers with no-store for the strongest of reasons (it must
     * never cache what the next edit will change).
     */
    public function testALiveLinkReRendersTheSessionOnEveryRequest(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $page = '<p>EARLY</p>';
        $renders = 0;
        $renderer = function (string $sessionId) use (&$page, &$renders): ?string {
            $renders++;
            self::assertSame('sess-live', $sessionId, 'the renderer is asked for the record\'s own session');

            return $page;
        };
        $record = $store->publish('sess-live', 'html', '<p>SNAPSHOT-FALLBACK</p>', 7, live: true);

        $first = $this->route($store, $record->path(), liveRenderer: $renderer);
        self::assertSame(200, $first->getStatusCode());
        self::assertStringContainsString('<p>EARLY</p>', (string) $first->getBody());
        self::assertStringNotContainsString('SNAPSHOT-FALLBACK', (string) $first->getBody(), 'a live row serves the render, not the stored fallback');
        self::assertSame('no-store', $first->getHeaderLine('Cache-Control'));
        self::assertSame('text/html; charset=utf-8', $first->getHeaderLine('Content-Type'));
        self::assertSame('DENY', $first->getHeaderLine('X-Frame-Options'));

        $page = '<p>LATE</p>';
        $second = $this->route($store, $record->path(), liveRenderer: $renderer);
        self::assertStringContainsString('<p>LATE</p>', (string) $second->getBody());
        self::assertStringNotContainsString('<p>EARLY</p>', (string) $second->getBody());
        self::assertSame(2, $renders, 'the renderer runs per request, nothing about the page is cached');
    }

    public function testALiveRowWithoutAWiredRendererFallsBackToItsStoredSnapshot(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-1', 'html', '<p>frozen-fallback</p>', 7, live: true);

        $response = $this->route($store, $record->path());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<p>frozen-fallback</p>', (string) $response->getBody());
    }

    public function testSnapshotRowsNeverConsultTheLiveRenderer(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $renders = 0;
        $renderer = function () use (&$renders): ?string {
            $renders++;

            return '<p>live-render</p>';
        };
        $snapshot = $store->publish('sess-1', 'html', '<p>stored</p>', 7);

        $response = $this->route($store, $snapshot->path(), liveRenderer: $renderer);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<p>stored</p>', (string) $response->getBody());
        self::assertStringNotContainsString('live-render', (string) $response->getBody());
        self::assertSame(0, $renders, 'the frozen-snapshot promise holds for rows published without --live');
    }

    public function testALiveLinkWhoseSessionIsGoneAnswersTheSharedDeath(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-gone', 'html', '<p>old</p>', 7, live: true);
        $renderer = static fn(string $sessionId): ?string => null;

        $dead = $this->route($store, $record->path(), liveRenderer: $renderer);
        $unknown = $this->route($store, '/s/' . str_repeat('0f', 16));

        self::assertSame(404, $dead->getStatusCode());
        self::assertSame((string) $unknown->getBody(), (string) $dead->getBody(), 'a deleted session is indistinguishable from a token that never existed');
    }

    public function testRevokedAndExpiredLiveRowsNeverReachTheRenderer(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $renders = 0;
        $renderer = function () use (&$renders): ?string {
            $renders++;

            return '<p>x</p>';
        };
        $revoked = $store->publish('sess-1', 'html', '<p>x</p>', 7, live: true);
        $expired = $store->publish('sess-1', 'html', '<p>x</p>', 7, live: true);

        self::assertSame(200, $this->route($store, $revoked->path(), liveRenderer: $renderer)->getStatusCode());
        self::assertSame(1, $renders);

        $store->revoke($revoked->token);
        self::assertSame(404, $this->route($store, $revoked->path(), liveRenderer: $renderer)->getStatusCode());
        self::assertSame(1, $renders, 'revocation is enforced before the session is read');

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->prepare('UPDATE shares SET expires_at = :past WHERE token = :token')
            ->execute([':token' => $expired->token, ':past' => \time() - 1]);
        $pdo = null;
        self::assertSame(404, $this->route($store, $expired->path(), liveRenderer: $renderer)->getStatusCode());
        self::assertSame(1, $renders, 'expiry is enforced before the session is read');
    }

    /**
     * The no-oracle law, byte-exact: a token that never existed, one past
     * expiry and one just revoked answer the SAME status, body and type.
     */
    public function testUnknownExpiredAndRevokedAreOneIndistinguishableAnswer(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $live = $store->publish('sess-1', 'html', '<p>x</p>', 7);
        $expired = $store->publish('sess-1', 'html', '<p>x</p>', 7);
        $revoked = $store->publish('sess-1', 'html', '<p>x</p>', 7);

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->prepare('UPDATE shares SET expires_at = :past WHERE token = :token')
            ->execute([':token' => $expired->token, ':past' => \time() - 1]);
        $pdo = null;
        $store->revoke($revoked->token);

        $deaths = [
            'unknown' => $this->route($store, '/s/' . str_repeat('0f', 16)),
            'expired' => $this->route($store, '/s/' . $expired->token),
            'revoked' => $this->route($store, '/s/' . $revoked->token),
        ];
        foreach ($deaths as $label => $response) {
            self::assertSame(404, $response->getStatusCode(), $label);
            self::assertSame((string) $deaths['unknown']->getBody(), (string) $response->getBody(), $label . ' body differs');
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'), $label);
        }
        self::assertSame(200, $this->route($store, '/s/' . $live->token)->getStatusCode());
    }

    public function testRevocationKillsThePageOnTheVeryNextRequest(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-1', 'html', '<p>x</p>', 7);
        $controller = new SharePageController($store);

        self::assertSame(200, $controller($this->request('GET', $record->path()))->getStatusCode());
        $store->revoke($record->token);
        self::assertSame(404, $controller($this->request('GET', $record->path()))->getStatusCode());
    }

    public function testPostToASharePageIsRefusedWithAllow(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-1', 'html', '<p>x</p>', 7);

        $response = $this->route($store, $record->path(), 'POST');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    public function testTraversalAndEmptyTokenNeverReachTheStoreAsACandidate(): void
    {
        $store = ShareStore::atPath($this->dbPath);

        self::assertSame(404, $this->route($store, '/s/')->getStatusCode());
        self::assertSame(404, $this->route($store, '/s/' . str_repeat('0f', 16) . '/extra')->getStatusCode());
        self::assertSame(404, $this->route($store, '/s/hello')->getStatusCode());
    }

    public function testWithoutAShareHandlerThePrefixFallsThroughToTheUi(): void
    {
        $router = new Router(
            static fn(): ResponseInterface => Responses::error(500, 'no', 'upgrade must not run'),
            static fn(): ResponseInterface => Responses::error(500, 'no', 'api must not run'),
            static fn(): ResponseInterface => Responses::json(200, ['ui' => true]),
        );

        $response = $router($this->request('GET', '/s/' . str_repeat('0f', 16)));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('ui', (string) $response->getBody());
    }

    public function testTheShareBranchSitsAheadOfTheStaticFallthrough(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-1', 'html', '<p>x</p>', 7);

        // A web root that WOULD answer /s/... with the SPA index: the share
        // branch must win, or the per-request revocation check hides behind
        // a client-side route.
        $indexDir = $this->dir . '/dist';
        \mkdir($indexDir, 0o700);
        \file_put_contents($indexDir . '/index.html', '<!doctype html><title>spa</title>');
        $files = StaticFiles::new($indexDir);
        $share = new SharePageController($store);
        $router = new Router(
            static fn(): ResponseInterface => Responses::error(500, 'no', 'upgrade must not run'),
            static fn(): ResponseInterface => Responses::error(500, 'no', 'api must not run'),
            $files(...),
            $share(...),
        );

        $response = $router($this->request('GET', $record->path()));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('spa', (string) $response->getBody());
        self::assertStringContainsString('<p>x</p>', (string) $response->getBody());
    }

    public function testTheApiAndStaticPathsAreUntouchedByTheBranch(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $share = new SharePageController($store);
        $router = new Router(
            static fn(): ResponseInterface => Responses::error(500, 'no', 'upgrade must not run'),
            static fn(): ResponseInterface => Responses::error(418, 'api', 'the api answered'),
            static fn(): ResponseInterface => Responses::error(418, 'static', 'the ui answered'),
            $share(...),
        );

        self::assertSame('api', $this->decodeKind($router($this->request('GET', '/api/sessions'))));
        self::assertSame('static', $this->decodeKind($router($this->request('GET', '/index.html'))));
        self::assertSame('static', $this->decodeKind($router($this->request('GET', '/secrets'))), 'a path merely STARTING with the prefix letter stays UI-side');
    }

    /**
     * The request log is a plain line per request, but for a share page the
     * path itself carries the whole capability — logged verbatim, the log
     * file becomes a directory of live unauthenticated transcript URLs
     * (C-SES-3). This drives a REAL listener: the middleware closure is not
     * reachable any other way, and a redaction unit-tested in isolation
     * proves nothing about the line the server actually writes.
     */
    public function testTheAccessLogRedactsTheShareTokenItJustServed(): void
    {
        $store = ShareStore::atPath($this->dbPath);
        $record = $store->publish('sess-1', 'html', '<p>served</p>', 7);
        $controller = new SharePageController($store);

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
            $controller(...),
        );
        $server->start();
        $port = (int) $server->port();
        $socket = null;
        try {
            $socket = @\stream_socket_client('127.0.0.1:' . $port, $errno, $errstr, 5.0);
            self::assertNotFalse($socket, 'connect: ' . $errstr);
            $bytes = 'GET ' . $record->path() . " HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\n\r\n";
            self::assertSame(\strlen($bytes), @\fwrite($socket, $bytes));
            $this->pumpLoop(0.25);
            self::assertStringStartsWith('HTTP/1.1 200', $this->drainSocket($socket), 'sanity: the page was served — this is about what the log kept');

            self::assertContains('GET /s/' . \substr($record->token, 0, 4) . '… 200', $lines, 'the line names the page without holding the key');
            foreach ($lines as $line) {
                self::assertStringNotContainsString($record->token, $line, 'the capability never reaches the log whole');
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

    private function route(ShareStore $store, string $path, string $method = 'GET', ?\Closure $liveRenderer = null): ResponseInterface
    {
        $share = new SharePageController($store, $liveRenderer);
        $router = new Router(
            static fn(): ResponseInterface => Responses::error(500, 'no', 'upgrade must not run'),
            static fn(): ResponseInterface => Responses::error(500, 'no', 'api must not run'),
            static fn(): ResponseInterface => Responses::error(500, 'no', 'static must not run'),
            $share(...),
        );

        return $router($this->request($method, $path));
    }

    private function request(string $method, string $path): ServerRequest
    {
        return new ServerRequest($method, 'http://127.0.0.1:7420' . $path);
    }

    /** @return string the refusal `kind`, for pinning which arm answered */
    private function decodeKind(ResponseInterface $response): string
    {
        $body = (array) json_decode((string) $response->getBody(), true);

        return (string) $body['error']['kind'];
    }
}

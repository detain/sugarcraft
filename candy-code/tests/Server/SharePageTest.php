<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\ServerRequest;
use SugarCraft\Code\Server\Http\AuthMiddleware;
use SugarCraft\Code\Server\Http\Responses;
use SugarCraft\Code\Server\Http\Router;
use SugarCraft\Code\Server\Http\SharePageController;
use SugarCraft\Code\Server\Http\StaticFiles;
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

    private function route(ShareStore $store, string $path, string $method = 'GET'): ResponseInterface
    {
        $share = new SharePageController($store);
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

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\ServerRequest;
use SugarCraft\Crush\Server\Http\StaticFiles;

/**
 * The web UI's files, served from one directory with the URL held to it —
 * after symlinks — and the SPA fallback for extensionless routes.
 */
final class StaticFilesTest extends TestCase
{
    private string $webRoot;

    private string $outside;

    protected function setUp(): void
    {
        $base = \sys_get_temp_dir() . '/sc-web-' . \bin2hex(\random_bytes(4));
        $this->webRoot = $base . '/dist';
        $this->outside = $base . '/secret.txt';
        \mkdir($this->webRoot . '/assets', 0o700, true);
        \file_put_contents($this->webRoot . '/index.html', '<!doctype html><title>app</title>');
        \file_put_contents($this->webRoot . '/assets/app-1a2b.js', 'console.log(1)');
        \file_put_contents($this->webRoot . '/favicon.svg', '<svg/>');
        \file_put_contents($this->webRoot . '/.env', 'SECRET=1');
        \file_put_contents($this->outside, 'outside the root');
        \symlink($this->outside, $this->webRoot . '/leak.txt');
    }

    protected function tearDown(): void
    {
        $base = \dirname($this->webRoot);
        foreach (['/dist/leak.txt', '/dist/.env', '/dist/favicon.svg', '/dist/assets/app-1a2b.js', '/dist/index.html', '/secret.txt'] as $file) {
            @\unlink($base . $file);
        }
        @\rmdir($this->webRoot . '/assets');
        @\rmdir($this->webRoot);
        @\rmdir($base);
    }

    private function fetch(StaticFiles $files, string $path, string $method = 'GET'): ResponseInterface
    {
        return $files(new ServerRequest($method, 'http://127.0.0.1:7420' . $path));
    }

    public function testFilesAreServedWithTheirTypeAndCachePolicy(): void
    {
        $files = StaticFiles::new($this->webRoot);

        $index = $this->fetch($files, '/');
        self::assertSame(200, $index->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $index->getHeaderLine('Content-Type'));
        self::assertSame('no-cache', $index->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('<title>app</title>', (string) $index->getBody());

        $asset = $this->fetch($files, '/assets/app-1a2b.js');
        self::assertSame('text/javascript; charset=utf-8', $asset->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=31536000, immutable', $asset->getHeaderLine('Cache-Control'));

        self::assertSame('image/svg+xml', $this->fetch($files, '/favicon.svg')->getHeaderLine('Content-Type'));
        self::assertSame(200, $this->fetch($files, '/assets/app-1a2b.js', 'HEAD')->getStatusCode());
    }

    public function testAnExtensionlessRouteGetsTheShellAndAMissingAssetDoesNot(): void
    {
        $files = StaticFiles::new($this->webRoot);

        $route = $this->fetch($files, '/session/abc');
        self::assertSame(200, $route->getStatusCode());
        self::assertStringContainsString('<title>app</title>', (string) $route->getBody());

        self::assertSame(404, $this->fetch($files, '/assets/missing.js')->getStatusCode());
    }

    public function testTraversalDotfilesLinksOutAndNulAreRefused(): void
    {
        $files = StaticFiles::new($this->webRoot);

        foreach (['/../secret.txt', '/assets/../../secret.txt', '/%2e%2e/secret.txt', '/.env', '/leak.txt', "/index.html%00.js", '/a\\b.js'] as $path) {
            $response = $this->fetch($files, $path);
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertStringNotContainsString('outside the root', (string) $response->getBody(), $path);
            self::assertStringNotContainsString('SECRET', (string) $response->getBody(), $path);
        }
    }

    public function testOnlyGetAndHeadAreServed(): void
    {
        $response = $this->fetch(StaticFiles::new($this->webRoot), '/', 'POST');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    public function testWithoutAWebRootTheRootIsANoticeAndEverythingElse404(): void
    {
        $files = StaticFiles::new(null);

        $notice = $this->fetch($files, '/');
        self::assertSame(200, $notice->getStatusCode());
        self::assertStringContainsString('composer require sugarcraft/sugar-crush-web', (string) $notice->getBody());
        self::assertSame(404, $this->fetch($files, '/assets/app.js')->getStatusCode());
        self::assertNull($files->root());
    }

    public function testANonDirectoryWebRootIsRefusedUpFront(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StaticFiles::new($this->webRoot . '/index.html');
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\ServerRequest;
use SugarCraft\Code\Server\Http\StaticFiles;

/**
 * The web UI's files, served from one directory with the URL held to it —
 * after symlinks — the SPA fallback for extensionless routes, and the weak
 * ETag/304 revalidation that spare a re-reading the disk (CWPD-5).
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
        \file_put_contents($this->webRoot . '/sw.js', 'self.onpush = () => {};');
        \file_put_contents($this->webRoot . '/.env', 'SECRET=1');
        \file_put_contents($this->outside, 'outside the root');
        \symlink($this->outside, $this->webRoot . '/leak.txt');
    }

    protected function tearDown(): void
    {
        $base = \dirname($this->webRoot);
        foreach (['/dist/leak.txt', '/dist/.env', '/dist/favicon.svg', '/dist/sw.js', '/dist/assets/app-1a2b.js', '/dist/index.html', '/secret.txt'] as $file) {
            @\unlink($base . $file);
        }
        @\rmdir($this->webRoot . '/assets');
        @\rmdir($this->webRoot);
        @\rmdir($base);
    }

    /**
     * CWPD-5: a weak (mtime, size) validator answers revalidations with a
     * 304 instead of a full re-read and re-transfer, for the no-cache shell
     * and the immutable assets alike.
     */
    public function testA200CarriesAStableWeakValidator(): void
    {
        $files = StaticFiles::new($this->webRoot);

        $first = $this->fetch($files, '/');
        $second = $this->fetch($files, '/');
        $etag = $first->getHeaderLine('ETag');
        self::assertNotSame('', $etag, 'a served file carries a validator');
        self::assertStringStartsWith('W/"', $etag, 'weak: byte equality across an edit is not promised');
        self::assertSame($etag, $second->getHeaderLine('ETag'), 'mtime+size is stable between reads');
    }

    public function testARevalidationWithTheCurrentValidatorGetsAnEmpty304WithTheSameCachePolicy(): void
    {
        $files = StaticFiles::new($this->webRoot);

        foreach (['/' => 'no-cache', '/assets/app-1a2b.js' => 'public, max-age=31536000, immutable', '/sw.js' => 'no-cache'] as $path => $cacheControl) {
            $etag = $this->fetch($files, $path)->getHeaderLine('ETag');
            self::assertNotSame('', $etag, $path);

            $revalidated = $files(new ServerRequest('GET', 'http://127.0.0.1:7420' . $path, ['If-None-Match' => $etag]));
            self::assertSame(304, $revalidated->getStatusCode(), $path);
            self::assertSame('', (string) $revalidated->getBody(), 'a 304 never carries a body');
            self::assertSame($cacheControl, $revalidated->getHeaderLine('Cache-Control'), "the 304 repeats the representation's cache policy for $path");
            self::assertSame($etag, $revalidated->getHeaderLine('ETag'));
        }
    }

    public function testAWeakPrefixQuotedListAndStarAllCompareForRevalidation(): void
    {
        $files = StaticFiles::new($this->webRoot);
        $etag = $this->fetch($files, '/')->getHeaderLine('ETag');
        $bare = \trim(\substr($etag, 2), '"');

        foreach ([$etag, '"' . $bare . '"', $bare, 'garbage, ' . $etag, '*'] as $sent) {
            $response = $files(new ServerRequest('GET', 'http://127.0.0.1:7420/', ['If-None-Match' => $sent]));
            self::assertSame(304, $response->getStatusCode(), $sent);
        }
    }

    public function testAStaleValidatorStillGetsTheFullBody(): void
    {
        $files = StaticFiles::new($this->webRoot);

        $response = $files(new ServerRequest('GET', 'http://127.0.0.1:7420/', ['If-None-Match' => 'W/"0-0"']));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>app</title>', (string) $response->getBody());
    }

    public function testTheWebRootNoticeKeepsNoStoreAndGainsNoValidator(): void
    {
        $files = StaticFiles::new(null);

        $notice = $this->fetch($files, '/');
        self::assertSame(200, $notice->getStatusCode());
        self::assertSame('no-store', $notice->getHeaderLine('Cache-Control'), 'the notice is never cacheable — it is the state, not a file');
        self::assertSame('', $notice->getHeaderLine('ETag'), 'no validator on a generated page');
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

    /**
     * C-WEB-5.e: the push worker's URL must stay stable for its registered
     * scope, so it sits at the dist root with an unhashed name and is
     * revalidated on every navigation — an immutable pin here would stall
     * worker updates for a year.
     */
    public function testTheServiceWorkerScriptIsRevalidatedOnEveryNavigation(): void
    {
        $files = StaticFiles::new($this->webRoot);

        $worker = $this->fetch($files, '/sw.js');
        self::assertSame(200, $worker->getStatusCode());
        self::assertSame('text/javascript; charset=utf-8', $worker->getHeaderLine('Content-Type'));
        self::assertSame('no-cache', $worker->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('onpush', (string) $worker->getBody());
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
        self::assertStringContainsString('composer require sugarcraft/candy-code-web', (string) $notice->getBody());
        self::assertSame(404, $this->fetch($files, '/assets/app.js')->getStatusCode());
        self::assertNull($files->root());
    }

    public function testANonDirectoryWebRootIsRefusedUpFront(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StaticFiles::new($this->webRoot . '/index.html');
    }

    /**
     * CWPM-10: the Composer instruction reaches the page only where Composer
     * actually works. Inside a phar the vendor tree is frozen, so the bundled
     * build must be pointed at a build through --web-root instead of at an
     * install command it could never satisfy.
     */
    public function testTheNoticePageBranchesOnTheRuntime(): void
    {
        $branch = new \ReflectionMethod(StaticFiles::class, 'noticePageFor');

        $composer = (string) $branch->invoke(null, false);
        $phar = (string) $branch->invoke(null, true);

        self::assertStringContainsString('composer require sugarcraft/candy-code-web', $composer, 'a Composer runtime can act on the package advice');
        self::assertStringNotContainsString('composer require', $phar, 'a phar cannot add packages to its frozen vendor');
        self::assertStringContainsString('--web-root', $phar, 'the bundled build is pointed at a root it can serve');
        self::assertStringContainsString('/api/', $composer);
        self::assertStringContainsString('/api/', $phar, 'both pages promise the API works without a UI');
    }
}

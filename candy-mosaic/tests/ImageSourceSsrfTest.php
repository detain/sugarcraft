<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Tests\Support\LoopbackHttpServer;

/**
 * SSRF regression coverage for ImageSource::fromUrl()'s synchronous
 * redirect follower.
 *
 * The fetcher follows redirects manually (`max_redirects: 0`) and
 * re-validates BOTH the scheme AND the resolved host/IP of every hop, so a
 * 3xx into a disallowed scheme (file://, gopher://, …) or into a private /
 * cloud-metadata IP (169.254.169.254, …) cannot smuggle past the caller's
 * guards. These tests drive a real, ephemeral `php -S` server on 127.0.0.1
 * that issues such redirects. The loopback server host is explicitly
 * allow-listed so the private-IP deny-list does not block the test harness
 * itself.
 *
 * @covers \SugarCraft\Mosaic\ImageSource
 */
final class ImageSourceSsrfTest extends TestCase
{
    private ?LoopbackHttpServer $server = null;
    private string $tmpDir = '';
    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = LoopbackHttpServer::makeTempDir();

        $png    = __DIR__ . '/fixtures/4x2.png';
        $router = $this->tmpDir . '/' . LoopbackHttpServer::ROUTER_FILE;
        file_put_contents($router, $this->routerSource($png));

        $this->server = LoopbackHttpServer::start($router);
        if ($this->server === null) {
            $this->markTestSkipped('Could not start a local php -S server for SSRF tests.');
        }

        $this->port = $this->server->port();
    }

    protected function tearDown(): void
    {
        // Blocking reap: the server MUST be dead before this method returns.
        // See LoopbackHttpServer for why the old proc_terminate()/proc_close()
        // pair orphaned one server per test method.
        $this->server?->stop();
        $this->server = null;

        LoopbackHttpServer::removeTempDir($this->tmpDir);
        $this->tmpDir = '';

        parent::tearDown();
    }

    public function testFollowsSameSchemeRedirect(): void
    {
        // Regression guard: the manual follower must still follow a legitimate
        // http -> http redirect all the way to the image. Loopback is
        // allow-listed so the private-IP deny-list does not block it — and this
        // doubles as proof the allow-list bypass works end-to-end.
        $img = ImageSource::fromUrl(
            "http://127.0.0.1:{$this->port}/redirect-ok",
            allowedHosts: ['127.0.0.1'],
        );

        $this->assertSame('image/png', $img->format);
        $this->assertSame(4, $img->width);
        $this->assertSame(2, $img->height);
    }

    public function testBlocksRedirectToDisallowedFileScheme(): void
    {
        // A 302 into file:///etc/passwd must be rejected on scheme re-validation,
        // not followed into a local-file read.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/scheme file is not in the allowed list/');

        ImageSource::fromUrl(
            "http://127.0.0.1:{$this->port}/redirect-file",
            allowedHosts: ['127.0.0.1'],
        );
    }

    public function testBlocksRedirectToDisallowedGopherScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/scheme gopher is not in the allowed list/');

        ImageSource::fromUrl(
            "http://127.0.0.1:{$this->port}/redirect-gopher",
            allowedHosts: ['127.0.0.1'],
        );
    }

    public function testBlocksRedirectToMetadataIp(): void
    {
        // The #1325 fix was scheme-only: a same-scheme http -> http 302 into
        // the cloud-metadata IP still reached it. The host/IP deny-list must
        // reject the redirect target BEFORE fetching it, even though the first
        // hop (loopback) is allow-listed.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');

        ImageSource::fromUrl(
            "http://127.0.0.1:{$this->port}/redirect-metadata",
            allowedHosts: ['127.0.0.1'],
        );
    }

    // ---- helpers --------------------------------------------------------

    private function routerSource(string $pngPath): string
    {
        $png = var_export($pngPath, true);

        return <<<PHP
        <?php
        \$p = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);
        switch (\$p) {
            case '/health':
                echo 'ok';
                return true;
            case '/poster.png':
                header('Content-Type: image/png');
                echo file_get_contents({$png});
                return true;
            case '/redirect-ok':
                header('Location: /poster.png', true, 302);
                return true;
            case '/redirect-file':
                header('Location: file:///etc/passwd', true, 302);
                return true;
            case '/redirect-gopher':
                header('Location: gopher://127.0.0.1:70/x', true, 302);
                return true;
            case '/redirect-metadata':
                header('Location: http://169.254.169.254/latest/meta-data/', true, 302);
                return true;
            default:
                http_response_code(404);
                echo 'nf';
                return true;
        }
        PHP;
    }
}

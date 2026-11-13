<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Tests\Support\LoopbackHttpServer;

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
 * DECLARED BOUNDARY — connect-time TOCTOU residual (round-90 audit MEDIUM-1,
 * decision: disclose, not pin). Every green here asserts CHECK-TIME guard
 * semantics: the guard judges each hop's host BEFORE the fetch, and the fetch
 * then re-resolves it. These four tests drive literal-IP redirects over a real
 * loopback server; the multi-record name-resolution case is pinned through
 * overrideHostResolver() in the sibling ImageSourceSsrfIpTest and
 * ImageSourceAsyncSsrfTest. A TTL-0 zone answering public at check time and
 * private at connect time is NOT defended — IP-pinning the connect was judged
 * invasive for a pre-1.0 port (see the KNOWN RESIDUAL block on
 * guardHostNotPrivate() in src/ImageSource.php). Do not read these passes as
 * a connect-time guarantee.
 *
 * @covers \SugarCraft\Image\ImageSource
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

    public function testSyncSameAuthorityRedirectKeepsCredentials(): void
    {
        // finding sugar-image-101 (sync twin): a relative redirect stays on
        // one authority, so the caller's credential trio must replay verbatim
        // — shedding it here would break every internal-CDN bearer flow.
        $log = $this->tmpDir . '/authlog.jsonl';
        $this->writeRouter($log);

        $img = ImageSource::fromUrl(
            "http://127.0.0.1:{$this->port}/auth-same",
            ['Authorization' => 'Bearer sync123', 'Cookie' => 's=1', 'X-Keep' => 'k'],
            allowedHosts: ['127.0.0.1'],
        );
        $this->assertSame(4, $img->width);

        $landed = $this->lastRecord($log, '/poster-auth.png');
        $this->assertSame('Bearer sync123', $landed['authorization'] ?? null, 'same-authority replay vanished');
        $this->assertSame('s=1', $landed['cookie'] ?? null);
        $this->assertSame('k', $landed['x-keep'] ?? null);
    }

    public function testSyncCrossAuthorityRedirectShedsCredentialsKeepsOthers(): void
    {
        // Same socket, DIFFERENT authority name: the absolute Location
        // rewrites 127.0.0.1 -> localhost. The origin tuple compares host
        // strings, so this is a cross-authority hop (RFC 9110 §15.5.3) even
        // though both names reach this one server — the credentials must be
        // gone on landing while X-Keep rides on. (Skips honestly where
        // 'localhost' does not resolve to the loopback v4 address.)
        if (gethostbyname('localhost') !== '127.0.0.1') {
            $this->markTestSkipped('localhost does not resolve to 127.0.0.1 on this box');
        }

        $log = $this->tmpDir . '/authlog.jsonl';
        $this->writeRouter($log);

        $img = ImageSource::fromUrl(
            "http://127.0.0.1:{$this->port}/auth-cross",
            ['Authorization' => 'Bearer sync123', 'Cookie' => 's=1', 'X-Keep' => 'k'],
            allowedHosts: ['127.0.0.1', 'localhost'],
        );
        $this->assertSame(4, $img->width);

        $landed = $this->lastRecord($log, '/poster-auth.png');
        $this->assertSame('', $landed['authorization'] ?? '', 'cross-authority hop leaked a bearer token');
        $this->assertSame('', $landed['cookie'] ?? '', 'cross-authority hop leaked a session cookie');
        $this->assertSame('k', $landed['x-keep'] ?? null, 'non-credential headers must ride on');
    }

    public function testTheAuthorityTupleFoldsDefaultsAndComparesOrigins(): void
    {
        // Shape matrix for the carry-strip law, unit-level (no wire):
        // default-port folding, scheme/host/port movement, case, and the
        // host-less legs (file/data) that can never be "the same origin".
        $authority = static function (string $url): ?string {
            $m = new \ReflectionMethod(ImageSource::class, 'authorityOf');
            $m->setAccessible(true);

            return $m->invoke(null, $url);
        };

        $this->assertSame('http://a.test', $authority('http://a.test:80/x'), 'the default port must fold away');
        $this->assertSame('https://a.test', $authority('https://a.test:443/'));
        $this->assertSame($authority('http://a.test'), $authority('HTTP://A.TEST/x'), 'case must not forge a new origin');
        $this->assertNotSame($authority('http://a.test'), $authority('http://a.test:8080'), 'a port move is a new authority');
        $this->assertNotSame($authority('http://a.test'), $authority('https://a.test'), 'a scheme move is a new authority');
        $this->assertNotSame($authority('http://a.test'), $authority('http://b.test'), 'a host move is a new authority');
        $this->assertNotSame($authority('http://a.test'), $authority('http://a.test.evil'), 'suffix tricks are not the same host');
        $this->assertNull($authority('file:///etc/passwd'), 'host-less legs have no authority');
        $this->assertNotSame($authority('http://a.test'), $authority('data:image/png;base64,AA'), 'no data-leg replay');
        $this->assertSame('http://[::1]', $authority('http://[::1]:80/x'), 'v6 literals keep their brackets folded to default');
    }

    public function testTheCarryStripShedsTheTrioInBothHeaderShapes(): void
    {
        // The helper must handle the pre-formatted line shape as faithfully
        // as the map: trio gone (name-case-insensitive), everything else —
        // values, casing, order — untouched. Same-authority: untouched in
        // full. The credential trio itself is the law; a same-authority hop
        // is the replay contract, not a licence to rewrite anything.
        $strip = static function (?array $headers, string $from, string $to): ?array {
            $m = new \ReflectionMethod(ImageSource::class, 'stripCredentialsForHop');
            $m->setAccessible(true);

            return $m->invoke(null, $headers, $from, $to);
        };

        $map = [
            'AUTHORIZATION'        => 'Bearer x',
            'Cookie'               => 'a=b',
            'Proxy-Authorization'  => 'Basic y',
            'X-Keep'               => 'k',
        ];
        $shed = $strip($map, 'http://a.test/', 'http://b.test/');
        $this->assertSame(['X-Keep' => 'k'], $shed);
        $this->assertSame($map, $strip($map, 'http://a.test/', 'http://a.test:80/other'), 'same authority replays verbatim');

        $lines = ['Authorization: Bearer x', 'X-Keep: k', 'cookie: a=b'];
        $this->assertSame(['X-Keep: k'], $strip($lines, 'http://a/', 'https://a'), 'line shape sheds and reindexes');

        $this->assertNull($strip(null, 'http://a/', 'http://b/'));
        $this->assertSame([], $strip([], 'http://a/', 'http://b/'));

        // file/data legs can never replay credentials:
        $this->assertSame(['X-Keep' => 'k'], $strip($map, 'http://a.test/', 'file:///etc/passwd'));
    }

    // ---- helpers --------------------------------------------------------

    private function routerSource(string $pngPath, ?string $logFile = null): string
    {
        $png = var_export($pngPath, true);
        $log = var_export($logFile ?? '', true);

        return <<<PHP
        <?php
        \$p = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);
        \$logFile = {$log};
        \$record = static function () use (\$p, \$logFile): void {
            if (\$logFile === '') {
                return;
            }
            file_put_contents(\$logFile, json_encode([
                'path'          => \$p,
                'authorization' => \$_SERVER['HTTP_AUTHORIZATION'] ?? '',
                'cookie'        => \$_SERVER['HTTP_COOKIE'] ?? '',
                'x-keep'        => \$_SERVER['HTTP_X_KEEP'] ?? '',
            ]) . "\\n", FILE_APPEND);
        };
        switch (\$p) {
            case '/health':
                echo 'ok';
                return true;
            case '/poster.png':
                header('Content-Type: image/png');
                echo file_get_contents({$png});
                return true;
            case '/auth-same':
                \$record();
                header('Location: /poster-auth.png', true, 302);
                return true;
            case '/auth-cross':
                \$record();
                header('Location: http://localhost:' . \$_SERVER['SERVER_PORT'] . '/poster-auth.png', true, 302);
                return true;
            case '/poster-auth.png':
                \$record();
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

    /**
     * Swap in a logging router (php -S re-reads the router script on every
     * request, so rewriting the file takes effect on the next hop without
     * restarting the server).
     */
    private function writeRouter(string $logFile): void
    {
        file_put_contents(
            $this->tmpDir . '/' . LoopbackHttpServer::ROUTER_FILE,
            $this->routerSource(__DIR__ . '/fixtures/4x2.png', $logFile),
        );
    }

    /** @return array<string,string>|null the last log record for $path */
    private function lastRecord(string $log, string $path): ?array
    {
        $found = null;
        foreach (explode("\n", (string) @file_get_contents($log)) as $line) {
            $rec = json_decode($line, true);
            if (is_array($rec) && ($rec['path'] ?? null) === $path) {
                $found = $rec;
            }
        }

        return $found;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;

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
    /** @var resource|null */
    private $proc = null;
    private string $tmpDir = '';
    private int $port = 0;

    /** proc_open()'s direct child — the server itself, never a wrapping shell. */
    private int $serverPid = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/mosaic-ssrf-' . uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);

        $png    = __DIR__ . '/fixtures/4x2.png';
        $router = $this->tmpDir . '/router.php';
        file_put_contents($router, $this->routerSource($png));

        if (!$this->startServer($router)) {
            $this->markTestSkipped('Could not start a local php -S server for SSRF tests.');
        }
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        $this->proc      = null;
        $this->serverPid = 0;

        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            @unlink($this->tmpDir . '/router.php');
            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    /**
     * THE SERVER MUST BE proc_open()'s OWN CHILD, or tearDown() cannot kill it.
     *
     * This asserts the shape of the fixture rather than any behaviour of
     * ImageSource, and it is here because the fixture had the defect: a
     * command STRING makes proc_open() spawn `/bin/sh -c`, which on this box
     * stays in place instead of exec-ing the server away, so
     * `proc_terminate()` signals the shell and the server survives as an
     * orphan holding its port and its inherited descriptors.
     *
     * Two assertions, because either alone is weak. "No children" alone would
     * pass on a process that failed to start anything; "comm is php" alone
     * would pass on the shell if the shell had exec-ed. Together they say the
     * one thing that matters: the pid tearDown() will signal IS the server.
     *
     * Linux-only, because that is what `/proc` needs. The behaviour it pins
     * is not Linux-specific — an array command is the right shape everywhere
     * — but the observation is.
     */
    public function testTheServerIsProcOpensDirectChildSoTearDownCanReachIt(): void
    {
        if (!is_dir('/proc/self/fd')) {
            self::markTestSkipped('/proc is not available; cannot observe the child process shape');
        }

        self::assertGreaterThan(0, $this->serverPid, 'the fixture recorded no server pid');

        $comm = trim((string) @file_get_contents('/proc/' . $this->serverPid . '/comm'));
        self::assertStringStartsWith(
            'php',
            $comm,
            "proc_open()'s child is '" . $comm . "', not the server. A wrapping shell means "
                . 'proc_terminate() signals the shell and leaks the server onto init.',
        );

        $children = glob('/proc/' . $this->serverPid . '/task/' . $this->serverPid . '/children');
        if ($children !== [] && $children !== false) {
            self::assertSame(
                '',
                trim((string) @file_get_contents($children[0])),
                'the server pid has children of its own, so it is a wrapper rather than the server',
            );
        }
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

    private function startServer(string $router): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $port = $this->freePort();
            if ($port === 0) {
                continue;
            }

            // An ARGV ARRAY, not a command string, and the reason is a leak
            // rather than a style preference. MEASURED, PHP 8.3.6, three
            // takes: with a string, proc_open() spawns `/bin/sh -c` and the
            // shell does NOT exec the server away — `proc_get_status()`
            // reports a child whose `comm` is `sh`, with the `php -S` as ITS
            // child. tearDown()'s `proc_terminate()` then signals the shell,
            // the server is orphaned onto init, and it keeps its port and its
            // inherited descriptors for the life of the box. Driving this
            // file alone left four `php -S` processes at PPID 1, one per
            // test; a box that had been running the suite for a day carried
            // 140 of them. With an array the server IS proc_open()'s child
            // and the same `proc_terminate()` reaches it.
            //
            // The inherited-descriptor half is the part that bites someone
            // else: an orphan holds the write end of whatever pipe the runner
            // used, so `phpunit | tail` on this package appears to hang long
            // after the suite has finished. It cost this lane thirteen
            // minutes before the cause was found.
            $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router];

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ];
            $proc = proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                continue;
            }
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            $this->proc = $proc;
            $this->port = $port;
            $status     = proc_get_status($proc);
            $this->serverPid = \is_array($status) ? (int) $status['pid'] : 0;

            if ($this->waitForHealth($port)) {
                return true;
            }

            proc_terminate($proc);
            proc_close($proc);
            $this->proc = null;
        }

        return false;
    }

    private function freePort(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $_, $errstr);
        if ($sock === false) {
            return 0;
        }
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitForHealth(int $port): bool
    {
        for ($i = 0; $i < 50; $i++) {
            $body = @file_get_contents(
                "http://127.0.0.1:{$port}/health",
                false,
                stream_context_create(['http' => ['timeout' => 1]]),
            );
            if ($body === 'ok') {
                return true;
            }
            usleep(100_000);
        }

        return false;
    }
}

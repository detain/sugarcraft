<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\AccessControl;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\Repo;

/**
 * Smart-HTTP routing hardening: HTTP verbs are enforced per endpoint,
 * owner-namespaced clone paths resolve to the deepest repo segment, and
 * the push endpoint answers authorization BEFORE ever touching a body.
 *
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\Server
 */
final class RouteHardeningTest extends TestCase
{
    private string $tmpDir;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = \sys_get_temp_dir() . '/route-harden-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);

        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "name: RouteHarden\ngit:\n allow_anonymous_read: true\n"
        );
        $this->server = new Server(Config::load($this->tmpDir . '/config.yaml'));

        $path = $this->tmpDir . '/widget.git';
        \mkdir($path, 0755, true);
        \exec('git init --bare -q ' . \escapeshellarg($path) . ' 2>&1');
        // Well-known empty tree object: commit-tree on it needs no blobs.
        $commit = \trim((string) \exec(
            'git -C ' . \escapeshellarg($path)
            . ' -c user.email=t@t -c user.name=t commit-tree'
            . ' 4b825dc642cb6eb9a060e54bf8d69288fbee4904 -m empty 2>/dev/null'
        ));
        if (!\preg_match('/^[0-9a-f]{40}$/', $commit)) {
            $this->markTestSkipped('git commit-tree unavailable in this environment');
        }
        \file_put_contents($path . '/HEAD', "ref: refs/heads/main\n");
        if (!\is_dir($path . '/refs/heads')) {
            \mkdir($path . '/refs/heads', 0755, true);
        }
        \file_put_contents($path . '/refs/heads/main', $commit . "\n");

        $this->server->registerRepo(Repo::new('widget', $path)->withPublic(true));
    }

    protected function tearDown(): void
    {
        AccessControl::instance()->setAllowAnonymousRead(false);
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @\rmdir($item->getPathname());
            } else {
                @\unlink($item->getPathname());
            }
        }
        @\rmdir($dir);
    }

    public function testServiceEndpointRejectsGet(): void
    {
        $result = $this->server->handleRequest('GET', '/widget.git/git-upload-pack', '', [], '');

        $this->assertSame(405, $result['status']);
        $this->assertSame('Use POST for git-upload-pack', $result['body']);
    }

    public function testInfoRefsRejectsPost(): void
    {
        $result = $this->server->handleRequest(
            'POST',
            '/widget/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertSame(405, $result['status']);
        $this->assertSame('Use GET for info/refs', $result['body']);
    }

    public function testOwnerNamespacedPathResolvesTheDeepestSegment(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/alice/widget.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('# service=git-upload-pack', $result['body']);
        $this->assertStringContainsString('refs/heads/main', $result['body']);
    }

    public function testTrailingSegmentAfterRefsIsNotFound(): void
    {
        $result = $this->server->handleRequest('GET', '/widget/info/refs/trailing', '', [], '');

        $this->assertSame(404, $result['status']);
    }

    public function testAnonymousPushChallengesBeforeTheBodyIsTouched(): void
    {
        $result = $this->server->handleRequest(
            'POST',
            '/widget.git/git-receive-pack',
            '',
            [],
            "junk-not-a-negotiation\n"
        );

        $this->assertSame(401, $result['status']);
        $this->assertSame('Basic realm="candy-git-server"', $result['headers']['WWW-Authenticate'] ?? null);
        $this->assertStringContainsString('Authentication required', $result['body']);
    }
}

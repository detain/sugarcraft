<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\User;

/**
 * C-13 keystone for the LIVE transport: the whole smart-HTTP listener is
 * fail-closed by default because Config::fromDefaults() (no YAML anywhere)
 * sets allow_anonymous_read=false and the Server arms AccessControl from
 * the parsed config on every gated request. An anonymous clone of a
 * published repo must therefore get the 401 + WWW-Authenticate challenge,
 * never refs — the exact behavior the review flagged as "safe by accident,
 * about to be wired to a permissive default".
 *
 * Mutation-discriminating: flipping the Config default to true (or the
 * fromDefaults git block) reddens every pin here; replacing the
 * config-armed lever in Server::handleInfoRefs with the old hardcoded
 * allow-all reddens the ServerTest anon-200 pins instead.
 *
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\Server
 * @covers \SugarCraft\GitServer\Config
 */
final class AnonymousFailClosedDefaultTest extends TestCase
{
    private string $tmpDir;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/cgs-anon-default-' . \uniqid('', true);
        \mkdir($this->tmpDir . '/repo/refs/heads', 0755, true);
        \file_put_contents($this->tmpDir . '/repo/HEAD', "ref: refs/heads/main\n");
        \file_put_contents($this->tmpDir . '/repo/refs/heads/main', \str_repeat('a', 40) . "\n");

        // THE production default: fromDefaults, no config file consulted.
        $this->server = new Server(Config::fromDefaults());
        $this->server->registerRepo(Repo::new('pub.git', $this->tmpDir . '/repo'));
    }

    protected function tearDown(): void
    {
        // Never leave the singleton lever armed for sibling files.
        \SugarCraft\GitServer\AccessControl::instance()->setAllowAnonymousRead(false);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }
        @\rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testTheParsedDefaultIsFailClosed(): void
    {
        self::assertFalse(
            Config::fromDefaults()->allowAnonymousRead,
            'git.allow_anonymous_read must default to FALSE in the parsed config'
        );
    }

    public function testAnAnonymousInfoRefsMeetsTheChallengeNotRefs(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/pub.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        self::assertSame(401, $result['status']);
        self::assertSame('Basic realm="candy-git-server"', $result['headers']['WWW-Authenticate'] ?? null);
        self::assertStringNotContainsString('refs/heads/main', $result['body']);
    }

    public function testAnAnonymousUploadPackPostAlsoRefusesToServeBytes(): void
    {
        $want = \str_pad('', 4, "\x00");
        $result = $this->server->handleRequest(
            'POST',
            '/pub.git/git-upload-pack',
            '',
            [],
            'want ' . \str_repeat('a', 40) . "\n{$want}done\n"
        );

        self::assertSame(401, $result['status']);
        self::assertSame('Basic realm="candy-git-server"', $result['headers']['WWW-Authenticate'] ?? null);
        self::assertStringNotContainsString('PACK', $result['body']);
    }
}

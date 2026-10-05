<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Protocol\Methods\ServerMethods;
use SugarCraft\Crush\Protocol\Schema\MethodSchemas;
use SugarCraft\Crush\Protocol\Schema\ProtocolSchema;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Server\Workspace\Gateway;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tests\Server\Support\WireClient;

/**
 * `serve --allow-dir-browse`: the web UI's new-session directory picker.
 * Off by default (`fs.listDirs` and a browse-rooted `workspace.open` refuse,
 * `server.hello` says so); on, it lists directory names under the browse root
 * and nothing outside it, and a session can be started in a picked root.
 */
final class DirBrowseTest extends TestCase
{
    private ?ProtocolFixture $fixture = null;

    private ?Gateway $gateway = null;

    protected function tearDown(): void
    {
        $this->gateway?->stop('test over');
        $this->fixture?->tearDown();
        if ($this->base !== '') {
            ProtocolFixture::removeTree($this->base);
        }
    }

    private string $tmp = '';

    private string $base = '';

    private function serve(bool $browse): WireClient
    {
        // The browse root is the temp directory, so it holds both the picker's
        // tree and the fixture's own project root.
        $this->tmp = (string) \realpath(\sys_get_temp_dir());
        $this->base = $this->tmp . '/crush-dirbrowse-' . \bin2hex(\random_bytes(6));
        \mkdir($this->base . '/projects/app/.git', 0o700, true);
        \mkdir($this->base . '/projects/notes', 0o700, true);
        \mkdir($this->base . '/projects/.cache', 0o700, true);
        \file_put_contents($this->base . '/projects/secret.txt', 'never listed');

        $config = ServerConfig::new($this->base . '/state');
        if ($browse) {
            $config = $config->withDirBrowse(true)->withBrowseRoot($this->tmp);
        }
        $this->fixture = ProtocolFixture::new($config);
        $home = $this->base . '/home';
        \mkdir($home, 0o700, true);
        $this->gateway = Gateway::new(
            $this->fixture->dispatcher,
            $config->withRoot($this->fixture->root),
            'test',
            Loop::get(),
            $this->fixture->dir . '/run',
            ['HOME' => $home, 'SUGARCRUSH_PROVIDER' => 'echo'],
        );
        $this->gateway->start();
        $client = WireClient::open($this->gateway, 'c1');
        $client->hello();

        return $client;
    }

    public function testItIsOffByDefault(): void
    {
        self::assertFalse(ServerConfig::new('/x')->dirBrowse);
        $client = $this->serve(false);

        $hello = WireClient::open($this->gateway, 'c2')->hello();
        self::assertSame(['enabled' => false, 'root' => null], $hello['features']['dirBrowse']);

        $refused = $client->request('fs.listDirs');
        self::assertSame('dir_browse_disabled', $refused['error']['data']['kind']);
        self::assertStringContainsString('--allow-dir-browse', $refused['error']['message']);
        self::assertSame('dir_browse_disabled', $client->request('workspace.open', ['root' => $this->fixture->root, 'browse' => true])['error']['data']['kind']);
    }

    public function testItListsDirectoryNamesUnderTheBrowseRoot(): void
    {
        $client = $this->serve(true);
        $root = $this->tmp;

        $hello = WireClient::open($this->gateway, 'c2')->hello();
        self::assertSame(['enabled' => true, 'root' => $root], $hello['features']['dirBrowse']);
        $schema = MethodSchemas::result(ServerMethods::HELLO);
        self::assertNotNull($schema);
        self::assertSame([], ProtocolSchema::errors($hello, $schema, ServerMethods::HELLO));

        $top = $client->call('fs.listDirs');
        self::assertSame($root, $top['root']);
        self::assertSame($root, $top['path']);
        self::assertNull($top['parent']);

        $name = \basename($this->base);
        $listing = $client->call('fs.listDirs', ['path' => $name . '/projects']);
        self::assertSame($this->base . '/projects', $listing['path']);
        self::assertSame($this->base, $listing['parent']);
        self::assertSame(['app', 'notes'], \array_column($listing['entries'], 'name'), 'no file, no dot-dir');
        self::assertSame([true, false], \array_column($listing['entries'], 'project'));
        self::assertStringNotContainsString('secret.txt', (string) \json_encode($listing));
        $resultSchema = MethodSchemas::result('fs.listDirs');
        self::assertNotNull($resultSchema);
        self::assertSame([], ProtocolSchema::errors($listing, $resultSchema, 'fs.listDirs'));

        self::assertSame(['.cache', 'app', 'notes'], \array_column($client->call('fs.listDirs', ['path' => $this->base . '/projects', 'showHidden' => true])['entries'], 'name'));
    }

    public function testNothingOutsideTheBrowseRoot(): void
    {
        $client = $this->serve(true);

        foreach (['..', '/etc', $this->base . '/projects/../../..'] as $path) {
            $refused = $client->request('fs.listDirs', ['path' => $path]);
            self::assertSame('outside_browse_root', $refused['error']['data']['kind'] ?? null, $path);
        }
        self::assertSame('dir_not_found', $client->request('fs.listDirs', ['path' => $this->base . '/projects/nope'])['error']['data']['kind']);
        self::assertSame('outside_browse_root', $client->request('workspace.open', ['root' => '/etc', 'browse' => true])['error']['data']['kind']);
    }

    public function testPickingTheServersOwnRootIsThePrimaryWorkspace(): void
    {
        $client = $this->serve(true);

        $opened = $client->call('workspace.open', ['root' => $this->fixture->root, 'browse' => true]);
        self::assertTrue($opened['primary']);
        $created = $client->call('session.create', ['root' => $this->fixture->root]);
        self::assertTrue($this->fixture->hub->isOpen($created['id']), 'the server\'s own dispatcher answered');
        self::assertSame([], $this->gateway->hostRoots());
    }

    public function testASessionStartsInAPickedRoot(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('workspace hosts run forked turns: needs ext-pcntl and ext-posix');
        }
        $client = $this->serve(true);
        $picked = $this->base . '/projects/notes';

        $opened = $this->await($client, 'workspace.open', ['root' => $picked, 'browse' => true]);
        self::assertFalse($opened['primary']);
        self::assertSame($picked, $opened['root']);

        $created = $this->await($client, 'session.create', ['root' => $picked]);
        self::assertSame($picked, $created['root']);
        self::assertSame($picked, $this->gateway->rootOf($created['id']));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function await(WireClient $client, string $method, array $params): array
    {
        $id = 'w' . \bin2hex(\random_bytes(4));
        $client->raw((string) \json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]));
        $deadline = \microtime(true) + 40.0;
        while ($client->response($id) === null && \microtime(true) < $deadline) {
            $this->fixture?->run(0.02);
        }
        $response = (array) $client->response($id);
        self::assertArrayHasKey('result', $response, $method . ' failed: ' . \json_encode($response));

        return (array) $response['result'];
    }
}

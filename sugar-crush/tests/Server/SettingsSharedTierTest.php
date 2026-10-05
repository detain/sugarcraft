<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Host\SessionHub;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Protocol\Dispatcher;
use SugarCraft\Crush\Protocol\Methods\SettingsMethods;
use SugarCraft\Crush\Protocol\Schema\MethodSchemas;
use SugarCraft\Crush\Protocol\Schema\ProtocolSchema;
use SugarCraft\Crush\Protocol\ServerContext;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tests\Server\Support\WireClient;

/**
 * N-P5: the web settings form offers the project-shared tier — the committed
 * `<root>/.sugar-crush/settings.json`, the TUI's "This project (shared)" — as
 * `scope: "project-shared"`, under exactly the local tier's rules (trust gate,
 * project-settable keys only), and its preview says the file is committed.
 */
final class SettingsSharedTierTest extends TestCase
{
    private string $dir;
    private string $root;
    private Dispatcher $dispatcher;
    private SessionHub $hub;

    private string|false $home = false;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/crush-shared-tier-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir . '/project', 0o700, true);
        \mkdir($this->dir . '/home/' . LayeredSettings::dir(), 0o700, true);
        $this->root = (string) \realpath($this->dir . '/project');
        $this->home = \getenv('HOME');
        \putenv('HOME=' . $this->dir . '/home');
    }

    protected function tearDown(): void
    {
        if (isset($this->dispatcher)) {
            $this->dispatcher->stop('test over');
            $this->hub->closeAll();
            $timer = Loop::addTimer(0.001, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        \putenv($this->home === false ? 'HOME' : 'HOME=' . $this->home);
        ProtocolFixture::removeTree($this->dir);
    }

    public function testTheSchemaOffersTheSharedTierForATrustedProject(): void
    {
        $tiers = \array_column($this->checked($this->client(true), 'settings.schema')['tiers'], null, 'scope');

        self::assertSame(['user', 'project', 'project-shared'], \array_keys($tiers));
        self::assertTrue($tiers['project-shared']['writable']);
        self::assertSame($this->root . '/' . LayeredSettings::SHARED_PATH, $tiers['project-shared']['path']);
        self::assertSame('This project (shared)', $tiers['project-shared']['label']);
    }

    public function testASaveToTheSharedTierWritesTheCommittedFileAndThePreviewSaysSo(): void
    {
        $client = $this->client(true);
        $shared = $this->root . '/' . LayeredSettings::SHARED_PATH;

        $preview = $this->checked($client, 'settings.preview', ['scope' => 'project-shared', 'set' => ['parallelToolCalls' => false]]);
        self::assertTrue($preview['canSave']);
        self::assertSame($shared, $preview['path']);
        self::assertContains(SettingsMethods::SHARED_NOTE, $preview['notes']);
        self::assertFileDoesNotExist($shared, 'a preview writes nothing');

        $saved = $this->checked($client, 'settings.set', ['scope' => 'project-shared', 'key' => 'parallelToolCalls', 'value' => false]);
        self::assertSame($shared, $saved['written']);
        self::assertSame(['parallelToolCalls' => false], \json_decode((string) \file_get_contents($shared), true));
        self::assertFileDoesNotExist($this->root . '/' . LayeredSettings::LOCAL_PATH, 'the local file is a different tier');

        self::assertSame(['parallelToolCalls' => false], $this->checked($client, 'settings.get', ['scope' => 'project-shared'])['values']);
    }

    public function testTheSharedTierRefusesWhatTheLocalTierRefuses(): void
    {
        $client = $this->client(true);
        $refused = $client->request('settings.set', ['scope' => 'project-shared', 'key' => 'turnIdleTimeoutSeconds', 'value' => 90]);
        self::assertSame('setting_refused', $refused['error']['data']['kind'] ?? null, (string) \json_encode($refused));
        self::assertStringContainsString('may not be set by a project file', (string) $refused['error']['message']);

        $untrusted = $this->client(false);
        $tiers = \array_column($this->checked($untrusted, 'settings.schema')['tiers'], null, 'scope');
        self::assertFalse($tiers['project-shared']['writable']);
        self::assertStringContainsString('trust', (string) $tiers['project-shared']['refusal']);
    }

    private function client(bool $trusted): WireClient
    {
        if (isset($this->dispatcher)) {
            $this->dispatcher->stop('next client');
            $this->hub->closeAll();
        }

        $config = $this->dir . '/home/' . LayeredSettings::dir() . '/config.json';
        $writer = SettingsWriter::new($config, static function (array $set, array $unset) use ($config): void {
            $current = \is_file($config) ? (array) \json_decode((string) \file_get_contents($config), true) : [];
            \file_put_contents($config, (string) \json_encode(SettingsWriter::patched($current, $set, $unset)));
        })->withProject($this->root, $trusted);

        $workspace = WorkspaceContext::new(root: $this->root)->withService(SettingsWriter::class, $writer);
        $this->hub = SessionHub::new($workspace, 4);
        $context = ServerContext::new($this->hub, ServerConfig::new($this->dir)->withRoot($this->root), 'test', Loop::get());
        $this->dispatcher = Dispatcher::new($context);

        $client = WireClient::open($this->dispatcher, 'c1');
        $client->hello();

        return $client;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function checked(WireClient $client, string $method, array $params = []): array
    {
        $result = $client->call($method, $params);
        $schema = MethodSchemas::result($method);
        self::assertNotNull($schema, $method);
        self::assertSame([], ProtocolSchema::errors($result, $schema, $method), $method . ' answered outside its schema');

        return $result;
    }
}

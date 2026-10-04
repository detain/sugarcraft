<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\LSP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\LSP\LspLauncher;

/**
 * Step 3.F: the `lsp` setting becomes running servers. Parsing is checked on
 * its own; the rest drives the fixture server in `tests/fixtures/lsp/` through
 * a REAL {@see \SugarCraft\Crush\LSP\LspConnection}, so the handshake, the
 * `publishDiagnostics` subscription, the open-ask-close touch and the shutdown
 * are the production code paths.
 *
 * @see LspLauncher
 * @see LspClient::freshDiagnostics()
 * @see LspClient::outline()
 */
final class LspLauncherTest extends TestCase
{
    private string $root;

    private string $log;

    /** @var list<LspClient> */
    private array $clients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = (string) realpath(sys_get_temp_dir()) . '/sc-lsp-launch-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0o700, true);
        $this->log = $this->root . '/initialize.log';
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->disconnectAll();
        }
        // The names this file writes, removed by name: no directory walk.
        foreach (['A.php', 'Quiet.php', 'B.php', 'Widget.php', 'Widget.inc'] as $name) {
            @unlink($this->root . '/src/' . $name);
        }
        @unlink($this->log);
        @rmdir($this->root . '/src');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testAnAbsentOrEmptySettingIsNoServersAndNoProblem(): void
    {
        foreach ([null, [], false] as $raw) {
            $launcher = LspLauncher::fromConfig($raw, $this->root);
            $this->assertFalse($launcher->hasServers());
            $this->assertSame([], $launcher->problems());
            $this->assertNull($launcher->launch()[0]);
        }
    }

    public function testMalformedEntriesAreDroppedOneByOneAndReported(): void
    {
        $launcher = LspLauncher::fromConfig([
            'php' => ['command' => 'intelephense', 'args' => ['--stdio']],
            'go' => ['args' => ['serve']],
            'rust' => ['command' => 'rust-analyzer', 'args' => '--stdio'],
            'bad name!' => ['command' => 'x'],
            'ts' => ['command' => 'tsserver', 'disabled' => true],
            'c' => ['command' => 'clangd', 'timeout' => 0],
            'py' => ['command' => 'pylsp', 'env' => ['A' => 1]],
        ], $this->root);

        $this->assertSame(['php'], $launcher->languages());
        $problems = implode("\n", $launcher->problems());
        $this->assertStringContainsString('the go entry has no "command"', $problems);
        $this->assertStringContainsString('the rust entry\'s "args" must be a list of strings', $problems);
        $this->assertStringContainsString('"bad name!" is not a language identifier', $problems);
        $this->assertStringContainsString('the c entry\'s "timeout"', $problems);
        $this->assertStringContainsString('the py entry\'s "env"', $problems);
        $this->assertCount(5, $launcher->problems(), 'five bad entries, five lines — the disabled ts server is skipped silently');

        $this->assertSame(
            ['the `lsp` setting must be an object of language => server; it was ignored'],
            LspLauncher::fromConfig(['intelephense'], $this->root)->problems(),
        );
    }

    public function testAServerThatWillNotStartCostsOnlyItselfAndIsReported(): void
    {
        [$client, $after] = LspLauncher::fromConfig([
            'nope' => ['command' => $this->root . '/no-such-language-server'],
            'php' => $this->fakeServer(),
        ], $this->root)->launch();
        $this->assertInstanceOf(LspClient::class, $client);
        $this->clients[] = $client;

        $this->assertSame(['php'], $client->servers());
        $this->assertTrue($client->isConnected('php'));
        $this->assertCount(1, $after->problems());
        $this->assertStringContainsString('the nope server', $after->problems()[0]);
    }

    public function testTheHandshakeNamesTheWorkspaceRootAndDiagnosticsSupport(): void
    {
        $client = $this->launch(['php' => $this->fakeServer() + ['initializationOptions' => ['storagePath' => '/tmp/x']]]);
        $this->assertTrue($client->isConnected('php'));

        $params = json_decode((string) file_get_contents($this->log), true);
        $this->assertIsArray($params);
        $this->assertSame(LspClient::uriFor($this->root), $params['rootUri']);
        $this->assertSame(LspClient::uriFor($this->root), $params['workspaceFolders'][0]['uri']);
        $this->assertTrue($params['capabilities']['textDocument']['publishDiagnostics']['versionSupport']);
        $this->assertSame(['storagePath' => '/tmp/x'], $params['initializationOptions']);
    }

    public function testExtensionsRouteAFileToItsServer(): void
    {
        $client = $this->launch(['php' => $this->fakeServer() + ['extensions' => ['php', '.phtml']]]);

        $this->assertSame('php', $client->languageFor('/x/a.php'));
        $this->assertSame('php', $client->languageFor('/x/a.PHTML'));
        $this->assertNull($client->languageFor('/x/a.ts'));
        $this->assertNull($client->languageFor('/x/Makefile'));
    }

    public function testFreshDiagnosticsReReadsTheFileAndWaitsForThePublishedVerdict(): void
    {
        $client = $this->launch(['php' => $this->fakeServer()]);
        $file = $this->root . '/src/A.php';

        file_put_contents($file, "<?php\n\$x = 1; // BROKEN\n// WARN\n");
        $first = $client->freshDiagnostics('php', $file, 5.0);
        $this->assertNotNull($first);
        $this->assertTrue($first['delivered']);
        $this->assertCount(2, $first['diagnostics']);
        $this->assertSame(1, $first['diagnostics'][0]['severity']);
        $this->assertSame(1, $first['diagnostics'][0]['range']['start']['line']);

        // The file changed on disk: the next touch sees the new bytes, and the
        // empty list the server published on the last `didClose` is not taken
        // for the verdict.
        file_put_contents($file, "<?php\n\$x = 1;\n");
        $second = $client->freshDiagnostics('php', $file, 5.0);
        $this->assertNotNull($second);
        $this->assertTrue($second['delivered']);
        $this->assertSame([], $second['diagnostics']);
        $this->assertTrue($client->hasDiagnostics(LspClient::uriFor($file)));
    }

    public function testASilentServerIsNotDeliveredRatherThanClean(): void
    {
        $client = $this->launch(['php' => $this->fakeServer(silent: true)]);
        $file = $this->root . '/src/Quiet.php';
        file_put_contents($file, "<?php // BROKEN\n");

        $started = microtime(true);
        $fresh = $client->freshDiagnostics('php', $file, 0.6);

        $this->assertNotNull($fresh);
        $this->assertFalse($fresh['delivered']);
        $this->assertSame([], $fresh['diagnostics']);
        $this->assertGreaterThanOrEqual(0.5, microtime(true) - $started, 'it waited for the verdict');
        $this->assertLessThan(5.0, microtime(true) - $started, 'and the wait is bounded');
    }

    public function testAServerThatCannotBeAskedAnswersNull(): void
    {
        $client = $this->launch(['php' => $this->fakeServer()]);
        $this->assertNull($client->freshDiagnostics('php', $this->root . '/src/missing.php', 0.2), 'an unreadable file');

        $client->disconnectAll();
        file_put_contents($this->root . '/src/B.php', "<?php\n");
        $this->assertNull($client->freshDiagnostics('php', $this->root . '/src/B.php', 0.2), 'a stopped server');
    }

    public function testTheOutlineComesFromTheServerWhenOneIsConfiguredAndFromTheSourceOtherwise(): void
    {
        $file = $this->root . '/src/Widget.php';
        file_put_contents($file, "<?php\n\nfinal class Widget\n{\n    public function run(): void {}\n}\n");

        $client = $this->launch(['php' => $this->fakeServer()]);
        $this->assertSame(
            [
                ['line' => 3, 'depth' => 0, 'kind' => 'class', 'name' => 'Widget'],
                ['line' => 5, 'depth' => 1, 'kind' => 'method', 'name' => 'runFromServer'],
            ],
            $client->outline($file),
        );

        $this->assertSame(
            [
                ['line' => 3, 'depth' => 0, 'kind' => 'class', 'name' => 'Widget'],
                ['line' => 5, 'depth' => 0, 'kind' => 'function', 'name' => 'run'],
            ],
            LspClient::regexOutline($file),
        );

        $unmapped = $this->root . '/src/Widget.inc';
        copy($file, $unmapped);
        $this->assertSame(LspClient::regexOutline($unmapped), $client->outline($unmapped), 'an extension no server owns is outlined from the source');
    }

    public function testDisconnectAllStopsTheServer(): void
    {
        $client = $this->launch(['php' => $this->fakeServer()]);
        $this->assertTrue($client->isConnected('php'));

        $client->disconnectAll();

        $this->assertFalse($client->isConnected('php'));
    }

    /**
     * @param array<string, array<string, mixed>> $config
     */
    private function launch(array $config): LspClient
    {
        [$client, $after] = LspLauncher::fromConfig($config, $this->root)->launch();
        $this->assertSame([], $after->problems());
        $this->assertInstanceOf(LspClient::class, $client);
        $this->clients[] = $client;

        return $client;
    }

    /** @return array<string, mixed> */
    private function fakeServer(bool $silent = false): array
    {
        return [
            'command' => PHP_BINARY,
            'args' => ['-n', \dirname(__DIR__) . '/fixtures/lsp/fake-lsp-server.php'],
            'env' => ['FAKE_LSP_LOG' => $this->log] + ($silent ? ['FAKE_LSP_SILENT' => '1'] : []),
            'timeout' => 5,
        ];
    }
}

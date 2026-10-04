<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Hooks\BoundedHookInterface;
use SugarCraft\Crush\Hooks\BuiltIn\PostEditDiagnosticsHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\LSP\LspLauncher;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Step 3.F: after a `Write`/`Edit`, the built-in `PostToolUse` diagnostics hook
 * has the file's language server re-check it and hands the model the ERRORS
 * (at most twenty, 1-based positions) as the call's `additionalContext` —
 * never a refusal, and never a claim of "clean" the server did not make.
 *
 * Driven through the fixture server in `tests/fixtures/lsp/` on a real
 * connection.
 *
 * @see PostEditDiagnosticsHook
 */
final class PostEditDiagnosticsHookTest extends TestCase
{
    use HomeSandboxTrait;

    private string $root;

    private ?LspClient $client = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = (string) realpath(sys_get_temp_dir()) . '/sc-lsp-hook-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->client?->disconnectAll();
        Bootstrap::stopLspServers();
        // The names this file writes, removed by name: no directory walk.
        foreach (['A.php', 'Clean.php', 'Quiet.php', 'notes.txt'] as $name) {
            @unlink($this->root . '/src/' . $name);
        }
        @rmdir($this->root . '/src');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testItIsABoundedPostToolUseHookOnTheTwoEditingTools(): void
    {
        $hook = new PostEditDiagnosticsHook($this->client());

        $this->assertSame('post-edit-diagnostics', $hook->name());
        $this->assertSame(HookEvent::PostToolUse, $hook->event());
        $this->assertInstanceOf(BoundedHookInterface::class, $hook);
        $this->assertSame(5.0, $hook->timeoutSeconds());
        $this->assertSame(2.0, $hook->withTimeoutSeconds(2.0)->timeoutSeconds());
        $this->assertSame(5.0, $hook->withTimeoutSeconds(9.0)->timeoutSeconds(), 'a chain can only shorten the wait');
        foreach (['Write' => 1, 'Edit' => 1, 'Read' => 0, 'Bash' => 0] as $tool => $matches) {
            $this->assertSame($matches, preg_match('/' . $hook->matcher() . '/', $tool), "matcher vs {$tool}");
        }
    }

    public function testTheErrorsTheServerReportsRideOnTheEditsResult(): void
    {
        file_put_contents($this->root . '/src/A.php', "<?php\n\$a = 1; // BROKEN\n// WARN only\n");

        $result = (new PostEditDiagnosticsHook($this->client()))->execute($this->context('src/A.php'));

        $this->assertTrue($result->isAllowed());
        $this->assertSame(
            "LSP errors detected in this file, please fix:\n"
            . '<diagnostics file="' . $this->root . "/src/A.php\">\n"
            . "ERROR [2:12] BROKEN found on line 2\n"
            . '</diagnostics>',
            $result->additionalContext,
            'errors only, 1-based, one line each',
        );
    }

    public function testACleanFileAndASilentServerBothSayNothing(): void
    {
        file_put_contents($this->root . '/src/Clean.php', "<?php\n// WARN\n");
        $clean = (new PostEditDiagnosticsHook($this->client()))->execute($this->context('src/Clean.php'));
        $this->assertTrue($clean->isAllowed());
        $this->assertSame('', $clean->additionalContext);

        $this->client->disconnectAll();
        $this->client = null;
        file_put_contents($this->root . '/src/Quiet.php', "<?php // BROKEN\n");
        $silent = (new PostEditDiagnosticsHook($this->client(silent: true), 0.4))->execute($this->context('src/Quiet.php'));
        $this->assertTrue($silent->isAllowed());
        $this->assertSame('', $silent->additionalContext, 'no verdict is not a verdict');
    }

    public function testFilesNoServerOwnsAndBadArgumentsAreLeftAlone(): void
    {
        file_put_contents($this->root . '/src/notes.txt', "BROKEN\n");
        $hook = new PostEditDiagnosticsHook($this->client());

        foreach ([['file_path' => 'src/notes.txt'], ['file_path' => 'src/missing.php'], ['file_path' => 7], []] as $args) {
            $result = $hook->execute($this->context(null, $args));
            $this->assertTrue($result->isAllowed());
            $this->assertSame('', $result->additionalContext);
        }
    }

    /**
     * Jailed as the edit was: a `PostToolUse` chain also runs after an edit the
     * tool refused, and a diagnostic quotes the code it flags.
     */
    public function testAFileOutsideTheProjectRootIsNeverSentToTheServer(): void
    {
        file_put_contents($this->root . '/Secret.php', "<?php // BROKEN\n");
        $hook = new PostEditDiagnosticsHook($this->client());

        try {
            foreach ([$this->root . '/Secret.php', '../Secret.php'] as $path) {
                $result = $hook->execute(new HookContext('s', 'Edit', ['file_path' => $path], '', '', 'm', 'p', $this->root . '/src'));
                $this->assertTrue($result->isAllowed(), $path);
                $this->assertSame('', $result->additionalContext, "{$path} is outside the jail");
            }
        } finally {
            @unlink($this->root . '/Secret.php');
        }
    }

    public function testTheReportIsCappedAtTwentyErrorsPerFile(): void
    {
        $diagnostics = [];
        for ($i = 0; $i < 23; $i++) {
            $diagnostics[] = ['range' => ['start' => ['line' => $i, 'character' => 0]], 'severity' => 1, 'message' => "e{$i}"];
        }
        $diagnostics[] = ['range' => ['start' => ['line' => 0, 'character' => 0]], 'severity' => 3, 'message' => 'info'];

        $report = (string) PostEditDiagnosticsHook::render('/p/x.php', $diagnostics);

        $this->assertSame(20, substr_count($report, 'ERROR ['));
        $this->assertStringContainsString("ERROR [20:1] e19\n… and 3 more\n</diagnostics>", $report);
        $this->assertStringNotContainsString('info', $report);
        $this->assertNull(PostEditDiagnosticsHook::render('/p/x.php', [['severity' => 2, 'message' => 'w']]));
    }

    public function testBootstrapRegistersItOnlyWhenAServerIsConfigured(): void
    {
        $this->assertNull($this->launchChain([])->hook(HookEvent::PostToolUse->value, PostEditDiagnosticsHook::NAME), 'no `lsp` setting, no hook');

        $hooks = $this->launchChain([LspLauncher::SETTINGS_KEY => ['php' => self::fakeServer()]]);
        $this->assertInstanceOf(PostEditDiagnosticsHook::class, $hooks->hook(HookEvent::PostToolUse->value, PostEditDiagnosticsHook::NAME));
    }

    /**
     * Bootstrap's launch chain under a sandboxed HOME whose config is $config.
     *
     * @param array<string, mixed> $config
     */
    private function launchChain(array $config): HookManager
    {
        $home = $this->root . '/home';
        @mkdir($home . '/.sugar-crush', 0o700, true);
        file_put_contents($home . '/.sugar-crush/config.json', (string) json_encode($config === [] ? new \stdClass() : $config));
        chmod($home . '/.sugar-crush/config.json', 0o600);
        $this->useHomeSandbox($home);
        Bootstrap::useConfigPath(null);
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::stopLspServers();

        try {
            $hooks = (new \ReflectionMethod(Bootstrap::class, 'hooks'))->invoke(null, null, $this->root);
        } finally {
            ProcessContainment::useSecretEnvAllowlist([]);
            $this->restoreHomeSandbox();
            @unlink($home . '/.sugar-crush/config.json');
            @rmdir($home . '/.sugar-crush');
            @rmdir($home);
        }
        $this->assertInstanceOf(HookManager::class, $hooks);

        return $hooks;
    }

    /**
     * @param array<string, mixed>|null $args
     */
    private function context(?string $path, ?array $args = null): HookContext
    {
        return new HookContext(
            sessionId: 's',
            toolName: 'Edit',
            toolArgs: $args ?? ['file_path' => $path],
            toolInput: '',
            toolOutput: '',
            model: 'm',
            provider: 'p',
            projectRoot: $this->root,
        );
    }

    private function client(bool $silent = false): LspClient
    {
        if ($this->client === null) {
            [$client] = LspLauncher::fromConfig(['php' => self::fakeServer($silent)], $this->root)->launch();
            $this->assertInstanceOf(LspClient::class, $client);
            $this->client = $client;
        }

        return $this->client;
    }

    /** @return array<string, mixed> */
    private static function fakeServer(bool $silent = false): array
    {
        return [
            'command' => PHP_BINARY,
            'args' => ['-n', \dirname(__DIR__, 2) . '/fixtures/lsp/fake-lsp-server.php'],
            'env' => $silent ? ['FAKE_LSP_SILENT' => '1'] : [],
            'timeout' => 5,
        ];
    }
}

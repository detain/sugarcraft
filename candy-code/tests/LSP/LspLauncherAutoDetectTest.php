<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\LSP;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Code\LSP\LspClient;
use SugarCraft\Code\LSP\LspConnectionInterface;
use SugarCraft\Code\LSP\LspLauncher;

/**
 * EDT-2: `lspAutoDetect` lets {@see LspLauncher}'s AUTO_TABLE start the
 * server a project fingerprint names. Every case here injects
 * `$binaryPresent` — the tests never read the real PATH — and the default-off
 * half is pinned case by case, because "byte-identical without the key" IS
 * the compatibility promise the feature ships on.
 */
final class LspLauncherAutoDetectTest extends TestCase
{
    private string $root;

    /** @var list<LspClient> */
    private array $clients = [];

    /** @var list<string> files created under $root, removed by name */
    private array $created = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sc-lsp-auto-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->disconnectAll();
        }
        foreach ($this->created as $name) {
            @unlink($this->root . '/' . $name);
        }
        @rmdir($this->root);
    }

    // ── default off is the old launcher ────────────────────────────────

    public function testWithoutTheFlagNothingConsultsTheTableOrTheProbe(): void
    {
        $this->touch('composer.json');
        $probed = [];
        $probe = static function (string $binary) use (&$probed): bool {
            $probed[] = $binary;

            return true;
        };

        foreach ([null, [], false, 'a string', ['intelephense']] as $raw) {
            $launcher = LspLauncher::fromConfig($raw, $this->root, false, $probe);
            $this->assertSame([], $launcher->languages(), gettype($raw));
        }
        $this->assertSame([], $probed, 'a default-off launch must not even ask whether a binary exists');
    }

    // ── the fingerprint half ───────────────────────────────────────────

    public function testAFingerprintWithABinaryStartsExactlyItsServer(): void
    {
        $this->touch('composer.json');
        $launcher = LspLauncher::fromConfig(null, $this->root, true, $this->probeWith(['intelephense']));

        $this->assertSame(['php'], $launcher->languages());
        $this->assertContains('lspAutoDetect started php=intelephense', $launcher->problems(), 'a started-by-fingerprint server must never look like the user wrote it');

        $seen = $this->commandsOf($launcher);
        $this->assertSame([['intelephense', ['--stdio']]], $seen);
    }

    public function testAFingerprintWithoutABinaryStartsNothing(): void
    {
        $this->touch('composer.json');
        $launcher = LspLauncher::fromConfig(null, $this->root, true, $this->probeWith([]));

        $this->assertSame([], $launcher->languages());
        $this->assertSame([], $launcher->problems());
    }

    public function testATypeScriptTreeNeedsBothFilesAndRoutesAllFourExtensions(): void
    {
        $this->touch('tsconfig.json');
        $this->assertSame([], LspLauncher::fromConfig(null, $this->root, true, $this->probeWith(['typescript-language-server']))->languages(), 'tsconfig alone is not the pair');

        $this->touch('package.json');
        $launcher = LspLauncher::fromConfig(null, $this->root, true, $this->probeWith(['typescript-language-server']));
        $this->assertSame(['typescript'], $launcher->languages());

        $client = $launcher->launch(static fn (): LspConnectionInterface => self::stubConnection())[0];
        $this->assertNotNull($client);
        $this->clients[] = $client;
        foreach (['a.ts', 'a.tsx', 'a.js', 'a.jsx'] as $file) {
            $this->assertSame('typescript', $client->languageFor($this->root . '/' . $file));
        }
    }

    public function testPythonAcceptsEitherFingerprintAlone(): void
    {
        $this->touch('setup.py');
        $this->assertSame(['python'], LspLauncher::fromConfig(null, $this->root, true, $this->probeWith(['pyright-langserver']))->languages());
    }

    public function testEveryMatchedFingerprintFillsItsOwnLanguageInTableOrder(): void
    {
        $this->touch('Cargo.toml');
        $this->touch('go.mod');
        $this->touch('composer.json');
        $launcher = LspLauncher::fromConfig(null, $this->root, true, $this->probeWith(['gopls', 'rust-analyzer']));

        $this->assertSame(['go', 'rust'], $launcher->languages(), 'php stays out (no binary) and the order rides the table, not the filesystem');
        $this->assertSame(
            ['lspAutoDetect started go=gopls', 'lspAutoDetect started rust=rust-analyzer'],
            $launcher->problems(),
        );
    }

    public function testAFingerprintDirectoryNameIsNotAFingerprint(): void
    {
        mkdir($this->root . '/composer.json', 0o700, true);
        try {
            $this->assertSame([], LspLauncher::fromConfig(null, $this->root, true, $this->probeWith(['intelephense']))->languages());
        } finally {
            @rmdir($this->root . '/composer.json');
        }
    }

    // ── explicit entries win ───────────────────────────────────────────

    public function testAnExplicitEntryHoldsItsLanguageAndTheAutoHalfStaysOut(): void
    {
        $this->touch('composer.json');
        $launcher = LspLauncher::fromConfig(
            ['php' => ['command' => 'my-php-server']],
            $this->root,
            true,
            $this->probeWith(['intelephense']),
        );

        $this->assertSame(['php'], $launcher->languages());
        $this->assertSame([['my-php-server', []]], $this->commandsOf($launcher));
        $this->assertSame([], $launcher->problems());
    }

    public function testAnExplicitlyDisabledLanguageIsNotResurrectedByTheTable(): void
    {
        $this->touch('composer.json');
        $launcher = LspLauncher::fromConfig(
            ['php' => ['command' => 'intelephense', 'disabled' => true]],
            $this->root,
            true,
            $this->probeWith(['intelephense']),
        );

        $this->assertSame([], $launcher->languages(), 'switching php off is the user speaking for php');
        $this->assertSame([], $launcher->problems());
    }

    public function testABrokenExplicitEntryHoldsItsLanguageToo(): void
    {
        $this->touch('composer.json');
        $launcher = LspLauncher::fromConfig(
            ['php' => ['args' => ['--stdio']]],
            $this->root,
            true,
            $this->probeWith(['intelephense']),
        );

        $this->assertSame([], $launcher->languages());
        $problems = implode("\n", $launcher->problems());
        $this->assertStringContainsString('the php entry has no "command"', $problems);
        $this->assertStringNotContainsString('lspAutoDetect started', $problems, 'the user meant to define php; launching behind their back hides the line they need');
    }

    public function testAMalformedMapCostsTheExplicitHalfNotTheTable(): void
    {
        $this->touch('composer.json');
        $launcher = LspLauncher::fromConfig('not-a-map', $this->root, true, $this->probeWith(['intelephense']));

        $this->assertSame(['php'], $launcher->languages());
        $this->assertSame(
            ['the `lsp` setting must be an object of language => server; it was ignored', 'lspAutoDetect started php=intelephense'],
            $launcher->problems(),
        );
        // Without the flag the same malformed map keeps its exact old shape.
        $this->assertSame(
            ['the `lsp` setting must be an object of language => server; it was ignored'],
            LspLauncher::fromConfig('not-a-map', $this->root)->problems(),
        );
    }

    public function testTheCapIsSharedAndAutoYieldsToItSilently(): void
    {
        $this->touch('composer.json');
        $raw = [];
        for ($i = 0; $i < LspLauncher::MAX_SERVERS; $i++) {
            $raw['lang' . $i] = ['command' => 'server' . $i];
        }
        $launcher = LspLauncher::fromConfig($raw, $this->root, true, $this->probeWith(['intelephense']));

        $this->assertCount(LspLauncher::MAX_SERVERS, $launcher->languages());
        $this->assertNotContains('php', $launcher->languages());
        $this->assertSame([], $launcher->problems(), 'the explicit eight already named the cap problem nowhere; auto adds nothing it could not start');
    }

    // ── the table itself ───────────────────────────────────────────────

    public function testTheTableSpeaksTheStandardDidOpenLanguageIds(): void
    {
        $table = (new ReflectionClass(LspLauncher::class))->getConstant('AUTO_TABLE');

        $this->assertSame(['php', 'typescript', 'python', 'go', 'rust'], array_column($table, 'language'));
        foreach ($table as $row) {
            $this->assertNotSame([], $row['files'], $row['language']);
            $this->assertNotSame([], $row['extensions'], $row['language']);
            foreach ($row['candidates'] as $command => $args) {
                $this->assertIsString($command);
                $this->assertIsArray($args);
                $this->assertSame([], array_filter($args, static fn (mixed $a): bool => !\is_string($a)), $row['language']);
            }
        }
    }

    public function testAnAutoServerCarriesTheFullManualShape(): void
    {
        $this->touch('composer.json');
        $launcher = LspLauncher::fromConfig(null, $this->root, true, $this->probeWith(['intelephense']));

        $servers = (new ReflectionClass($launcher))->getProperty('servers')->getValue($launcher);

        $this->assertSame([
            'language' => 'php',
            'command' => 'intelephense',
            'args' => ['--stdio'],
            'extensions' => ['php'],
            'env' => [],
            'initializationOptions' => null,
            'timeout' => LspLauncher::DEFAULT_TIMEOUT_SECONDS,
        ], $servers[0]);
    }

    // ── the production seam (wired by Bootstrap, pinned without the PATH) ──

    public function testTheLaunchReadsTheKeyIntoTheThirdArgument(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Cli/Bootstrap.php');
        $body = substr($source, (int) strpos($source, 'public static function lspClient'), 1600);

        $this->assertStringContainsString(
            '($config[\\SugarCraft\\Code\\LSP\\LspLauncher::AUTO_DETECT_SETTINGS_KEY] ?? false) === true,',
            $body,
            'lspClient() must hand the flag through strictly === true — the table runs ONLY on the user key',
        );
        $this->assertStringContainsString('LspLauncher::SETTINGS_KEY] ?? null,', $body, 'the manual map keeps its own read');
    }

    // ── harness ────────────────────────────────────────────────────────

    /** @param list<string> $installed */
    private function probeWith(array $installed): callable
    {
        return static fn (string $binary): bool => \in_array($binary, $installed, true);
    }

    /** @return list<array{0: string, 1: list<string>}> */
    private function commandsOf(LspLauncher $launcher): array
    {
        $seen = [];
        [$client] = $launcher->launch(
            static function (string $command, array $args) use (&$seen): LspConnectionInterface {
                $seen[] = [$command, $args];

                return self::stubConnection();
            },
        );
        if ($client !== null) {
            $this->clients[] = $client;
        }

        return $seen;
    }

    private static function stubConnection(): LspConnectionInterface
    {
        return new class () implements LspConnectionInterface {
            public function connect(string $command, array $env, ?string $cwd = null, float $timeout = 30.0): void
            {
            }

            public function initialize(): array
            {
                return [];
            }

            public function disconnect(): void
            {
            }

            public function definitions(string $uri, int $line, int $col): array
            {
                return [];
            }

            public function references(string $uri, int $line, int $col): array
            {
                return [];
            }

            public function hover(string $uri, int $line, int $col): ?array
            {
                return null;
            }

            public function symbols(string $uri): array
            {
                return [];
            }

            public function codeActions(string $uri, int $line, int $col, array $context = []): array
            {
                return [];
            }

            public function isConnected(): bool
            {
                return true;
            }

            public function capabilities(): ?array
            {
                return null;
            }

            public function onNotification(callable $callback): void
            {
            }
        };
    }

    private function touch(string $name): void
    {
        file_put_contents($this->root . '/' . $name, "{}\n");
        $this->created[] = $name;
    }
}

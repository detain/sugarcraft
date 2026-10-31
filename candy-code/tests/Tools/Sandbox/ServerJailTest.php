<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Sandbox;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use SugarCraft\Code\LSP\LspConnection;
use SugarCraft\Code\LSP\LspConnectionInterface;
use SugarCraft\Code\LSP\LspLauncher;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Tools\Sandbox\Bubblewrap;
use SugarCraft\Code\Tools\Sandbox\SandboxBackend;
use SugarCraft\Code\Tools\Sandbox\SandboxScope;

/**
 * C-TOOL-4.e — the `lsp` and `mcp` scopes of sandboxScope jail the launch's
 * long-lived servers through the SAME jail the 4.d steps wired, and nothing
 * that is scoped runs unconfined.
 *
 * The LSP legs capture the prefix the launcher hands the connection seam and
 * prove the refusal path never spawns; one leg spawns a real process whose
 * argv dump proves the jail prefix sits between the detach prefix and the
 * server command inside the containment-wrapped spec (PREFIX-ONLY law: the
 * @proc_open site itself rides unchanged). The MCP legs drive
 * McpClient::stdioJailPrefix() — the containment seam — directly: the
 * user-tier `"sandbox": false` exempts, a project-tier one is recorded and
 * ignored, and a refused jail throws before the server is constructed.
 */
final class ServerJailTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    private string $root;

    protected function setUp(): void
    {
        Bubblewrap::resetProbeVerdictsForTesting();
        $dir = sys_get_temp_dir() . '/server-jail-' . getmypid() . '-' . uniqid('', true);
        self::assertTrue(mkdir($dir, 0700, true));
        $this->tempPaths[] = $dir;
        $this->root = $dir;
    }

    protected function tearDown(): void
    {
        Bubblewrap::resetProbeVerdictsForTesting();
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                unlink($path);
                continue;
            }
            if (is_dir($path)) {
                foreach (glob($path . '/' . chr(42)) ?: [] as $child) {
                    if (is_file($child)) {
                        unlink($child);
                    }
                }
                @rmdir($path);
            }
        }
        $this->tempPaths = [];
    }

    public function testScopedServersCarryTheJailPrefixAndLiteralFalseExempts(): void
    {
        $jail = new RecordingTestJail();
        $launcher = LspLauncher::fromConfig([
            'php' => ['command' => 'php-lsp-bin'],
            'typescript' => ['command' => 'ts-lsp-bin', 'sandbox' => false],
            'javascript' => ['command' => 'js-lsp-bin', 'sandbox' => 'false'],
        ], $this->root);

        $seen = [];
        [$client, $after] = $launcher->withSandbox($jail, null)->launch(
            static function (string $command, array $args, array $prefix) use (&$seen): LspConnectionInterface {
                $seen[$command] = $prefix;

                return new StubJailConnection();
            }
        );

        self::assertNotNull($client);
        self::assertSame([], $after->problems());
        // In-scope: the jail's argv, project root writable, --chdir defaulting
        // to that same root (the launcher's root is both).
        self::assertSame(['rec-jail', $this->root, $this->root, '--'], $seen['php-lsp-bin']);
        self::assertSame(
            [['root' => $this->root, 'chdir' => null], ['root' => $this->root, 'chdir' => null]],
            $jail->calls,
            'php and js build prefixes; the opted-out server must not even ask for one',
        );
        // The literal false exempts and the string 'false' does NOT — junk
        // jails, the strictest reading.
        self::assertSame([], $seen['ts-lsp-bin'], 'only literal false opts out');
        self::assertNotSame([], $seen['js-lsp-bin'], 'a string "false" must not opt out');
        self::assertCount(2, $jail->calls);
    }

    public function testWithoutAJailEveryServerSpawnsTheBareLine(): void
    {
        $launcher = LspLauncher::fromConfig(['php' => ['command' => 'php-lsp-bin']], $this->root);

        $seen = [];
        [, $after] = $launcher->launch(
            static function (string $command, array $args, array $prefix) use (&$seen): LspConnectionInterface {
                $seen[$command] = $prefix;

                return new StubJailConnection();
            }
        );

        self::assertSame([], $after->problems());
        self::assertSame(['php-lsp-bin' => []], $seen, 'out of scope the connect line is byte-identical to before');
    }

    public function testARefusedLspJailStartsNothingAndReportsThroughProblems(): void
    {
        $jail = new RecordingTestJail();
        $refusal = 'synthetic-' . uniqid('refusal-', true);
        $launcher = LspLauncher::fromConfig(['php' => ['command' => 'php-lsp-bin']], $this->root);

        $spawned = 0;
        [$client, $after] = $launcher->withSandbox($jail, $refusal)->launch(
            static function (string $command, array $args, array $prefix) use (&$spawned): LspConnectionInterface {
                $spawned++;

                return new StubJailConnection();
            }
        );

        self::assertSame(0, $spawned, 'a refused jail must not reach the spawn seam');
        self::assertNull($client);
        $problems = $after->problems();
        self::assertCount(1, $problems);
        self::assertStringContainsString('php-lsp-bin', $problems[0]);
        self::assertStringContainsString($refusal, $problems[0]);
        self::assertStringContainsString('Nothing started, jailed or otherwise.', $problems[0]);
    }

    public function testTheRealSpawnLineCarriesTheJailPrefixBetweenTheDetachWrapperAndTheServer(): void
    {
        // An executable "jail" that records its own argv proves the prepend
        // inside the containment-wrapped spec — the @proc_open site itself is
        // unchanged, so this is the same spawn the product performs.
        $log = $this->root . '/spawned-argv.log';
        $jailer = $this->root . '/jailer.php';
        self::assertTrue(file_put_contents(
            $jailer,
            "#!/usr/bin/env php\n<?php\nfile_put_contents(" . var_export($log, true) . ", implode(chr(9), array_slice(\$argv, 1)));\n"
        ) !== false);
        self::assertTrue(chmod($jailer, 0755));
        $this->tempPaths[] = $jailer;
        $this->tempPaths[] = $log;

        $prefix = Bubblewrap::new($jailer)->argv($this->root);
        self::assertNotSame([], $prefix);

        $connection = new LspConnection('/bin/true', [], $prefix);
        try {
            $connection->connect('/bin/true', [], null, 2.0);
        } catch (\Throwable) {
            // The "server" printed nothing and died; the handshake failure is
            // expected — what this leg owns is the argv the kernel exec'd.
        }

        // The jailer is a real child; give its argv dump a bounded moment to
        // land before judging (same reap-poll discipline as the 4.d legs).
        $deadline = microtime(true) + 2.0;
        while (!is_file($log) && microtime(true) < $deadline) {
            usleep(20_000);
        }

        self::assertFileExists($log, 'the jail prefix must run ahead of the server command');
        $spawnedArgv = explode(chr(9), (string) file_get_contents($log));
        self::assertSame('/bin/true', $spawnedArgv[count($spawnedArgv) - 1], 'the server command must sit last');
        self::assertSame('--', $spawnedArgv[count($spawnedArgv) - 2], 'the jail separator must close the prefix');
        $chdirAt = array_search('--chdir', $spawnedArgv, true);
        self::assertIsInt($chdirAt);
        self::assertSame($this->root, $spawnedArgv[$chdirAt + 1], '--chdir carries the project root (spawn cwd == writable root)');
    }

    public function testTheE672PreCheckStillNamesTheServerBinaryUnderAPrefix(): void
    {
        $binary = 'definitely-not-a-language-server-' . bin2hex(random_bytes(6));
        $connection = new LspConnection($binary, [], Bubblewrap::new('/bin/echo')->argv($this->root));

        $raised = null;
        try {
            $connection->connect($binary, [], null, 2.0);
        } catch (RuntimeException $failure) {
            $raised = $failure->getMessage();
        }

        // The pre-check is gated on a probed detach wrapper; where none
        // probed clean the spec is the bare command and the throw still
        // names the binary (the spawn itself fails), so both shapes pass.
        self::assertNotNull($raised, 'an off-PATH language server must never look started');
        self::assertStringContainsString($binary, $raised);
        self::assertStringStartsWith('Failed to start LSP server:', $raised);
    }

    public function testMcpStdioJailHonoursTheUserTierOptOutOnly(): void
    {
        $user = '/home/op/' . uniqid('candy-config-', true) . '.json';
        $client = new McpClient('/nonexistent/' . uniqid('mcp-', true) . '/.mcp.json', userConfigPath: $user);
        $jail = new RecordingTestJail();
        $client->withSandbox($jail, null, '/trust/root');
        $prefix = new ReflectionMethod(McpClient::class, 'stdioJailPrefix');
        $prefix->setAccessible(true);

        // User-tier literal false: exempt, unrecorded.
        self::assertSame([], $prefix->invoke($client, 'homeSrv', $user, false, null));
        // Project tier (a real path) and the unknown '' tier: IGNORED and
        // recorded, the server jails as scoped.
        self::assertNotSame([], $prefix->invoke($client, 'projSrv', $this->root . '/.mcp.json', false, null));
        self::assertNotSame([], $prefix->invoke($client, 'bareSrv', '', false, null));
        $notice = $client->sandboxOptOutNotice();
        self::assertStringContainsString('projSrv', $notice);
        self::assertStringContainsString('bareSrv', $notice);
        self::assertStringNotContainsString('homeSrv', $notice);
        self::assertStringNotContainsString('userSrv', $client->sandboxOptOutNotice());
        // A jailed server: writable root is the trust root, the per-server
        // cwd rides --chdir only (the user-tier $HOME cwd survives without
        // $HOME becoming writable).
        self::assertNotSame([], $prefix->invoke($client, 'userSrv', $user, true, '/home/op/srv-dir'));
        self::assertSame(
            ['root' => '/trust/root', 'chdir' => '/home/op/srv-dir'],
            $jail->calls[count($jail->calls) - 1],
        );
        // No jail installed: the whole door is inert.
        $plain = new McpClient('/nonexistent/' . uniqid('mcp-', true) . '/.mcp.json');
        self::assertSame([], $prefix->invoke($plain, 'any', '', null, null));
    }

    public function testTheOptOutNoticeStaysSilentWhenNoJailIsActive(): void
    {
        // C-TOOL-4.e r1 MINOR: a project-tier false under a DEAD scope must
        // not be recorded — the drained notice says the server "jails as
        // scoped", and that claim is false when nothing jails. Both
        // polarities, same client, same server name.
        $user = '/home/op/' . uniqid('candy-config-', true) . '.json';
        $client = new McpClient('/nonexistent/' . uniqid('mcp-', true) . '/.mcp.json', userConfigPath: $user);
        $prefix = new ReflectionMethod(McpClient::class, 'stdioJailPrefix');
        $prefix->setAccessible(true);
        $projTier = $this->root . '/.mcp.json';

        // NO jail in force: no prefix (unchanged) and NO notice.
        self::assertSame([], $prefix->invoke($client, 'projSrv', $projTier, false, null));
        self::assertSame('', $client->sandboxOptOutNotice(), 'with no jail, no opt-out was ignored');

        // Jail installed: the same project-tier false is now IGNORED and
        // the user must be told.
        $client->withSandbox(new RecordingTestJail(), null, '/trust/root');
        self::assertNotSame([], $prefix->invoke($client, 'projSrv', $projTier, false, null));
        self::assertStringContainsString('projSrv', $client->sandboxOptOutNotice());
    }

    public function testARefusedMcpJailThrowsBeforeTheServerIsBuilt(): void
    {
        $user = '/home/op/' . uniqid('candy-config-', true) . '.json';
        $client = new McpClient('/nonexistent/' . uniqid('mcp-', true) . '/.mcp.json', userConfigPath: $user);
        $refusal = 'synthetic-' . uniqid('refusal-', true);
        $client->withSandbox(new RecordingTestJail(), $refusal, '/trust/root');
        $prefix = new ReflectionMethod(McpClient::class, 'stdioJailPrefix');
        $prefix->setAccessible(true);

        $raised = null;
        try {
            $prefix->invoke($client, 'jailedSrv', $user, true, null);
        } catch (RuntimeException $failure) {
            $raised = $failure->getMessage();
        }

        self::assertNotNull($raised, 'a scoped server must not be constructable against a jail that cannot start');
        self::assertStringContainsString('jailedSrv', $raised);
        self::assertStringContainsString($refusal, $raised);
        self::assertStringContainsString('Nothing started, jailed or otherwise.', $raised);
        // The opt-out is still the escape hatch: an exempt server is not
        // gated by the refusal.
        self::assertSame([], $prefix->invoke($client, 'exemptSrv', $user, false, null));
    }

    public function testTheServerScopesJoinTheEveryScopeReadingAndTheRoster(): void
    {
        self::assertTrue(SandboxScope::requests(['not-a-real-scope'], SandboxScope::SCOPE_LSP));
        self::assertTrue(SandboxScope::requests(['not-a-real-scope'], SandboxScope::SCOPE_MCP));
        self::assertTrue(SandboxScope::requests(['lsp'], SandboxScope::SCOPE_LSP));
        self::assertFalse(SandboxScope::requests(['lsp'], SandboxScope::SCOPE_MCP));
        self::assertTrue(SandboxScope::requests(['mcp'], SandboxScope::SCOPE_MCP));
        self::assertFalse(SandboxScope::requests([], SandboxScope::SCOPE_LSP));
        self::assertContains(SandboxScope::SCOPE_LSP, SandboxScope::SCOPES);
        self::assertContains(SandboxScope::SCOPE_MCP, SandboxScope::SCOPES);
    }
}

/**
 * A jail that records the (writableRoot, chdir) pair each argv() was asked
 * for and answers with one recognisable line.
 */
final class RecordingTestJail implements SandboxBackend
{
    /** @var list<array{root: string, chdir: ?string}> */
    public array $calls = [];

    public function name(): string
    {
        return 'recording';
    }

    public function mode(): string
    {
        return SandboxBackend::MODE_ON;
    }

    public function resolvedBinary(): string
    {
        return '/bin/true';
    }

    public function argv(
        string $writableRoot,
        bool $interactive = false,
        ?string $tmpDir = null,
        ?string $chdir = null,
        array $readOnlyBinds = [],
    ): array {
        $this->calls[] = ['root' => $writableRoot, 'chdir' => $chdir];

        return ['rec-jail', $writableRoot, $chdir ?? $writableRoot, '--'];
    }

    public function wrap(
        string $shellCommand,
        string $writableRoot,
        bool $interactive = false,
        ?string $tmpDir = null,
        ?string $chdir = null,
        array $readOnlyBinds = [],
    ): string {
        return implode(' ', $this->argv($writableRoot, $interactive, $tmpDir, $chdir, $readOnlyBinds)) . ' ' . $shellCommand;
    }

    public function probe(\Closure $run): ?string
    {
        return null;
    }

    public function refusalRemedy(): string
    {
        return 'no remedy — this jail always stands up';
    }

    public function describe(string $writableRoot): string
    {
        return 'the recording jail over ' . $writableRoot;
    }
}

/**
 * Stands in for a language-server connection at the launcher's seam so the
 * prefix, not the protocol, is what these legs observe.
 */
final class StubJailConnection implements LspConnectionInterface
{
    public function connect(string $command, array $env, ?string $cwd = null, float $timeout = 30.0): void
    {
    }

    public function initialize(): array
    {
        return ['capabilities' => []];
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
        return [];
    }

    public function onNotification(callable $callback): void
    {
    }
}

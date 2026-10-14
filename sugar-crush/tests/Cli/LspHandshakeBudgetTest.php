<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\LSP\LspConnectionInterface;
use SugarCraft\Crush\LSP\LspLauncher;
use SugarCraft\Crush\LSP\LspResponse;

/**
 * rv-tui-2 (fix 2): `Bootstrap::app()` handshakes every configured LSP
 * server BEFORE the first frame paints, and each handshake rode the full
 * per-request timeout — with the shipped default of 10.0 s, eight wedged
 * servers could hold the launch for eighty seconds behind a black screen.
 *
 * The owned half of the cure is the budget split: `connect()` — the
 * connect + `initialize`/`initialized` exchange — is now bound by
 * {@see LspLauncher::DEFAULT_HANDSHAKE_TIMEOUT_SECONDS} (2.0 s), and the
 * server's own per-request timeout is restored onto the connection only
 * after the handshake cleared, so tool traffic keeps the budget its
 * configuration asked for. The post-first-frame completion (handshake off
 * the launch path entirely) needs an App-side init Cmd and is recorded as
 * this lane's seam.
 *
 * The seam these tests drive is the launcher's own: `launch(?\Closure
 * $connect)` takes the connection factory, so the recorded call order on a
 * fake connection IS the production call order — no process is spawned.
 */
final class LspHandshakeBudgetTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = (string) sys_get_temp_dir() . '/lsp_budget_' . uniqid('', true);
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            @rmdir($this->root);
        }
    }

    public function testTheHandshakeBudgetIsTheSmallOneAndTheRequestBudgetKeepsItsFigure(): void
    {
        $this->assertSame(2.0, LspLauncher::DEFAULT_HANDSHAKE_TIMEOUT_SECONDS);
        $this->assertSame(10.0, LspLauncher::DEFAULT_TIMEOUT_SECONDS);
        $this->assertLessThan(
            LspLauncher::DEFAULT_TIMEOUT_SECONDS,
            LspLauncher::DEFAULT_HANDSHAKE_TIMEOUT_SECONDS,
            'the pre-paint budget must be strictly smaller than the per-request one — that gap is the whole fix'
        );
    }

    public function testThePrePaintConnectCarriesTheHandshakeBudgetAndTheRequestBudgetFollowsIt(): void
    {
        $launcher = LspLauncher::fromConfig(['php' => ['command' => 'fake-lsp']], $this->root);
        $fake = new BudgetRecordingConnection();

        [$client, $after] = $launcher->launch(
            static fn (string $command, array $args): LspConnectionInterface => $fake
        );

        $this->assertInstanceOf(LspClient::class, $client);
        $this->assertSame([], $after->problems(), 'a clean handshake must report nothing');
        $this->assertSame(
            LspLauncher::DEFAULT_HANDSHAKE_TIMEOUT_SECONDS,
            $fake->connectTimeout,
            'connect() pre-paint must be bound by the handshake budget, not the 10 s request default'
        );
        $this->assertSame(
            10.0,
            $fake->requestTimeout,
            'after the handshake the server gets its own per-request budget back'
        );
        $this->assertNotNull(
            $fake->connectTimeout,
            'and the restore must arrive AFTER connect — order is the fix'
        );
        $this->assertGreaterThan(
            $fake->connectAt,
            $fake->setAt,
            'setRequestTimeout must run after connect() (sequence positions pin it)'
        );
    }

    public function testATighterConfiguredTimeoutKeepsItsOwnNumberOnTheHandshake(): void
    {
        $launcher = LspLauncher::fromConfig(
            ['php' => ['command' => 'fake-lsp', 'timeout' => 1.0]],
            $this->root
        );
        $fake = new BudgetRecordingConnection();

        [$client] = $launcher->launch(
            static fn (string $command, array $args): LspConnectionInterface => $fake
        );

        $this->assertInstanceOf(LspClient::class, $client);
        $this->assertSame(1.0, $fake->connectTimeout, 'min() must not grow a config that asks for less');
        $this->assertSame(1.0, $fake->requestTimeout);
    }

    public function testAFailedHandshakeNeverReachesTheBudgetRestore(): void
    {
        $launcher = LspLauncher::fromConfig(['php' => ['command' => 'fake-lsp']], $this->root);
        $fake = new BudgetRecordingConnection(failInitialize: true);

        [$client, $after] = $launcher->launch(
            static fn (string $command, array $args): LspConnectionInterface => $fake
        );

        $this->assertNull($client, 'the only server failed, so there is no client');
        $this->assertNotNull($fake->connectTimeout, 'the handshake was still attempted, bounded');
        $this->assertNull($fake->requestTimeout, 'a server that never initialised must not keep a live budget');
        $this->assertTrue($fake->disconnected, 'and it must be stopped again');
        $this->assertNotSame([], $after->problems());
    }

    public function testAConnectionWithoutTheSetterStillLaunches(): void
    {
        // The in-memory fakes across the suite (and any third-party
        // connection) implement only the interface; the launcher tolerates
        // the missing setter the same way it already tolerates missing
        // sendRequest/sendNotification — method_exists, never instanceof.
        $launcher = LspLauncher::fromConfig(['php' => ['command' => 'fake-lsp']], $this->root);
        $legacy = new LegacyInterfaceOnlyConnection();

        [$client, $after] = $launcher->launch(
            static fn (string $command, array $args): LspConnectionInterface => $legacy
        );

        $this->assertInstanceOf(LspClient::class, $client);
        $this->assertSame([], $after->problems());
        $this->assertSame(LspLauncher::DEFAULT_HANDSHAKE_TIMEOUT_SECONDS, $legacy->connectTimeout);
    }

    public function testTheConnectionGuardValidatesTheBudgetItIsHanded(): void
    {
        // LspConnection cannot be instantiated here (the ctor spawns), so
        // the setter's contract is pinned where it is cheap and total: the
        // declared guard in its body.
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/LSP/LspConnection.php');
        $body = substr($src, (int) strpos($src, 'function setRequestTimeout'));
        $body = substr($body, 0, (int) strpos($body, "\n    }"));

        $this->assertStringContainsString('is_finite($seconds)', $body);
        $this->assertStringContainsString('$seconds <= 0', $body);
        $this->assertStringContainsString('InvalidArgumentException', $body);
    }
}

/**
 * Interface-complete double PLUS the two duck-typed methods the launcher
 * reaches for, recording the timeout each call carried and the order of the
 * two budget touchpoints.
 */
final class BudgetRecordingConnection implements LspConnectionInterface
{
    public ?float $connectTimeout = null;
    public ?float $requestTimeout = null;
    public int $connectAt = 0;
    public int $setAt = 0;
    public bool $disconnected = false;

    private int $clock = 0;

    public function __construct(private readonly bool $failInitialize = false)
    {
    }

    public function connect(string $command, array $env, ?string $cwd = null, float $timeout = 30.0): void
    {
        $this->connectTimeout = $timeout;
        $this->connectAt = ++$this->clock;
    }

    public function sendRequest(string $method, ?array $params = null): LspResponse
    {
        return $this->failInitialize
            ? LspResponse::error(['message' => 'fake server refuses initialize'])
            : LspResponse::ok(['capabilities' => []]);
    }

    public function sendNotification(string $method, ?array $params = null): void
    {
    }

    public function setRequestTimeout(float $seconds): void
    {
        $this->requestTimeout = $seconds;
        $this->setAt = ++$this->clock;
    }

    public function initialize(): array
    {
        return ['capabilities' => []];
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
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
        return ['capabilities' => []];
    }

    public function onNotification(callable $callback): void
    {
    }
}

/** Interface only — no sendRequest, no sendNotification, no setter. */
final class LegacyInterfaceOnlyConnection implements LspConnectionInterface
{
    public ?float $connectTimeout = null;

    public function connect(string $command, array $env, ?string $cwd = null, float $timeout = 30.0): void
    {
        $this->connectTimeout = $timeout;
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
        return ['capabilities' => []];
    }

    public function onNotification(callable $callback): void
    {
    }
}

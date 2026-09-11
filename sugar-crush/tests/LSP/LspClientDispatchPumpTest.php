<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\LSP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspCacheInterface;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\LSP\LspConnection;
use SugarCraft\Crush\LSP\LspConnectionInterface;

/**
 * E690: `LspClient` drains a child's idle stderr at DISPATCH ENTRY.
 *
 * The shape is the MCP precedent (`McpClientDispatchPumpTest`) carried to the
 * LSP side: a well-behaved worker answers the exchange, a second registered
 * connection is a CHATTERBOX that writes to fd 2 strictly AFTER a settled
 * exchange (here: after `connect()` completes — the fixture proves the bytes
 * LEFT the child via its done-file), and the NEXT dispatched operation on the
 * client must take those bytes. There is no timer and no loop tick anywhere
 * in this file; the ONLY thing that could drain is the mount itself, and the
 * empty-tail precondition before the dispatch says nothing drained earlier.
 *
 * The chatterbox is registered under a language the dispatch does NOT use —
 * that is the point: the fan-out must reach other servers' stderr too, and
 * the worker itself is an in-memory fake, which additionally pins the
 * `instanceof LspConnection` gate (a fake has no `pumpStderr()` to reach and
 * must not break the call).
 */
final class LspClientDispatchPumpTest extends TestCase
{
    private const FIXTURE_LIFETIME_SECONDS = 20.0;

    private const HANDSHAKE_BOUND_SECONDS = 3.0;

    private string $tempDir;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_lsp_dispatch_pump_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
        $this->marker = 'BA-E690-' . bin2hex(random_bytes(4)) . "\n";
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    /**
     * THE HEADLINE: noise written after a settled exchange sits unread (tail
     * provably empty) until the next dispatched operation, which returns
     * normally, fast, with the noise already drained into the SAME
     * `stderrTail()` the in-exchange cadence writes.
     */
    public function testStderrWrittenAfterASettledExchangeIsDrainedByTheNextDispatchedOperation(): void
    {
        $done = $this->tempDir . '/done';
        $chatter = $this->connectChatterbox($done);

        try {
            $this->waitForFile($done, 'the chatterbox never finished its post-connect stderr write');
            $this->assertSame(
                '',
                $chatter->stderrTail(),
                'fd 2 was consumed before any dispatch — the drain became self-scheduled '
                . '(the E537 hazard) or connect() already read it; either way the entry mount '
                . 'below cannot be attributed and this precondition is the witness',
            );

            $client = new LspClient(new ScriptedWorkerConnection(), new InertCache());
            // The dispatch below targets 'php' (the fake); 'typescript' is the
            // real child whose stderr must ride along on the fan-out.
            $client->addServer('typescript', $chatter, new InertCache());

            $began = microtime(true);
            $result = $client->definitionsFor('php', 'file:///project/src/Subject.php', 3, 5);
            $elapsed = microtime(true) - $began;

            $this->assertSame([['fake' => 'definition']], $result);
            $this->assertLessThan(
                2.0,
                $elapsed,
                'the dispatch-entry drain must stay inside the bounded non-blocking tick — '
                . sprintf('this pass took %.3fs', $elapsed),
            );
            $this->assertStringContainsString(
                trim($this->marker),
                $chatter->stderrTail(),
                'the next dispatched operation did not drain the chatterbox — the E690 entry '
                . 'mount is gone and idle stderr is the E475 gap again',
            );

            // Fan-out is safe to call directly too (the public seam the ops use).
            $client->pumpStderr();
            $this->assertStringContainsString(trim($this->marker), $chatter->stderrTail());
        } finally {
            $chatter->disconnect();
        }
    }

    /**
     * THE MOUNT ORDER: the unknown-language guard fires BEFORE the drain, so
     * a misrouted dispatch throws without touching any fd 2 — pinning that
     * the pump runs inside `…For()` at entry, not above the guards.
     */
    public function testUnknownLanguageThrowsBeforeAnyDrainRuns(): void
    {
        $done = $this->tempDir . '/done';
        $chatter = $this->connectChatterbox($done);

        try {
            $this->waitForFile($done, 'the chatterbox never finished its post-connect stderr write');
            $client = new LspClient(new ScriptedWorkerConnection(), new InertCache());
            $client->addServer('typescript', $chatter, new InertCache());

            $this->expectException(\InvalidArgumentException::class);
            try {
                $client->definitionsFor('cobol', 'file:///x', 0, 0);
            } finally {
                $this->assertSame(
                    '',
                    $chatter->stderrTail(),
                    'a dispatch that threw on its language guard still drained fd 2 — the pump '
                    . 'moved above the guard and now misroutes pay for stderr they never owned',
                );
            }
        } finally {
            $chatter->disconnect();
        }
    }

    // -------------------------------------------------------------------------
    // Fixture plumbing
    // -------------------------------------------------------------------------

    /**
     * Spawn the chatterbox: `php <script>` writes one stderr line then sleeps
     * out its lifetime. The DONE file is written AFTER the `fwrite`, so its
     * existence proves the bytes left the child and sit in the pipe unread —
     * the same witness LspConnectionStderrIdleDrainTest uses.
     */
    private function connectChatterbox(string $doneFile): LspConnection
    {
        $script = $this->tempDir . '/chatterbox.php';
        file_put_contents($script, sprintf(
            "<?php\nfwrite(STDERR, %s);\nfile_put_contents(%s, '1');\nusleep(%d);\n",
            var_export($this->marker, true),
            var_export($doneFile, true),
            (int) (self::FIXTURE_LIFETIME_SECONDS * 1000000),
        ));

        $connection = new LspConnection($script, [$script]);
        $connection->connect(PHP_BINARY, [], null, 10.0);

        return $connection;
    }

    private function waitForFile(string $path, string $context): void
    {
        $bound = microtime(true) + self::HANDSHAKE_BOUND_SECONDS;
        while (!file_exists($path)) {
            if (microtime(true) >= $bound) {
                $this->fail(sprintf(
                    'timed out after %.1fs waiting for %s: %s',
                    self::HANDSHAKE_BOUND_SECONDS,
                    basename($path),
                    $context,
                ));
            }
            usleep(20000);
        }
    }
}

/**
 * The well-behaved worker: answers the exchange entirely in memory. It is
 * deliberately NOT an LspConnection — reaching it through the fan-out must be
 * a skip (instanceof gate), not a crash and not an interface growth.
 */
final class ScriptedWorkerConnection implements LspConnectionInterface
{
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
        return [['fake' => 'definition']];
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
}

/** Cache that always misses and stores nothing — every dispatch reaches the connection. */
final class InertCache implements LspCacheInterface
{
    public function set(string $uri, string $method, mixed $value): void
    {
    }

    public function get(string $uri, string $method): mixed
    {
        return null;
    }

    public function has(string $uri, string $method): bool
    {
        return false;
    }

    public function clearFile(string $uri): void
    {
    }

    public function clear(): void
    {
    }

    public function prune(): int
    {
        return 0;
    }

    public function count(): int
    {
        return 0;
    }
}

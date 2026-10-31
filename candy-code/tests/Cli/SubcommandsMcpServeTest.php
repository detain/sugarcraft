<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\Serve\McpServeHost;
use SugarCraft\Code\MCP\Serve\McpServeRoster;
use SugarCraft\McpClient\McpMessage;

/**
 * C-MCP-5.b — the production reachability of `candy-code mcp serve`.
 *
 * `McpServeHostTest` (C-MCP-5.a) proves the host answers correctly once it is
 * handed a roster and a chain in-process. That is exactly the span a wiring
 * step can leave unreachable: a verb gate that never routes `serve`, a hand-
 * written tool list replacing the classified roster build, a chain built
 * without the gate. This file therefore runs the REAL binary — `php
 * bin/candy-code mcp serve --root <temp>` with stdin on a pipe — and holds
 * the conversation to its documented close: `initialize` echoes the host's
 * own protocol constant, `tools/list` carries `McpServeRoster::NAMES` in
 * order (derived from the constant, never a second hand-list that could
 * drift from what the roster classifies), closing stdin exits 0, and
 * stderr never carries wire bytes — framing lives on stdout alone, so a
 * client can read its channel without filtering noise. The host itself
 * writes nothing to stderr; a Bootstrap launch-time notice (such as the
 * bypass-mode warning) may, and it must stay plainly out of the protocol:
 * no JSON-RPC envelope and no ANSI on that stream is the contract. The
 * frames are built with `McpMessage::request()` itself, so the test speaks
 * the wire the library defines rather than a remembered copy of it.
 *
 * A second, source-shape arm pins the wiring the child run cannot see the
 * absence of cheaply: the serve arm of the verb gate hands
 * `McpServeRoster::build($root)` INTO `McpServeHost::run()` under a
 * gate-backed chain (r1 NIT-2). Deleting that call shape — say, widening the
 * roster by hand at the call site — reddens it without waiting on a spawn.
 */
final class SubcommandsMcpServeTest extends TestCase
{
    /** Generous for a cold autoloader; a hung child must fail, never wedge. */
    private const DEADLINE_SECONDS = 20.0;

    private string $projectRoot;

    private string $tempHome;

    /** @var resource|null */
    private $child = null;

    protected function setUp(): void
    {
        $tmp = (string) realpath(sys_get_temp_dir());
        $stamp = uniqid((string) getmypid(), true);
        $this->projectRoot = $tmp . '/mcp-serve-proj-' . $stamp;
        $this->tempHome = $tmp . '/mcp-serve-home-' . $stamp;
        self::assertTrue(mkdir($this->projectRoot, 0700, true));
        self::assertTrue(mkdir($this->tempHome, 0700, true));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->child)) {
            $this->killServeChild();
        }
        foreach ([$this->projectRoot, $this->tempHome] as $dir) {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($dir . '/' . $entry);
                }
            }
            @rmdir($dir);
        }
    }

    public function testTheSpawnedVerbAnswersInitializeAndListThenExitsZeroOnEof(): void
    {
        $pipes = $this->spawnServeChild();

        fwrite($pipes[0], McpMessage::request('i1', 'initialize')->toJson() . "\n");
        fwrite($pipes[0], McpMessage::request('l1', 'tools/list')->toJson() . "\n");
        // The documented close: the host's run() loop reads fgets until false
        // and returns 0 — nothing else ever ends an `mcp serve`.
        fclose($pipes[0]);

        [$stdout, $stderr, $exitCode] = $this->drainServeChild($pipes);

        self::assertSame(0, $exitCode, 'EOF on stdin must exit 0, not on a signal or a fatal; stderr was: ' . $stderr);
        // The stderr contract: launch notices are permitted, protocol bytes
        // are not — a client that merges the two channels must never be able
        // to mistake a warning for a frame.
        self::assertStringNotContainsString('"jsonrpc"', $stderr, 'framing leaked to stderr: ' . $stderr);
        self::assertStringNotContainsString("\x1b", $stderr, 'ANSI styling leaked to the diagnostic stream: ' . $stderr);

        $lines = array_values(array_filter(explode("\n", $stdout), static fn (string $line): bool => trim($line) !== ''));
        self::assertCount(2, $lines, 'exactly one answer frame per request frame; raw stdout: ' . $stdout);

        $init = json_decode($lines[0], true);
        self::assertIsArray($init, 'the initialize answer must be one JSON frame per line: ' . $lines[0]);
        self::assertSame('2.0', $init['jsonrpc'] ?? null);
        self::assertSame('i1', $init['id'] ?? null, 'the reply must echo the request id the client paired it to');
        self::assertArrayNotHasKey('error', $init, json_encode($init));
        self::assertSame(McpServeHost::PROTOCOL_VERSION, $init['result']['protocolVersion'] ?? null);
        self::assertSame('candy-code', $init['result']['serverInfo']['name'] ?? null);

        $list = json_decode($lines[1], true);
        self::assertIsArray($list, 'the tools/list answer must be one JSON frame per line: ' . $lines[1]);
        self::assertSame('l1', $list['id'] ?? null);
        self::assertArrayNotHasKey('error', $list, json_encode($list));
        self::assertSame(
            McpServeRoster::NAMES,
            array_column($list['result']['tools'] ?? [], 'name'),
            'the wire roster must be the classified roster, in its order — not a call-site hand-list',
        );
    }

    public function testTheServeArmOfTheGateHandsTheRosterBuildToTheHostUnderAGateBackedChain(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Cli/Subcommands.php');

        $gate = strpos($source, "if (\$verb === 'serve') {");
        self::assertNotFalse($gate, 'the verb gate lost its serve arm — `candy-code mcp serve` would bail to usage');

        $start = strpos($source, 'private static function mcpServe(');
        self::assertNotFalse($start);
        $end = strpos($source, 'private static function mcpTrust(', $start);
        self::assertNotFalse($end, 'the span of the serve method must end at the next verb body');
        $body = substr($source, $start, $end - $start);

        // r1 NIT-2: the roster that reaches the host is the BUILD's output,
        // classified by the roster class — the only call shape that keeps the
        // read-only guarantee enforced rather than copied.
        self::assertMatchesRegularExpression(
            '/McpServeHost::run\(\s*McpServeRoster::build\(\$root\),/',
            preg_replace('/\s+/', ' ', $body) ?? $body,
            'the serve arm must pass McpServeRoster::build()' . "'s output directly into the host",
        );
        // The chain is Bootstrap's own, built over the headless-surface gate,
        // so the PreToolUse floor is the launch's — not a gate-less roster.
        self::assertStringContainsString('Bootstrap::hooks(Bootstrap::permissionGate()', $body);
        // The root is the launch root the parser already validated, with the
        // same cwd fallback the headless turn uses.
        self::assertStringContainsString("\$args->root ?? (getcwd() ?: '')", $body);
    }

    /** @return array{0:resource,1:resource,2:resource} */
    private function spawnServeChild(): array
    {
        $root = dirname(__DIR__, 2);
        $command = [
            PHP_BINARY,
            $root . '/bin/candy-code',
            'mcp',
            'serve',
            '--root',
            $this->projectRoot,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $root, $this->serveEnvironment());
        self::assertIsResource($process, 'failed to spawn bin/candy-code mcp serve');
        // Array-form proc_open runs the argv without a shell, so this pid is
        // the php process itself and terminate() aims at it directly.
        $this->child = $process;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return $pipes;
    }

    /**
     * @param array{0:resource,1:resource,2:resource} $pipes
     *
     * @return array{0:string,1:string,2:int} stdout, stderr, exit code
     */
    private function drainServeChild(array $pipes): array
    {
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + self::DEADLINE_SECONDS;
        $exitCode = null;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($this->child);
            if ($status['running'] === false) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                $this->killServeChild();
                self::fail('`mcp serve` did not exit within ' . self::DEADLINE_SECONDS . 's of EOF on stdin; stdout so far: ' . substr($stdout, 0, 400));
            }
            usleep(20000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($this->child);
        $this->child = null;

        return [$stdout, $stderr, (int) $exitCode];
    }

    private function killServeChild(): void
    {
        if (is_resource($this->child)) {
            proc_terminate($this->child, 9);
            proc_close($this->child);
            $this->child = null;
        }
    }

    /**
     * PATH + a throwaway HOME + TMPDIR, and deliberately nothing else — the
     * same whitelist {@see \SugarCraft\Code\Tests\Integration\BinCandyCodeDispatchTest}
     * argues for: no `CANDY_CODE_*` or provider credential from the runner may
     * reach the child, and an override list cannot name what it does not know
     * the machine set.
     *
     * @return array<string, string>
     */
    private function serveEnvironment(): array
    {
        return [
            'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => $this->tempHome,
            'TMPDIR' => (string) (getenv('TMPDIR') ?: sys_get_temp_dir()),
        ];
    }
}

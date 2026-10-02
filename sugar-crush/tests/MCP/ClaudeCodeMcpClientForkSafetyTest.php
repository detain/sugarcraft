<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

/**
 * Audit B1 / AG-1 for the `claude-mcp` transport: the client is connected in
 * the TUI parent and used from forked turns and sub-agents, all holding copies
 * of one stdio pipe pair. Before the fix every fork reused the parent's id
 * counter, so a SIGKILLed call's late reply answered the next fork's call, and
 * concurrent forks read each other's replies.
 *
 * Children report through temp files and leave through ForkedChild::exitNow()
 * (never a plain exit(), which would run PHPUnit's shutdown in the copy); every
 * wait is bounded, and only this test's own tracked children are signalled.
 */
final class ClaudeCodeMcpClientForkSafetyTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private const CHILD_DEADLINE_SECONDS = 15.0;

    /** Single-threaded stdio server: answers initialize; slow (600ms), fast, echo (random 50-300ms). */
    private const FIXTURE = <<<'PHP'
        <?php
        $born = microtime(true);
        while (($line = fgets(STDIN)) !== false) {
            if (microtime(true) - $born > 60.0) {
                exit(0);
            }
            $msg = json_decode($line, true);
            if (is_array($msg) && ($msg['method'] ?? null) === 'initialize' && isset($msg['id'])) {
                // The handshake connect() waits for (audit MCP-3).
                echo json_encode(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => [
                    'protocolVersion' => '2024-11-05', 'capabilities' => new stdClass(),
                    'serverInfo' => ['name' => 'fixture', 'version' => '0'],
                ]]), "\n";
                fflush(STDOUT);
                continue;
            }
            if (!is_array($msg) || ($msg['method'] ?? null) !== 'tools/call' || !isset($msg['id'])) {
                continue;
            }
            $name = $msg['params']['name'] ?? '';
            if ($name === 'slow') {
                usleep(600000);
            } elseif ($name === 'echo') {
                usleep(random_int(50000, 300000));
                $name = 'echo:' . ($msg['params']['arguments']['n'] ?? '?');
            }
            echo json_encode(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => ['content' => [['type' => 'text', 'text' => $name]]]]), "\n";
            fflush(STDOUT);
        }
        PHP;

    private string $script = '';

    private ?ClaudeCodeMcpClient $client = null;

    /** @var array<int, string> pid => result file */
    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->script = (string) tempnam(sys_get_temp_dir(), 'cc_fork_safety_');
        file_put_contents($this->script, self::FIXTURE);

        $this->client = new ClaudeCodeMcpClient(PHP_BINARY, [$this->script]);
        $this->client->connect();
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        foreach ($this->children as $file) {
            @unlink($file);
        }
        $this->children = [];

        $this->client?->disconnect();
        $this->client = null;
        if ($this->script !== '' && is_file($this->script)) {
            unlink($this->script);
        }

        parent::tearDown();
    }

    public function testAKilledCallsLateReplyIsNeverHandedToTheNextProcess(): void
    {
        $client = $this->client();

        $turn1 = $this->fork(static fn (): string => self::text($client->callTool('slow')));
        usleep(200_000);
        posix_kill($turn1, SIGKILL);
        pcntl_waitpid($turn1, $status);
        $this->forgetForkedChild($turn1);
        @unlink($this->children[$turn1]);
        unset($this->children[$turn1]);

        $turn2 = $this->fork(static fn (): string => self::text($client->callTool('fast')));

        self::assertSame('fast', $this->reap($turn2), 'turn 2 must get the tool it asked for');
        self::assertSame('echo:parent', self::text($client->callTool('echo', ['n' => 'parent'])));
    }

    public function testConcurrentForkedCallersEachGetTheirOwnReply(): void
    {
        $client = $this->client();

        // Several rounds: one round of a broken transport can pass by luck.
        for ($round = 0; $round < 3; $round++) {
            $pids = [];
            foreach (['a', 'b', 'c', 'd'] as $tag) {
                $n = "r{$round}-{$tag}";
                $pids[$n] = $this->fork(static fn (): string => self::text($client->callTool('echo', ['n' => $n])));
            }

            foreach ($pids as $n => $pid) {
                self::assertSame("echo:{$n}", $this->reap($pid), "{$n} must receive its own reply");
            }
        }

        self::assertSame('echo:after', self::text($client->callTool('echo', ['n' => 'after'])));
    }

    public function testANonOwnerDisconnectLeavesTheSharedServerRunning(): void
    {
        $client = $this->client();

        $child = $this->fork(static function () use ($client): string {
            $up = $client->isUp() ? 'up' : 'down';
            $client->disconnect();
            $client->__destruct();

            return $up;
        });

        self::assertSame('up', $this->reap($child), 'a forked process must see the shared server as up');
        self::assertTrue($client->isUp(), 'a non-owner disconnect must not signal the server');
        self::assertSame('echo:still', self::text($client->callTool('echo', ['n' => 'still'])));
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function client(): ClaudeCodeMcpClient
    {
        self::assertNotNull($this->client);

        return $this->client;
    }

    private static function text(\SugarCraft\Crush\McpMessage $reply): string
    {
        return (string) ($reply->result['content'][0]['text'] ?? '');
    }

    /** @param \Closure(): string $work */
    private function fork(\Closure $work): int
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'cc-fork-test-');
        $pid = $this->forkTracked();
        self::assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            $this->runChild($work, $file);
        }

        $this->children[$pid] = $file;

        return $pid;
    }

    /** @param \Closure(): string $work */
    private function runChild(\Closure $work, string $file): never
    {
        try {
            file_put_contents($file, json_encode(['ok' => $work()]));
        } catch (\Throwable $thrown) {
            file_put_contents($file, json_encode(['thrown' => $thrown::class . ': ' . $thrown->getMessage()]));
        }

        ForkedChild::exitNow();
    }

    private function reap(int $pid): string
    {
        $deadline = hrtime(true) + (int) (self::CHILD_DEADLINE_SECONDS * 1e9);
        while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            if (hrtime(true) >= $deadline) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                $this->forgetForkedChild($pid);
                unset($this->children[$pid]);
                self::fail("child {$pid} did not finish within " . self::CHILD_DEADLINE_SECONDS . 's');
            }
            usleep(10_000);
        }

        $this->forgetForkedChild($pid);
        $file = $this->children[$pid];
        unset($this->children[$pid]);
        $raw = (string) @file_get_contents($file);
        @unlink($file);

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, "child {$pid} reported nothing");
        self::assertArrayNotHasKey('thrown', $decoded, (string) ($decoded['thrown'] ?? ''));

        return (string) ($decoded['ok'] ?? '');
    }
}

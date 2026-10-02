<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit B3: the turn's frame socketpair must not leak into the processes the
 * turn spawns, and the parent must not need the socket's EOF to learn that
 * the turn child died.
 *
 * Before the fix the child kept BOTH socket ends (it never closed the
 * parent's) and neither was close-on-exec, so every Bash/Grep/hook command
 * inherited them; and a child that died without a result frame was only
 * noticed at EOF — which every inherited copy postponed until its holder
 * exited.
 */
final class EngineBackendFrameSocketInheritanceTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private string $dir = '';

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('completeAsync() only forks with ext-pcntl + ext-posix.');
        }
        if (!\is_dir('/proc/self/fd')) {
            self::markTestSkipped('descriptor inheritance is read from /proc/self/fd.');
        }

        $this->dir = \sys_get_temp_dir() . '/frame_socket_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        // The holder is forked by the TURN child, so this process never
        // tracked it; its pid comes back through the file instead.
        $pidFile = $this->dir . '/holder.pid';
        if (\is_file($pidFile)) {
            $holder = (int) \file_get_contents($pidFile);
            if ($holder > 0) {
                @\posix_kill($holder, 9);
            }
        }
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);

        parent::tearDown();
    }

    public function testACommandTheTurnSpawnsInheritsNeitherFrameSocketEnd(): void
    {
        if (!\extension_loaded('ffi')) {
            self::markTestSkipped('close-on-exec is set through ext-ffi, which this build lacks.');
        }

        // Every socket this process already holds is inherited by the fork
        // and is not the turn's to fix; only a socket the turn itself
        // created can show up as NEW in the command's descriptor table.
        $before = self::socketInodes(self::ownDescriptorListing());

        $out = $this->dir . '/fds.txt';
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [
                new ToolCall('call_1', 'Bash', ['command' => 'ls -l /proc/self/fd > ' . \escapeshellarg($out)]),
            ]),
            new CompleteResponse(content: 'done'),
        ]);
        $backend = EngineBackend::new($provider, 'm')->withTools([new Bash($this->dir)]);

        [$error] = $this->settle($backend->completeAsync([Message::user('go')]));

        self::assertNull($error, 'the turn itself failed: ' . ($error?->getMessage() ?? ''));
        self::assertFileExists($out, 'the Bash command never ran');
        $leaked = \array_values(\array_diff(self::socketInodes((string) \file_get_contents($out)), $before));
        self::assertSame([], $leaked, 'the spawned command inherited the turn\'s frame socket');
    }

    public function testATurnChildThatDiesWithoutAResultIsNoticedWhileAForkStillHoldsItsSocket(): void
    {
        $pidFile = $this->dir . '/holder.pid';
        $test = $this;
        // Forks inherit a descriptor whatever its close-on-exec flag says, so
        // this holder keeps the frame socket open for 5s no matter what B3
        // does to spawned commands. Only watching the turn child's pid can
        // settle the turn before the holder lets go.
        $dying = new class (static function () use ($test, $pidFile): void {
            $holder = $test->forkHolder();
            \file_put_contents($pidFile, (string) $holder);
            // The turn child dies here, mid-turn, without a result frame.
            \posix_kill(\posix_getpid(), 9);
        }) implements Tool {
            public function __construct(private \Closure $body)
            {
            }

            public function name(): string
            {
                return 'die';
            }

            public function description(): string
            {
                return 'forks a socket holder, then kills the turn child';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                ($this->body)();

                return new ToolResult(toolCallId: 'unreachable', content: '', isError: true, durationMs: 0);
            }
        };

        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'die', [])]),
            new CompleteResponse(content: 'unreachable'),
        ]);
        $backend = EngineBackend::new($provider, 'm')->withTools([$dying]);

        $started = \microtime(true);
        [$error] = $this->settle($backend->completeAsync([Message::user('go')]));
        $elapsed = \microtime(true) - $started;

        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertStringContainsString('exited without a result', $error->getMessage());
        self::assertLessThan(2.0, $elapsed, \sprintf(
            'the dead turn was only noticed after %.2fs — when the forked holder let go of the socket',
            $elapsed,
        ));
    }

    /**
     * The fork the dying tool makes. Public so the anonymous tool can reach
     * the tracked-fork helper; it runs inside the (forked) turn child.
     */
    public function forkHolder(): int
    {
        $pid = $this->forkTracked();
        if ($pid === 0) {
            \usleep(5_000_000);
            ForkedChild::exitNow(0);
        }

        return $pid;
    }

    private static function ownDescriptorListing(): string
    {
        $listing = '';
        foreach (\scandir('/proc/self/fd') ?: [] as $entry) {
            $listing .= $entry . ' -> ' . (string) @\readlink('/proc/self/fd/' . $entry) . "\n";
        }

        return $listing;
    }

    /**
     * @return list<string>
     */
    private static function socketInodes(string $listing): array
    {
        \preg_match_all('/socket:\[(\d+)\]/', $listing, $matches);

        return \array_values(\array_unique($matches[1]));
    }

    /**
     * Run the loop until $promise settles (bounded).
     *
     * @return array{0: ?\Throwable}
     */
    private function settle(\React\Promise\PromiseInterface $promise): array
    {
        $loop = Loop::get();
        $done = false;
        $error = null;
        $promise->then(
            static function () use (&$done, $loop): void {
                $done = true;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$done, &$error, $loop): void {
                $done = true;
                $error = $e;
                $loop->stop();
            },
        );
        if (!$done) {
            $guard = $loop->addTimer(15.0, static fn() => $loop->stop());
            $loop->run();
            $loop->cancelTimer($guard);
        }
        self::assertTrue($done, 'the turn never settled');

        return [$error];
    }
}

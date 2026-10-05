<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Workspace;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\Connection as SocketConnection;
use SugarCraft\Crush\Host\RemoteSessionHost;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\PrivateDir;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ProcessReaper;

/**
 * The gateway's handle on one workspace-host child (roadmap O-7): spawning
 * it, proving the connection that comes back is it, the JSON-RPC client over
 * that connection, and taking it down again.
 *
 * THE SPAWN ({@see start()}) follows `BackgroundSupervisor::spawnSession()`'s
 * hard-won shape, for its reasons:
 *
 *  - an ARGV, never a shell string: `php -r <entry>`, with nothing secret in it
 *    (`/proc/<pid>/cmdline` is world-readable) — the root, the socket path
 *    and the per-spawn token go down the child's stdin as one JSON line
 *    ({@see WorkspaceHostProcess::main()});
 *  - wrapped by {@see ProcessContainment::spawnSpec()}, so the child leads a
 *    process group and {@see stop()} takes its turn children with it;
 *  - the UNIX listener is bound AFTER the spawn, in the 0700 run directory, so
 *    no child ever inherits it; the first connection must send the token, a
 *    connection that does not is closed and the wait goes on, and the
 *    listener is closed and unlinked as soon as the child is in.
 *
 * Its stdin stays open for its whole life: EOF there is how the child learns
 * the gateway is gone, even when the gateway dies without a word. stdout and
 * stderr go to a per-root log in the run directory.
 *
 * MUTABLE ON PURPOSE: the live state of one child process.
 */
final class WorkspaceHostClient
{
    /** How long a child may take to start, connect back and say hello. */
    public const START_TIMEOUT_SECONDS = 30.0;

    /** What the gateway's connection says it is in `server.hello`. */
    public const CLIENT_NAME = 'sugarcrush-gateway';

    private bool $running = true;

    private float $lastUsed;

    /** @var list<\Closure(string): void> */
    private array $exitListeners = [];

    /**
     * @param resource $process
     * @param resource $stdin
     */
    private function __construct(
        private readonly string $root,
        private $process,
        private readonly ?int $groupPid,
        private $stdin,
        private readonly RemoteSessionHost $rpc,
        private readonly string $logPath,
    ) {
        $this->lastUsed = \microtime(true);
        $rpc->onClose(function (string $reason): void {
            $this->exited($reason);
        });
    }

    /**
     * Spawn the workspace host for $root, wait for it to connect back with
     * its token, and say `server.hello` over the connection.
     *
     * @param string $root a canonical project directory
     * @param string $runDir where the listener and the log go (created 0700)
     * @param array<string, string> $env overrides on the inherited environment
     *
     * @return PromiseInterface<self>
     */
    public static function start(
        string $root,
        ServerConfig $config,
        string $runDir,
        string $version,
        ?LoopInterface $loop = null,
        array $env = [],
    ): PromiseInterface {
        $loop ??= Loop::get();
        try {
            PrivateDir::ensure($runDir, 'workspace host run directory');
        } catch (\RuntimeException $e) {
            return \React\Promise\reject($e);
        }
        $autoload = BackgroundSupervisor::autoloadPath();
        if ($autoload === null) {
            return \React\Promise\reject(new \RuntimeException('cannot find the composer autoloader a workspace host must load'));
        }

        $tag = \substr(\hash('sha256', $root), 0, 12);
        $socketPath = $runDir . '/ws-' . $tag . '-' . \bin2hex(\random_bytes(4)) . '.sock';
        $logPath = $runDir . '/workspace-' . $tag . '.log';
        $token = \bin2hex(\random_bytes(32));

        $code = \sprintf(
            'require %s; exit(\\%s::main());',
            \var_export($autoload, true),
            WorkspaceHostProcess::class,
        );
        $pipes = [];
        $process = @\proc_open(
            ProcessContainment::spawnSpec([\PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code]),
            [['pipe', 'r'], ['file', $logPath, 'a'], ['file', $logPath, 'a']],
            $pipes,
            $root,
            ProcessContainment::env($env),
        );
        if (!\is_resource($process)) {
            return \React\Promise\reject(new \RuntimeException('cannot start a workspace host for ' . $root));
        }
        $groupPid = ProcessContainment::groupId($process);
        $stdin = $pipes[0];

        // Bound AFTER the spawn: the child must never inherit the listener.
        @\unlink($socketPath);
        $listener = @\stream_socket_server('unix://' . $socketPath, $errno, $errstr);
        if ($listener === false) {
            @\fclose($stdin);
            ProcessReaper::terminateAndClose($process, $groupPid);

            return \React\Promise\reject(new \RuntimeException('cannot listen for the workspace host: ' . $errstr));
        }
        \stream_set_blocking($listener, false);
        ProcessContainment::closeOnExec($listener);
        ForkedChild::registerServerStream($listener);

        \fwrite($stdin, (string) \json_encode(
            WorkspaceHostProcess::settingsFor($config, $root, $socketPath, $token, $version),
            \JSON_UNESCAPED_SLASHES,
        ) . "\n");

        $deferred = new Deferred();
        $settled = false;
        $timer = null;
        $closeListener = static function () use (&$listener, $loop, $socketPath): void {
            if (\is_resource($listener)) {
                $loop->removeReadStream($listener);
                ForkedChild::forgetServerStream($listener);
                \fclose($listener);
            }
            $listener = null;
            @\unlink($socketPath);
        };
        $fail = static function (string $reason) use (&$settled, &$timer, $closeListener, $deferred, $loop, $process, $groupPid, $stdin, $logPath): void {
            if ($settled) {
                return;
            }
            $settled = true;
            if ($timer instanceof TimerInterface) {
                $loop->cancelTimer($timer);
            }
            $closeListener();
            @\fclose($stdin);
            ProcessReaper::terminateAndClose($process, $groupPid);
            $deferred->reject(new \RuntimeException($reason . '; see ' . $logPath));
        };

        $timer = $loop->addTimer(self::START_TIMEOUT_SECONDS, static function () use ($fail, $root): void {
            $fail(\sprintf('the workspace host for %s did not start within %d s', $root, (int) self::START_TIMEOUT_SECONDS));
        });

        $loop->addReadStream($listener, static function ($server) use (&$settled, &$timer, $closeListener, $fail, $deferred, $loop, $root, $process, $groupPid, $stdin, $logPath, $token): void {
            $socket = @\stream_socket_accept($server, 0);
            if ($socket === false) {
                return;
            }
            ProcessContainment::closeOnExec($socket);
            ForkedChild::registerServerStream($socket);
            $connection = new SocketConnection($socket, $loop);
            $connection->on('close', static fn () => ForkedChild::forgetServerStream($socket));

            // The first line is the token; anything else is not our child.
            $buffer = '';
            $onData = null;
            $onData = static function (string $chunk) use (&$buffer, &$onData, &$settled, &$timer, $connection, $closeListener, $fail, $deferred, $loop, $root, $process, $groupPid, $stdin, $logPath, $token): void {
                $buffer .= $chunk;
                $newline = \strpos($buffer, "\n");
                if ($newline === false) {
                    if (\strlen($buffer) > 256) {
                        $connection->close();
                    }

                    return;
                }
                $connection->removeListener('data', $onData);
                if (!\hash_equals($token, \substr($buffer, 0, $newline)) || $settled) {
                    $connection->close();

                    return;
                }
                $closeListener();
                $rpc = RemoteSessionHost::overStream($connection, self::CLIENT_NAME, \substr($buffer, $newline + 1));
                $rpc->hello()->then(
                    static function () use (&$settled, &$timer, $deferred, $loop, $root, $process, $groupPid, $stdin, $rpc, $logPath): void {
                        if ($settled) {
                            return;
                        }
                        $settled = true;
                        if ($timer instanceof TimerInterface) {
                            $loop->cancelTimer($timer);
                        }
                        $deferred->resolve(new self($root, $process, $groupPid, $stdin, $rpc, $logPath));
                    },
                    static function (\Throwable $e) use ($fail, $root): void {
                        $fail(\sprintf('the workspace host for %s refused the handshake: %s', $root, $e->getMessage()));
                    },
                );
            };
            $connection->on('data', $onData);
        });

        return $deferred->promise();
    }

    public function root(): string
    {
        return $this->root;
    }

    /** The JSON-RPC client over the child's connection. */
    public function rpc(): RemoteSessionHost
    {
        return $this->rpc;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /** The child's pid (the containment wrapper's when there is one). */
    public function pid(): ?int
    {
        if (!\is_resource($this->process)) {
            return null;
        }
        $status = \proc_get_status($this->process);

        return \is_int($status['pid'] ?? null) ? $status['pid'] : null;
    }

    public function logPath(): string
    {
        return $this->logPath;
    }

    /** Note a use, for the gateway's idle recycling. */
    public function touch(): void
    {
        $this->lastUsed = \microtime(true);
    }

    /** Seconds since the last {@see touch()}. */
    public function idleSeconds(): float
    {
        return \microtime(true) - $this->lastUsed;
    }

    /**
     * Hear the child go — stopped, crashed or its connection lost — with why.
     *
     * @param \Closure(string): void $listener
     */
    public function onExit(\Closure $listener): void
    {
        if (!$this->running) {
            $listener('the workspace host is not running');

            return;
        }
        $this->exitListeners[] = $listener;
    }

    /**
     * Take the child down: close the connection and its stdin (the child
     * stops on either), then the TERM→KILL reap ladder over its process
     * group, so its turn children go with it. Idempotent.
     */
    public function stop(string $reason = 'stopped by the gateway'): void
    {
        $this->rpc->close($reason);
        $this->exited($reason);
    }

    private function exited(string $reason): void
    {
        if (\is_resource($this->stdin)) {
            @\fclose($this->stdin);
        }
        if (\is_resource($this->process)) {
            ProcessReaper::terminateAndClose($this->process, $this->groupPid);
        }
        $this->process = null;
        if (!$this->running) {
            return;
        }
        $this->running = false;
        $listeners = $this->exitListeners;
        $this->exitListeners = [];
        foreach ($listeners as $listener) {
            try {
                $listener($reason);
            } catch (\Throwable) {
            }
        }
    }
}

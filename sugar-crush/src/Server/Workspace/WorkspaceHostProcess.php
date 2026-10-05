<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Workspace;

use React\EventLoop\Loop;
use React\Socket\Connection as SocketConnection;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Host\SessionHub;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Protocol\Dispatcher;
use SugarCraft\Crush\Protocol\ServerContext;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Server\Ws\Connection;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\ProcessContainment;

/**
 * The workspace-host CHILD: one project root's sessions, in a process of its
 * own, behind the `serve` gateway (roadmap O-7, Appendix O §4.1 "Phase 7").
 *
 * WHY A PROCESS PER ROOT. `Cli\Bootstrap` keeps a launch's root in process
 * statics — the project the settings layers read, the frozen trust lists, the
 * MCP clients started once per launch, the hook and skill rosters — so one
 * process serves exactly one root ({@see Bootstrap::workspace()}). A second
 * root therefore gets a second process, which also keeps a crashing or
 * leaking root from taking the others down and lets the gateway recycle an
 * idle one ({@see Gateway}).
 *
 * THE HANDSHAKE, mirroring {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor::spawnSession()}
 * ("bind after spawn, prove it is the child"): {@see WorkspaceHostClient}
 * spawns this with an argv that carries nothing but the PHP binary and this
 * entry point, then binds a UNIX listener of its own; this reads ONE JSON
 * line on stdin — the root, that socket's path, a per-spawn token and the
 * server's session settings, none of which may ride the world-readable argv —
 * connects, and sends the token as its first line. After that the socket
 * carries the same `sugarcrush.v1` JSON-RPC a browser speaks, WebSocket-framed
 * (this side is the SERVER end: the gateway's frames arrive masked), answered
 * by the same {@see Dispatcher} over this root's {@see SessionHub}.
 *
 * IT ENDS when stdin reaches EOF (the gateway closed it, or died), the socket
 * closes, or SIGTERM/SIGINT arrives: the dispatcher stops, every session is
 * released (their single-writer locks with them), and the loop returns.
 * Nothing is printed on purpose; stdout and stderr go to the host's log, which
 * is where an uncaught failure lands.
 */
final class WorkspaceHostProcess
{
    /** How long connecting back to the gateway may take. */
    public const CONNECT_TIMEOUT_SECONDS = 10.0;

    /** The connection id the dispatcher knows the gateway by. */
    public const GATEWAY_CONNECTION_ID = 'gateway';

    private function __construct()
    {
    }

    /**
     * The child's entry point; answers its exit code. The spawn's `-r` code
     * is `exit(WorkspaceHostProcess::main());`.
     */
    public static function main(): int
    {
        \umask(0o077);
        $line = \fgets(\STDIN, 65536);
        $config = \is_string($line) ? \json_decode($line, true, 8) : null;
        if (!\is_array($config) || !\is_string($config['root'] ?? null) || !\is_string($config['socket'] ?? null) || !\is_string($config['token'] ?? null)) {
            return 2;
        }

        return self::serve($config);
    }

    /**
     * The spawn line's settings as a {@see ServerConfig}: the gateway's own
     * session settings (default permission mode and whether bypass may be
     * asked for, the session and turn caps, the question timeout), rooted at
     * the child's root.
     *
     * @param array<string, mixed> $settings
     */
    public static function configFrom(array $settings): ServerConfig
    {
        $config = ServerConfig::new(\is_string($settings['stateDir'] ?? null) ? $settings['stateDir'] : '')
            ->withRoot((string) ($settings['root'] ?? ''))
            ->withAllowBypass(($settings['allowBypass'] ?? false) === true);
        $mode = PermissionMode::tryFrom((string) ($settings['permissionMode'] ?? ''));
        if ($mode !== null) {
            $config = $config->withPermissionMode($mode);
        }
        if (\is_int($settings['maxOpenSessions'] ?? null)) {
            $config = $config->withMaxOpenSessions($settings['maxOpenSessions']);
        }
        if (\is_int($settings['maxConcurrentTurns'] ?? null)) {
            $config = $config->withMaxConcurrentTurns($settings['maxConcurrentTurns']);
        }
        if (\is_int($settings['askTimeoutSeconds'] ?? null) || \is_float($settings['askTimeoutSeconds'] ?? null)) {
            $config = $config->withAskTimeoutSeconds((float) $settings['askTimeoutSeconds']);
        }

        return $config;
    }

    /**
     * The settings line {@see main()} expects, for $config's sessions on
     * $root behind $socket with $token.
     *
     * @return array<string, mixed>
     */
    public static function settingsFor(ServerConfig $config, string $root, string $socket, string $token, string $version): array
    {
        return [
            'root' => $root,
            'socket' => $socket,
            'token' => $token,
            'version' => $version,
            'stateDir' => $config->stateDir,
            'permissionMode' => $config->permissionMode->value,
            'allowBypass' => $config->allowBypass,
            'maxOpenSessions' => $config->maxOpenSessions,
            'maxConcurrentTurns' => $config->maxConcurrentTurns,
            'askTimeoutSeconds' => $config->askTimeoutSeconds,
        ];
    }

    /** @param array<string, mixed> $settings */
    private static function serve(array $settings): int
    {
        $root = (string) $settings['root'];
        $loop = Loop::get();
        $config = self::configFrom($settings);

        // The root's workspace — built HERE, in the process that serves it,
        // which is the whole point: its statics are this root's alone.
        $workspace = Bootstrap::workspace($root);
        $hub = SessionHub::new($workspace, $config->maxOpenSessions, \SugarCraft\Crush\Context\CompactorConfig::fromSettings(Bootstrap::readUserConfig()));
        $dispatcher = Dispatcher::new(ServerContext::new($hub, $config, \is_string($settings['version'] ?? null) ? $settings['version'] : 'unknown', $loop));

        $socket = @\stream_socket_client('unix://' . $settings['socket'], $errno, $errstr, self::CONNECT_TIMEOUT_SECONDS);
        if ($socket === false) {
            $hub->closeAll();

            return 1;
        }
        // The turns this host forks must not hold the gateway's socket open,
        // and no tool process it spawns may inherit it.
        ProcessContainment::closeOnExec($socket);
        ForkedChild::registerServerStream($socket);
        \fwrite($socket, $settings['token'] . "\n");

        $stopped = false;
        $handlers = [];
        $stop = static function () use (&$stopped, &$handlers, $dispatcher, $hub, $loop): void {
            if ($stopped) {
                return;
            }
            $stopped = true;
            $dispatcher->stop('the workspace host is stopping');
            $hub->closeAll();
            $loop->removeReadStream(\STDIN);
            foreach ($handlers as $signal => $handler) {
                $loop->removeSignal($signal, $handler);
            }
            $loop->stop();
        };

        $stream = new SocketConnection($socket, $loop);
        $connection = new Connection(
            self::GATEWAY_CONNECTION_ID,
            'workspace-gateway',
            $stream,
            $stream,
            $dispatcher,
            ServerConfig::MAX_CLIENT_MESSAGE_BYTES,
            static function () use ($stop): void {
                $stop();
            },
        );
        $dispatcher->onOpen($connection);

        // EOF on stdin is the gateway letting go — or dying, which closes it
        // all the same: either way nobody is left to answer to.
        \stream_set_blocking(\STDIN, false);
        $loop->addReadStream(\STDIN, static function ($stdin) use ($stop): void {
            $chunk = @\fread($stdin, 8192);
            if ($chunk === '' || $chunk === false) {
                if (\feof($stdin)) {
                    $stop();
                }
            }
        });
        // Never SIGCHLD: the turn children are reaped by the engine's own sweep.
        if (\function_exists('pcntl_signal')) {
            foreach ([\SIGTERM, \SIGINT] as $signal) {
                $handlers[$signal] = $stop;
                $loop->addSignal($signal, $stop);
            }
        }

        $loop->run();

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;
use SugarCraft\Crush\Host\SessionHub;
use SugarCraft\Crush\Protocol\Dispatcher;
use SugarCraft\Crush\Protocol\ServerContext;
use SugarCraft\Crush\Server\Auth\AuthContext;
use SugarCraft\Crush\Server\Auth\LoginCodes;
use SugarCraft\Crush\Server\Auth\TokenStore;
use SugarCraft\Crush\Server\DiscoveryFile;
use SugarCraft\Crush\Server\Http\StaticFiles;
use SugarCraft\Crush\Server\InterfaceAddresses;
use SugarCraft\Crush\Server\Listener;
use SugarCraft\Crush\Server\ParentPidWatchdog;
use SugarCraft\Crush\Server\Preflight;
use SugarCraft\Crush\Server\Server;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Server\ServerConfigException;
use SugarCraft\Crush\Server\StateDir;
use SugarCraft\Crush\Server\Workspace\Gateway;
use SugarCraft\Crush\Support\Daemonize;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * `sugarcrush serve` — the WebSocket server, in the foreground or detached,
 * and the verbs that manage a running one (Appendix O §4.6, §4.7; roadmap
 * O-3a, O-4a).
 *
 *     serve [flags] [--detach] [--parent-pid <pid>]
 *     serve status | stop [--force] | logs [-f] | url | token [--rotate]
 *
 * Like every other verb it is dispatched before `Program` exists, so it never
 * touches the terminal beyond its own stderr lines.
 *
 * STARTING. Refused at exit 2 before binding anything (nothing was attempted
 * and a retry cannot help): an unknown action or a stray operand, a flag that
 * does not belong to the action, a malformed flag value, a non-loopback
 * `--host` without `--allow-remote`, a `--permission-mode` of
 * `bypass-permissions` or `dont-ask` without `--allow-bypass`, running as root
 * without `--allow-root`, a PHP without pcntl, posix or FFI ({@see Preflight}),
 * an unsafe state directory, and a `--parent-pid` that is not running. A port
 * already in use, or a server already running from the same state directory
 * (the singleton lock is held), is exit 1: it ran, failed, and may succeed
 * later (under `--output-format json`, `{"result":{"listening":false,…}}`).
 *
 * WHAT IT PRINTS. stderr: the address, the root, the session permission mode
 * and a sign-in URL whose one-time code (120 s, single use) rides in the URL
 * fragment. With `--output-format json`, the same facts as ONE document on
 * stdout. `--detach` prints the same once the background server has bound its
 * port, then returns: the URL it prints is one that answers.
 *
 * WHAT IT SERVES: the `sugarcrush.v1` protocol (roadmap O-3b) over the
 * served root's workspace — the same {@see Bootstrap::workspace()} bundle a
 * TUI session is built on, its sessions driven through a
 * {@see \SugarCraft\Crush\Host\SessionHub} and answered by the
 * {@see Dispatcher} ({@see protocol()}).
 *
 * WHILE IT RUNS it holds `server.lock`, keeps `server.json` (the discovery
 * record `status`/`stop`/`attach` read) and answers `serve url` on a 0600
 * control socket in the 0700 state directory ({@see StateDir}). SIGTERM or
 * SIGINT drains it: tell every client (`server.shutdown`), stop accepting,
 * close every WebSocket with 1001, release every session, remove the record
 * and the socket, release the lock.
 */
final class Serve
{
    /** The management verbs; none of them starts a server. */
    public const ACTIONS = ['status', 'stop', 'logs', 'url', 'token'];

    /**
     * The {@see ParsedArgs::SUBCOMMAND_FLAGS} each action accepts, keyed by
     * action ('' = starting a server). A flag outside its action's list is a
     * usage error rather than silently ignored.
     *
     * @var array<string, list<string>>
     */
    public const ACTION_FLAGS = [
        '' => ['--allow-bypass', '--allow-remote', '--allow-root', '--allowed-host', '--allowed-origin', '--detach', '--host', '--no-web', '--parent-pid', '--port', '--web-root'],
        'status' => [],
        'stop' => ['--force'],
        'logs' => ['--follow', '-f'],
        'url' => [],
        'token' => ['--rotate'],
    ];

    /**
     * How long `stop` waits after SIGTERM for the server to drain before
     * SIGKILL — at least, and longer when `server.drainSeconds` asks the
     * server to wait longer ({@see stopWaitSeconds()}).
     */
    public const STOP_DRAIN_SECONDS = 30.0;

    /** What `stop` allows beyond the configured drain for the rest of the shutdown. */
    public const STOP_DRAIN_MARGIN_SECONDS = 5.0;

    /** How long `stop` waits for the kernel to take a SIGKILLed server away. */
    public const STOP_KILL_WAIT_SECONDS = 5.0;

    /** How long `--detach` waits for the background server to report its URL. */
    public const DETACH_REPORT_SECONDS = 20.0;

    /** How often a detached server checks whether its log needs rotating. */
    public const LOG_ROTATE_CHECK_SECONDS = 60.0;

    /** Lines `serve logs` prints before following (or exiting). */
    public const LOG_TAIL_LINES = 100;

    /** The control-socket request `serve url` sends: `login-url <token>`. */
    private const CONTROL_LOGIN_URL = 'login-url';

    /**
     * The control-socket request `serve token --rotate` sends, carrying the
     * NEW token: `reload-token <token>`. The server re-reads the token file
     * first, so the request proves it was sent by someone who can read it.
     */
    private const CONTROL_RELOAD_TOKEN = 'reload-token';

    private const USAGE = 'Usage: sugarcrush serve [--host <ip>] [--port <n>] [--allow-remote] [--allowed-host <hosts>] [--allowed-origin <origins>]'
        . ' [--web-root <dir>] [--no-web] [--allow-bypass] [--allow-root] [--detach] [--parent-pid <pid>]'
        . ' | serve status | serve stop [--force] | serve logs [-f] | serve url | serve token [--rotate]';

    private function __construct()
    {
    }

    public static function run(ParsedArgs $args): int
    {
        $action = $args->subcommandArgs[0] ?? '';
        if ($action !== '' && !\in_array($action, self::ACTIONS, true)) {
            return NonInteractive::failUsage(\sprintf('sugarcrush serve %s: unknown action', $action), $args->outputFormat, self::USAGE);
        }
        if (\count($args->subcommandArgs) > 1) {
            return NonInteractive::failUsage(
                \sprintf('sugarcrush serve %s %s: unexpected operand', $action, $args->subcommandArgs[1]),
                $args->outputFormat,
                self::USAGE,
            );
        }
        foreach (\array_keys($args->subcommandFlags) as $flag) {
            if (!\in_array($flag, self::ACTION_FLAGS[$action], true)) {
                return NonInteractive::failUsage(
                    \sprintf('sugarcrush serve%s: %s does not apply to %s', $action === '' ? '' : ' ' . $action, $flag, $action === '' ? 'starting a server' : 'this action'),
                    $args->outputFormat,
                    self::USAGE,
                );
            }
        }

        $env = self::environment();

        return match ($action) {
            '' => self::start($args, $env),
            'status' => self::status($args, $env),
            'stop' => self::stop($args, $env),
            'logs' => self::logs($args, $env),
            'url' => self::url($args, $env),
            'token' => self::token($args, $env),
        };
    }

    /**
     * The configuration `serve` would run with for $args, $env and the user
     * config — the whole resolution, without binding anything.
     *
     * @param array<string, string> $env
     * @param array<string, mixed>  $userConfig
     *
     * @throws ServerConfigException
     */
    public static function config(ParsedArgs $args, array $env, array $userConfig): ServerConfig
    {
        $config = ServerConfig::resolve(
            $args->subcommandFlags,
            $env,
            $userConfig,
            self::defaultStateDir(),
            $args->permissionMode,
            $args->root ?? (\getcwd() ?: null),
        );
        if ($config->stateDir === '') {
            throw new ServerConfigException('cannot determine a home directory this user owns for the server state; set SUGARCRUSH_SERVER_DIR');
        }
        // Read once, here: the guard answers to these and the sign-in URLs
        // name them, since `0.0.0.0` is not an address a browser can open.
        if ($config->isWildcard() && $config->allowRemote) {
            $config = $config->withInterfaceAddresses(InterfaceAddresses::detect());
        }

        return $config;
    }

    /**
     * The parent pid a launch watches: `--parent-pid`, else
     * `SUGARCRUSH_SERVER_PARENT_PID`, else none.
     *
     * @param array<string, string> $env
     *
     * @throws ServerConfigException when the value is not a pid
     */
    public static function parentPid(ParsedArgs $args, array $env): ?int
    {
        $flag = $args->subcommandFlags['--parent-pid'] ?? null;

        return ParentPidWatchdog::parse(\is_string($flag) ? $flag : ($env['SUGARCRUSH_SERVER_PARENT_PID'] ?? null));
    }

    // ── starting ─────────────────────────────────────────────────────────

    /** @param array<string, string> $env */
    private static function start(ParsedArgs $args, array $env): int
    {
        try {
            $config = self::config($args, $env, Bootstrap::readUserConfig());
            $parentPid = self::parentPid($args, $env);
        } catch (ServerConfigException $e) {
            return NonInteractive::failUsage('sugarcrush serve: ' . $e->getMessage(), $args->outputFormat);
        }
        $detach = isset($args->subcommandFlags['--detach']);

        $problems = Preflight::detect()->problems($config);
        if ($problems !== []) {
            return NonInteractive::failUsage(
                'sugarcrush serve: ' . $problems[0],
                $args->outputFormat,
                \count($problems) > 1 ? 'Also: ' . \implode('; ', \array_slice($problems, 1)) . '.' : null,
            );
        }

        // Every file the server creates (the token, the record, the lock, the
        // log) is this user's alone.
        \umask(0o077);

        try {
            $state = StateDir::open($config->stateDir);
            $tokens = TokenStore::new($state->path)->withOverride($env['SUGARCRUSH_SERVER_TOKEN'] ?? null);
            $tokens->token();
            $static = StaticFiles::new($config->webRoot ?? ($config->web ? self::installedWebRoot() : null));
            $watchdog = $parentPid === null ? null : ParentPidWatchdog::of($parentPid);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return NonInteractive::failUsage('sugarcrush serve: ' . $e->getMessage(), $args->outputFormat);
        }

        try {
            $locked = $state->acquireLock();
        } catch (\RuntimeException $e) {
            return self::failStart($args, $config, $e->getMessage());
        }
        if (!$locked) {
            $running = DiscoveryFile::read($state);

            return self::failStart(
                $args,
                $config,
                \sprintf(
                    'a server is already running from %s%s — stop it with: sugarcrush serve stop',
                    $state->path,
                    $running !== null && $running->isLive() ? \sprintf(' (pid %d, %s)', $running->pid, $running->url) : '',
                ),
            );
        }

        if (!$detach) {
            return self::serve($args, $config, $state, $tokens, $static, $watchdog, false, function (array $report) use ($args, $config, $static): void {
                if ($report['ok']) {
                    self::announce($args, $config, $report, $static, false);
                }
            });
        }

        return self::detachAndServe($args, $config, $state, $tokens, $static, $watchdog);
    }

    /**
     * `--detach`: daemonize, let the daemon bind and report over a socket
     * pair, and print what it reported. The original process returns as soon
     * as the daemon has either bound its port or failed to.
     */
    private static function detachAndServe(
        ParsedArgs $args,
        ServerConfig $config,
        StateDir $state,
        TokenStore $tokens,
        StaticFiles $static,
        ?ParentPidWatchdog $watchdog,
    ): int {
        $state->rotateLog();
        $pair = @\stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if ($pair === false) {
            $state->releaseLock();

            return self::failStart($args, $config, 'cannot create the channel the background server reports on');
        }
        [$parentEnd, $daemonEnd] = $pair;

        try {
            Daemonize::detach($state->logPath(), static function () use ($args, $config, $state, $tokens, $static, $watchdog, $parentEnd, $daemonEnd): int {
                \fclose($parentEnd);

                return self::serve($args, $config, $state, $tokens, $static, $watchdog, true, static function (array $report) use ($daemonEnd): void {
                    @\fwrite($daemonEnd, (string) \json_encode($report, \JSON_UNESCAPED_SLASHES) . "\n");
                    @\fclose($daemonEnd);
                });
            });
        } catch (\RuntimeException $e) {
            \fclose($parentEnd);
            \fclose($daemonEnd);
            $state->releaseLock();

            return self::failStart($args, $config, $e->getMessage());
        }

        // The daemon holds the lock now (the same open file description,
        // inherited); unlocking it here would unlock it for the daemon.
        $state->forgetLock();
        \fclose($daemonEnd);
        $report = self::readReport($parentEnd, self::DETACH_REPORT_SECONDS);
        \fclose($parentEnd);

        if ($report === null) {
            return self::failStart($args, $config, \sprintf(
                'the background server did not report within %d s; see %s',
                (int) self::DETACH_REPORT_SECONDS,
                $state->logPath(),
            ));
        }
        if (!$report['ok']) {
            return self::failStart($args, $config, (string) ($report['reason'] ?? 'the background server failed to start') . '; see ' . $state->logPath());
        }
        self::announce($args, $config, $report, $static, true);

        return NonInteractive::EXIT_OK;
    }

    /**
     * Bind, publish the discovery record and control socket, report, and run
     * the loop until a signal (or the parent-pid watchdog) stops it.
     *
     * @param \Closure(array<string, mixed>): void $report called exactly once,
     *        with `ok` and either the URLs or the `reason` it failed
     */
    private static function serve(
        ParsedArgs $args,
        ServerConfig $config,
        StateDir $state,
        TokenStore $tokens,
        StaticFiles $static,
        ?ParentPidWatchdog $watchdog,
        bool $detached,
        \Closure $report,
    ): int {
        $log = static function (string $line): void {
            self::stderr('[' . \date('H:i:s') . '] ' . $line);
        };
        $loop = Loop::get();

        // The protocol's sessions are built HERE, in the process that serves
        // them — after a --detach fork, never before it: the workspace holds
        // the session database and the forked turns' notice inbox, neither of
        // which may be shared with the process that returns to the shell.
        try {
            $protocol = self::protocol($config, $loop);
        } catch (\Throwable $e) {
            $state->releaseLock();
            $reason = 'cannot open the workspace: ' . $e->getMessage();
            self::stderr('sugarcrush serve: ' . $reason);
            $report(['ok' => false, 'reason' => $reason]);

            return NonInteractive::EXIT_FAILURE;
        }

        // The gateway (roadmap O-7) answers `workspace.*` and routes a request
        // naming another project root to that root's workspace host; every
        // other request reaches $protocol untouched.
        $gateway = Gateway::new($protocol, $config, Help::versionString(), $loop);
        $server = Server::new($config, AuthContext::new($tokens), $static, $gateway, $loop, $log);

        try {
            $server->start();
            $record = DiscoveryFile::forThisProcess(
                Help::versionString(),
                $server->url(),
                $config->host,
                (int) $server->port(),
                $config->root,
                $detached,
                $detached ? $state->logPath() : null,
            );
            $record->write($state);
        } catch (\RuntimeException $e) {
            $server->stop();
            $gateway->stop($e->getMessage());
            $protocol->stop();
            $protocol->context()->hub()->closeAll();
            $state->releaseLock();
            self::stderr('sugarcrush serve: ' . $e->getMessage());
            $report(['ok' => false, 'reason' => $e->getMessage()]);
            if (!$detached && $args->outputFormat === NonInteractive::FORMAT_JSON) {
                echo NonInteractive::encodeDocument(['result' => ['listening' => false, 'address' => $config->bindUri(), 'reason' => $e->getMessage()]]) . "\n";
            }

            return NonInteractive::EXIT_FAILURE;
        }

        $control = self::openControlSocket($state, $server, $tokens, $log);

        $loginUrls = $server->loginUrls();
        $report([
            'ok' => true,
            'url' => $server->url(),
            'loginUrl' => $loginUrls[0],
            'loginUrls' => $loginUrls,
            'pid' => \getmypid(),
            'log' => $detached ? $state->logPath() : null,
        ]);
        if ($detached) {
            // The log gets the address, never the sign-in code.
            $log(\sprintf('listening on %s (pid %d, root %s)', $server->url(), \getmypid(), $config->root ?? '(none)'));
        }

        $rotation = null;
        if ($detached) {
            $rotation = $loop->addPeriodicTimer(self::LOG_ROTATE_CHECK_SECONDS, static function () use ($state): void {
                if ($state->rotateLog()) {
                    Daemonize::redirectStdio($state->logPath());
                }
            });
        }

        $stopping = false;
        $handlers = [];
        $finish = static function (string $reason) use (&$stopping, &$handlers, $server, $protocol, $gateway, $state, $record, $control, $watchdog, $rotation, $loop): void {
            if ($stopping) {
                return;
            }
            $stopping = true;
            self::stderr('sugarcrush serve: stopping (' . $reason . ')');
            $watchdog?->disarm($loop);
            if ($rotation instanceof TimerInterface) {
                $loop->cancelTimer($rotation);
            }
            $control?->close();
            @\unlink($state->controlSocketPath());
            $protocol->stop($reason);
            $server->stop();
            $gateway->stop($reason);
            $protocol->context()->hub()->closeAll();
            $record->removeFrom($state);
            $state->releaseLock();
            foreach ($handlers as $signal => $handler) {
                Loop::removeSignal($signal, $handler);
            }
            Loop::stop();
        };

        // DRAIN FIRST (`server.drainSeconds`, Appendix O §4.6): admit nothing
        // new, tell the clients, and give the turns in flight their time to
        // settle; a second signal stops at once. What still runs when the
        // drain ends is cancelled by the hub's closeAll() above.
        $stop = static function (string $reason, ?float $drainSeconds = null) use ($finish, $protocol, $config): void {
            $drainSeconds ??= $config->drainSeconds;
            $protocol->drain($drainSeconds, static function (bool $settled) use ($finish, $reason): void {
                $finish($settled ? $reason : $reason . '; cancelling the turns still running');
            }, $reason);
            if ($protocol->isDraining()) {
                self::stderr(\sprintf(
                    'sugarcrush serve: draining (%s) — waiting up to %s s for %d running turn%s; signal again to stop now',
                    $reason,
                    \rtrim(\rtrim(\sprintf('%.1f', $drainSeconds), '0'), '.'),
                    $protocol->context()->turnsRunning(),
                    $protocol->context()->turnsRunning() === 1 ? '' : 's',
                ));
            }
        };

        // Never SIGCHLD: the turn children are reaped by EngineBackend's own
        // sweep, and a SIGCHLD handler would race it (Appendix O §4.6).
        foreach ([\SIGINT => 'SIGINT', \SIGTERM => 'SIGTERM'] as $signal => $name) {
            $handlers[$signal] = static function () use ($stop, $name): void {
                $stop($name);
            };
            Loop::addSignal($signal, $handlers[$signal]);
        }
        $watchdog?->arm($loop, static function (int $pid) use ($stop): void {
            $stop(\sprintf('parent process %d is gone', $pid));
        });
        $protocol->context()->onShutdown(static function (?float $drainSeconds) use ($stop, $loop): void {
            // Next tick: the client's answer goes out before the sockets close.
            $loop->futureTick(static fn () => $stop('server.shutdown was requested', $drainSeconds));
        });
        $protocol->start();
        $gateway->start();

        Loop::run();

        return NonInteractive::EXIT_OK;
    }

    /**
     * The `sugarcrush.v1` protocol over this server's workspace: the launch's
     * workspace for the served root ({@see Bootstrap::workspace()}, the same
     * bundle a TUI session is built on), a {@see SessionHub} capped at
     * `server.maxOpenSessions`, and the {@see Dispatcher} the WebSocket
     * transport hands its messages to.
     */
    private static function protocol(ServerConfig $config, LoopInterface $loop): Dispatcher
    {
        $workspace = Bootstrap::workspace($config->root);
        $hub = SessionHub::new($workspace, $config->maxOpenSessions, \SugarCraft\Crush\Context\CompactorConfig::fromSettings(Bootstrap::readUserConfig()));

        return Dispatcher::new(ServerContext::new($hub, $config, Help::versionString(), $loop));
    }

    /**
     * The local control socket `serve url` asks for a fresh sign-in URL on:
     * the login codes live in this process's memory, so a code minted anywhere
     * else would be one the server never heard of. Inside the 0700 state
     * directory, created under umask 077, and every request must still carry
     * the owner token. Registered with the fork registry by {@see Listener}
     * like the TCP listener. Null (logged) when it cannot be bound: the server
     * still serves, only `serve url` cannot reach it.
     *
     * @param \Closure(string): void $log
     */
    private static function openControlSocket(StateDir $state, Server $server, TokenStore $tokens, \Closure $log): ?Listener
    {
        $path = $state->controlSocketPath();
        // This process holds the singleton lock, so whatever is at the path
        // was left by a server that died without cleaning up.
        $stat = @\lstat($path);
        if ($stat !== false && ((int) $stat['mode'] & 0o170000) === 0o140000) {
            @\unlink($path);
        }

        try {
            $listener = Listener::bind('unix://' . $path, Loop::get());
        } catch (\RuntimeException $e) {
            $log('control socket unavailable (serve url will not reach this server): ' . $e->getMessage());

            return null;
        }

        $listener->on('connection', static function (ConnectionInterface $connection) use ($server, $tokens): void {
            $buffer = '';
            $connection->on('data', static function (string $chunk) use (&$buffer, $connection, $server, $tokens): void {
                $buffer .= $chunk;
                if (\strlen($buffer) > 4096) {
                    $connection->close();

                    return;
                }
                $newline = \strpos($buffer, "\n");
                if ($newline === false) {
                    return;
                }
                [$verb, $token] = \explode(' ', \substr($buffer, 0, $newline), 2) + [1 => ''];
                $reloaded = null;
                if ($verb === self::CONTROL_RELOAD_TOKEN) {
                    try {
                        $reloaded = $server->reloadToken();
                    } catch (\RuntimeException) {
                        $connection->end((string) \json_encode(['ok' => false, 'error' => 'the token file could not be read'], \JSON_UNESCAPED_SLASHES) . "\n");

                        return;
                    }
                }
                $answer = match (true) {
                    !$tokens->matches(\trim($token)) => ['ok' => false, 'error' => 'unauthorized'],
                    $verb === self::CONTROL_LOGIN_URL => (static fn (array $urls): array => ['ok' => true, 'loginUrl' => $urls[0], 'loginUrls' => $urls])($server->loginUrls()),
                    $verb === self::CONTROL_RELOAD_TOKEN => ['ok' => true, 'rotated' => $reloaded !== null, 'closed' => $reloaded ?? 0],
                    default => ['ok' => false, 'error' => 'unknown request'],
                };
                $connection->end((string) \json_encode($answer, \JSON_UNESCAPED_SLASHES) . "\n");
            });
        });

        return $listener;
    }

    // ── managing a running server ────────────────────────────────────────

    /** @param array<string, string> $env */
    private static function status(ParsedArgs $args, array $env): int
    {
        $found = self::locate($args, $env);
        if (\is_int($found)) {
            return $found;
        }
        [$state, $record] = $found;

        $live = $record !== null && $record->isLive();
        $locked = $state !== null && $state->lockHeldElsewhere();
        $health = $live ? (self::probeHealth($record->url) ? 'ok' : 'unreachable') : null;
        $running = $live || $locked;

        $result = [
            'running' => $running,
            'stateDir' => $state?->path ?? self::stateDirPath($env),
            'pid' => $live ? $record->pid : null,
            'url' => $live ? $record->url : null,
            'root' => $live ? $record->root : null,
            'version' => $live ? $record->version : null,
            'protocol' => $live ? $record->protocol : null,
            'startedAt' => $live ? $record->startedAt : null,
            'uptimeSeconds' => $live ? $record->uptime() : null,
            'detached' => $live ? $record->detached : null,
            'log' => $live ? $record->log : null,
            'health' => $health,
            'staleRecord' => $record !== null && !$live,
        ];

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            echo NonInteractive::encodeDocument(['result' => $result]) . "\n";
        } elseif ($live) {
            echo \sprintf("running: pid %d, %s (health: %s)\n", $record->pid, $record->url, $health);
            echo '  root:    ' . ($record->root ?? '(none)') . "\n";
            echo '  version: ' . $record->version . "\n";
            echo '  started: ' . \date('Y-m-d H:i:s', $record->startedAt) . ' (up ' . self::duration($record->uptime()) . ")\n";
            echo '  mode:    ' . ($record->detached ? 'background, log ' . $record->log : 'foreground') . "\n";
        } elseif ($locked) {
            echo 'running: a server holds the lock in ' . $state->path . " but has not published its record yet\n";
        } else {
            echo 'not running' . ($record !== null ? \sprintf(' (stale record for pid %d left in %s)', $record->pid, $state?->discoveryPath()) : '') . "\n";
        }

        return $running ? NonInteractive::EXIT_OK : NonInteractive::EXIT_FAILURE;
    }

    /** @param array<string, string> $env */
    private static function stop(ParsedArgs $args, array $env): int
    {
        $found = self::locate($args, $env);
        if (\is_int($found)) {
            return $found;
        }
        [$state, $record] = $found;

        if ($record === null || !$record->isLive()) {
            $reason = $state !== null && $state->lockHeldElsewhere()
                ? 'a server holds the lock in ' . $state->path . ' but published no record, so it cannot be identified to stop'
                : 'no server is running';
            if ($record !== null && $state !== null && !$state->lockHeldElsewhere()) {
                $record->removeFrom($state);
            }

            return self::answer($args, ['stopped' => false, 'reason' => $reason], 'sugarcrush serve stop: ' . $reason, NonInteractive::EXIT_FAILURE);
        }

        if (!\function_exists('posix_kill')) {
            return NonInteractive::failUsage('sugarcrush serve stop: ext-posix is missing, so the server cannot be signalled', $args->outputFormat);
        }

        // Literal numbers: SIGTERM and SIGKILL are 15 and 9 on every platform
        // `serve` runs on, and the constants exist only with ext-pcntl.
        $force = isset($args->subcommandFlags['--force']);
        $signal = $force ? 'KILL' : 'TERM';
        if (!$force) {
            @\posix_kill($record->pid, 15);
            if (!self::waitForExit($record, self::stopWaitSeconds($env))) {
                $signal = 'KILL';
            }
        }
        if ($signal === 'KILL' && $record->isLive()) {
            @\posix_kill($record->pid, 9);
        }
        if (!self::waitForExit($record, self::STOP_KILL_WAIT_SECONDS)) {
            return self::answer(
                $args,
                ['stopped' => false, 'pid' => $record->pid, 'reason' => 'the server did not exit'],
                \sprintf('sugarcrush serve stop: pid %d did not exit', $record->pid),
                NonInteractive::EXIT_FAILURE,
            );
        }

        // A drained server removed its own record; a killed one could not.
        if ($state !== null) {
            $record->removeFrom($state);
            if (!$state->lockHeldElsewhere()) {
                @\unlink($state->controlSocketPath());
            }
        }

        return self::answer(
            $args,
            ['stopped' => true, 'pid' => $record->pid, 'signal' => $signal],
            \sprintf('stopped the server (pid %d)%s', $record->pid, $signal === 'KILL' ? ' with SIGKILL' : ''),
            NonInteractive::EXIT_OK,
        );
    }

    /** @param array<string, string> $env */
    private static function logs(ParsedArgs $args, array $env): int
    {
        $follow = isset($args->subcommandFlags['--follow']) || isset($args->subcommandFlags['-f']);
        if ($follow && $args->outputFormat === NonInteractive::FORMAT_JSON) {
            return NonInteractive::failUsage('sugarcrush serve logs: -f streams text; it does not combine with --output-format json', $args->outputFormat);
        }

        $found = self::locate($args, $env);
        if (\is_int($found)) {
            return $found;
        }
        [$state, $record] = $found;

        $path = $state?->logPath() ?? self::stateDirPath($env) . '/' . StateDir::LOG_FILE;
        $stat = @\lstat($path);
        if ($stat === false || ((int) $stat['mode'] & 0o170000) !== 0o100000) {
            return self::answer(
                $args,
                ['path' => $path, 'lines' => null, 'reason' => 'no log'],
                'sugarcrush serve logs: no log at ' . $path . ' (only a detached server writes one)',
                NonInteractive::EXIT_FAILURE,
            );
        }

        [$lines, $offset] = self::tail($path, self::LOG_TAIL_LINES);
        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            echo NonInteractive::encodeDocument(['result' => ['path' => $path, 'lines' => $lines]]) . "\n";

            return NonInteractive::EXIT_OK;
        }
        foreach ($lines as $line) {
            echo $line . "\n";
        }
        if ($follow) {
            self::follow($path, $offset, $record);
        }

        return NonInteractive::EXIT_OK;
    }

    /** @param array<string, string> $env */
    private static function url(ParsedArgs $args, array $env): int
    {
        $found = self::locate($args, $env);
        if (\is_int($found)) {
            return $found;
        }
        [$state, $record] = $found;
        if ($state === null || $record === null || !$record->isLive()) {
            return self::answer($args, ['loginUrl' => null, 'reason' => 'no server is running'], 'sugarcrush serve url: no server is running', NonInteractive::EXIT_FAILURE);
        }

        $token = $env['SUGARCRUSH_SERVER_TOKEN'] ?? '';
        if (\trim($token) === '') {
            $token = self::storedToken($state);
        }
        $answer = $token === null ? null : self::controlRequest($state, self::CONTROL_LOGIN_URL . ' ' . \trim($token));
        if (!\is_array($answer) || ($answer['ok'] ?? false) !== true || !\is_string($answer['loginUrl'] ?? null)) {
            $why = \is_array($answer) && ($answer['error'] ?? null) === 'unauthorized'
                ? 'the server refused this token (was it started with a different SUGARCRUSH_SERVER_TOKEN?)'
                : 'the server did not answer on ' . $state->controlSocketPath();

            return self::answer($args, ['loginUrl' => null, 'reason' => $why], 'sugarcrush serve url: ' . $why, NonInteractive::EXIT_FAILURE);
        }

        // A server older than `loginUrls` answers with the one URL.
        $loginUrls = self::loginUrlsOf($answer);
        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            echo NonInteractive::encodeDocument(['result' => ['url' => $record->url, 'loginUrl' => $answer['loginUrl'], 'loginUrls' => $loginUrls, 'pid' => $record->pid]]) . "\n";
        } else {
            echo \implode("\n", $loginUrls) . "\n";
            self::stderr('(one-time code, valid ' . LoginCodes::TTL_SECONDS . ' s)');
        }

        return NonInteractive::EXIT_OK;
    }

    /** @param array<string, string> $env */
    private static function token(ParsedArgs $args, array $env): int
    {
        $rotate = isset($args->subcommandFlags['--rotate']);
        if (\trim($env['SUGARCRUSH_SERVER_TOKEN'] ?? '') !== '') {
            return NonInteractive::failUsage(
                'sugarcrush serve token: the server token comes from SUGARCRUSH_SERVER_TOKEN here; '
                    . ($rotate ? 'unset it to rotate the stored one' : 'it is not printed'),
                $args->outputFormat,
            );
        }

        \umask(0o077);
        try {
            $state = StateDir::open(self::stateDirPath($env));
            $store = TokenStore::new($state->path);
            $token = $rotate ? $store->rotate() : $store->token();
        } catch (\RuntimeException $e) {
            return NonInteractive::failUsage('sugarcrush serve token: ' . $e->getMessage(), $args->outputFormat);
        }

        $running = DiscoveryFile::read($state);
        $reloaded = null;
        if ($rotate && $running !== null && $running->isLive()) {
            // A running server reloads the file now and signs every client of
            // the old token out; one that cannot be reached keeps the old
            // token until it restarts, and says so.
            $answer = self::controlRequest($state, self::CONTROL_RELOAD_TOKEN . ' ' . $token);
            $reloaded = \is_array($answer) && ($answer['ok'] ?? false) === true;
            self::stderr($reloaded
                ? \sprintf(
                    'sugarcrush serve token: the running server (pid %d) now accepts only the new token; every client was signed out (%d connection%s closed)',
                    $running->pid,
                    (int) ($answer['closed'] ?? 0),
                    (int) ($answer['closed'] ?? 0) === 1 ? '' : 's',
                )
                : \sprintf(
                    'sugarcrush serve token: the running server (pid %d) could not be told and keeps accepting the old token until it restarts: sugarcrush serve stop && sugarcrush serve --detach',
                    $running->pid,
                ));
        }

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            echo NonInteractive::encodeDocument(['result' => \array_filter([
                'token' => $token,
                'rotated' => $rotate,
                'path' => $state->path . '/' . TokenStore::FILE,
                'serverReloaded' => $reloaded,
            ], static fn (mixed $value): bool => $value !== null)]) . "\n";
        } else {
            echo $token . "\n";
        }

        return NonInteractive::EXIT_OK;
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * The state directory (null when there is none yet) and its discovery
     * record, or the exit code of a refusal: an unsafe directory is exit 2,
     * like `serve` itself refuses one.
     *
     * @param array<string, string> $env
     *
     * @return array{0: StateDir|null, 1: DiscoveryFile|null}|int
     */
    private static function locate(ParsedArgs $args, array $env): array|int
    {
        $path = self::stateDirPath($env);
        if ($path === '') {
            return NonInteractive::failUsage('sugarcrush serve: cannot determine a home directory this user owns for the server state; set SUGARCRUSH_SERVER_DIR', $args->outputFormat);
        }

        try {
            $state = StateDir::existing($path);
        } catch (\RuntimeException $e) {
            return NonInteractive::failUsage('sugarcrush serve: ' . $e->getMessage(), $args->outputFormat);
        }

        return [$state, $state === null ? null : DiscoveryFile::read($state)];
    }

    /** @param array<string, string> $env */
    private static function stateDirPath(array $env): string
    {
        return ServerConfig::stateDirFrom($env, self::defaultStateDir());
    }

    private static function defaultStateDir(): string
    {
        $home = HomeDirectory::owned();

        return $home === null ? '' : $home . '/.sugar-crush/server';
    }

    /**
     * A failed start: stderr, the `listening: false` document under JSON,
     * exit 1 — it ran and may succeed later.
     */
    private static function failStart(ParsedArgs $args, ServerConfig $config, string $reason): int
    {
        self::stderr('sugarcrush serve: ' . $reason);
        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            echo NonInteractive::encodeDocument(['result' => ['listening' => false, 'address' => $config->bindUri(), 'reason' => $reason]]) . "\n";
        }

        return NonInteractive::EXIT_FAILURE;
    }

    /**
     * Print a management verb's answer: the document under JSON, else $text
     * (stdout on success, stderr on failure). Like `doctor`'s failing report,
     * a "no server" answer is a result, not a new `error.type`.
     *
     * @param array<string, mixed> $result
     */
    private static function answer(ParsedArgs $args, array $result, string $text, int $code): int
    {
        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            echo NonInteractive::encodeDocument(['result' => $result]) . "\n";
        } elseif ($code === NonInteractive::EXIT_OK) {
            echo $text . "\n";
        } else {
            self::stderr($text);
        }

        return $code;
    }

    /**
     * The daemon's one-line report, or null when it closed or stayed silent
     * for $seconds.
     *
     * @param resource $stream
     *
     * @return array<string, mixed>|null
     */
    private static function readReport($stream, float $seconds): ?array
    {
        \stream_set_blocking($stream, false);
        $buffer = '';
        $deadline = \microtime(true) + $seconds;
        while (!\str_contains($buffer, "\n")) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0) {
                return null;
            }
            $read = [$stream];
            $write = $except = null;
            $ready = @\stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1_000_000));
            if ($ready === false) {
                return null;
            }
            if ($ready === 0) {
                continue;
            }
            $chunk = @\fread($stream, 8192);
            if ($chunk === false || ($chunk === '' && \feof($stream))) {
                return null;
            }
            $buffer .= $chunk;
        }

        $report = \json_decode(\substr($buffer, 0, (int) \strpos($buffer, "\n")), true);

        return \is_array($report) && \is_bool($report['ok'] ?? null) ? $report : null;
    }

    /** Poll until $record's process is gone, up to $seconds; whether it went. */
    private static function waitForExit(DiscoveryFile $record, float $seconds): bool
    {
        $deadline = \microtime(true) + $seconds;
        while ($record->isLive()) {
            if (\microtime(true) >= $deadline) {
                return false;
            }
            \usleep(50_000);
        }

        return true;
    }

    /**
     * `GET /api/health` on $url, bounded to two seconds: the one route that
     * needs no credential, so `status` proves the server answers without
     * spending the token.
     */
    private static function probeHealth(string $url): bool
    {
        $parts = \parse_url($url);
        if (!\is_array($parts) || !isset($parts['host'], $parts['port'])) {
            return false;
        }
        $host = $parts['host'];
        $connectHost = \str_starts_with($host, '[') ? $host : (\str_contains($host, ':') ? '[' . $host . ']' : $host);
        $socket = @\stream_socket_client('tcp://' . $connectHost . ':' . $parts['port'], $errno, $errstr, 2.0);
        if ($socket === false) {
            return false;
        }
        \stream_set_timeout($socket, 2);
        \fwrite($socket, "GET /api/health HTTP/1.1\r\nHost: " . $connectHost . ':' . $parts['port'] . "\r\nConnection: close\r\n\r\n");
        $response = (string) @\stream_get_contents($socket, 65536);
        \fclose($socket);

        return \preg_match('#^HTTP/1\.[01] 200 #', $response) === 1 && \str_contains($response, '"ok":true');
    }

    /**
     * One request line on the running server's control socket, and its JSON
     * answer, bounded to five seconds; null when it does not answer.
     *
     * @return array<string, mixed>|null
     */
    private static function controlRequest(StateDir $state, string $line): ?array
    {
        $socket = @\stream_socket_client('unix://' . $state->controlSocketPath(), $errno, $errstr, 5.0);
        if ($socket === false) {
            return null;
        }
        \stream_set_timeout($socket, 5);
        \fwrite($socket, $line . "\n");
        $answer = (string) @\stream_get_contents($socket, 65536);
        \fclose($socket);
        $decoded = \json_decode(\trim($answer), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * How long `stop` waits for a drained exit: {@see STOP_DRAIN_SECONDS}, or
     * the configured `server.drainSeconds` plus a margin when that is longer,
     * so `stop` never SIGKILLs a server still inside the drain it was told to
     * allow.
     *
     * @param array<string, string> $env
     */
    private static function stopWaitSeconds(array $env): float
    {
        try {
            $drain = ServerConfig::resolve([], $env, Bootstrap::readUserConfig(), '')->drainSeconds;
        } catch (\Throwable) {
            return self::STOP_DRAIN_SECONDS;
        }

        return \max(self::STOP_DRAIN_SECONDS, $drain + self::STOP_DRAIN_MARGIN_SECONDS);
    }

    /** The token file's token, read without minting one; null when absent. */
    private static function storedToken(StateDir $state): ?string
    {
        $path = $state->path . '/' . TokenStore::FILE;
        $stat = @\lstat($path);
        if ($stat === false || ((int) $stat['mode'] & 0o170000) !== 0o100000) {
            return null;
        }
        $token = \trim((string) @\file_get_contents($path));

        return \preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? $token : null;
    }

    /**
     * The last $count lines of $path and the byte offset reading stopped at.
     *
     * @return array{0: list<string>, 1: int}
     */
    private static function tail(string $path, int $count): array
    {
        $handle = @\fopen($path, 'r');
        if ($handle === false) {
            return [[], 0];
        }
        $size = (int) (\fstat($handle)['size'] ?? 0);
        $window = 256 * 1024;
        $from = \max(0, $size - $window);
        \fseek($handle, $from);
        $text = (string) \stream_get_contents($handle, $size - $from);
        \fclose($handle);

        $lines = \explode("\n", \rtrim($text, "\n"));
        if ($from > 0) {
            \array_shift($lines);
        }
        if ($lines === ['']) {
            $lines = [];
        }

        return [\array_slice($lines, -$count), $size];
    }

    /**
     * `logs -f`: print what is appended to $path from $offset on, across a
     * rotation (the file shrinks or is replaced), until the server named by
     * $record is gone — or forever if there was none to watch (Ctrl+C ends it).
     */
    private static function follow(string $path, int $offset, ?DiscoveryFile $record): void
    {
        $inode = @\fileinode($path);
        $watch = $record !== null && $record->isLive() ? $record : null;
        while (true) {
            \clearstatcache(true, $path);
            $stat = @\stat($path);
            if ($stat !== false && ($stat['ino'] !== $inode || $stat['size'] < $offset)) {
                $inode = $stat['ino'];
                $offset = 0;
            }
            if ($stat !== false && $stat['size'] > $offset) {
                $handle = @\fopen($path, 'r');
                if ($handle !== false) {
                    \fseek($handle, $offset);
                    $chunk = (string) \stream_get_contents($handle);
                    \fclose($handle);
                    $offset += \strlen($chunk);
                    echo $chunk;
                }

                continue;
            }
            if ($watch !== null && !$watch->isLive()) {
                return;
            }
            \usleep(250_000);
        }
    }

    private static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => $seconds . ' s',
            $seconds < 7200 => \intdiv($seconds, 60) . ' min',
            $seconds < 172800 => \intdiv($seconds, 3600) . ' h',
            default => \intdiv($seconds, 86400) . ' d',
        };
    }

    /**
     * The variables `serve` consults, read by name rather than taken from a
     * whole-environment dump, so the set this command depends on is the list
     * below and nothing an unrelated variable could widen.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $env = [];
        foreach ([
            'SUGARCRUSH_SERVER_HOST',
            'SUGARCRUSH_SERVER_PORT',
            'SUGARCRUSH_SERVER_ALLOWED_ORIGINS',
            'SUGARCRUSH_SERVER_ALLOWED_HOSTS',
            'SUGARCRUSH_SERVER_WEB_ROOT',
            'SUGARCRUSH_SERVER_TOKEN',
            'SUGARCRUSH_SERVER_DIR',
            'SUGARCRUSH_SERVER_PARENT_PID',
            'SUGARCRUSH_PERMISSION_MODE',
        ] as $name) {
            $value = \getenv($name);
            if (\is_string($value)) {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * The installed `sugarcraft/sugar-crush-web` build, or null when the
     * package is absent. Looked up by name so this package carries no hard
     * dependency on it (it is a composer `suggest`).
     */
    private static function installedWebRoot(): ?string
    {
        $assets = 'SugarCraft\\CrushWeb\\Assets';
        if (!\class_exists($assets) || !\method_exists($assets, 'distPath')) {
            return null;
        }

        $path = $assets::distPath();

        return \is_string($path) && \is_dir($path) ? $path : null;
    }

    /**
     * The startup lines (stderr) and, under JSON, the one stdout document —
     * from the foreground server itself, or from the process that detached
     * one, reporting what the daemon told it.
     *
     * @param array<string, mixed> $report
     */
    private static function announce(ParsedArgs $args, ServerConfig $config, array $report, StaticFiles $static, bool $detached): void
    {
        $url = (string) $report['url'];
        $loginUrl = (string) $report['loginUrl'];
        $loginUrls = self::loginUrlsOf($report);
        $pid = (int) $report['pid'];

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            echo NonInteractive::encodeDocument(['result' => [
                'url' => $url,
                'loginUrl' => $loginUrl,
                'loginUrls' => $loginUrls,
                'pid' => $pid,
                'protocol' => ServerConfig::PROTOCOL_MAJOR,
                'root' => $config->root,
                'permissionMode' => $config->permissionMode->value,
                'web' => $static->root() !== null,
                'detached' => $detached,
                'log' => $report['log'] ?? null,
            ]]) . "\n";
        }

        $lines = [
            $detached
                ? 'sugarcrush serve: running in the background on ' . $url . ' (pid ' . $pid . ')'
                : 'sugarcrush serve: listening on ' . $url . ' (pid ' . $pid . ')',
            '  root:            ' . ($config->root ?? '(none)'),
            '  permission mode: ' . $config->permissionMode->value . ($config->allowBypass ? ' (clients may choose bypass)' : ''),
            '  web UI:          ' . ($static->root() ?? 'not installed (composer require sugarcraft/sugar-crush-web)'),
            '  sign in:         ' . \implode("\n                   ", $loginUrls),
            $detached
                ? '                   (one-time code, valid ' . LoginCodes::TTL_SECONDS . ' s; a fresh one: sugarcrush serve url)'
                : '                   (one-time code, valid ' . LoginCodes::TTL_SECONDS . ' s; Ctrl+C stops the server)',
        ];
        if ($detached) {
            $lines[] = '  log:             ' . (string) ($report['log'] ?? '');
            $lines[] = '  stop:            sugarcrush serve stop';
        }
        if ($config->isWildcard() && $config->interfaceAddresses === []) {
            $lines[] = '                   (no interface address of this machine was found: use the one other machines reach it at)';
        }
        if (!$config->isLoopback()) {
            $lines[] = '';
            $lines[] = '  !! Plain HTTP on ' . $config->host . ': the token, sign-in code and session cookie cross the network in cleartext — use an SSH tunnel or a TLS reverse proxy (docs/SERVER.md, "Remote access").';
        }
        self::stderr(\implode("\n", $lines));
    }

    /**
     * The sign-in URLs a report or a `serve url` answer carries; one, the
     * `loginUrl`, when it predates `loginUrls`.
     *
     * @param array<string, mixed> $answer
     *
     * @return list<string>
     */
    private static function loginUrlsOf(array $answer): array
    {
        $urls = \is_array($answer['loginUrls'] ?? null) ? \array_values(\array_filter($answer['loginUrls'], 'is_string')) : [];

        return $urls !== [] ? $urls : [(string) ($answer['loginUrl'] ?? '')];
    }

    /**
     * The server's one stderr channel: startup lines, the request log and the
     * stop notice. Stderr alone, never the transcript seam — there is no
     * session to report into. A detached server's stderr IS its log file.
     */
    private static function stderr(string $text): void
    {
        \fwrite(\STDERR, $text . "\n");
    }
}

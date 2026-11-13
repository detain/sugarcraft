<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli\Serve;

use SugarCraft\Code\Channels\ChannelInboundLog;
use SugarCraft\Code\Channels\ChannelRegistry;
use SugarCraft\Code\Channels\ChannelsStore;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\Help;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\ParsedArgs;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;
use SugarCraft\Code\Host\Commands\BackgroundCommand;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\Capability\MediaAffordance;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ServerContext;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\LoginCodes;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Auth\ViewerTokens;
use SugarCraft\Code\Server\DiscoveryFile;
use SugarCraft\Code\Server\Http\CanvasLinks;
use SugarCraft\Code\Server\Http\CanvasPageController;
use SugarCraft\Code\Server\Http\HookWebhooks;
use SugarCraft\Code\Server\Http\SharePageController;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\InterfaceAddresses;
use SugarCraft\Code\Server\Listener;
use SugarCraft\Code\Server\LogTail;
use SugarCraft\Code\Server\Pairing\QrEncoder;
use SugarCraft\Code\Server\Pairing\QrTerminal;
use SugarCraft\Code\Server\ParentPidWatchdog;
use SugarCraft\Code\Server\Preflight;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\ServerConfigException;
use SugarCraft\Code\Server\StateDir;
use SugarCraft\Code\Share\ShareSession;
use SugarCraft\Code\Share\ShareStore;
use SugarCraft\Code\Server\Workspace\Gateway;
use SugarCraft\Code\Support\Daemonize;
use SugarCraft\Code\Support\Directories\DirectoryBrowser;
use SugarCraft\Code\Support\Directories\DirectoryBrowserException;
use SugarCraft\Code\Server\ErrorLog;
use SugarCraft\Code\Support\HomeDirectory;

/**
 * Part of the CWPD-14 split of Serve.php (lane G4-H, OD-30 guard-first).
 *
 * The methods below were MOVED byte-verbatim into a composition trait: Serve/
 * SessionHost compile them in, so every self:: call, private-const read and
 * property touch resolves exactly where it did before the split. Behavior is
 * byte-identical; the structural census in tests/Host/SessionHostSurfaceCensusTest
 * pins that. The docblocks carried below are prose history notes, kept verbatim.
 */
trait ServeStartup
{

    // ── starting ─────────────────────────────────────────────────────────

    /** @param array<string, string> $env */
    private static function start(ParsedArgs $args, array $env): int
    {
        try {
            $config = self::config($args, $env, Bootstrap::readUserConfig());
            $parentPid = self::parentPid($args, $env);
        } catch (ServerConfigException $e) {
            return NonInteractive::failUsage('candy-code serve: ' . $e->getMessage(), $args->outputFormat);
        }
        $detach = isset($args->subcommandFlags['--detach']);

        $problems = Preflight::detect()->problems($config);
        if ($problems !== []) {
            return NonInteractive::failUsage(
                'candy-code serve: ' . $problems[0],
                $args->outputFormat,
                \count($problems) > 1 ? Lang::t('cli.serve.also', ['problems' => \implode('; ', \array_slice($problems, 1))]) : null,
            );
        }

        // Every file the server creates (the token, the record, the lock, the
        // log) is this user's alone.
        \umask(0o077);

        try {
            $state = StateDir::open($config->stateDir);
            $tokens = TokenStore::new($state->path)->withOverride($env['CANDY_CODE_SERVER_TOKEN'] ?? null);
            $tokens->token();
            $static = StaticFiles::new($config->webRoot ?? ($config->web ? self::installedWebRoot() : null));
            $watchdog = $parentPid === null ? null : ParentPidWatchdog::of($parentPid);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return NonInteractive::failUsage('candy-code serve: ' . $e->getMessage(), $args->outputFormat);
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
                $running !== null && $running->isLive()
                    ? Lang::t('cli.serve.already_running_pid', ['dir' => $state->path, 'pid' => $running->pid, 'url' => $running->url])
                    : Lang::t('cli.serve.already_running', ['dir' => $state->path]),
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

            return self::failStart($args, $config, Lang::t('cli.serve.no_report_channel'));
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
            return self::failStart($args, $config, Lang::t('cli.serve.no_report', [
                'seconds' => (int) self::DETACH_REPORT_SECONDS,
                'log' => $state->logPath(),
            ]));
        }
        if (!$report['ok']) {
            return self::failStart($args, $config, Lang::t('cli.serve.reason_see_log', [
                'reason' => (string) ($report['reason'] ?? Lang::t('cli.serve.failed_to_start')),
                'log' => $state->logPath(),
            ]));
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

        // One AuthContext (and with it one ViewerTokens instance) serves both
        // the HTTP auth path below and the token.* protocol handlers — the
        // dispatcher threads it onto its workspace as a service, so a revoke
        // made over the wire and a rotation made at the cookie layer act on
        // the same rows. The construction is hoisted here because the
        // workspace is built before the server; AuthContext::new is a pure
        // in-memory mint by the store's own law (nothing touches disk at
        // construction), so it cannot throw and needs no guard here.
        $auth = AuthContext::new($tokens);

        // The protocol's sessions are built HERE, in the process that serves
        // them — after a --detach fork, never before it: the workspace holds
        // the session database and the forked turns' notice inbox, neither of
        // which may be shared with the process that returns to the shell.
        try {
            $protocol = self::protocol($config, $loop, $log, $auth);
        } catch (\Throwable $e) {
            $state->releaseLock();
            $reason = Lang::t('cli.serve.workspace_unavailable', ['error' => $e->getMessage()]);
            self::stderr('candy-code serve: ' . $reason);
            $report(['ok' => false, 'reason' => $reason]);

            return NonInteractive::EXIT_FAILURE;
        }

        // The gateway (roadmap O-7) answers `workspace.*` and routes a request
        // naming another project root to that root's workspace host; every
        // other request reaches $protocol untouched.
        $gateway = Gateway::new($protocol, $config, Help::versionString(), $loop);
        // SES-3 (COMPARISON §6.5): share pages are read from the same session
        // database the workspace writes, built HERE like the workspace itself
        // (after any detach fork — a connection may not ride into the child
        // that returns to the shell). The lookup is fail-safe: a database
        // without the shares table answers 404, never an error page.
        // C-SES-3.d: the renderer carries LIVE pages (`/share serve --live`).
        // It re-reads the CURRENT transcript through the exact serializer a
        // snapshot went through — ShareSession's row filter and SecretRedactor
        // included. OD-10 (D-19) put a size bound on what that re-render may
        // cost: the newest SharePageController::MAX_SHARE_ROWS rows survive
        // into the serialize, a fixed notice row says how many did not, and
        // the byte budget caps the finished page — all before the reply
        // returns, so the render memo (D-20) only ever holds post-budget
        // bytes. The reply carries the session's event lastSeq as its
        // revision: a new transcript row bumps it, the memo key misses, the
        // page re-renders. Every other failure
        // (session deleted, store unreadable, a row nothing can revive)
        // answers null, which the controller returns as the shared 404; the
        // page can never outlive its session nor leak more than a snapshot
        // could, and `Cache-Control: no-store` (the controller's own law,
        // D-22) keeps proxies from freezing what promises to track.
        $sessionStore = Bootstrap::sessionStore(prune: false);
        $share = new SharePageController(
            ShareStore::atPath($sessionStore->path()),
            static function (string $sessionId) use ($sessionStore): ?array {
                try {
                    $rows = $sessionStore->loadTranscript($sessionId);
                    if ($rows === null) {
                        return null;
                    }
                    $revision = $sessionStore->sessionEventBounds($sessionId)[1];
                    $dropped = 0;
                    $rows = SharePageController::budgetRows($rows, $dropped);
                    $messages = array_map(
                        static fn(array $row): \SugarCraft\Code\Message => \SugarCraft\Code\Host\TranscriptStore::reviveRow($row),
                        $rows,
                    );

                    $html = (new ShareSession($messages, ShareSession::FORMAT_HTML))->serialize();

                    return [
                        'html' => SharePageController::capBytes(
                            $dropped > 0 ? $html . SharePageController::truncationNotice($dropped) : $html,
                        ),
                        'revision' => $revision,
                    ];
                } catch (\Throwable) {
                    return null;
                }
            },
            // D-20 probe: lastSeq as a single indexed MAX() read — this is
            // what lets the memo answer without the full re-serialize above.
            // A null (unreadable store) simply forces the honest render path.
            static function (string $sessionId) use ($sessionStore): ?int {
                try {
                    return $sessionStore->sessionEventBounds($sessionId)[1];
                } catch (\Throwable) {
                    return null;
                }
            },
        );
        // C-TOOL-6.g: sandboxed canvas pages. Built after the detach fork,
        // like $share — the minted-link table is live server state and must
        // not ride into the child. The controller answers both doors: the
        // authenticated `POST /api/canvas` mint and the public single-use
        // `/canvas/{token}` page; the mint re-checks the artifact against the
        // media store, the page re-resolves it again at delivery, so a sweep
        // between the two retires the link with the artifact.
        $canvas = new CanvasPageController(CanvasLinks::new());
        // AUT-6: inbound webhook delivery, mounted ONLY when the operator's
        // user config names hooks (they were validated at launch inside
        // ServerConfig::resolve — one bad hook refused the boot, never a
        // half-mounted route). The run closure is the same door `bg.spawn`
        // walks: the server context's background supervisor, tracked, and the
        // summary answered to the caller — caps, draining and the event feed
        // stay one truth. A throw here is logged at its cause (ISS-057) and
        // answered as the handler's generic 503.
        $hooks = $config->webhooks === [] ? null : HookWebhooks::new(
            $config->webhooks,
            static function (string $name, array $hook, string $prompt) use ($protocol, $config): array {
                $server = $protocol->context();
                if ($server->isDraining()) {
                    throw new \RuntimeException('the server is shutting down');
                }
                $events = $server->background();
                if ($events === null) {
                    throw new \RuntimeException('this server has no background supervisor');
                }
                $manager = $server->hub()->workspace()->agentManager;
                $agent = $manager?->get('default') ?? ($manager?->all()[0] ?? null) ?? BackgroundCommand::defaultAgent();
                // The hook's own configured mode wins (launch-validated, and
                // bypass-class was refused there); unmodeled hooks run in the
                // server's mode, clamped to the Default family (AUT-6 MINOR-1)
                // so a bypass-class server cannot hand an inbound HTTP request
                // the unattended bypass the per-hook refusal denies it.
                $mode = $hook['permissionMode'];
                $modeValue = $mode?->value ?? $config->hookPermissionModeFallback();
                $root = $server->hub()->workspace()->root;
                try {
                    $session = $events->supervisor()->spawnSession(
                        name: BackgroundCommand::sessionName('hook ' . $name),
                        agent: $agent,
                        task: $prompt,
                        workingDirectory: $root !== null && $root !== '' ? $root : '.',
                        permissionMode: $modeValue,
                        permissionAskTimeout: $config->askTimeoutSeconds,
                    );
                } catch (\Throwable $e) {
                    $server->logError($e, 'webhook ' . $name . ' failed');

                    throw $e;
                }
                $events->track($session);

                return $events->summary($session);
            },
        );
        // code-chat P1 step 4: plugin channels boot before the port is bound.
        // Trust comes from the same user-tier trustedBundleChannels list the
        // launch doors use (ruling 2c: nothing loads untrusted, and a channel
        // that collides on a name is refused there, never shadowing a
        // built-in or another accepted bundle). Webhook-kind channels mount
        // their own routes under /channels/ - never /api/ - and verify their
        // own signatures per the HookWebhooks doctrine; the webhooksOut
        // envelope is untouched (ruling 5b). Field fact from the bridge lane:
        // trust prompts ride the ephemeral permission.asked kind "trust", so
        // a channel surface must never assume permission.requested; and the
        // RESET subscribe shape must never be filtered by a seq cursor.
        $channelRegistry = $protocol->context()->hub()->workspace()->service(ChannelRegistry::class);
        $channels = null;
        if ($channelRegistry instanceof ChannelRegistry) {
            try {
                self::reportChannelBuckets($channelRegistry->boot(
                    Bootstrap::bundles($config->root),
                    Bootstrap::trustedBundleChannelNames(),
                    ChannelsStore::new(),
                    $config->root,
                    $loop,
                    $log,
                    ChannelInboundLog::new(),
                ));
            } catch (\Throwable $e) {
                // A broken channel never takes the serve down with it.
                $log(ErrorLog::line($e, 'channel boot'));
                self::stderr('candy-code serve: ' . $e->getMessage());
            }
            $channels = $channelRegistry->httpRouter();
        }
        $server = Server::new($config, $auth, $static, $gateway, $loop, $log, null, null, $share(...), $canvas(...), $hooks, $channels);
        // S-V02: a revoked or rotated credential's remembered answers go with it.
        $server->onCredentialsRevoked(static function (?string $tokenId) use ($protocol): void {
            $protocol->forgetCredential($tokenId);
        });

        try {
            $server->start();
            // Backstop until the loop owns the signals (see ServeResidueGuard).
            ServeResidueGuard::arm(static function () use ($state): void {
                @\unlink($state->discoveryPath());
                @\unlink($state->controlSocketPath());
                $state->releaseLock();
            });
            $record = DiscoveryFile::forThisProcess(
                Help::versionString(),
                $server->url(),
                $config->host,
                (int) $server->port(),
                $config->root,
                $detached,
                $detached ? $state->logPath() : null,
                // W6.7: the same boot-time flags the hello carries, so an
                // attach-style client reading server.json sees one truth.
                media: MediaAffordance::current(),
            );
            $record->write($state);
        } catch (\RuntimeException $e) {
            ServeResidueGuard::disarm();
            $server->stop();
            $gateway->stop($e->getMessage());
            $protocol->stop();
            if ($channelRegistry instanceof ChannelRegistry) {
                $channelRegistry->disposeAll($log);
            }
            $protocol->context()->hub()->closeAll();
            $state->releaseLock();
            self::stderr('candy-code serve: ' . $e->getMessage());
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
        $finish = static function (string $reason) use (&$stopping, &$handlers, $server, $protocol, $gateway, $state, $record, $control, $watchdog, $rotation, $loop, $channelRegistry, $log): void {
            if ($stopping) {
                return;
            }
            $stopping = true;
            self::stderr(Lang::t('cli.serve.stopping', ['reason' => $reason]));
            $watchdog?->disarm($loop);
            if ($rotation instanceof TimerInterface) {
                $loop->cancelTimer($rotation);
            }
            $control?->close();
            @\unlink($state->controlSocketPath());
            $protocol->stop($reason);
            $server->stop();
            if ($channelRegistry instanceof ChannelRegistry) {
                $channelRegistry->disposeAll($log);
            }
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
                $running = $protocol->context()->turnsRunning();
                $params = [
                    'reason' => $reason,
                    'seconds' => \rtrim(\rtrim(\sprintf('%.1f', $drainSeconds), '0'), '.'),
                    'count' => $running,
                ];
                self::stderr($running === 1
                    ? Lang::t('cli.serve.draining.one', $params)
                    : Lang::t('cli.serve.draining.many', $params));
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
        ServeResidueGuard::disarm();
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
     * code-chat P1 step 4: the launch notices rendering the channel buckets -
     * loaded/quarantined lists and refusals through the lang keys, plus one
     * line per named collision. Ruling 2c keeps the refusal localised: a
     * plugin channel never shadows a built-in or another accepted bundle.
     *
     * @param array{loaded: list<array<array-key, mixed>>, quarantined: list<array<array-key, mixed>>, refusals: list<array<array-key, mixed>>} $buckets
     */
    private static function reportChannelBuckets(array $buckets): void
    {
        /** @param list<array<array-key, mixed>> $rows */
        $names = static fn (array $rows): string => \implode(', ', \array_map(static fn (array $row): string => (string) ($row['class'] ?? '?'), $rows));
        if ($buckets['loaded'] !== []) {
            self::stderr(Lang::t('bundles.channels.loaded', [
                'names' => $names($buckets['loaded']),
                'count' => \count($buckets['loaded']),
            ]));
        }
        if ($buckets['quarantined'] !== []) {
            self::stderr(Lang::t('bundles.channels.quarantined', [
                'names' => $names($buckets['quarantined']),
                'count' => \count($buckets['quarantined']),
            ]));
        }
        $refusals = $buckets['refusals'];
        foreach ($refusals as $refusal) {
            if (($refusal['reason'] ?? '') === 'collision') {
                self::stderr(Lang::t('bundles.channels.collision', [
                    'name' => (string) ($refusal['name'] ?? $refusal['class'] ?? '?'),
                    'bundle' => (string) ($refusal['bundle'] ?? '?'),
                ]));
            }
        }
        if ($refusals !== []) {
            self::stderr(Lang::t('bundles.channels.refused', [
                'names' => $names($refusals),
                'count' => \count($refusals),
            ]));
        }
    }

    /**
     * The `candy-code.v1` protocol over this server's workspace: the launch's
     * workspace for the served root ({@see Bootstrap::workspace()}, the same
     * bundle a TUI session is built on), a {@see SessionHub} capped at
     * `server.maxOpenSessions`, and the {@see Dispatcher} the WebSocket
     * transport hands its messages to.
     */
    private static function protocol(ServerConfig $config, LoopInterface $loop, ?\Closure $log = null, ?AuthContext $auth = null): Dispatcher
    {
        $workspace = Bootstrap::workspace($config->root);
        // G4-G addendum: the token.* handlers reach the store through the
        // workspace locator (ServerContext carries no auth accessor). Hosts
        // without an AuthContext answer tokens_unavailable, never a half-
        // governed grant surface.
        if ($auth !== null) {
            $workspace = $workspace->withService(ViewerTokens::class, $auth->viewerTokens);
        }
        $hub = SessionHub::new($workspace, $config->maxOpenSessions, \SugarCraft\Code\Context\CompactorConfig::fromSettings(Bootstrap::readUserConfig()));

        // ISS-057: the dispatcher's -32099 arms hide their cause from the
        // client; the context's log sink is where that cause lands instead.
        return Dispatcher::new(ServerContext::new($hub, $config, Help::versionString(), $loop, null, $log));
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
                // Authenticate BEFORE any verb runs: a refused peer must cause
                // zero side effects (reload-token rotates every credential).
                if (!$tokens->matches(\trim($token))) {
                    $connection->end((string) \json_encode(['ok' => false, 'error' => 'unauthorized'], \JSON_UNESCAPED_SLASHES) . "\n");

                    return;
                }
                if ($verb === self::CONTROL_RELOAD_TOKEN) {
                    try {
                        $reloaded = $server->reloadToken();
                    } catch (\RuntimeException) {
                        // Two causes reach this guard: the token file itself
                        // could not be read (the shape the answer names), or —
                        // since WEB-6's fail-closed revocation — the rotation's
                        // revokeAll could not land its revocation on disk nor
                        // discard the durable copy. Either way the client is
                        // told it may not trust the reload; the answer text
                        // stays single-purpose bytes.
                        $connection->end((string) \json_encode(['ok' => false, 'error' => 'the token file could not be read'], \JSON_UNESCAPED_SLASHES) . "\n");

                        return;
                    }
                    $answer = ['ok' => true, 'rotated' => $reloaded !== null, 'closed' => $reloaded ?? 0];
                } elseif ($verb === self::CONTROL_LOGIN_URL) {
                    $urls = $server->loginUrls();
                    $answer = ['ok' => true, 'loginUrl' => $urls[0], 'loginUrls' => $urls];
                } else {
                    $answer = ['ok' => false, 'error' => 'unknown request'];
                }
                $connection->end((string) \json_encode($answer, \JSON_UNESCAPED_SLASHES) . "\n");
            });
        });

        return $listener;
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

        $mode = ['mode' => $config->permissionMode->value];
        $ttl = ['seconds' => LoginCodes::TTL_SECONDS];
        $lines = [
            $detached
                ? Lang::t('cli.serve.announce.background', ['url' => $url, 'pid' => $pid])
                : Lang::t('cli.serve.announce.listening', ['url' => $url, 'pid' => $pid]),
            Lang::t('cli.serve.announce.root', ['root' => $config->root ?? Lang::t('cli.serve.none')]),
            $config->allowBypass
                ? Lang::t('cli.serve.announce.mode_bypass', $mode)
                : Lang::t('cli.serve.announce.mode', $mode),
            $static->root() !== null
                ? Lang::t('cli.serve.announce.web', ['dir' => $static->root()])
                : Lang::t('cli.serve.announce.web_missing'),
            ...($config->allowedIps === [] ? [] : [Lang::t('serve.announce.allowed_ips', ['ips' => \implode(', ', $config->allowedIps)])]),
            ...($config->dirBrowse ? [Lang::t('serve.announce.dir_browse', ['root' => (string) $config->browseRoot])] : []),
            Lang::t('cli.serve.announce.sign_in', ['url' => \implode("\n                   ", $loginUrls)]),
            $detached
                ? Lang::t('cli.serve.announce.code_detached', $ttl)
                : Lang::t('cli.serve.announce.code_foreground', $ttl),
        ];
        if ($detached) {
            $lines[] = Lang::t('cli.serve.announce.log', ['log' => (string) ($report['log'] ?? '')]);
            $lines[] = Lang::t('cli.serve.announce.stop');
        }
        if ($config->isWildcard() && $config->interfaceAddresses === []) {
            $lines[] = Lang::t('cli.serve.announce.no_interface');
        }
        // D-16: the remote-bind warning is about reachability, not only
        // cleartext. A TLS listener still hands every credential that
        // survives a leaked page to whoever can route to the port, so the
        // block fires on ANY non-loopback bind and the wording branches on
        // TLS; an empty --allowed-ips roster says so out loud. Credentials
        // stay mandatory on every bind either way (docs/SERVER.md, "Remote
        // access").
        if (!$config->isLoopback()) {
            $lines[] = '';
            $lines[] = $config->hasTls()
                ? Lang::t('cli.serve.announce.remote_tls', ['host' => $config->host])
                : Lang::t('cli.serve.announce.remote', ['host' => $config->host]);
            if ($config->allowedIps === []) {
                $lines[] = Lang::t('cli.serve.announce.remote_unpinned');
            }
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
     * The installed `sugarcraft/candy-code-web` build, or null when the
     * package is absent or its bundle is not built. Looked up by name so this
     * package carries no hard dependency on it (it is a composer `suggest`).
     *
     * CW-P2: this asks the SOFT probe `tryDistPath()` — which answers null for
     * a half-installed bundle (composer registered the package, `dist/` was
     * never built) — rather than the throwing `distPath()`. A missing bundle
     * is the designed "serve the API without a UI" state, not a startup crash:
     * null here routes through `StaticFiles::new(null)` to the
     * `cli.serve.announce.web_missing` notice and API-over-WebSocket-only
     * mode. `distPath()` would raise a `RuntimeException` the caller's catch
     * turns into a hard `failUsage`, never reaching that graceful branch.
     */
    private static function installedWebRoot(): ?string
    {
        $assets = 'SugarCraft\\CodeWeb\\Assets';
        if (!\class_exists($assets) || !\method_exists($assets, 'tryDistPath')) {
            return null;
        }

        $path = $assets::tryDistPath();

        return \is_string($path) && \is_dir($path) ? $path : null;
    }
}

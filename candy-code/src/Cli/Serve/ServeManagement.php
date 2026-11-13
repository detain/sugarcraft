<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli\Serve;

use SugarCraft\Code\Cli\Bootstrap;
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
trait ServeManagement
{

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
            echo Lang::t('cli.serve.status.running', ['pid' => $record->pid, 'url' => $record->url, 'health' => (string) $health]) . "\n";
            echo Lang::t('cli.serve.status.root', ['root' => $record->root ?? Lang::t('cli.serve.none')]) . "\n";
            echo Lang::t('cli.serve.status.version', ['version' => $record->version]) . "\n";
            echo Lang::t('cli.serve.status.started', [
                'started' => \date('Y-m-d H:i:s', $record->startedAt),
                'uptime' => self::duration($record->uptime()),
            ]) . "\n";
            echo ($record->detached
                ? Lang::t('cli.serve.status.mode_background', ['log' => (string) $record->log])
                : Lang::t('cli.serve.status.mode_foreground')) . "\n";
        } elseif ($locked) {
            echo Lang::t('cli.serve.status.locked', ['dir' => $state->path]) . "\n";
        } else {
            echo ($record !== null
                ? Lang::t('cli.serve.status.stale', ['pid' => $record->pid, 'path' => (string) $state?->discoveryPath()])
                : Lang::t('cli.serve.status.not_running')) . "\n";
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
                ? Lang::t('cli.serve.stop.unidentified', ['dir' => $state->path])
                : Lang::t('cli.serve.no_server');
            if ($record !== null && $state !== null && !$state->lockHeldElsewhere()) {
                $record->removeFrom($state);
            }

            return self::answer($args, ['stopped' => false, 'reason' => $reason], Lang::t('cli.serve.stop.failed', ['reason' => $reason]), NonInteractive::EXIT_FAILURE);
        }

        if (!\function_exists('posix_kill')) {
            return NonInteractive::failUsage(Lang::t('cli.serve.stop.no_posix'), $args->outputFormat);
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
                ['stopped' => false, 'pid' => $record->pid, 'reason' => Lang::t('cli.serve.stop.did_not_exit')],
                Lang::t('cli.serve.stop.pid_did_not_exit', ['pid' => $record->pid]),
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
            $signal === 'KILL'
                ? Lang::t('cli.serve.stop.killed', ['pid' => $record->pid])
                : Lang::t('cli.serve.stop.stopped', ['pid' => $record->pid]),
            NonInteractive::EXIT_OK,
        );
    }

    /** @param array<string, string> $env */
    private static function logs(ParsedArgs $args, array $env): int
    {
        $follow = isset($args->subcommandFlags['--follow']) || isset($args->subcommandFlags['-f']);
        if ($follow && $args->outputFormat === NonInteractive::FORMAT_JSON) {
            return NonInteractive::failUsage(Lang::t('cli.serve.logs.follow_json'), $args->outputFormat);
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
                ['path' => $path, 'lines' => null, 'reason' => Lang::t('cli.serve.logs.no_log')],
                Lang::t('cli.serve.logs.no_log_at', ['path' => $path]),
                NonInteractive::EXIT_FAILURE,
            );
        }

        [$lines, $offset] = LogTail::tail($path, self::LOG_TAIL_LINES);
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
        $qr = isset($args->subcommandFlags['--qr']);
        if ($qr && $args->outputFormat === NonInteractive::FORMAT_JSON) {
            return NonInteractive::failUsage(Lang::t('cli.serve.url.qr_json'), $args->outputFormat);
        }

        $found = self::locate($args, $env);
        if (\is_int($found)) {
            return $found;
        }
        [$state, $record] = $found;
        if ($state === null || $record === null || !$record->isLive()) {
            $reason = Lang::t('cli.serve.no_server');

            return self::answer($args, ['loginUrl' => null, 'reason' => $reason], Lang::t('cli.serve.url.failed', ['reason' => $reason]), NonInteractive::EXIT_FAILURE);
        }

        $token = $env['CANDY_CODE_SERVER_TOKEN'] ?? '';
        if (\trim($token) === '') {
            $token = self::storedToken($state);
        }
        $answer = $token === null ? null : self::controlRequest($state, self::CONTROL_LOGIN_URL . ' ' . \trim($token));
        if (!\is_array($answer) || ($answer['ok'] ?? false) !== true || !\is_string($answer['loginUrl'] ?? null)) {
            $why = \is_array($answer) && ($answer['error'] ?? null) === 'unauthorized'
                ? Lang::t('cli.serve.url.token_refused')
                : Lang::t('cli.serve.url.no_answer', ['path' => $state->controlSocketPath()]);

            return self::answer($args, ['loginUrl' => null, 'reason' => $why], Lang::t('cli.serve.url.failed', ['reason' => $why]), NonInteractive::EXIT_FAILURE);
        }

        // A server older than `loginUrls` answers with the one URL.
        $loginUrls = self::loginUrlsOf($answer);
        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            echo NonInteractive::encodeDocument(['result' => ['url' => $record->url, 'loginUrl' => $answer['loginUrl'], 'loginUrls' => $loginUrls, 'pid' => $record->pid]]) . "\n";
        } else {
            echo \implode("\n", $loginUrls) . "\n";
            if ($qr) {
                foreach ($loginUrls as $loginUrl) {
                    $cleartextHost = self::qrCleartextHost($loginUrl);
                    if ($cleartextHost !== null) {
                        self::stderr(Lang::t('cli.serve.qr.cleartext', ['host' => $cleartextHost]));
                    }

                    try {
                        $symbol = QrEncoder::encode($loginUrl);
                    } catch (\InvalidArgumentException) {
                        self::stderr(Lang::t('cli.serve.qr.too_long'));

                        continue;
                    }
                    echo QrTerminal::render($symbol['modules'], $symbol['size']) . "\n";
                }
            }
            self::stderr(Lang::t('cli.serve.url.one_time_code', ['seconds' => LoginCodes::TTL_SECONDS]));
        }

        return NonInteractive::EXIT_OK;
    }

    /** @param array<string, string> $env */
    private static function token(ParsedArgs $args, array $env): int
    {
        $rotate = isset($args->subcommandFlags['--rotate']);
        if (\trim($env['CANDY_CODE_SERVER_TOKEN'] ?? '') !== '') {
            return NonInteractive::failUsage(
                $rotate ? Lang::t('cli.serve.token.env_rotate') : Lang::t('cli.serve.token.env_print'),
                $args->outputFormat,
            );
        }

        \umask(0o077);
        try {
            $state = StateDir::open(self::stateDirPath($env));
            $store = TokenStore::new($state->path);
            // The control socket authenticates BEFORE any verb runs (S-O02), against
            // the token the running server still holds in memory: that is the OLD
            // one until the reload lands, so the reload request has to carry it.
            $previous = $rotate ? $store->token() : null;
            $token = $rotate ? $store->rotate() : $store->token();
        } catch (\RuntimeException $e) {
            return NonInteractive::failUsage('candy-code serve token: ' . $e->getMessage(), $args->outputFormat);
        }

        $running = DiscoveryFile::read($state);
        $reloaded = null;
        if ($rotate && $running !== null && $running->isLive()) {
            // A running server reloads the file now and signs every client of
            // the old token out; one that cannot be reached keeps the old
            // token until it restarts, and says so.
            $answer = self::controlRequest($state, self::CONTROL_RELOAD_TOKEN . ' ' . $previous);
            $reloaded = \is_array($answer) && ($answer['ok'] ?? false) === true;
            $closed = (int) ($answer['closed'] ?? 0);
            $params = ['pid' => $running->pid, 'count' => $closed];
            self::stderr(match (true) {
                !$reloaded => Lang::t('cli.serve.token.not_reloaded', $params),
                $closed === 1 => Lang::t('cli.serve.token.reloaded.one', $params),
                default => Lang::t('cli.serve.token.reloaded.many', $params),
            });
        }

        // A plain print with no live server is not a "up" answer: the stored
        // token is still printed, but the exit code matches status/stop/url so a
        // readiness probe (`serve token >/dev/null && ...`) is not falsified.
        // --rotate is a legitimate offline operation and stays exit 0.
        $down = !$rotate && ($running === null || !$running->isLive());
        if ($down) {
            self::stderr(Lang::t('cli.serve.token.no_server'));
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

        return $down ? NonInteractive::EXIT_FAILURE : NonInteractive::EXIT_OK;
    }

    /**
     * The host a pairing QR would carry in the clear, or null when the URL is
     * safe to print: https (TLS — `serve` itself refuses to mint LAN/WAN http
     * codes elsewhere), or an http bind on a loopback host where the token
     * never leaves the machine. Names the host only — never the fragment code.
     */
    private static function qrCleartextHost(string $loginUrl): ?string
    {
        $parts = \parse_url($loginUrl);
        if (!\is_array($parts) || ($parts['scheme'] ?? '') !== 'http') {
            return null;
        }

        $host = (string) ($parts['host'] ?? '');
        $host = \trim($host, '[]'); // parse_url keeps IPv6 brackets; LOOPBACK_HOSTS spells '::1' bare
        if ($host === '' || \in_array($host, ServerConfig::LOOPBACK_HOSTS, true) || \str_starts_with($host, '127.')) {
            return null;
        }

        return $host;
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
}

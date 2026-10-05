<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message as Psr7Message;
use GuzzleHttp\Psr7\Uri;
use Ratchet\RFC6455\Handshake\ClientNegotiator;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use SugarCraft\Core\Program;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Backend\RemoteBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Diagnostics\TuiErrorLog;
use SugarCraft\Crush\Host\RemoteSessionHost;
use SugarCraft\Crush\Server\Auth\TokenStore;
use SugarCraft\Crush\Server\DiscoveryFile;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Server\StateDir;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * `sugarcrush attach [<session>] [--url <url>]` — the TUI as a client of a
 * running `sugarcrush serve` (roadmap O-8a, Appendix O §4.9).
 *
 * WHAT IT DOES. Finds the server — `--url`, else the discovery record
 * (`server.json`) in the state directory `serve` keeps (`~/.sugar-crush/server`,
 * or `SUGARCRUSH_SERVER_DIR`) — and authenticates with the owner token
 * (`SUGARCRUSH_SERVER_TOKEN`, else that directory's `token` file) as a bearer
 * client over the `sugarcrush.v1` WebSocket. It then follows one session: the
 * one `<session>` names (an id, a name or a unique id prefix, resolved on the
 * server), or a new one, and runs the normal full-screen TUI over it:
 *
 *  - the transcript on screen is the SERVER's, from the subscription's
 *    snapshot ({@see \SugarCraft\Crush\Host\RemoteSessionHost::history()});
 *  - every turn is the server's turn on that session
 *    ({@see \SugarCraft\Crush\Backend\RemoteBackend}): tools run there, under
 *    the server's permission mode, and its questions come up here as the same
 *    approval modal — answered here, or by any other client, first answer wins;
 *  - nothing is written to the local session store: the server holds the
 *    session's single-writer lock and keeps its transcript.
 *
 * Closing the terminal detaches; it does not stop a turn (Appendix O §4.5).
 *
 * THE OTHER DIRECTION. A plain `sugarcrush --resume <id>` whose session the
 * server holds opens read-only, and its notice now says how to attach instead
 * ({@see lockHolderOffer()}).
 *
 * EXITS. 2 for a usage error (a stray operand, an unknown flag, a malformed
 * `--url`, `--output-format json`: the TUI has no document to print). 1 when
 * it ran and could not attach — no server running, no token, the connection
 * refused, an unknown session — after saying why on stderr. 0 when the TUI
 * was quit.
 */
final class Attach
{
    /** How long connecting, the handshake and opening the session may take. */
    public const CONNECT_TIMEOUT_SECONDS = 15.0;

    /** The WebSocket path every `sugarcrush serve` answers on. */
    public const WS_PATH = '/ws';

    /** The flags `attach` accepts (see {@see ParsedArgs::SUBCOMMAND_FLAGS}). */
    public const FLAGS = ['--url'];

    private function __construct()
    {
    }

    public static function run(ParsedArgs $args): int
    {
        if (\count($args->subcommandArgs) > 1) {
            return NonInteractive::failUsage(Lang::t('cli.attach.unexpected_operand', ['operand' => $args->subcommandArgs[1]]), $args->outputFormat, Lang::t('cli.attach.usage'));
        }
        foreach (\array_keys($args->subcommandFlags) as $flag) {
            if (!\in_array($flag, self::FLAGS, true)) {
                return NonInteractive::failUsage(Lang::t('cli.attach.flag_does_not_apply', ['flag' => $flag]), $args->outputFormat, Lang::t('cli.attach.usage'));
            }
        }
        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            return NonInteractive::failUsage(Lang::t('cli.attach.json_does_not_apply'), $args->outputFormat, Lang::t('cli.attach.usage'));
        }

        $env = self::environment();
        $flagUrl = $args->subcommandFlags['--url'] ?? null;
        $url = null;
        if (\is_string($flagUrl)) {
            $url = self::websocketUrl($flagUrl);
            if ($url === null) {
                return NonInteractive::failUsage(Lang::t('cli.attach.bad_url', ['url' => $flagUrl]), $args->outputFormat, Lang::t('cli.attach.usage'));
            }
        }

        $state = self::stateDir($env);
        if ($url === null) {
            $record = $state === null ? null : DiscoveryFile::read($state);
            if ($record === null || !$record->isLive()) {
                return self::fail($state !== null
                    ? Lang::t('cli.attach.no_server_from', ['dir' => $state->path])
                    : Lang::t('cli.attach.no_server'));
            }
            $url = (string) self::websocketUrl($record->url);
        }

        $token = self::token($env, $state);
        if ($token === null) {
            return self::fail(Lang::t('cli.attach.no_token'));
        }

        $target = $args->subcommandArgs[0] ?? null;
        $outcome = self::await(
            self::connect($url, $token)->then(
                static fn (RemoteSessionHost $host): PromiseInterface => $host->hello()->then(
                    static fn (): PromiseInterface => $host->open($target),
                ),
            ),
            self::CONNECT_TIMEOUT_SECONDS,
        );
        if (!$outcome instanceof RemoteSessionHost) {
            return self::fail($outcome instanceof \Throwable
                ? $outcome->getMessage()
                : Lang::t('cli.attach.no_answer', ['seconds' => (int) self::CONNECT_TIMEOUT_SECONDS]));
        }

        return self::runTui($outcome, $args);
    }

    /**
     * Open the `sugarcrush.v1` WebSocket at $url (`ws://host:port/ws`) with
     * $token as the bearer credential: the HTTP upgrade, its answer checked
     * (101, the accept key, the subprotocol echoed), then a
     * {@see RemoteSessionHost} over the socket. A refusal rejects with the
     * status line and the server's own reason.
     *
     * A connect timeout only — once connected, the socket stays open as long
     * as the session is followed (turns can run for many minutes).
     *
     * @return PromiseInterface<RemoteSessionHost>
     */
    public static function connect(string $url, string $token, ?LoopInterface $loop = null): PromiseInterface
    {
        $loop ??= Loop::get();
        $uri = new Uri($url);
        $secure = $uri->getScheme() === 'wss';
        $port = $uri->getPort() ?? ($secure ? 443 : 80);
        $host = $uri->getHost();

        $negotiator = new ClientNegotiator(new HttpFactory());
        $request = $negotiator->generateRequest($uri->withScheme($secure ? 'https' : 'http'))
            ->withHeader('Sec-WebSocket-Protocol', ServerConfig::SUBPROTOCOL)
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('User-Agent', RemoteSessionHost::CLIENT_NAME . '/' . Help::versionString());

        $deferred = new Deferred();
        $connector = new Connector(['timeout' => self::CONNECT_TIMEOUT_SECONDS, 'tls' => ['peer_name' => $host]], $loop);
        $connector->connect(($secure ? 'tls://' : 'tcp://') . (\str_contains($host, ':') && !\str_starts_with($host, '[') ? '[' . $host . ']' : $host) . ':' . $port)->then(
            static function (ConnectionInterface $connection) use ($request, $negotiator, $deferred): void {
                $buffer = '';
                $settled = false;
                $onClose = static function () use (&$settled, $deferred): void {
                    if (!$settled) {
                        $settled = true;
                        $deferred->reject(new \RuntimeException(Lang::t('cli.attach.closed_during_handshake')));
                    }
                };
                $connection->on('close', $onClose);
                $onData = null;
                $onData = static function (string $chunk) use (&$buffer, &$settled, &$onData, $onClose, $connection, $request, $negotiator, $deferred): void {
                    $buffer .= $chunk;
                    $end = \strpos($buffer, "\r\n\r\n");
                    if ($end === false) {
                        if (\strlen($buffer) > 65536) {
                            $settled = true;
                            $connection->close();
                            $deferred->reject(new \RuntimeException(Lang::t('cli.attach.not_http')));
                        }

                        return;
                    }
                    $connection->removeListener('data', $onData);
                    $connection->removeListener('close', $onClose);
                    $settled = true;

                    try {
                        $response = Psr7Message::parseResponse(\substr($buffer, 0, $end + 4));
                    } catch (\Throwable) {
                        $connection->close();
                        $deferred->reject(new \RuntimeException(Lang::t('cli.attach.not_http')));

                        return;
                    }
                    $rest = \substr($buffer, $end + 4);
                    if ($response->getStatusCode() !== 101) {
                        $connection->close();
                        $deferred->reject(new \RuntimeException(self::refusal($response->getStatusCode(), $response->getReasonPhrase(), $rest)));

                        return;
                    }
                    if (!$negotiator->validateResponse($request, $response)
                        || $response->getHeaderLine('Sec-WebSocket-Protocol') !== ServerConfig::SUBPROTOCOL) {
                        $connection->close();
                        $deferred->reject(new \RuntimeException(Lang::t('cli.attach.handshake_incomplete', ['subprotocol' => ServerConfig::SUBPROTOCOL])));

                        return;
                    }
                    $deferred->resolve(RemoteSessionHost::overStream($connection, RemoteSessionHost::CLIENT_NAME, $rest));
                };
                $connection->on('data', $onData);
                $connection->write(Psr7Message::toString($request));
            },
            static function (\Throwable $e) use ($deferred): void {
                $deferred->reject(new \RuntimeException(Lang::t('cli.attach.unreachable', ['error' => $e->getMessage()]), 0, $e));
            },
        );

        return $deferred->promise();
    }

    /**
     * `ws://host:port/ws` for an `http(s)://` or `ws(s)://` address — the URL
     * `serve` prints and `server.json` records — or null when $url is
     * neither. A path other than the WebSocket's is replaced, so the
     * address a browser opens works here as given.
     */
    public static function websocketUrl(string $url): ?string
    {
        $parts = \parse_url(\trim($url));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = match (\strtolower($parts['scheme'])) {
            'http', 'ws' => 'ws',
            'https', 'wss' => 'wss',
            default => null,
        };
        if ($scheme === null || $parts['host'] === '') {
            return null;
        }
        $host = \str_contains($parts['host'], ':') && !\str_starts_with($parts['host'], '[') ? '[' . $parts['host'] . ']' : $parts['host'];

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . self::WS_PATH;
    }

    /**
     * The sentence a read-only session's notice adds when the process
     * holding its lock is the running `sugarcrush serve`: how to drive that
     * session from this terminal instead. Null when the holder is not the
     * server (another TUI), unknown, or no server is running.
     */
    public static function lockHolderOffer(string $sessionId, ?int $holderPid): ?string
    {
        if ($holderPid === null) {
            return null;
        }
        try {
            $state = self::stateDir(self::environment());
            $record = $state === null ? null : DiscoveryFile::read($state);
        } catch (\Throwable) {
            return null;
        }
        if ($record === null || $record->pid !== $holderPid || !$record->isLive()) {
            return null;
        }

        return Lang::t('cli.attach.lock_holder_offer', ['url' => $record->url, 'session' => $sessionId]);
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * The full-screen TUI over the attached session: the launch's usual App,
     * with the session opened from the server
     * ({@see Bootstrap::openSession()} asks {@see RemoteSessionHost::forLaunch()})
     * and the Chat's turns sent there.
     */
    private static function runTui(RemoteSessionHost $host, ParsedArgs $args): int
    {
        RemoteSessionHost::useForLaunch($host);
        try {
            $serverRoot = $host->serverRoot();
            $root = $serverRoot !== null && \is_dir($serverRoot) ? $serverRoot : ($args->root ?? (\getcwd() ?: null));

            $tuiErrorLog = TuiErrorLog::install(HomeDirectory::owned());
            if ($tuiErrorLog !== null) {
                \ini_set('log_errors', '1');
                \ini_set('display_errors', '0');
            }

            $app = Bootstrap::app($root, tuiErrorLog: $tuiErrorLog);
            $chat = $app->chat;
            if ($chat instanceof Chat) {
                // The server keeps this session: no local saves (it holds the
                // lock) and no local title or summary calls on its rows.
                $app = $app->withChat($chat
                    ->withBackend(RemoteBackend::new($host))
                    ->withSessionStore(null)
                    ->withTitleBackend(null));
            }

            (new Program($app, Chat::programOptions()))->run();
            \fwrite(\STDOUT, Ansi::popKittyKeyboard());
        } finally {
            $host->close('detached');
            RemoteSessionHost::useForLaunch(null);
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * Run the loop until $promise settles or $seconds pass: its value, its
     * rejection, or null on the timeout.
     */
    private static function await(PromiseInterface $promise, float $seconds): mixed
    {
        $done = false;
        $result = null;
        $promise->then(
            static function (mixed $value) use (&$done, &$result): void {
                $done = true;
                $result = $value;
                Loop::stop();
            },
            static function (\Throwable $e) use (&$done, &$result): void {
                $done = true;
                $result = $e;
                Loop::stop();
            },
        );
        if (!$done) {
            $timer = Loop::addTimer($seconds, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        return $done ? $result : null;
    }

    /** The server's own words for a refused upgrade, when it sent JSON. */
    private static function refusal(int $status, string $phrase, string $body): string
    {
        $decoded = \json_decode($body, true);
        $message = \is_array($decoded) && \is_array($decoded['error'] ?? null) && \is_string($decoded['error']['message'] ?? null)
            ? $decoded['error']['message']
            : null;

        $params = ['status' => $status, 'phrase' => $phrase];

        return $message === null
            ? Lang::t('cli.attach.refused', $params)
            : Lang::t('cli.attach.refused_because', $params + ['reason' => $message]);
    }

    /** @param array<string, string> $env */
    private static function stateDir(array $env): ?StateDir
    {
        $home = HomeDirectory::owned();
        $path = ServerConfig::stateDirFrom($env, $home === null ? '' : $home . '/.sugar-crush/server');
        if ($path === '') {
            return null;
        }
        try {
            return StateDir::existing($path);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * The owner token: `SUGARCRUSH_SERVER_TOKEN`, else the state directory's
     * token file — read, never minted (a client must not create the
     * credential a server has not got).
     *
     * @param array<string, string> $env
     */
    private static function token(array $env, ?StateDir $state): ?string
    {
        $override = \trim($env['SUGARCRUSH_SERVER_TOKEN'] ?? '');
        if ($override !== '') {
            return $override;
        }
        if ($state === null) {
            return null;
        }
        $path = $state->path . '/' . TokenStore::FILE;
        $stat = @\lstat($path);
        if ($stat === false || ((int) $stat['mode'] & 0o170000) !== 0o100000) {
            return null;
        }
        $token = \trim((string) @\file_get_contents($path));

        return \preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? $token : null;
    }

    private static function fail(string $reason): int
    {
        \fwrite(\STDERR, 'sugarcrush attach: ' . $reason . "\n");

        return NonInteractive::EXIT_FAILURE;
    }

    /**
     * The variables `attach` consults, read by name.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $env = [];
        foreach (['SUGARCRUSH_SERVER_DIR', 'SUGARCRUSH_SERVER_TOKEN'] as $name) {
            $value = \getenv($name);
            if (\is_string($value)) {
                $env[$name] = $value;
            }
        }

        return $env;
    }
}

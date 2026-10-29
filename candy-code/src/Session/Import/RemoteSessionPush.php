<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session\Import;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use SugarCraft\Code\Cli\Attach;
use SugarCraft\Code\Host\RemoteSessionHost;
use SugarCraft\Code\Protocol\Methods\SessionMethods;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\StateDir;
use SugarCraft\Code\Support\HomeDirectory;
use Throwable;

/**
 * `/handoff --remote <url>` teleport client (roadmap C-SES-7.g).
 *
 * The remote arm of the handoff command pushes the current transcript to
 * another candy-code serve through the same websocket and token-resolution
 * discipline {@see Attach} uses for `attach`: the token is READ from the
 * environment or the server state directory and never minted here, and the
 * dial is refused before any socket opens unless the target is loopback
 * `ws://` or TLS `wss://` (plain `ws://` off-loopback carries the transcript
 * unencrypted and is refused until serve speaks TLS). The transcript rides
 * the wire as the JSON body {@see \SugarCraft\Code\Util\Exporter::toJson()}
 * writes and `session.import` already accepts, so the server side stays the
 * C-SES-7.e machinery untouched.
 */
final class RemoteSessionPush
{
    private function __construct()
    {
    }

    /**
     * The websocket URL for a user-typed target, or null when it is not an
     * address `attach` could dial at all ({@see Attach::websocketUrl()} — the
     * one mapping, so the handoff arm and the CLI never disagree on shape).
     */
    public static function websocketUrl(string $url): ?string
    {
        return Attach::websocketUrl($url);
    }

    /**
     * Whether a host names this machine: loopback BY NAME (`localhost`, `::1`)
     * or a numeric dotted-quad inside 127.0.0.0/8. The 127. leg matches
     * OCTETS ONLY (C-SES-7.g r1): `127.attacker.example` starts like an
     * address but RESOLVES wherever its owner points it, and a plain `ws://`
     * dial to it would carry the transcript and bearer token in the clear to
     * exactly the stranger this gate exists to refuse.
     */
    public static function isLoopbackHost(string $host): bool
    {
        $host = trim($host, '[]');

        return $host === 'localhost' || $host === '::1'
            || preg_match('/^127\.(?:[0-9]{1,3}\.){2}[0-9]{1,3}$/', $host) === 1;
    }

    /**
     * The law the dial obeys: `wss://` always, `ws://` only to a loopback
     * host. Checked on the mapped URL BEFORE any socket opens, because a
     * transcript is not something to send in the clear to a stranger.
     */
    public static function dialAllowed(?string $wsUrl): bool
    {
        if ($wsUrl === null) {
            return false;
        }
        $parts = parse_url($wsUrl);
        if ($parts === false) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === 'wss') {
            return true;
        }

        return $scheme === 'ws' && self::isLoopbackHost((string) ($parts['host'] ?? ''));
    }

    /** The one bound `session.import` enforces server-side, read here so an
     * over-large transcript is refused before the dial instead of mid-wire. */
    public static function fitsWithinImportBound(string $content): bool
    {
        return strlen($content) <= SessionMethods::IMPORT_MAX_CONTENT_BYTES;
    }

    /**
     * The owner token, exactly as `attach` resolves it: the environment
     * override, else the state directory's token file — read, never minted
     * (a client must not create the credential a server has not got). The
     * resolver is deliberately a second reader, not {@see TokenStore::token()},
     * which rotates on a miss.
     */
    public static function resolveToken(): ?string
    {
        $env = self::environment();
        $override = trim($env['CANDY_CODE_SERVER_TOKEN'] ?? '');
        if ($override !== '') {
            return $override;
        }
        $state = self::stateDir($env);
        if ($state === null) {
            return null;
        }
        $path = $state->path . '/' . TokenStore::FILE;
        $stat = @lstat($path);
        if ($stat === false || ((int) $stat['mode'] & 0o170000) !== 0o100000) {
            return null;
        }
        $token = trim((string) @file_get_contents($path));

        return preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? $token : null;
    }

    /**
     * Dial, say hello, import the transcript, and hand back the NEW remote
     * session id. The socket `attach` opened is closed on every settled path
     * — success, wire error, malformed answer or expired answer window — so
     * a teleport never leaves a half-open attachment on the target.
     *
     * THE ANSWER WINDOW. {@see Attach::connect()} bounds only the TCP
     * connect; without a second bound a target that completes the upgrade
     * and then stays silent would leave hello()/session.import pending
     * forever — no final row, socket held (C-SES-7.g r1, HandoffHostCommand
     * pushCall never applies the await discipline {@see Attach::run()} uses
     * for this same chain). The handshake-and-import chain therefore rides a
     * one-shot reject timer defaulting to
     * {@see Attach::CONNECT_TIMEOUT_SECONDS}, cancelled the moment the chain
     * settles so a success leaves no pending loop work; E646 shelters LLM
     * turns from blanket timeouts, this is a connection-phase bound on a
     * serve round-trip, not one.
     *
     * @param ?float $answerBound  widened only so tests can arm a short
     *                             window; production always uses the default
     *
     * @return PromiseInterface<string>
     */
    public static function push(
        string $wsUrl,
        string $token,
        string $content,
        ?LoopInterface $loop = null,
        ?float $answerBound = null,
    ): PromiseInterface {
        $loop ??= Loop::get();
        $bound = $answerBound ?? Attach::CONNECT_TIMEOUT_SECONDS;
        $deferred = new Deferred();
        $settled = false;
        $opened = null;

        $timer = $loop->addTimer($bound, static function () use (&$settled, &$opened, $deferred, $bound): void {
            if ($settled) {
                return;
            }
            $settled = true;
            if ($opened instanceof RemoteSessionHost) {
                $opened->close();
            }
            $deferred->reject(new RuntimeException('the teleport target accepted the dial but answered nothing within ' . $bound . ' seconds'));
        });

        Attach::connect($wsUrl, $token, $loop)->then(
            static function (RemoteSessionHost $host) use ($content, &$opened, &$settled): PromiseInterface {
                // C-SES-7.g weld-minor rider (REVIEW-2 finding 1): a dial that
                // completes AFTER the answer window already rejected must not
                // silently land a session the user was told failed. Close the
                // late host and stay rejected — the deferred is already settled.
                if ($settled) {
                    $host->close();

                    return Promise\reject(new RuntimeException('the teleport answer window expired before the dial completed'));
                }
                $opened = $host;

                return $host->hello()->then(
                    static fn (array $unused): PromiseInterface => $host->call('session.import', [
                        'format' => ImportFormat::CandyCode->value,
                        'content' => $content,
                    ]),
                )->then(
                    static function (mixed $summary) use ($host): string {
                        $id = is_array($summary) ? ($summary['id'] ?? null) : null;
                        $host->close();
                        if (!is_string($id) || $id === '') {
                            throw new RuntimeException('session.import answered without a session id');
                        }

                        return $id;
                    },
                    static function (Throwable $failure) use ($host): PromiseInterface {
                        $host->close();

                        return Promise\reject($failure);
                    },
                );
            },
        )->then(
            static function (string $id) use (&$settled, $timer, $loop, $deferred): void {
                $loop->cancelTimer($timer);
                if ($settled) {
                    return;
                }
                $settled = true;
                $deferred->resolve($id);
            },
            static function (Throwable $failure) use (&$settled, $timer, $loop, $deferred): void {
                $loop->cancelTimer($timer);
                if ($settled) {
                    return;
                }
                $settled = true;
                $deferred->reject($failure);
            },
        );

        return $deferred->promise();
    }

    /**
     * The variables this client consults, read by name (the same two
     * `attach` reads — no new environment surface).
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $env = [];
        foreach (['CANDY_CODE_SERVER_DIR', 'CANDY_CODE_SERVER_TOKEN'] as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * @param array<string, string> $env
     */
    private static function stateDir(array $env): ?StateDir
    {
        $home = HomeDirectory::owned();
        $path = ServerConfig::stateDirFrom($env, $home === null ? '' : $home . '/.candy-code/server');
        if ($path === '') {
            return null;
        }
        try {
            return StateDir::existing($path);
        } catch (RuntimeException) {
            return null;
        }
    }
}

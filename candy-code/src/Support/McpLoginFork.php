<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Code\Lang;
use SugarCraft\Code\App\McpLoginProgressMsg;

/**
 * MCP-5: the process slots behind an in-TUI `mcp auth login <server>`.
 *
 * The login itself is not reimplemented here — the child runs the very CLI
 * arm (`candy-code mcp auth login …` → {@see \SugarCraft\Code\MCP\OAuthLoopbackFlow}),
 * so endpoints, loopback binding, storage and the 300 s human-wait bound
 * (which that class owns — E646 forbids this class from adding its own) all
 * stay in one place. This class only owns the PIPES: spawn with
 * {@see ProcessContainment::spawnSpec} so the child is its own session and
 * survives nothing of the TUI's terminal, drain them non-blocking on a
 * second-by-second tick, and translate what appears into
 * {@see McpLoginProgressMsg} beats for Chat to announce.
 *
 * State lives in process-static slots keyed by server, NOT on the Chat
 * model: the model is a value (every mutate() copies it) and a live process
 * handle is the one thing that must not be duplicated — the same
 * reasoning behind StatusLineCommand holding process state off-model. A
 * second `/mcp login` for the SAME server is refused while the first child
 * lives; different servers get independent slots.
 */
final class McpLoginFork
{
    /** How much of the child's output is kept for the closing transcript row. */
    public const TAIL_MAX_BYTES = 1500;

    /**
     * How much of the child's output is scanned for the login URL. This is a
     * flood bound, not a window: the URL rides to the FRONT of the buffer and
     * nothing before it is ever discarded (C-MCP-6.a). The old scheme scanned
     * only the last 1500 bytes with a bare first-match regex, and that is
     * doubly wrong for a real `mcp auth login` child — OAuthLoopbackFlow
     * prints its authorize URL on a line of its own AFTER a line that
     * backticks the server URL, so a first-anywhere match announced the wrong
     * URL, and a 2 KB authorize URL (client_id, scope, state and PKCE riding
     * the query) fell out of a tail that had already scrolled past the line
     * start. The announce now matches ONLY a line consisting of nothing but
     * one URL — the shape OAuthLoopbackFlow emits.
     */
    public const SCAN_MAX_BYTES = 65536;

    /** Connect deadline for the paste-back GET to the child's own listener. */
    public const PASTEBACK_TIMEOUT_SECONDS = 2.0;

    /** How much of the child's callback answer is read and dropped (8 × 8 KiB). */
    public const PASTEBACK_ANSWER_READS = 8;

    /**
     * Test seam: replaces the argv handed to the child. Production never
     * sets it; a test may point the slot at a stub script so the whole
     * announce/poll/exit chain runs without OAuth.
     *
     * @var ?\Closure(string $server, list<string> $rest): list<string>
     */
    public static ?\Closure $argvOverride = null;

    /**
     * @var array<string, array{proc: mixed, out: mixed, err: mixed, tail: string, scan: string, urlSent: bool}>
     */
    private static array $slots = [];

    /**
     * Loopback ports parsed out of the redirect_uri of each announced login
     * URL, kept past the slot's death so the correlation leg (C-MCP-4.c) can
     * still ask which port its server was told to call back on. Cleared on
     * re-arm and by {@see releaseAll}.
     *
     * @var array<string, ?int>
     */
    private static array $redirectPorts = [];

    private function __construct()
    {
    }

    /**
     * Open a login child for $server.
     *
     * Never blocks and never throws at the caller: a missing entry point,
     * a failed spawn, or a busy slot all answer with the row Chat should
     * print, and $armed=false means no poll is due.
     *
     * @param list<string> $rest  the trailing CLI arguments as typed ([token-url] …),
     *                            or the paste-back pair `['--code', $url]` (C-MCP-4.c)
     * @return array{0: string, 1: bool} [transcript notice, armed]
     */
    public static function arm(string $server, array $rest, string $cwd = ''): array
    {
        // C-MCP-4.c: `--code` is a delivery, not a spawn — judged before the
        // busy check so a paste-back can reach the slot that IS live.
        if (($rest[0] ?? null) === '--code') {
            return self::deliverPasteBack($server, $rest);
        }

        if (isset(self::$slots[$server])) {
            return [Lang::t('cmd.mcp-auth.login-busy', ['server' => $server]), false];
        }

        $argv = self::$argvOverride !== null
            ? (self::$argvOverride)($server, $rest)
            : self::cliArgv($server, $rest);
        if ($argv === null) {
            return [Lang::t('cmd.mcp-auth.login-no-entry', ['server' => $server]), false];
        }

        $pipes = [];
        $proc = @\proc_open(
            ProcessContainment::spawnSpec(array_values($argv)),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd === '' ? null : $cwd,
            ProcessContainment::env(),
        );
        if (!\is_resource($proc)) {
            unset(self::$slots[$server]);

            return [Lang::t('cmd.mcp-auth.login-failed-spawn', ['server' => $server]), false];
        }

        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);
        self::$slots[$server] = ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2], 'tail' => '', 'scan' => '', 'urlSent' => false];
        self::$redirectPorts[$server] = null;

        return [Lang::t('cmd.mcp-auth.login-armed', ['server' => $server]), true];
    }

    /**
     * One non-blocking look at the child: drain output, judge the beat.
     * Never returns null while a slot lives — {@see Cmd::tick()} is a
     * ONE-SHOT timer, so silence answers as a `waiting` beat that Chat uses
     * to re-arm the next look. A `gone` beat when the slot is absent is
     * Chat's cue to stop re-arming without printing anything.
     */
    public static function poll(string $server): ?McpLoginProgressMsg
    {
        if (!isset(self::$slots[$server])) {
            return new McpLoginProgressMsg($server, McpLoginProgressMsg::PHASE_GONE);
        }

        $slot = &self::$slots[$server];
        foreach (['out', 'err'] as $pipe) {
            $chunk = \fread($slot[$pipe], 65536);
            if (\is_string($chunk) && $chunk !== '') {
                $slot['tail'] = \substr($slot['tail'] . $chunk, -self::TAIL_MAX_BYTES);
                if (!$slot['urlSent']) {
                    // Head-trimmed: the announce happens at the FIRST URL line,
                    // so only ever-growing pre-URL noise may be dropped (C-MCP-6.a).
                    $slot['scan'] = \substr($slot['scan'] . $chunk, 0, self::SCAN_MAX_BYTES);
                }
            }
        }

        // Announce ONLY a line that consists of nothing but one URL — the shape
        // OAuthLoopbackFlow prints. The old bare first-match grabbed the
        // backticked SERVER url from the sentence above it (C-MCP-6.a).
        if (!$slot['urlSent'] && \preg_match('~^\s+(https?://\S+)\s*$~m', $slot['scan'], $m) === 1) {
            $slot['urlSent'] = true;
            $url = $m[1];
            self::$redirectPorts[$server] = self::redirectPortFromAuthorizeUrl($url);

            return new McpLoginProgressMsg($server, McpLoginProgressMsg::PHASE_URL, $url);
        }

        if (\proc_get_status($slot['proc'])['running'] ?? false) {
            return new McpLoginProgressMsg($server, McpLoginProgressMsg::PHASE_WAITING);
        }

        $exit = \proc_close($slot['proc']); // reaps the child and closes the pipes we still hold
        $tail = $slot['tail'];
        unset(self::$slots[$server]);

        return new McpLoginProgressMsg($server, McpLoginProgressMsg::PHASE_DONE, $tail, $exit);
    }

    /** Whether a slot for $server is live (pins and teardown use this). */
    public static function isArmed(string $server): bool
    {
        return isset(self::$slots[$server]);
    }

    /**
     * The loopback port the announced login URL asked the browser to call
     * back on, parsed from its `redirect_uri` — the handle C-MCP-4.c
     * correlates a hit callback against, readable while the slot lives and
     * after the done beat retires it. Null when nothing was announced yet or
     * the URL carried no parseable redirect_uri.
     */
    public static function redirectPort(string $server): ?int
    {
        return self::$redirectPorts[$server] ?? null;
    }

    /**
     * C-MCP-4.c: the paste-back leg. A browser on another machine finished
     * the authorize step, and its redirect to the child's loopback listener
     * could not cross the machine boundary; the user pastes the URL the
     * browser landed on, and the waiting child's own listener receives it
     * through a local GET, so the child finishes the state+PKCE exchange
     * itself. This leg validates only the ADDRESS; the authorization code
     * rides through untouched for the child to verify.
     *
     * The pasted URL is untrusted input, so acceptance is narrow and the
     * comparisons are constant-time (a timing oracle beside a listener bound
     * to a random loopback port would leak how far a forged callback matched
     * the live login's announced port): the scheme must be plain http, the
     * host exactly 127.0.0.1 or localhost, and the port the slot's announced
     * redirect_uri port. Nothing else is ever dialed — this leg must not
     * become an SSRF carrier for a pasted address — and refusals name the
     * reason without echoing the URL, which carries the live code. The child
     * self-exits after its own wait bound, so a callback pasted for a dead
     * login meets a closed port here, not a wedge.
     *
     * @param list<string> $rest  argv with '--code' at 0
     * @return array{0: string, 1: bool} [transcript notice, armed] — the
     *                            second is always false: this leg spawns nothing
     */
    private static function deliverPasteBack(string $server, array $rest): array
    {
        $refuse = static fn (string $reasonKey): array => [
            Lang::t('cmd.mcp-auth.login-code-refused', ['server' => $server, 'reason' => Lang::t($reasonKey)]),
            false,
        ];

        $url = (string) ($rest[1] ?? '');
        if ($url === '' || isset($rest[2])) {
            return $refuse('cmd.mcp-auth.login-code-reason-shape');
        }

        if (!self::isArmed($server)) {
            return $refuse('cmd.mcp-auth.login-code-reason-noslot');
        }

        $parts = \parse_url($url);
        if (!\is_array($parts)) {
            return $refuse('cmd.mcp-auth.login-code-reason-shape');
        }

        if (!\hash_equals('http', \strtolower((string) ($parts['scheme'] ?? '')))) {
            return $refuse('cmd.mcp-auth.login-code-reason-scheme');
        }

        $host = \strtolower((string) ($parts['host'] ?? ''));
        if (!\hash_equals('127.0.0.1', $host) && !\hash_equals('localhost', $host)) {
            return $refuse('cmd.mcp-auth.login-code-reason-host');
        }

        $port = isset($parts['port']) ? (string) $parts['port'] : '';
        $expected = self::redirectPort($server);
        if ($expected === null || !\hash_equals((string) $expected, $port)) {
            return $refuse('cmd.mcp-auth.login-code-reason-port');
        }

        $target = (string) ($parts['path'] ?? '/');
        if (isset($parts['query']) && $parts['query'] !== '') {
            $target .= '?' . $parts['query'];
        }
        if (\strpbrk($target, "\r\n\0") !== false) {
            return $refuse('cmd.mcp-auth.login-code-reason-shape');
        }

        // The dialed address is the validated literal itself: loopback, the
        // slot's own announced port — never a host taken from the paste.
        // HTTP/1.0 so the child closes after answering; the answer is read
        // and dropped, because the child's beats, not this row, tell the
        // user what the exchange decided. Nothing of request or response is
        // ever logged — the request target carries the live authorization code.
        $socket = @\stream_socket_client(
            'tcp://127.0.0.1:' . $expected,
            $errno,
            $errstr,
            self::PASTEBACK_TIMEOUT_SECONDS,
        );
        if (!\is_resource($socket)) {
            return $refuse('cmd.mcp-auth.login-code-reason-dial');
        }

        try {
            \stream_set_timeout($socket, (int) \ceil(self::PASTEBACK_TIMEOUT_SECONDS));
            if (\fwrite($socket, "GET {$target} HTTP/1.0\r\nHost: 127.0.0.1:{$expected}\r\n\r\n") === false) {
                return $refuse('cmd.mcp-auth.login-code-reason-dial');
            }
            for ($i = 0; $i < self::PASTEBACK_ANSWER_READS; $i++) {
                $chunk = \fread($socket, 8192);
                if (!\is_string($chunk) || $chunk === '') {
                    break;
                }
            }

            return [Lang::t('cmd.mcp-auth.login-code-delivered', ['server' => $server]), false];
        } finally {
            \fclose($socket);
        }
    }

    /**
     * `…?…&redirect_uri=http%3A%2F%2F127.0.0.1%3A41239%2Fcb…` → 41239-style
     * int, or null. Parse failures answer null; this is a report aid beside a
     * URL that was already announced, never a gate.
     */
    private static function redirectPortFromAuthorizeUrl(string $url): ?int
    {
        $query = \parse_url($url, \PHP_URL_QUERY);
        if (!\is_string($query) || $query === '') {
            return null;
        }
        \parse_str($query, $params);
        $redirect = $params['redirect_uri'] ?? null;
        if (!\is_string($redirect)) {
            return null;
        }
        $port = \parse_url($redirect, \PHP_URL_PORT);

        return $port === null ? null : (int) $port;
    }

    /**
     * Tear every live slot down: SIGTERM, then a blocking proc_close reap.
     * There is no TERM-then-KILL ladder here — the wait is bounded not by an
     * escalation rung but by the child's own deadline (OAuthLoopbackFlow
     * self-exits after its wait budget — 300 s by default, or the explicit
     * --timeout the login line passed through arm() — no matter what signal
     * it sees).
     * TEST HYGIENE ONLY, and nothing on the quit path calls it — by design:
     * a login still running when the TUI exits is deliberately left to
     * finish. The child's loopback bind is to port 0 (OAuthLoopbackFlow),
     * so a survivor cannot block the next login's bind, and a grant stored
     * after the parent is gone still attaches at the NEXT launch, exactly
     * as the done beat announces. That self-exit, not a shutdown rung here,
     * is the process hygiene; the zero-production-caller census is pinned
     * in the fork's own test, so wiring a call site is a product change
     * (quit would abort in-flight logins) that must re-derive this
     * paragraph in the same commit.
     */
    public static function releaseAll(): void
    {
        foreach (self::$slots as $slot) {
            @\proc_terminate($slot['proc']);
            @\proc_close($slot['proc']);
        }
        self::$slots = [];
        self::$redirectPorts = [];
        self::$argvOverride = null;
    }

    /**
     * The child argv: this program's own entry point, re-run headless for
     * one verb. `$_SERVER['argv'][0]` covers a checkout's bin script and a
     * shipped phar alike (php runs both by path); a CLI whose argv0 names
     * only a basename is resolved on PATH. Null when neither works — the
     * honest fallback is the guidance row, not a guessed command.
     *
     * @param list<string> $rest
     * @return ?list<string>
     */
    private static function cliArgv(string $server, array $rest): ?array
    {
        $self = (string) ($_SERVER['argv'][0] ?? '');
        $entry = $self !== '' && \is_file($self) ? $self : ProcessContainment::locateOnPath('candy-code');
        if ($entry === '') {
            return null;
        }

        return [\PHP_BINARY, $entry, 'mcp', 'auth', 'login', $server, ...array_values($rest)];
    }

    /**
     * The Cmd Chat arms after every beat but the last: one poll per second
     * on the shared loop, silent while the child is quiet. The tick is the
     * only sleeper — the TUI keeps painting and taking keys throughout the
     * browser leg, which was the entire point of forking.
     */
    public static function pollCmd(string $server): \Closure
    {
        return Cmd::tick(1.0, static fn (): ?Msg => self::poll($server));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Code\Lang;
use SugarCraft\Code\McpLoginProgressMsg;

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
     * Test seam: replaces the argv handed to the child. Production never
     * sets it; a test may point the slot at a stub script so the whole
     * announce/poll/exit chain runs without OAuth.
     *
     * @var ?\Closure(string $server, list<string> $rest): list<string>
     */
    public static ?\Closure $argvOverride = null;

    /**
     * @var array<string, array{proc: mixed, out: mixed, err: mixed, tail: string, urlSent: bool}>
     */
    private static array $slots = [];

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
     * @param list<string> $rest  the trailing CLI arguments as typed ([token-url] …)
     * @return array{0: string, 1: bool} [transcript notice, armed]
     */
    public static function arm(string $server, array $rest, string $cwd = ''): array
    {
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
        self::$slots[$server] = ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2], 'tail' => '', 'urlSent' => false];

        return [Lang::t('cmd.mcp-auth.login-armed', ['server' => $server]), true];
    }

    /**
     * One non-blocking look at the child: drain output, judge the beat.
     * Never returns null while a slot lives — {@see Cmd::tick()} is a
     * ONE-SHOT timer, so silence answers as a `waiting` beat that Chat uses
     * to re-arm the next look. A `gone` beat when the slot is absent is
     * Chat's cue to stop re-arming without printing anything.
     */
    public static function poll(string $server): ?Msg
    {
        if (!isset(self::$slots[$server])) {
            return new McpLoginProgressMsg($server, McpLoginProgressMsg::PHASE_GONE);
        }

        $slot = &self::$slots[$server];
        foreach (['out', 'err'] as $pipe) {
            $chunk = \fread($slot[$pipe], 65536);
            if (\is_string($chunk) && $chunk !== '') {
                $slot['tail'] = \substr($slot['tail'] . $chunk, -self::TAIL_MAX_BYTES);
            }
        }

        if (!$slot['urlSent'] && \preg_match('~https://\S+|http://\S+~', $slot['tail'], $m) === 1) {
            $slot['urlSent'] = true;
            $url = $m[0];

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
     * Tear every live slot down: bounded TERM then KILL, reaped. Test and
     * process-teardown hygiene, never the login path itself — a child left
     * holding the loopback port would poison the next login attempt.
     */
    public static function releaseAll(): void
    {
        foreach (self::$slots as $slot) {
            @\proc_terminate($slot['proc']);
            @\proc_close($slot['proc']);
        }
        self::$slots = [];
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

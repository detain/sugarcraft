<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use SugarCraft\Code\App\McpLoginProgressMsg;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\McpLoginFork;

/**
 * C-MCP-4.c: the watches behind a SERVED `mcp auth login <server>`.
 *
 * The TUI arm needs no class like this: {@see McpLoginFork} beats ride a
 * {@see \SugarCraft\Code\Support\McpLoginFork::pollCmd()} subscription inside
 * the one Chat that owns the terminal, so the announcing session is obvious.
 * Under `candy-code serve` it is not — the command arrives from whichever
 * client typed it, the fork is process-static, and the follow-up beats
 * (authorize URL, closing row) have no subscription to reach anyone. This
 * class records the pairing: server name to the session id that armed it.
 *
 * {@see \SugarCraft\Code\Protocol\ServerContext::pump()} then asks
 * {@see pollDue()} once per pump and routes each row into that session's
 * transcript through {@see SessionHost::appendNotice()}. If the session
 * closed while its login was running the watch retires silently — the child
 * still finishes and stores (or does not), but the rows have nowhere to go.
 *
 * State lives in process statics for the same reason the fork keeps its
 * slots there: a live child must be polled from exactly one place, and the
 * hub hands out SessionHost values that must not carry process handles.
 */
final class ServedMcpLogins
{
    /**
     * @var array<string, string> server name => session id whose transcript the beats belong to
     */
    private static array $watched = [];

    private function __construct()
    {
    }

    /**
     * Take over announcing $server's progress into $sessionId's transcript.
     *
     * Called only after {@see McpLoginFork::arm()} answered armed; a session
     * less (CLI `--` invocations, or a context without one) has no transcript
     * to append to, and nothing is registered.
     */
    public static function register(string $server, ?string $sessionId): void
    {
        if ($sessionId === null || $sessionId === '') {
            return;
        }

        self::$watched[$server] = $sessionId;
    }

    /**
     * Ask every live watch, at most once each, whether anything is due.
     *
     * The rows mirror Chat's own beat translation one-for-one — `url`
     * announces the browser link, `done` closes with the exit status and the
     * honest next-launch sentence — so the served transcript reads exactly
     * like the local TUI's. `waiting` stays silent, and `gone` (a slot torn
     * down under the watch, e.g. by the test-hygiene release) retires the
     * watch without printing, matching Chat's silence on the same beat.
     *
     * @return list<array{0: string, 1: string}> [session id, transcript row] pairs, due order
     */
    public static function pollDue(): array
    {
        $rows = [];

        foreach (self::$watched as $server => $sessionId) {
            $beat = McpLoginFork::poll($server);
            if ($beat === null) {
                continue;
            }

            if ($beat->phase === McpLoginProgressMsg::PHASE_URL) {
                $rows[] = [$sessionId, Lang::t('cmd.mcp-auth.login-url', ['server' => $server, 'url' => $beat->text])];

                continue;
            }

            if ($beat->phase === McpLoginProgressMsg::PHASE_DONE) {
                $rows[] = [$sessionId, Lang::t($beat->exitCode === 0 ? 'cmd.mcp-auth.login-done' : 'cmd.mcp-auth.login-failed', [
                    'server' => $server,
                    'code' => (string) $beat->exitCode,
                ])];
                unset(self::$watched[$server]);

                continue;
            }

            if ($beat->phase === McpLoginProgressMsg::PHASE_GONE) {
                unset(self::$watched[$server]);
            }
        }

        return $rows;
    }

    /**
     * Drop every watch — test hygiene only. The same rule the fork's own
     * release method documents for its slots applies here: production never
     * calls it, and a login still running when the process goes is
     * deliberately left to finish.
     */
    public static function clear(): void
    {
        self::$watched = [];
    }
}

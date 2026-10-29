<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\McpAuthCommand;
use SugarCraft\Code\Host\ServedMcpLogins;
use SugarCraft\Code\MCP\McpAuthStore;
use SugarCraft\Code\Support\McpLoginFork;

/**
 * `/mcp` (and its legacy bare `mcp auth …` spelling) — MCP server OAuth
 * credentials and the project inventory panel; the session half of
 * {@see McpAuthCommand} (roadmap O-2h).
 *
 * Both spellings reach this with the WHOLE draft, and {@see arguments()}
 * reduces either to the same argv.
 */
final class McpAuthHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $argv = self::arguments($text);
        $login = self::interceptLogin($context, $text, $argv);
        if ($login !== null) {
            return $login;
        }

        ob_start();
        (new McpAuthCommand(McpAuthStore::create()))->execute($context, $argv);
        $output = (string) ob_get_clean();

        return CommandResult::reply($text, $output);
    }

    /**
     * C-MCP-4.c: `login <server>` on a served session forks the real login
     * exactly like the TUI arm, and registers a watch so the follow-up
     * beats — the authorize URL, then the closing row — reach THIS
     * session's transcript through {@see \SugarCraft\Code\Protocol\ServerContext::pump()}
     * and {@see ServedMcpLogins}. A remote browser finishes the leg; the
     * paste-back form `login <server> --code <callback-url>` rides the same
     * intercept into {@see McpLoginFork::arm()}'s correlation leg.
     *
     * The bare `login` spelling and the refusals (`login --anything`) are
     * NOT claimed here — they fall through to {@see McpAuthCommand}'s
     * guidance arm, whose DocFigure-pinned source this class must not move.
     *
     * @param list<string> $argv
     */
    private static function interceptLogin(CommandContext $context, string $text, array $argv): ?CommandResult
    {
        if (($argv[0] ?? null) !== 'login') {
            return null;
        }

        $server = (string) ($argv[1] ?? '');
        if ($server === '' || str_starts_with($server, '--')) {
            return null;
        }

        [$notice, $armed] = McpLoginFork::arm($server, array_slice($argv, 2), $context->projectRoot());
        if ($armed) {
            ServedMcpLogins::register($server, $context->currentSessionId());
        }

        return CommandResult::reply($text, $notice);
    }

    /**
     * Split an MCP command line into {@see McpAuthCommand::execute()}'s argv.
     *
     * Both spellings reduce to the same argv: the leading command word is
     * dropped whether it is written `/mcp`, `/mcp:` or `mcp`, and the `auth`
     * noun the bare form spells out is optional under the slash form —
     * `/mcp list` and `mcp auth list` are the same command. The slash form goes
     * through {@see CommandText::tokens()}, so `/mcp:list` is `/mcp list`
     * (audit 15b-24); the bare form has no colon spelling to honour.
     *
     * @return list<string>
     */
    public static function arguments(string $text): array
    {
        $tokens = str_starts_with(ltrim($text), '/')
            ? CommandText::tokens($text)
            : (preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if (isset($tokens[0]) && ltrim($tokens[0], '/') === 'mcp') {
            array_shift($tokens);
        }

        if (($tokens[0] ?? null) === 'auth') {
            array_shift($tokens);
        }

        return array_values($tokens);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Commands\McpAuthCommand;
use SugarCraft\Crush\MCP\McpAuthStore;

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
        ob_start();
        (new McpAuthCommand(McpAuthStore::create()))->execute($context, self::arguments($text));
        $output = (string) ob_get_clean();

        return CommandResult::reply($text, $output);
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

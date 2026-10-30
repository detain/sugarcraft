<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Backend\QueueMode;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\McpPromptCommands;
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;

/**
 * Slash commands over the wire (Appendix O §6.3 "command").
 *
 * `command.list` names every command a session knows — the built-ins and the
 * session's command ROWS: the workspace's command FILES plus the launch-frozen
 * MCP prompt rows (C-MCP-2.b), each listed under its own `source` — and says
 * where each runs. A command file or prompt row is a prompt template, so
 * `command.exec` runs it exactly as typing `/name args` would: expanded by the
 * session's host (whose map is the one listing answer), then admitted like
 * `session.send`. A built-in with a host body (roadmap O-2h, `Host\Commands`) runs on the
 * server through {@see \SugarCraft\Code\Host\SessionHost::runCommand()}
 * and answers its `CommandResult` rows and effects; it is listed
 * `runsIn: "server"`. The rest are screen-only (`/theme`, the pickers) or
 * still Chat's, listed `runsIn: "client"` and refused `-32030` (`ui_only`)
 * rather than half-run.
 */
final class CommandMethods
{
    public const RUNS_IN_SERVER = 'server';
    public const RUNS_IN_CLIENT = 'client';

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('command.list', Scope::Read, 'The slash commands a session knows, and where each runs.', self::list(...)));
        $registry->add(MethodSpec::new('command.exec', Scope::Write, 'Run a slash command in a session (command files, and the built-ins that run headless).', self::exec(...), true));
    }

    /** @return array<string, mixed> */
    private static function list(CallContext $call, Params $params): array
    {
        // C-MCP-2.b: the listing reads the session host's command map — the
        // very table submit() resolves — so served MCP prompt rows appear
        // (source `mcp`) and a row can never be listed that exec would refuse.
        $host = $call->host(SessionMethods::sessionId($params));
        $items = [];
        foreach (CommandRegistry::all() as $spec) {
            if ($spec->slashVisible) {
                $items[$spec->name] = self::describe($spec, 'builtin', self::runsIn($spec->name));
            }
        }
        foreach ($host->customCommands() as $name => $spec) {
            $source = McpPromptCommands::isMcpPrompt($spec) ? 'mcp' : 'file';
            $items[(string) $name] = self::describe($spec, $source, self::RUNS_IN_SERVER);
        }
        // C-WEB-2.d (GAP-SKL-06, menu half): the session's user-invocable
        // skills join the roster as `source: "skill"` rows. A skill is not a
        // command — `command.exec` never runs one — and selecting its row is
        // the client sending the typed `/name args` over `session.send`,
        // which the host rewrites through the SAME gate the TUI's slash door
        // passes ({@see SkillSlashInvocation::draft()}, C-WEB-2.b). So the
        // listing rule is "list exactly what admission would dispatch": a
        // name a built-in or a command file already claims is skipped (the
        // draft veto below is what makes that claim the admission's own,
        // including the gate's case-insensitive built-in re-check), and a
        // disabled or non-user-invocable skill never reaches the loop at all
        // (`getUserInvocable()`). An item the user cannot run is not shown,
        // and an advertised row can never be spoofed into an action its
        // spelling would not take when typed.
        $skills = $host->workspace()->skills;
        foreach ($skills?->getUserInvocable() ?? [] as $skill) {
            if (isset($items[$skill->name]) || \SugarCraft\Code\Skills\SkillSlashInvocation::draft('/' . $skill->name, $skills) === null) {
                continue;
            }
            $items[$skill->name] = [
                'name' => $skill->name,
                'description' => $skill->description,
                'argumentHint' => null,
                'source' => 'skill',
                'runsIn' => self::RUNS_IN_SERVER,
            ];
        }
        \ksort($items);

        return ['items' => \array_values($items)];
    }

    /** @return array<string, mixed> */
    private static function exec(CallContext $call, Params $params): array
    {
        if ($call->server->isDraining()) {
            throw RpcError::of(ErrorCode::Busy, 'the server is shutting down', 'draining');
        }
        $host = $call->host(SessionMethods::sessionId($params));
        $name = \ltrim($params->string('name', 128), '/');
        $args = \trim((string) ($params->optionalString('args', 65_536) ?? ''));

        $row = $host->customCommands()[$name] ?? null;
        if ($row === null) {
            foreach (CommandRegistry::all() as $spec) {
                if ($spec->name === $name) {
                    return self::runBuiltIn($host, $name, $args);
                }
            }

            throw RpcError::notFound(\sprintf('no command /%s', $name), 'command_not_found');
        }

        $ticket = TurnMethods::admit($call, $host, '/' . $name . ($args === '' ? '' : ' ' . $args), QueueMode::Followup, $params->optionalString('idempotencyKey', 64));

        return ['rows' => [], 'effects' => [], ...$ticket];
    }

    /**
     * A built-in, run by the session's host: its rows and effects, or the
     * reason it did not run — `-32030` (`ui_only`) for a screen-only one,
     * `-32009` (`command_refused`) when a turn holds the session.
     *
     * @return array<string, mixed>
     */
    private static function runBuiltIn(\SugarCraft\Code\Host\SessionHost $host, string $name, string $args): array
    {
        $result = $host->runCommand($name, $args);
        if ($result->isClientOnly()) {
            throw RpcError::of(
                ErrorCode::UnsupportedInServer,
                \sprintf('/%s runs in the terminal UI only', $name),
                'ui_only',
                ['command' => $name],
            );
        }
        if ($result->isRefused()) {
            throw RpcError::of(ErrorCode::Conflict, (string) $result->error, 'command_refused', ['command' => $name]);
        }

        return $result->toArray();
    }

    /** Where built-in $name runs: on the server when it has a host body. */
    private static function runsIn(string $name): string
    {
        return BuiltInCommands::forSpelling($name)?->hostCommand !== null ? self::RUNS_IN_SERVER : self::RUNS_IN_CLIENT;
    }

    /**
     * One `command.list` row. C-MCP-2.b retired the loader-side listing seam
     * (`files()`) that lived above this: both list and exec answer through the
     * session host's merged command map now, so rows and admission agree.
     *
     * @return array<string, mixed>
     */
    private static function describe(CommandSpec $spec, string $source, string $runsIn): array
    {
        return [
            'name' => $spec->name,
            'description' => $spec->description,
            'argumentHint' => $spec->argumentHint,
            'source' => $source,
            'runsIn' => $runsIn,
        ];
    }
}

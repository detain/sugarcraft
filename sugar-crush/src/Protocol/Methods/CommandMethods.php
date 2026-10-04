<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Backend\QueueMode;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommands;
use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;

/**
 * Slash commands over the wire (Appendix O §6.3 "command").
 *
 * `command.list` names every command a session knows — the built-ins and the
 * workspace's command FILES — and says where each runs. A command file is a
 * prompt template, so `command.exec` runs it exactly as typing `/name args`
 * would: expanded by the session's host, then admitted like `session.send`.
 * A built-in with a host body (roadmap O-2h, `Host\Commands`) runs on the
 * server through {@see \SugarCraft\Crush\Host\SessionHost::runCommand()}
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
        $items = [];
        foreach (CommandRegistry::all() as $spec) {
            if ($spec->slashVisible) {
                $items[$spec->name] = self::describe($spec, 'builtin', self::runsIn($spec->name));
            }
        }
        foreach (self::files($call) as $name => $spec) {
            $items[$name] = self::describe($spec, 'file', self::RUNS_IN_SERVER);
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

        $file = self::files($call)[$name] ?? null;
        if ($file === null) {
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
    private static function runBuiltIn(\SugarCraft\Crush\Host\SessionHost $host, string $name, string $args): array
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
     * The workspace's command files, by name.
     *
     * @return array<string, CommandSpec>
     */
    private static function files(CallContext $call): array
    {
        $workspace = $call->server->hub()->workspace();
        $files = [];
        foreach ($workspace->commandLoader?->loadAll($workspace->root ?? (\getcwd() ?: '.')) ?? [] as $name => $spec) {
            if ($spec instanceof CommandSpec && $spec->isFileBased()) {
                $files[(string) $name] = $spec;
            }
        }

        return $files;
    }

    /** @return array<string, mixed> */
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

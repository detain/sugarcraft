<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Backend\QueueMode;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
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
 * The built-ins are TUI handlers today; until they move to the host
 * (roadmap O-2h, `Host\Commands`) they are listed with `runsIn: "client"`
 * and refused `-32030` (`unsupported_in_server`) rather than half-run.
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
        $registry->add(MethodSpec::new('command.exec', Scope::Write, 'Run a slash command in a session (command files; built-ins once they run headless).', self::exec(...), true));
    }

    /** @return array<string, mixed> */
    private static function list(CallContext $call, Params $params): array
    {
        $items = [];
        foreach (CommandRegistry::all() as $spec) {
            if ($spec->slashVisible) {
                $items[$spec->name] = self::describe($spec, 'builtin', self::RUNS_IN_CLIENT);
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
                    throw RpcError::of(
                        ErrorCode::UnsupportedInServer,
                        \sprintf('/%s runs in the terminal UI only for now', $name),
                        'ui_only',
                        ['command' => $name],
                    );
                }
            }

            throw RpcError::notFound(\sprintf('no command /%s', $name), 'command_not_found');
        }

        $ticket = TurnMethods::admit($call, $host, '/' . $name . ($args === '' ? '' : ' ' . $args), QueueMode::Followup, $params->optionalString('idempotencyKey', 64));

        return ['rows' => [], 'effects' => [], ...$ticket];
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

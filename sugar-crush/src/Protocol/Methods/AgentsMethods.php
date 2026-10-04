<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\Scope;

/**
 * `agents.*` (Appendix O §6.3): the agent roster, and the live tree of the
 * sub-agents a session's turn has delegated to — what the `subagent.*`
 * events have said so far, for a client that attached after they started.
 */
final class AgentsMethods
{
    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('agents.list', Scope::Read, 'The agents a turn can delegate to.', self::list(...)));
        $registry->add(MethodSpec::new('agents.subtree', Scope::Read, 'The sub-agents a session\'s turns have delegated to, with their latest activity.', self::subtree(...)));
    }

    /** @return array<string, mixed> */
    private static function list(CallContext $call, Params $params): array
    {
        $agents = $call->server->hub()->workspace()->agentManager?->all() ?? [];

        return ['items' => \array_values(\array_map(static fn (Agent $agent): array => [
            'name' => $agent->name,
            'description' => $agent->description,
            'model' => $agent->model,
            'provider' => $agent->provider,
            'permissionMode' => $agent->permissionMode->value,
            'active' => $agent->isActive,
        ], $agents))];
    }

    /** @return array<string, mixed> */
    private static function subtree(CallContext $call, Params $params): array
    {
        $host = $call->host(SessionMethods::sessionId($params));

        return ['items' => $call->feed($host)->subagents()];
    }
}

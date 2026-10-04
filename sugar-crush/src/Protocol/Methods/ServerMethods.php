<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\Dispatcher;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\EventType;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;
use SugarCraft\Crush\Protocol\SessionFeed;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Server\Ws\Outbox;
use SugarCraft\Crush\Tools\Tool;

/**
 * `server.*` and `client.*` (Appendix O §6.2, §6.3): the handshake, health,
 * what this server offers, shutting it down, and what a client is looking at.
 */
final class ServerMethods
{
    public const HELLO = 'server.hello';

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new(self::HELLO, Scope::Read, 'The handshake: protocol version, features, limits; optionally resume subscriptions.', self::hello(...)));
        $registry->add(MethodSpec::new('server.health', Scope::Read, 'Liveness and load.', self::health(...)));
        $registry->add(MethodSpec::new('server.info', Scope::Read, 'What this server offers: providers, agents, commands, tools, permission modes.', self::info(...)));
        $registry->add(MethodSpec::new('server.shutdown', Scope::Admin, 'Stop the server, draining running turns first.', self::shutdown(...), true));
        $registry->add(MethodSpec::new('client.viewing', Scope::Read, 'Say which sessions this client shows, and which is in front.', self::viewing(...)));
    }

    /** @return array<string, mixed> */
    private static function hello(CallContext $call, Params $params): array
    {
        if ($call->client->isInitialized()) {
            throw RpcError::of(ErrorCode::Conflict, 'server.hello was already answered on this connection', 'already_initialized');
        }
        $min = $params->int('minProtocol', Dispatcher::PROTOCOL, 0);
        $max = $params->int('maxProtocol', Dispatcher::PROTOCOL, 0);
        if ($min > Dispatcher::PROTOCOL || $max < Dispatcher::PROTOCOL) {
            throw RpcError::of(ErrorCode::InvalidRequest, \sprintf('this server speaks protocol %d', Dispatcher::PROTOCOL), 'unsupported_protocol', [
                'protocol' => Dispatcher::PROTOCOL,
            ]);
        }
        $info = $params->object('client');
        $caps = $params->strings('caps', 64);
        $resume = $params->object('resume');

        $call->client->initialize($info, $caps);

        $resumed = [];
        foreach ($resume as $sessionId => $afterSeq) {
            $sessionId = (string) $sessionId;
            if (!\is_int($afterSeq) || $afterSeq < 0 || \count($resumed) >= SessionFeed::MAX_SUBSCRIBERS) {
                $resumed[$sessionId] = ['error' => 'invalid_params'];

                continue;
            }
            try {
                $host = $call->host($sessionId);
                $resumed[$sessionId] = $call->feed($host)->subscribe($call->client, $afterSeq);
            } catch (RpcError $e) {
                $resumed[$sessionId] = ['error' => $e->kind];
            }
        }

        $config = $call->server->config();
        $workspace = $call->server->hub()->workspace();

        return [
            'protocol' => Dispatcher::PROTOCOL,
            'server' => [
                'version' => $call->server->version(),
                'connectionId' => $call->client->id(),
                'root' => $config->root ?? $workspace->root,
                'pid' => \getmypid(),
            ],
            'features' => [
                'methods' => $call->methods->names(),
                'events' => EventType::all(),
            ],
            'limits' => [
                'maxClientFrameBytes' => ServerConfig::MAX_CLIENT_MESSAGE_BYTES,
                'maxServerFrameBytes' => Dispatcher::MAX_SERVER_FRAME_BYTES,
                'maxInflight' => Dispatcher::MAX_INFLIGHT,
                'tickIntervalMs' => (int) (Dispatcher::TICK_INTERVAL_SECONDS * 1000),
                'softBufferedBytes' => Outbox::SOFT_WATERMARK_BYTES,
                'maxBufferedBytes' => Outbox::HARD_WATERMARK_BYTES,
                'maxSubscribersPerSession' => SessionFeed::MAX_SUBSCRIBERS,
            ],
            'principal' => [
                'kind' => 'owner',
                'via' => $call->client->principal(),
                'scopes' => $call->client->scopes(),
            ],
            'defaults' => [
                'permissionMode' => $config->permissionMode->value,
            ],
            'resumed' => $resumed === [] ? new \stdClass() : $resumed,
        ];
    }

    /** @return array<string, mixed> */
    private static function health(CallContext $call, Params $params): array
    {
        return [
            'ok' => true,
            'version' => $call->server->version(),
            'uptimeS' => $call->server->uptimeSeconds(),
            'sessionsOpen' => \count($call->server->hub()->openSessionIds()),
            'turnsRunning' => $call->server->turnsRunning(),
            'draining' => $call->server->isDraining(),
        ];
    }

    /** @return array<string, mixed> */
    private static function info(CallContext $call, Params $params): array
    {
        $config = $call->server->config();
        $workspace = $call->server->hub()->workspace();

        $providers = [];
        try {
            $providers = \array_keys(Bootstrap::availableProviders());
        } catch (\Throwable) {
            // A provider config that cannot be read lists none, not an error.
        }
        \sort($providers);

        $agents = \array_map(static fn (Agent $agent): array => [
            'name' => $agent->name,
            'description' => $agent->description,
        ], \array_values($workspace->agentManager?->all() ?? []));

        $tools = [];
        if ($workspace->backend instanceof EngineBackend) {
            $tools = \array_values(\array_map(static fn (Tool $tool): string => $tool->name(), $workspace->backend->tools()));
            \sort($tools);
        }

        $commands = [];
        foreach (CommandRegistry::all() as $spec) {
            $commands[] = $spec->name;
        }

        return [
            'version' => $call->server->version(),
            'protocol' => Dispatcher::PROTOCOL,
            'root' => $config->root ?? $workspace->root,
            'providers' => $providers,
            'agents' => $agents,
            'tools' => $tools,
            'commands' => $commands,
            'permissionModes' => \array_values(\array_map(
                static fn (PermissionMode $mode): string => $mode->value,
                \array_filter(PermissionMode::cases(), $config->admitsPermissionMode(...)),
            )),
            'defaultPermissionMode' => $config->permissionMode->value,
            'limits' => [
                'maxOpenSessions' => $config->maxOpenSessions,
                'maxConcurrentTurns' => $config->maxConcurrentTurns,
                'askTimeoutSeconds' => $config->askTimeoutSeconds,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function shutdown(CallContext $call, Params $params): array
    {
        $drain = $params->raw('drainSeconds');
        if ($drain !== null && ((!\is_int($drain) && !\is_float($drain)) || $drain < 0)) {
            throw RpcError::invalidParams('drainSeconds must be a number of seconds, 0 or more');
        }
        $accepted = $call->server->shutdown($drain === null ? null : (float) $drain);

        return ['accepted' => $accepted];
    }

    /** @return array<string, mixed> */
    private static function viewing(CallContext $call, Params $params): array
    {
        $call->client->view($params->strings('sessionIds', SessionFeed::MAX_SUBSCRIBERS), $params->optionalString('foreground', 64));

        return [];
    }
}

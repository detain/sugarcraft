<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Host\SessionSnapshot;
use SugarCraft\Code\Media\Capability\MediaAffordance;
use SugarCraft\Code\Backend\QueueMode;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\SessionFeed;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Ws\Outbox;
use SugarCraft\Code\Tools\Tool;

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
        $registry->add(MethodSpec::new(self::HELLO, Scope::Read, 'The handshake: protocol version, features, limits; optionally resume subscriptions (snapshotLimit pages their reset snapshots).', self::hello(...)));
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
        // ISS-058: a resumed session whose cursor the log can no longer serve
        // resets from a snapshot; page it so one huge transcript cannot make
        // the whole hello answer outgrow a server frame.
        $snapshotLimit = $params->optionalInt('snapshotLimit', 1, SessionSnapshot::MAX_PAGE_ROWS);

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
                $resumed[$sessionId] = $call->feed($host)->subscribe($call->client, $afterSeq, SessionFeed::MODE_FULL, $snapshotLimit);
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
                // Whether the new-session directory picker may be offered:
                // `fs.listDirs` and a browse-rooted `workspace.open` answer.
                'dirBrowse' => [
                    'enabled' => $config->dirBrowse && $config->browseRoot !== null,
                    'root' => $config->dirBrowse ? $config->browseRoot : null,
                ],
                // crush-media W6.7: whether media generation may be offered,
                // derived from config ∩ capability doors at boot — this read
                // dials nothing (plan :844) and the host rides hashed (:839).
                'media' => MediaAffordance::current(),
            ],
            'limits' => [
                'maxClientFrameBytes' => ServerConfig::MAX_CLIENT_FRAME_BYTES,
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
                // What a prompt sent mid-turn does when the client picks
                // nothing: the `queueMode` setting, as the TUI's Enter reads
                // it (decision D6, `steer` unless set), in the wire's words.
                'delivery' => self::defaultDelivery(),
            ],
            'resumed' => $resumed === [] ? new \stdClass() : $resumed,
        ];
    }

    /**
     * The `queueMode` setting ({@see QueueMode::onEnter()}) as a
     * `session.send` delivery ({@see TurnMethods::DELIVERY}), so a client's
     * composer defaults to what the terminal's Enter does mid-turn.
     */
    public static function defaultDelivery(): string
    {
        $delivery = \array_search(QueueMode::onEnter(), TurnMethods::DELIVERY, true);

        return \is_string($delivery) ? $delivery : 'steer';
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

    /**
     * What the client shows (Appendix O §4.5, §6.9). With `narrate`, every
     * session it follows but does not have in front is heard as narration —
     * the multi-pane grid's glanced-at tiles — and a session that comes to the
     * front is handed the reply streamed so far at once, so its deltas pick up
     * without a gap.
     *
     * @return array<string, mixed>
     */
    private static function viewing(CallContext $call, Params $params): array
    {
        $narrate = $params->raw('narrate');
        if ($narrate !== null && !\is_bool($narrate)) {
            throw RpcError::invalidParams('narrate must be true or false');
        }

        $feeds = $call->server->followedBy($call->client);
        $before = [];
        foreach ($feeds as $feed) {
            $before[$feed->sessionId()] = $feed->isNarratedFor($call->client);
        }

        $call->client->view(
            $params->strings('sessionIds', SessionFeed::MAX_SUBSCRIBERS),
            $params->optionalString('foreground', 64),
            $narrate === true,
        );

        $narrated = [];
        foreach ($feeds as $feed) {
            if ($feed->isNarratedFor($call->client)) {
                $narrated[] = $feed->sessionId();
            } elseif ($before[$feed->sessionId()]) {
                $feed->catchUp($call->client);
            }
        }

        return ['narrated' => $narrated];
    }
}

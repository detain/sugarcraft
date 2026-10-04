<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Host\BackgroundEvents;
use SugarCraft\Crush\Host\Commands\BackgroundCommand;
use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundStopOutcome;

/**
 * `bg.*` (Appendix O §6.3): the workspace's background (`/bg`) sessions —
 * list them, start one, read one's output from an offset, stop one, and send
 * a settled one's answer into a session.
 *
 * Every method reads the server's {@see BackgroundEvents}, so `bg.list`
 * includes the sessions the server re-adopted at boot and the ones that have
 * already settled (the supervisor itself only enumerates active ones), and a
 * session `bg.spawn` starts is announced (`bg.started`) before the answer.
 *
 * `bg.spawn` is code execution, so it needs scope `write` like `session.send`
 * (§8.5). It answers once the daemon has authenticated its handshake — the
 * supervisor waits at most a few seconds for that, the same bounded wait
 * `bg.stop` makes for a stop.
 *
 * `bg.inject` closes the gap Appendix A names ("`/bg` results never land back
 * in chat") for a client: the settled session's report — the one the TUI
 * queues for its agent ({@see BackgroundSession::announcement()}) — is sent to
 * a session like any prompt, through `session.send`'s admission, with its
 * `@path` tokens left as text: they are the background model's, not a request
 * to attach a file.
 */
final class BgMethods
{
    private const MAX_OUTPUT_BYTES = 262_144;

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('bg.list', Scope::Read, 'The background sessions this server supervises, running and settled.', self::list(...)));
        $registry->add(MethodSpec::new('bg.spawn', Scope::Write, 'Start a background session on a task, as /bg does.', self::spawn(...), true));
        $registry->add(MethodSpec::new('bg.output', Scope::Read, 'A background session\'s output from an offset.', self::output(...)));
        $registry->add(MethodSpec::new('bg.stop', Scope::Write, 'Stop a background session.', self::stop(...), true));
        $registry->add(MethodSpec::new('bg.inject', Scope::Write, 'Send a settled background session\'s result into a session as a prompt.', self::inject(...), true));
    }

    /** @return array<string, mixed> */
    private static function list(CallContext $call, Params $params): array
    {
        $events = $call->server->background();
        if ($events === null) {
            return ['items' => []];
        }

        return ['items' => \array_values(\array_map($events->summary(...), $events->sessions()))];
    }

    /** @return array<string, mixed> */
    private static function spawn(CallContext $call, Params $params): array
    {
        if ($call->server->isDraining()) {
            throw RpcError::of(ErrorCode::Busy, 'the server is shutting down', 'draining');
        }
        $events = self::events($call);
        $task = \trim($params->string('task', ServerConfig::MAX_CLIENT_MESSAGE_BYTES));
        if ($task === '') {
            throw RpcError::invalidParams('invalid params: task is empty');
        }
        $agent = self::agent($call, $params->optionalString('agent', 128));
        $name = BackgroundCommand::sessionName($params->optionalString('name', 256) ?? $task);
        $root = $call->server->hub()->workspace()->root;

        try {
            $session = $events->supervisor()->spawnSession(
                name: $name,
                agent: $agent,
                task: $task,
                workingDirectory: $root !== null && $root !== '' ? $root : '.',
            );
        } catch (\Throwable) {
            // The cause can name a private path; it stays out of the answer.
            throw RpcError::of(ErrorCode::Internal, 'the background session could not be started', 'bg_spawn_failed');
        }
        $events->track($session);

        return $events->summary($session);
    }

    /** @return array<string, mixed> */
    private static function output(CallContext $call, Params $params): array
    {
        $session = self::session($call, $params->string('bgId', 128));
        $offset = $params->int('offset', 0, 0);
        $limit = $params->int('limit', self::MAX_OUTPUT_BYTES, 1, self::MAX_OUTPUT_BYTES);
        $text = \substr($session->output, $offset, $limit);

        return [
            'bgId' => $session->id,
            'offset' => $offset,
            'text' => \mb_strcut($text, 0, \strlen($text), 'UTF-8'),
            'total' => \strlen($session->output),
            'status' => $session->status->value,
        ];
    }

    /** @return array<string, mixed> */
    private static function stop(CallContext $call, Params $params): array
    {
        $bgId = $params->string('bgId', 128);
        $outcome = self::events($call)->supervisor()->stopSession($bgId);
        if ($outcome === BackgroundStopOutcome::UnknownSession) {
            throw RpcError::notFound(\sprintf('no background session %s', $bgId), 'bg_not_found');
        }

        return ['bgId' => $bgId, 'outcome' => $outcome->value];
    }

    /** @return array<string, mixed> */
    private static function inject(CallContext $call, Params $params): array
    {
        if ($call->server->isDraining()) {
            throw RpcError::of(ErrorCode::Busy, 'the server is shutting down', 'draining');
        }
        $session = self::session($call, $params->string('bgId', 128));
        if (!$session->isSettled()) {
            throw RpcError::of(ErrorCode::Conflict, \sprintf('background session %s is still %s', $session->id, $session->status->value), 'bg_not_settled');
        }
        $host = $call->host(SessionMethods::sessionId($params));
        $delivery = TurnMethods::DELIVERY[$params->enum('delivery', \array_keys(TurnMethods::DELIVERY), 'queue')];
        $key = $params->optionalString('idempotencyKey', 64);

        return ['bgId' => $session->id, 'sessionId' => $host->sessionId()]
            + TurnMethods::admit($call, $host, $session->announcement(), $delivery, $key, false);
    }

    /**
     * The agent a spawned session runs as: the one named, else what `/bg`
     * picks — a registered "default", then whatever is registered, then a
     * stand-in.
     */
    private static function agent(CallContext $call, ?string $name): Agent
    {
        $manager = $call->server->hub()->workspace()->agentManager;
        if ($name !== null) {
            return $manager?->get($name) ?? throw RpcError::notFound(\sprintf('no agent %s', $name), 'agent_not_found');
        }

        return $manager?->get('default')
            ?? ($manager?->all()[0] ?? null)
            ?? BackgroundCommand::defaultAgent();
    }

    private static function session(CallContext $call, string $bgId): BackgroundSession
    {
        return self::events($call)->supervisor()->getSession($bgId)
            ?? throw RpcError::notFound(\sprintf('no background session %s', $bgId), 'bg_not_found');
    }

    private static function events(CallContext $call): BackgroundEvents
    {
        return $call->server->background()
            ?? throw RpcError::of(ErrorCode::UnsupportedInServer, 'this workspace supervises no background sessions', 'bg_unavailable');
    }
}

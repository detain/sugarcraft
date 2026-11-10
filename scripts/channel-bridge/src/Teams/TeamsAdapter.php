<?php

declare(strict_types=1);

namespace ChannelBridge\Teams;

use ChannelBridge\Bridge;
use ChannelBridge\ChannelInterface;
use ChannelBridge\Keys;
use ChannelBridge\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;

/**
 * Teams side (§8): the inbound webhook receiver AND the ChannelInterface
 * outbound. One class because both halves speak "activity" and the reply
 * address is the inbound's conversationReference.
 *
 * The webhook listens on the DAEMON's own HTTP port (same loop as the
 * serve client; `bridge.http` in the config). Public reachability is the
 * OPERATOR's tunnel job (§8.2: "public reachability via operator tunnel —
 * document, do not build"): the bot's messaging endpoint in the Teams
 * app registration points at `https://<tunnel>/api/messages`. The bridge
 * binds loopback by default; a non-loopback bind is a config decision the
 * operator owns, and the JWT door is what makes it survivable.
 *
 * Activities in, in order: bearer JWT validated (§8.2) → type gate (only
 * `message` and `invoke` are conversation; `conversationUpdate` and friends
 * are traffic noise) → policy door (§8.3: GROUP CHATS ARE BLOCKED BY
 * DEFAULT; personal DMs follow the dmScope ladder) → bot-loop guard →
 * §9.1 key → prompt or approval answer.
 *
 * MUTABLE ON PURPOSE: it holds the listening socket.
 */
final class TeamsAdapter implements ChannelInterface
{
    private ?SocketServer $socket = null;

    public function __construct(
        private readonly string $account,
        private readonly ConnectorApi $connector,
        private readonly JwtValidator $jwt,
        private readonly Bridge $bridge,
        private readonly array $cfg,
        private readonly Log $log,
        private readonly LoopInterface $loop,
    ) {
    }

    public function name(): string
    {
        return 'msteams';
    }

    public function textChunkLimit(): int
    {
        $value = $this->cfg['textLimit'] ?? 4000;

        return \is_int($value) && $value > 100 ? $value : 4000;
    }

    public function maxLinesPerMessage(): int
    {
        $value = $this->cfg['maxLines'] ?? 40;

        return \is_int($value) && $value > 0 ? $value : 40;
    }

    public function listen(): void
    {
        $host = (string) ($this->cfg['host'] ?? '127.0.0.1');
        $port = (int) ($this->cfg['port'] ?? 3978);
        $path = (string) ($this->cfg['path'] ?? '/api/messages');
        $handler = function (ServerRequestInterface $request) use ($path): PromiseInterface {
            return $this->handle($request, $path);
        };
        $server = new HttpServer($handler);
        $socket = new SocketServer($host . ':' . $port, [], $this->loop);
        $server->listen($socket);
        $this->socket = $socket;
        $this->log->info('teams_listening', ['address' => (string) $socket->getAddress(), 'path' => $path]);
    }

    public function stop(): void
    {
        $this->socket?->close();
        $this->socket = null;
    }

    public function deliver(array $ref, string $chunk): PromiseInterface
    {
        return $this->connector->sendActivity($ref, ['type' => 'message', 'text' => $chunk, 'summary' => \substr($chunk, 0, 80)]);
    }

    public function promptApproval(array $ref, string $sessionId, array $ask): void
    {
        $this->connector->sendActivity($ref, ConnectorApi::approvalCard($ask, self::approvalSummary($ask)))
            ->then(null, fn (\Throwable $e) => $this->log->warn('teams_approval_prompt_failed', ['error' => $e->getMessage()]));
    }

    public function retireApproval(string $askId, string $line): void
    {
        // Bot Framework cannot re-render a submitted card across clients
        // from here; the answer lands as its own activity, and the card's
        // buttons are single-fire by the first-valid-answer-wins law.
        $this->log->debug('teams_approval_resolved', ['ask' => $askId, 'line' => $line]);
    }

    public function typing(array $ref): void
    {
        $this->connector->sendActivity($ref, ['type' => 'typing'])
            ->then(null, static function (): void {
            });
    }

    // ── the webhook ─────────────────────────────────────────────────────

    private function handle(ServerRequestInterface $request, string $path): PromiseInterface
    {
        if ($request->getMethod() !== 'POST' || $request->getUri()->getPath() !== $path) {
            if ($request->getUri()->getPath() === '/healthz') {
                return \React\Promise\resolve(new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'));
            }

            return \React\Promise\resolve(new Response(404, ['Content-Type' => 'application/json'], '{"error":"not found"}'));
        }

        $auth = $request->getHeaderLine('Authorization');
        if (!\str_starts_with($auth, 'Bearer ')) {
            return \React\Promise\resolve(new Response(401, ['Content-Type' => 'application/json'], '{"error":"missing bearer"}'));
        }
        $claims = $this->jwt->validate(\substr($auth, 7));
        if ($claims === null) {
            $this->log->warn('teams_jwt_rejected');

            return \React\Promise\resolve(new Response(401, ['Content-Type' => 'application/json'], '{"error":"invalid token"}'));
        }

        $raw = (string) $request->getBody();
        if (\strlen($raw) > 419_4304) {
            return \React\Promise\resolve(new Response(413, ['Content-Type' => 'application/json'], '{"error":"too large"}'));
        }
        try {
            $activity = \json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return \React\Promise\resolve(new Response(400, ['Content-Type' => 'application/json'], '{"error":"bad json"}'));
        }
        if (!\is_array($activity)) {
            return \React\Promise\resolve(new Response(400, ['Content-Type' => 'application/json'], '{"error":"activity must be an object"}'));
        }

        try {
            $this->onActivity($activity);
        } catch (\Throwable $e) {
            // The activity is ACKED regardless (§8.2): Microsoft redelivers
            // a 5xx, and a redelivered prompt is a double prompt.
            $this->log->error('teams_activity_failed', ['error' => $e->getMessage()]);
        }

        return \React\Promise\resolve(new Response(200, ['Content-Type' => 'application/json'], '{}'));
    }

    /** @param array<string,mixed> $activity */
    private function onActivity(array $activity): void
    {
        $type = (string) ($activity['type'] ?? '');
        $conversationType = (string) ($activity['conversation']['conversationType'] ?? '');
        $from = \is_array($activity['from'] ?? null) ? $activity['from'] : [];
        $fromId = (string) ($from['id'] ?? '');
        $botId = (string) ($activity['recipient']['id'] ?? '');
        if ($fromId !== '' && $fromId === $botId) {
            return; // bot-loop guard: our own echo
        }
        $inboundId = (string) ($activity['id'] ?? '');

        $reference = self::referenceOf($activity);

        if ($type === 'invoke') {
            $this->onInvoke($activity, $reference);

            return;
        }
        if ($type !== 'message') {
            $this->log->debug('teams_activity_ignored', ['type' => $type]);

            return;
        }

        $isPersonal = $conversationType === 'personal';
        if (!$this->allowed($isPersonal, $reference)) {
            return;
        }

        $text = \trim((string) ($activity['text'] ?? ''));
        if ($text === '' && $isPersonal) {
            return;
        }
        $peerId = (string) ($activity['from']['aadObjectId'] ?? $fromId);
        $conversationId = (string) ($activity['conversation']['id'] ?? '');
        if ($conversationId === '') {
            return;
        }
        $key = $isPersonal
            ? Keys::dmKey('msteams', $this->account, $peerId, $conversationId, (string) ($this->cfg['dmScope'] ?? Keys::DM_PER_PEER))
            : Keys::channelKey('msteams', $this->account, $conversationId);

        if ($text === '') {
            return; // attachment-only activity: P4 work
        }
        $mention = $this->mentionedSelf($activity, $botId);
        $this->bridge->inbound('msteams', $key, $reference, $text, $mention, $inboundId !== '' ? 'msteams:' . $inboundId : null);
    }

    /** @param array<string,mixed> $activity */
    private function onInvoke(array $activity, array $reference): void
    {
        $name = (string) ($activity['name'] ?? '');
        $value = \is_array($activity['value'] ?? null) ? $activity['value'] : [];
        // Both card spellings: the legacy `cardAction.submit` and the
        // `adaptiveCard/action` invoke (the action payload sits one layer
        // deeper in the v2 shape).
        $data = \is_array($value['data'] ?? null) ? $value['data'] : $value;
        $askId = (string) ($data['askId'] ?? '');
        $verdict = (string) ($data['verdict'] ?? '');
        if ($askId === '' || !\in_array($verdict, ['once', 'always', 'reject'], true)) {
            $this->log->debug('teams_invoke_unmapped', ['name' => $name]);

            return;
        }
        $actorId = (string) ($activity['from']['userObjectId'] ?? $activity['from']['id'] ?? '');
        $this->bridge->answerByAskId($askId, $verdict, ['userId' => $actorId, 'name' => (string) ($activity['from']['name'] ?? $actorId)]);
    }

    /** @return array<string,mixed> */
    private static function referenceOf(array $activity): array
    {
        return [
            'serviceUrl' => (string) ($activity['serviceUrl'] ?? ''),
            'conversation' => \is_array($activity['conversation'] ?? null) ? $activity['conversation'] : [],
            'user' => \is_array($activity['from'] ?? null) ? $activity['from'] : [],
            'bot' => \is_array($activity['recipient'] ?? null) ? $activity['recipient'] : [],
        ];
    }

    /** @param array<string,mixed> $activity */
    private function mentionedSelf(array $activity, string $botId): bool
    {
        foreach ((array) ($activity['entities'] ?? []) as $entity) {
            if (\is_array($entity) && (string) ($entity['type'] ?? '') === 'mention') {
                $mentionedId = (string) ($entity['mentioned']['id'] ?? '');
                if ($mentionedId !== '' && $mentionedId === $botId) {
                    return true;
                }
            }
        }

        return $botId !== '' && \str_contains((string) ($activity['text'] ?? ''), '<at>' . (string) ($activity['recipient']['name'] ?? '') . '</at>');
    }

    /** The §8.3 policy door: personal by dmPolicy, group/channel blocked unless the roster opens it. */
    private function allowed(bool $isPersonal, array $reference): bool
    {
        $conversationId = (string) ($reference['conversation']['id'] ?? '');
        if ($isPersonal) {
            $dmPolicy = (string) ($this->cfg['dmPolicy'] ?? 'allowlist');
            if ($dmPolicy === 'open') {
                return true;
            }
            if ($dmPolicy === 'disabled') {
                return false;
            }
            $allow = \array_map('strval', (array) ($this->cfg['allowUsers'] ?? []));
            $peer = (string) ($reference['user']['aadObjectId'] ?? $reference['user']['id'] ?? '');

            return $allow !== [] && \in_array($peer, $allow, true);
        }
        $groupPolicy = (string) ($this->cfg['groupPolicy'] ?? 'disabled');
        if ($groupPolicy === 'open') {
            return true;
        }
        if ($groupPolicy !== 'allowlist') {
            $this->log->debug('teams_group_blocked', ['conversation' => $conversationId]);

            return false;
        }
        $allow = \array_map('strval', (array) ($this->cfg['allowGroups'] ?? []));

        return $allow !== [] && \in_array($conversationId, $allow, true);
    }

    /** @param array<string,mixed> $ask */
    private static function approvalSummary(array $ask): string
    {
        $tool = (string) ($ask['tool'] ?? 'a tool');
        $reason = (string) ($ask['reason'] ?? '');
        $args = $ask['arguments'] ?? [];
        $rendered = \is_array($args) ? (string) \json_encode($args, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE) : (string) $args;
        if (\strlen($rendered) > 360) {
            $rendered = \substr($rendered, 0, 360) . '...';
        }

        return \trim($tool . ' - ' . $reason . "\n" . $rendered);
    }
}

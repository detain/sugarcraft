<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Bridge;

use CandyCode\Bundles\MsTeams\Candy\ServeBridge;
use CandyCode\Bundles\MsTeams\Candy\ServeError;
use CandyCode\Bundles\MsTeams\MsTeams\Cards;
use CandyCode\Bundles\MsTeams\MsTeams\Commands;
use CandyCode\Bundles\MsTeams\Support\Keys;
use CandyCode\Bundles\MsTeams\Support\Log;
use CandyCode\Bundles\MsTeams\Support\OutboundChunker;
use CandyCode\Bundles\MsTeams\Support\QueueGate;
use CandyCode\Bundles\MsTeams\Support\SessionMap;
use CandyCode\Bundles\MsTeams\Support\TextSafety;
use React\EventLoop\LoopInterface;
use SugarCraft\Code\Lang;
use React\Promise\PromiseInterface;

/**
 * The bridge's middle: channel inbounds become session prompts, serve
 * events become channel output, and an approval ask is presented on
 * whichever platform its conversation belongs to (§9.3's one rule:
 * "an incoming message is a prompt, an outgoing event is a reply").
 *
 * Delivery choice (§9.3's queue/debounce mapping): idle → `queue` (the
 * server admits it as a fresh turn); busy + mention → `steer` (hand it to
 * the running turn, the protocol's own vocabulary); busy without a
 * mention → `queue` (park it after the turn).
 *
 * DURABLE CURSOR LAW: a durable event whose seq is at-or-below the stored
 * cursor has already been APPLIED (channel message sent, approval shown),
 * so a replay advances nothing and outputs nothing — the channel never
 * sees the same answer twice because a socket hiccup replayed the log.
 * Approvals ride differently: a still-OPEN ask is re-presented from the
 * subscribe result's `pendingAsks` after every (re)follow, because the
 * human may not have answered yet — and re-presenting an ask that was
 * answered elsewhere is harmless by `permission.respond`'s first-valid-
 * answer-wins law.
 *
 * APPROVAL POSTURE (§7.9): `policy['approvals']` shapes presentation —
 * `enabled: false` falls back to the same-chat `/approve` text (the ask
 * stays answerable, only the buttons go away), `target` restricts buttons
 * to dm/channel conversations, `sessionFilter`/`agentFilter` are regex
 * doors, and `approvers` is the answer roster enforced by
 * {@see answerApproval()}. The configured roster is ONE wall (empty =
 * documented everyone); behind it rides the C-1 answer gate: every answer
 * must also prove conversation binding — a button/card click carries the
 * per-ask nonce minted at presentation, a text `/approve` must arrive in
 * the ask's own conversation, and the adapter applies the platform's
 * access floor to the answerer first. A card's roster line stays a
 * courtesy display; unbound answers are refused fail-closed and never
 * touch the permission response. An EMPTY roster admits any answerer to
 * approve-once and deny, but never to `always` (a persistent grant): that
 * answer requires a configured approver, so the open floor cannot widen a
 * tool's permission for good.
 *
 * MUTABLE ON PURPOSE: it mirrors live session state.
 */
final class ConversationRouter
{
    /** @var array<string, PlatformFace> "<platform>:<account>" (plus a bare "<platform>" fallback alias) → face */
    private array $channels = [];

    /** @var array<string, string> sessionId → serve status word */
    private array $busy = [];

    /** @var array<string, true> askId → presented (dedupe double deliveries) */
    private array $presentedAsks = [];

    /** @var array<string, string> askId → sessionId (an answer names only the ask) */
    private array $askSession = [];

    /** @var array<string, string> askId → per-ask answer nonce minted at presentation (C-1) */
    private array $askNonce = [];

    /** @var array<string, string> askId → conversation key the ask was presented on (C-1 binding) */
    private array $askConversation = [];

    /** @var array<string, array<string,mixed>> askId → presentation ref (where refusal lines are delivered) */
    private array $askRef = [];

    /** @var array<string, PromiseInterface<void>> platform → tail of that face's chunk chain (per conversation below) */
    private array $deliveryTails = [];

    private ?QueueGate $queueGate = null;

    /** @var \Closure(string, array<string,mixed>, list<string>): void|null */
    private ?\Closure $pairHandler = null;

    public function __construct(
        private readonly ServeBridge $serve,
        private readonly SessionMap $map,
        private readonly Log $log,
        private readonly TextSafety $safety,
        private readonly LoopInterface $loop,
        private readonly array $policy = [],
    ) {
        $serve->onEvent(fn (array $envelope) => $this->handleEvent($envelope));
        $serve->onFollowResult(function (string $sessionId, array $result): void {
            $this->consumeFollowResult($sessionId, $result);
        });
    }

    /**
     * Register a platform face. Multi-account bundles register one face per
     * account under the same platform (§7.11): conversation keys carry the
     * account segment, so routing is by "<platform>:<account>" with the
     * FIRST face registered for a platform kept as the fallback for keys
     * whose account no longer has a face (history stays readable).
     */
    public function addChannel(PlatformFace $channel, string $account = ''): void
    {
        $platform = $channel->name();
        $this->channels[$platform . ':' . $account] = $channel;
        $this->channels[$platform] ??= $channel;
    }

    public function channel(string $name): ?PlatformFace
    {
        return $this->channels[$name] ?? null;
    }

    /** The face that owns a §9.1 conversation key. */
    public function channelFor(string $conversationKey): ?PlatformFace
    {
        $parts = Keys::parse($conversationKey);

        return $this->channels[$parts['platform'] . ':' . $parts['account']] ?? $this->channels[$parts['platform']] ?? null;
    }

    /**
     * First-delivery claim for one platform activity id (Teams retries a slow
     * webhook POST). The adapter claims before dispatch, so commands and card
     * answers dedupe exactly like prompts.
     */
    public function claimInbound(string $platform, string $inboundId): bool
    {
        return $this->map->claimInbound($platform, $inboundId);
    }

    /** @return list<PlatformFace> */
    public function allChannels(): array
    {
        return \array_values($this->channels);
    }

    /**
     * One inbound human message.
     *
     * @param array<string,mixed> $ref the adapter's reply address
     * @param list<array{name?:string,mime:string,data:string}> $images
     * @param list<array{name?:string,mime:string,data:string}> $documents
     */
    public function inbound(string $platform, string $key, array $ref, string $text, bool $isMention, ?string $inboundId = null, array $images = [], array $documents = []): void
    {
        if ($inboundId !== null && !$this->map->claimInbound($platform, $inboundId)) {
            $this->log->debug('inbound_duplicate', ['platform' => $platform]);

            return;
        }
        $channel = $this->channelFor($key);
        if ($channel === null) {
            $this->log->warn('inbound_without_channel', ['platform' => $platform]);

            return;
        }

        $this->sessionFor($key, $ref)->then(function (string $sessionId) use ($key, $ref, $text, $isMention, $channel, $images, $documents): void {
            $this->map->rememberConversation($sessionId, $key, $ref);
            $this->dispatch($channel, $ref, $sessionId, $key, $text, $isMention, $images, $documents);
        }, fn (\Throwable $e) => $this->failWith($channel, $ref, 'Could not open a session: ' . $e->getMessage()));
    }

    /**
     * Route one admitted inbound: the §9.2 queue vocabulary decides arrival.
     *
     * steer  — a mention while the turn runs piggybacks on it;
     * interrupt — a mention while busy cancels the turn, then sends;
     * collect — plain queued text coalesces through the QueueGate;
     * queue  — the default: it waits its turn (bridge legacy shape).
     *
     * @param list<array{name?:string,mime:string,data:string}> $images
     * @param list<array{name?:string,mime:string,data:string}> $documents
     */
    private function dispatch(PlatformFace $channel, array $ref, string $sessionId, string $key, string $text, bool $isMention, array $images, array $documents): void
    {
        $busy = ($this->busy[$sessionId] ?? 'idle') !== 'idle';
        $mode = (string) (($this->policy['queue']['mode'] ?? 'steer'));
        $fenced = $this->safety->fence($text);

        if ($busy && $isMention && $mode === 'interrupt') {
            $this->serve->cancel($sessionId)
                ->then(
                    fn () => $this->sendPrompt($channel, $ref, $sessionId, $fenced, 'queue', $images, $documents),
                    fn (\Throwable $e) => $this->failWith($channel, $ref, 'Cancel failed: ' . $e->getMessage()),
                );
            $channel->typing($ref);

            return;
        }

        $delivery = $busy && $isMention ? 'steer' : 'queue';

        if ($delivery === 'queue' && $mode === 'collect' && $this->queueGate !== null && $images === [] && $documents === [] && $text !== '') {
            //  collect coalesces plain queued text; media always rides its own
            //  prompt — the serve media caps (§9.4) forbid surprise merges.
            $dropped = $this->queueGate->push($key, $fenced, function (array $items) use ($channel, $ref, $sessionId): void {
                $this->sendPrompt($channel, $ref, $sessionId, \implode("\n\n", $items), 'queue');
                $channel->typing($ref);
            });

            if ($dropped > 0) {
                $this->deliver($channel, $ref, 'Dropped ' . $dropped . ' queued prompt(s) over the cap.');
            }

            return;
        }

        $this->sendPrompt($channel, $ref, $sessionId, $fenced, $delivery, $images, $documents);
        $channel->typing($ref);
    }

    /** @param array{name:string, args:list<string>} $command */
    public function runCommand(string $platform, string $key, array $ref, array $command): void
    {
        $channel = $this->channelFor($key);
        if ($channel === null) {
            return;
        }
        [$name, $args] = [$command['name'], $command['args']];

        switch ($name) {
            case 'new':
                // M-4: the replacement session carries the SAME channel-policy
                // grammar as the auto-bound one — a free-text argument here
                // used to land as the session name, silently dropping the
                // conversation out of its D6 policy binding. Positional args
                // are advisory.
                $this->serve->createSession(self::grammarName($key))
                    ->then(function (array $created) use ($key, $ref, $channel): void {
                        $sessionId = (string) ($created['id'] ?? '');
                        if ($sessionId === '') {
                            $this->failWith($channel, $ref, 'session.create came back without an id.');

                            return;
                        }
                        $this->map->bind($key, $sessionId);
                        $this->map->rememberConversation($sessionId, $key, $ref);
                        $this->followNow($sessionId, $key);
                        $this->deliver($channel, $ref, "New session `{$sessionId}`. Anything you say here goes to it.");
                    }, fn (\Throwable $e) => $this->failWith($channel, $ref, 'Could not create a session: ' . $e->getMessage()));
                break;
            case 'stop':
                $sessionId = $this->map->sessionIdFor($key);
                if ($sessionId === null) {
                    $this->deliver($channel, $ref, 'Nothing is running here.');

                    return;
                }
                $this->serve->cancel($sessionId)
                    ->then(
                        fn () => $this->deliver($channel, $ref, 'Turn cancelled.'),
                        fn (\Throwable $e) => $this->failWith($channel, $ref, 'Cancel failed: ' . $e->getMessage()),
                    );
                break;
            case 'approve':
                $askId = (string) ($args[0] ?? '');
                $verdict = Cards::VERDICTS[\strtolower((string) ($args[1] ?? 'once'))] ?? null;
                $sessionId = $this->askSession[$askId] ?? $this->map->sessionIdFor($key);
                if ($askId === '' || $verdict === null || $sessionId === null) {
                    $this->deliver($channel, $ref, 'Usage: `/approve <askId> [once|always|deny]` (the ask id is in the approval message).');

                    return;
                }
                $this->answerApproval($sessionId, $askId, $verdict, $ref['__actor'] ?? ['userId' => 'text-fallback'], '', $key, 'text');
                break;
            case 'model':
                $sessionId = $this->map->sessionIdFor($key);
                if ($sessionId === null) {
                    $this->deliver($channel, $ref, 'Nothing is attached here yet — send a message first.');

                    return;
                }
                $this->serve->call('command.exec', [
                    'sessionId' => $sessionId,
                    'name' => 'model',
                    'args' => \implode(' ', $args),
                ])->then(
                    fn (array $result) => $this->deliver($channel, $ref, $this->commandResultText($result)),
                    fn (\Throwable $e) => $this->failWith($channel, $ref, 'The /model command failed: ' . $e->getMessage()),
                );
                break;
            case 'pair':
                $handler = $this->pairHandler;
                if ($handler === null) {
                    $this->deliver($channel, $ref, 'Pairing is not wired on this account.');

                    return;
                }
                $handler($key, $ref, $args);
                break;
            case 'help':
                $this->deliver($channel, $ref, Commands::helpText());
                break;
            case 'status':
            case 'cost':
                $this->sessionReport($channel, $key, $ref, $name);
                break;
            case 'queue':
                $text = \implode(' ', $args);
                if ($text === '') {
                    $this->deliver($channel, $ref, 'Usage: `/queue <text>`');

                    return;
                }
                $this->inbound($platform, $key, $ref, $text, false);
                break;
            default:
                $this->log->debug('command_unhandled', ['name' => $name]);
        }
    }

    // ── serve → channel ─────────────────────────────────────────────────

    /** @param array<string,mixed> $envelope */
    private function handleEvent(array $envelope): void
    {
        $sessionId = (string) ($envelope['sessionId'] ?? '');
        $type = (string) ($envelope['type'] ?? '');
        $durable = (bool) ($envelope['durable'] ?? false);
        $seq = isset($envelope['seq']) ? (int) $envelope['seq'] : null;
        $data = \is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        if ($sessionId === '' || $type === '') {
            return;
        }

        // Server-scope liveness: the tick proves the socket breathes.
        if ($seq === null && !$durable) {
            return; // ephemeral noise: deltas, steps — assistant.completed is the contract
        }

        $conversation = $this->map->lastConversation($sessionId);
        if ($conversation === null) {
            return; // nobody's conversation owns this session
        }
        $key = $conversation['key'];
        $channel = $this->channelFor($key);

        if ($durable && $seq !== null) {
            if ($seq <= $this->map->cursorForSession($sessionId)) {
                return; // already applied — replay is for the cursor, not the channel
            }
            $this->map->noteSeqForSession($sessionId, $seq);
        }
        if ($channel === null) {
            return;
        }

        switch ($type) {
            case 'assistant.completed':
                $content = (string) ($data['content'] ?? '');
                if ($content !== '') {
                    $this->deliverReply($channel, $conversation['ref'], $content);
                }
                break;
            case 'permission.requested':
                $this->presentApproval($sessionId, $data);
                break;
            case 'permission.resolved':
                $askId = (string) ($data['askId'] ?? '');
                if ($askId !== '') {
                    unset($this->presentedAsks[$askId], $this->askSession[$askId], $this->askNonce[$askId], $this->askConversation[$askId], $this->askRef[$askId]);
                    $verdict = (string) ($data['reply'] ?? 'resolved');
                    foreach ($this->channelFaces() as $candidate) {
                        $candidate->retireApproval($askId, self::resolvedLine($verdict, $data));
                    }
                }
                break;
            case 'session.status':
                $this->busy[$sessionId] = (string) ($data['status'] ?? 'idle');
                break;
            case 'turn.completed':
                $stop = (string) ($data['stopReason'] ?? '');
                if ($stop === 'error') {
                    $this->deliver($channel, $conversation['ref'], '⚠ The turn ended in an error: ' . (string) ($data['error'] ?? 'unknown'));
                }
                // A progressive placeholder whose reply never came must not
                // keep ticking; a reply that already settled it makes this a no-op.
                if ($channel instanceof StreamingFace) {
                    $channel->abandonReply($conversation['ref']);
                }
                break;
            case 'spend_cap.breached':
                $this->deliver($channel, $conversation['ref'], '⚠ The spend cap stopped this turn.');
                break;
            default:
                break;
        }
    }

    private static function resolvedLine(string $verdict, array $data): string
    {
        $who = isset($data['note']) && \is_string($data['note']) && $data['note'] !== '' ? " ({$data['note']})" : '';

        return match ($verdict) {
            'once' => '✅ Approved once' . $who,
            'always' => '✅ Always allowed' . $who,
            'reject' => '⛔ Denied' . $who,
            default => 'Question resolved',
        };
    }

    /**
     * exec-approval posture (§7.9): target/session/agent doors first, then
     * either the button payload or the same-chat text fallback. Approver
     * identity is enforced at answer time in answerApproval() — the
     * configured roster is one wall; the per-ask nonce minted below and
     * the conversation binding recorded beside it are the C-1 answer gate
     * behind it. The card's roster line remains a courtesy display.
     *
     * agentFilter matches against the ask's tool + arguments haystack: the
     * v1 serve event carries no agent identity, so this is tool-scoped
     * best-effort (disclosed, not silently narrower than the name claims).
     *
     * @param array<string,mixed> $ask
     */
    private function presentApproval(string $sessionId, array $ask): void
    {
        $askId = (string) ($ask['askId'] ?? '');
        if ($askId === '' || isset($this->presentedAsks[$askId])) {
            return;
        }
        $conversation = $this->map->lastConversation($sessionId);
        if ($conversation === null) {
            return;
        }
        $channel = $this->channelFor($conversation['key']);
        if ($channel === null) {
            return;
        }
        $conversationKey = (string) $conversation['key'];
        $approvals = (array) ($this->policy['approvals'] ?? []);
        $target = (string) ($approvals['target'] ?? 'both');
        $isDm = \str_contains($conversationKey, ':dm:');
        if (($target === 'dm' && !$isDm) || ($target === 'channel' && $isDm)) {
            return; // stays pending — answerable where the target allows, or via /approve
        }
        $sessionFilter = (string) ($approvals['sessionFilter'] ?? '');
        if ($sessionFilter !== '' && !$this->regexPasses($sessionFilter, $sessionId . ' ' . $conversationKey)) {
            return;
        }
        $agentFilter = (string) ($approvals['agentFilter'] ?? '');
        if ($agentFilter !== '') {
            $haystack = (string) ($ask['tool'] ?? '') . ' ' . \json_encode($ask['arguments'] ?? [], \JSON_UNESCAPED_SLASHES);
            if (!$this->regexPasses($agentFilter, $haystack)) {
                return;
            }
        }
        $nonce = \bin2hex(\random_bytes(8));
        $this->presentedAsks[$askId] = true;
        $this->askSession[$askId] = $sessionId;
        $this->askNonce[$askId] = $nonce;
        $this->askConversation[$askId] = $conversationKey;
        $this->askRef[$askId] = (array) $conversation['ref'];
        if ((bool) ($approvals['enabled'] ?? true)) {
            $channel->promptApproval($conversation['ref'], $sessionId, $ask, $nonce);

            return;
        }
        $this->deliver($channel, $conversation['ref'], '/approve ' . $askId . ' once|always|deny');
    }

    /**
     * A human answered (button press, card submit, or the text fallback).
     *
     * Two walls, in order (C-1): the configured approver roster rejects
     * non-approvers ON the permission wire (the ask resolves denied — the
     * pre-existing shape, unchanged); then the BINDING wall refuses
     * fail-closed, BEFORE anything reaches respondPermission, any answer
     * that cannot prove it belongs to this ask: the button/card face must
     * carry the nonce minted at presentation and answer inside the ask's
     * conversation; the text face must answer inside the ask's
     * conversation. A refused answer leaves the ask pending for a
     * legitimate approver and is surfaced as a deny line.
     *
     * @param array<string,mixed> $actor
     * @param string              $nonce           custom_id / card-data nonce ('' = text face)
     * @param string|null         $conversationKey the conversation the ANSWER arrived on
     * @param string              $face            'button' | 'card' | 'text'
     */
    public function answerApproval(string $sessionId, string $askId, string $verdict, array $actor, string $nonce = '', ?string $conversationKey = null, string $face = 'text'): void
    {
        $approvers = (array) ($this->policy['approvals']['approvers'] ?? $this->policy['approvers'] ?? []);
        $userId = (string) ($actor['userId'] ?? '');
        if ($approvers !== [] && !\in_array($userId, \array_map('strval', $approvers), true)) {
            $this->log->warn('approval_by_non_approver', ['ask' => $askId]);
            $this->serve->respondPermission($sessionId, $askId, 'reject', "refused: {$userId} is not an approver")
                ->then(null, fn (\Throwable $e) => $this->log->warn('approval_refuse_failed', ['error' => $e->getMessage()]));

            return;
        }
        if ($approvers === [] && $verdict === 'always') {
            // A persistent grant needs a named approver; an open roster may
            // still answer once or deny, and the ask stays open otherwise.
            $this->log->warn('approval_always_without_approver', ['ask' => $askId, 'actor' => $userId]);
            $this->refuseAlways($askId);

            return;
        }
        if (!$this->answerIsBound($sessionId, $askId, $nonce, $conversationKey, $face)) {
            $this->refuseUnbound($askId, $userId, $face);

            return;
        }
        $this->serve->respondPermission($sessionId, $askId, $verdict, (string) ($actor['name'] ?? $userId))
            ->then(
                function (array $r) use ($askId): void {
                    $this->log->info('approval_answered', ['ask' => $askId, 'answered' => (bool) ($r['answered'] ?? false)]);
                },
                function (\Throwable $e) use ($askId): void {
                    $already = \str_contains($e->getMessage(), 'already_resolved');
                    $this->log->warn('approval_answer_failed', ['ask' => $askId, 'error' => $e->getMessage(), 'already' => $already ? 1 : 0]);
                },
            );
    }

    /**
     * The ask-first spelling an adapter reaches with an askId and the
     * answer's own context: the session is whatever the ask was presented
     * on. Unknown asks (answered after a restart, before the re-follow
     * landed) resolve through `permission.pending` so the TEXT `/approve`
     * recovery keeps working — but C-1 binds every answer here too: the
     * nonce/conversation gate in answerApproval() refuses a stale card
     * submit from a dead process fail-closed (the re-follow re-presents
     * the ask with a fresh card), and a text answer must arrive on the
     * session's own conversation.
     *
     * @param array<string,mixed> $actor
     * @param string              $nonce           button/card face: the per-ask secret from the payload
     * @param string|null         $conversationKey the conversation the answer arrived on
     * @param string              $face            'button' | 'card' | 'text'
     */
    public function answerByAskId(string $askId, string $verdict, array $actor, string $nonce = '', ?string $conversationKey = null, string $face = 'text'): void
    {
        $sessionId = $this->askSession[$askId] ?? null;
        if ($sessionId !== null) {
            $this->answerApproval($sessionId, $askId, $verdict, $actor, $nonce, $conversationKey, $face);

            return;
        }
        $this->serve->call('permission.pending', [])->then(
            function (array $r) use ($askId, $verdict, $actor, $nonce, $conversationKey, $face): void {
                foreach ((array) ($r['pending'] ?? $r['asks'] ?? []) as $entry) {
                    if (\is_array($entry) && (string) ($entry['askId'] ?? '') === $askId) {
                        $sid = (string) ($entry['sessionId'] ?? '');
                        if ($sid !== '') {
                            $this->askSession[$askId] = $sid;
                            $this->answerApproval($sid, $askId, $verdict, $actor, $nonce, $conversationKey, $face);

                            return;
                        }
                    }
                }
                $this->log->warn('answer_for_unknown_ask', ['ask' => $askId]);
            },
            fn (\Throwable $e) => $this->log->warn('permission_pending_failed', ['error' => $e->getMessage()]),
        );
    }

    /**
     * The C-1 binding wall. Asks presented in THIS process carry their
     * minted nonce and presentation key; an answer arriving with neither
     * match is not an answer to this ask. For asks this process never
     * presented (post-restart text `/approve` — the documented recovery
     * line), the binding is derived lazily from the session's own last
     * conversation; the card face can never pass that way, because the
     * nonce died with the presenting process and a crafted value without
     * one is exactly what the forge looks like.
     */
    private function answerIsBound(string $sessionId, string $askId, string $nonce, ?string $conversationKey, string $face): bool
    {
        if ($conversationKey === null || $conversationKey === '') {
            return false; // an answer with no origin never reaches someone else's ask
        }
        $bound = $this->askConversation[$askId] ?? null;
        if ($bound === null) {
            $conversation = $this->map->lastConversation($sessionId);
            if ($conversation === null) {
                return false;
            }
            $bound = (string) $conversation['key'];
            $this->askConversation[$askId] = $bound;
            $this->askRef[$askId] = (array) $conversation['ref'];
        }
        if ($conversationKey !== $bound) {
            return false;
        }
        if ($face !== 'text') {
            $expected = (string) ($this->askNonce[$askId] ?? '');

            return $expected !== '' && \hash_equals($expected, $nonce);
        }

        return true;
    }

    /**
     * `always` without an approver roster: the ask is NOT answered on the
     * permission wire (it stays open for a once/deny answer or a roster); the
     * refusal is said in the ask's own presentation conversation.
     */
    private function refuseAlways(string $askId): void
    {
        $key = $this->askConversation[$askId] ?? null;
        $ref = $this->askRef[$askId] ?? null;
        if ($key === null || !\is_array($ref)) {
            return;
        }
        $channel = $this->channelFor($key);
        if ($channel !== null) {
            $this->deliver($channel, $ref, Lang::t('channels.msteams.approval.always_needs_approver'));
        }
    }

    /**
     * Refused fail-closed: logged LOUD, denied visibly in the ask's own
     * presentation conversation (the legitimate approvers' eyes), and the
     * permission wire is NOT touched — another user's pending ask stays
     * pending for its real approver.
     */
    private function refuseUnbound(string $askId, string $userId, string $face): void
    {
        $this->log->warn('approval_answer_unbound', ['ask' => $askId, 'actor' => $userId, 'face' => $face]);
        $key = $this->askConversation[$askId] ?? null;
        $ref = $this->askRef[$askId] ?? null;
        if ($key === null || !\is_array($ref)) {
            return;
        }
        $channel = $this->channelFor($key);
        if ($channel !== null) {
            $this->deliver($channel, $ref, Lang::t('channels.msteams.approval.refused'));
        }
    }

    // ── plumbing ────────────────────────────────────────────────────────

    /** @return PromiseInterface<string> */
    private function sessionFor(string $key, array $ref): PromiseInterface
    {
        $existing = $this->map->sessionIdFor($key);
        if ($existing !== null) {
            return \React\Promise\resolve($existing);
        }

        return $this->serve->createSession(self::grammarName($key))->then(function (array $created) use ($key, $ref): string {
            $sessionId = (string) ($created['id'] ?? '');
            if ($sessionId === '') {
                throw new \RuntimeException('session.create returned no id');
            }
            $this->map->bind($key, $sessionId);
            $this->map->rememberConversation($sessionId, $key, $ref);
            $this->followNow($sessionId, $key);

            return $sessionId;
        });
    }

    /**
     * Stamp the §2.3 session grammar onto the existing `session.create` name
     * field (zero protocol change) so the core's ChannelToolPolicyService can
     * resolve this conversation's tool policy AT ADMISSION — the Discord
     * bundle's M5 twin, mirrored exactly. Teams keys by conversation id: the
     * DM ladder shapes (`dm:main`, `dm:<peer>`, `dm:<chan>:<peer>`,
     * `dm:acc:<chan>:<peer>`) match Discord's, so the same kind logic speaks
     * here — the aggregate is honestly a CHANNEL session (no per-sender
     * identity to bind), a per-peer shape is a DM session whose tail IS the
     * sender, and every other conversation id is a GROUP session. The
     * agentId segment is a placeholder: policy keys on channel + kind + id,
     * never on who runs the session. Without this stamp D6 per-channel
     * policy was dead config on Teams (agent: appeared nowhere in the
     * bundle).
     */
    private static function grammarName(string $key): string
    {
        $rest = Keys::parse($key)['rest'];
        if (\str_starts_with($rest, 'dm:')) {
            if ($rest === 'dm:main') {
                return 'agent:main:msteams:channel:main';
            }
            // Every per-peer DM shape ends with the peer id.
            $segments = \explode(':', $rest);

            return 'agent:main:msteams:dm:' . $segments[\count($segments) - 1];
        }

        // Teams conversation ids carry their own colons ('19:<topic>@thread.v2').
        // The policy grammar reads exactly five segments (a colon inside the id
        // would push the name past ChannelToolPolicyService::parse()'s ceiling
        // and land the session in its fail-closed refusingAll bucket), so the
        // id rides colon-encoded: '%3A' for ':'. Per-group and per-sender
        // overrides in channels.json spell the id in this same encoded form;
        // the channel-level entry that makes D6 live on Teams reads the
        // 'msteams' segment regardless.
        return 'agent:main:msteams:group:' . \str_replace(':', '%3A', $rest);
    }

    private function followNow(string $sessionId, string $key): void
    {
        $cursor = $this->map->cursorForSession($sessionId);
        $this->serve->follow($sessionId, $cursor > 0 ? $cursor : null, fn (): int => $this->map->cursorForSession($sessionId))
            ->then(function (array $result) use ($sessionId): void {
                $this->consumeFollowResult($sessionId, $result);
            }, fn (\Throwable $e) => $this->log->warn('follow_failed', ['session' => $sessionId, 'error' => $e->getMessage()]));
    }

    /**
     * Consume a follow result: the pendingAsks ride, and a RESET answer is
     * ACCOUNTED for before it is absorbed. The core stream never re-feeds the
     * snapshot window (SessionFeed pins the subscriber's lastSent at
     * throughSeq and goes live strictly above it), and snapshot message rows
     * carry no seq — re-delivering them could not dedupe against frames this
     * bridge already delivered and WOULD duplicate the tail. The honest shape
     * of "never cursor-skip on RESET" is therefore: measure the replayed-in
     * gap, disclose it loudly (error log + one notice into the bound
     * conversation), and only THEN advance the session cursor to throughSeq.
     * A first open (cursor 0) has claimed nothing, so nothing is skipped and
     * no alarm is raised.
     *
     * @param array<string,mixed> $result
     */
    private function consumeFollowResult(string $sessionId, array $result): void
    {
        foreach ((array) ($result['pendingAsks'] ?? []) as $ask) {
            if (\is_array($ask)) {
                $this->presentApproval($sessionId, $ask);
            }
        }

        if (($result['reset'] ?? false) !== true) {
            return;
        }

        $through = (int) ($result['throughSeq'] ?? 0);
        $seen = $this->map->cursorForSession($sessionId);
        $snapshot = $result['snapshot'] ?? null;
        $dropped = \is_array($snapshot) && \is_array($snapshot['messages'] ?? null)
            ? \count($snapshot['messages'])
            : 0;

        if ($seen <= 0 || $through <= $seen) {
            $this->log->debug('serve_reset_open', [
                'session' => $sessionId,
                'cursor' => $seen,
                'throughSeq' => $through,
                'snapshotRows' => $dropped,
            ]);

            return;
        }

        $this->log->error('reset_snapshot_discarded', [
            'session' => $sessionId,
            'cursor' => $seen,
            'throughSeq' => $through,
            'snapshotDropped' => $dropped,
        ]);

        $conversation = $this->map->lastConversation($sessionId);
        $channel = $conversation === null ? null : $this->channelFor($conversation['key']);

        if ($conversation !== null && $channel !== null) {
            $this->deliver($channel, $conversation['ref'], \sprintf(
                '⚠ The serve log could not replay seq %d–%d while this bridge was away — %d snapshot row(s) were discarded; some replies may be missing here.',
                $seen + 1,
                $through,
                $dropped
            ));
        }

        $this->map->noteSeqForSession($sessionId, $through);
    }

    /**
     * Chunks of one reply go out strictly in order: each waits for the one
     * before it to settle (a 429 retry included), so an out-of-order platform
     * completion can never reorder the answer. Chains are per conversation,
     * so unrelated conversations never queue behind each other.
     */
    private function deliver(PlatformFace $channel, array $ref, string $text): void
    {
        $chain = $channel->name() . '|' . (string) ($ref['conversationId'] ?? '');
        $tail = $this->deliveryTails[$chain] ?? \React\Promise\resolve(null);
        foreach (OutboundChunker::split($this->safety->outbound($text), $channel->textChunkLimit(), $channel->maxLinesPerMessage()) as $chunk) {
            $tail = $tail
                ->then(static fn () => $channel->deliver($ref, $chunk))
                ->then(null, fn (\Throwable $e) => $this->log->warn('deliver_failed', ['error' => $e->getMessage()]));
        }
        $this->deliveryTails[$chain] = $tail;
    }

    /**
     * The final reply of a turn. A face with an open progressive placeholder
     * takes the chunks through finishReply() (first chunk replaces the
     * placeholder); everything else rides the plain chunk chain, exactly as
     * {@see deliver()} always has.
     */
    private function deliverReply(PlatformFace $channel, array $ref, string $text): void
    {
        if (!$channel instanceof StreamingFace || !$channel->hasOpenReply($ref)) {
            $this->deliver($channel, $ref, $text);

            return;
        }
        $chain = $channel->name() . '|' . (string) ($ref['conversationId'] ?? '');
        $tail = $this->deliveryTails[$chain] ?? \React\Promise\resolve(null);
        $chunks = OutboundChunker::split($this->safety->outbound($text), $channel->textChunkLimit(), $channel->maxLinesPerMessage());
        $this->deliveryTails[$chain] = $tail
            ->then(static fn () => $channel->finishReply($ref, $chunks))
            ->then(null, fn (\Throwable $e) => $this->log->warn('deliver_failed', ['error' => $e->getMessage()]));
    }

    /**
     * `/status` and `/cost`: one read of the session's own snapshot. The
     * host reports status, queue and spend there; nothing here is estimated.
     */
    private function sessionReport(PlatformFace $channel, string $key, array $ref, string $name): void
    {
        $sessionId = $this->map->sessionIdFor($key);
        if ($sessionId === null) {
            $this->deliver($channel, $ref, 'No session is attached to this conversation yet. Send a message first.');

            return;
        }
        $this->serve->call('session.get', ['sessionId' => $sessionId, 'limit' => 1])->then(
            function (array $snapshot) use ($channel, $ref, $sessionId, $name): void {
                $this->deliver($channel, $ref, $name === 'cost'
                    ? self::costText($snapshot)
                    : $this->statusText($sessionId, $snapshot));
            },
            fn (\Throwable $e) => $this->failWith($channel, $ref, 'Could not read the session: ' . $e->getMessage()),
        );
    }

    /** @param array<string,mixed> $snapshot */
    private function statusText(string $sessionId, array $snapshot): string
    {
        $state = (string) ($snapshot['status'] ?? ($this->busy[$sessionId] ?? 'idle'));
        $queued = \is_array($snapshot['queued'] ?? null) ? \count($snapshot['queued']) : 0;
        $mode = (string) ($this->policy['queue']['mode'] ?? 'steer');
        $pending = 0;
        foreach ($this->askSession as $askId => $owner) {
            if ($owner === $sessionId && isset($this->presentedAsks[$askId])) {
                $pending++;
            }
        }
        $lines = [
            '**Session status**',
            "- Session: `{$sessionId}`",
            "- State: {$state}",
        ];
        if (\is_string($snapshot['model'] ?? null) && $snapshot['model'] !== '') {
            $lines[] = '- Model: ' . $snapshot['model'];
        }
        $lines[] = "- Queue mode: {$mode}";
        $lines[] = "- Queued prompts: {$queued}";
        $lines[] = "- Approvals awaiting an answer: {$pending}"
            . ($pending > 0 ? ' (answer with the card, or `/approve <askId> once|always|deny`)' : '');

        return \implode("\n", $lines);
    }

    /** @param array<string,mixed> $snapshot */
    private static function costText(array $snapshot): string
    {
        $spent = $snapshot['spentUsd'] ?? null;
        if (!\is_int($spent) && !\is_float($spent)) {
            return 'Cost: not reported.';
        }
        $unpriced = (bool) ($snapshot['spendUnpriced'] ?? false);

        return \sprintf('Spend this session: $%.4f', (float) $spent)
            . ($unpriced ? ' (a lower bound: a call used a model with no price on file)' : '');
    }

    /**
     * The conversation reply-address store the proactive-notify door reads.
     * Keyed by account + Teams conversation id, never by session.
     *
     * @param array<string,mixed> $ref
     */
    public function rememberTeamsConversation(string $account, string $conversationId, array $ref): void
    {
        $this->map->rememberTeamsConversation($account, $conversationId, $ref);
    }

    /** @return array<string,mixed>|null */
    public function teamsConversation(string $account, string $conversationId): ?array
    {
        return $this->map->teamsConversation($account, $conversationId);
    }

    private function failWith(PlatformFace $channel, array $ref, string $message): void
    {
        $this->log->error('bridge_failure', ['channel' => $channel->name(), 'error' => $message]);
        $this->deliver($channel, $ref, $message);
    }

    /**
     * The collect-mode coalescing gate (§9.2). Swapped in by the channel
     * owner whenever the serve connection (and so the router) is rebuilt.
     */
    public function setQueueGate(QueueGate $gate): void
    {
        $this->queueGate = $gate;
    }

    /**
     * `/pair` lands here: the account face owns the pairing store, so the
     * router only hands over (conversation key, reply-ref, args).
     *
     * @param \Closure(string, array<string,mixed>, list<string>): void $handler
     */
    public function setPairHandler(\Closure $handler): void
    {
        $this->pairHandler = $handler;
    }

    /**
     * After a serve rebuild: re-subscribe every bound conversation, so the
     * cursor-carrying follows resume where the SQLite map left them.
     */
    public function adoptBindings(): void
    {
        foreach ($this->map->allBindings() as $row) {
            $this->followNow((string) $row['sessionId'], (string) $row['key']);
        }
    }

    /**
     * Distinct faces (the '<platform>:<account>' map also carries bare
     * platform fallback aliases — retiring an approval must touch each
     * face once, not once per alias).
     *
     * @return list<PlatformFace>
     */
    private function channelFaces(): array
    {
        $seen = [];
        foreach ($this->channels as $face) {
            $seen[\spl_object_id($face)] = $face;
        }

        return \array_values($seen);
    }

    /**
     * One prompt out the serve door, media included. A media refusal (the
     * serve re-sniffs bytes and caps totals below the platform ceilings)
     * gets exactly one retry without attachments plus a disclosed notice —
     * the text was never the problem, and silent drops are not an option.
     *
     * @return PromiseInterface<mixed>
     */
    private function sendPrompt(PlatformFace $channel, array $ref, string $sessionId, string $fenced, string $delivery, array $images = [], array $documents = []): PromiseInterface
    {
        if ($channel instanceof StreamingFace) {
            $channel->beginReply($ref);
        }

        // A prompt the serve never admitted gets no reply: say so in the log,
        // and close any progressive placeholder so its refresh tick stops.
        // Callers do not chain on this promise, so the rejection ends here.
        return $this->sendPromptOnWire($channel, $ref, $sessionId, $fenced, $delivery, $images, $documents)->then(
            null,
            function (\Throwable $error) use ($channel, $ref, $sessionId): void {
                if ($channel instanceof StreamingFace) {
                    $channel->abandonReply($ref);
                }
                $this->log->warn('prompt_not_admitted', ['session' => $sessionId, 'error' => $error->getMessage()]);
            },
        );
    }

    /** @return PromiseInterface<mixed> */
    private function sendPromptOnWire(PlatformFace $channel, array $ref, string $sessionId, string $fenced, string $delivery, array $images, array $documents): PromiseInterface
    {
        if ($images === [] && $documents === []) {
            return $this->serve->send($sessionId, $fenced, $delivery)->then(
                fn () => $this->log->info('prompt_admitted', ['session' => $sessionId, 'delivery' => $delivery]),
            );
        }

        return $this->serve->send($sessionId, $fenced, $delivery, null, $images, $documents)->then(
            fn () => $this->log->info('prompt_admitted', ['session' => $sessionId, 'delivery' => $delivery, 'media' => 1]),
            function (\Throwable $error) use ($channel, $ref, $sessionId, $fenced, $delivery): PromiseInterface {
                if (!self::isMediaRefusal($error)) {
                    throw $error;
                }
                $this->log->warn('media_refused', ['session' => $sessionId, 'kind' => $error instanceof ServeError ? $error->kind : 'unknown']);
                $this->deliver($channel, $ref, 'Attachments refused by the server — sent without them.');

                return $this->serve->send($sessionId, $fenced, $delivery);
            },
        );
    }

    /**
     * Serve media refusals share one shape: a ServeError whose kind names
     * the rejected family (images_not_a_list, image_mime_mismatch,
     * documents_not_a_list, document_not_base64, document_not_decodable,
     * document_mime_mismatch — TurnMethods.php).
     */
    private static function isMediaRefusal(\Throwable $error): bool
    {
        return $error instanceof ServeError
            && (\str_starts_with($error->kind, 'image') || \str_starts_with($error->kind, 'document'));
    }

    /**
     * command.exec returns {rows: [{role, content, uiOnly}], effects: []}
     * (CommandResult::toArray). uiOnly rows are transcript chrome for the
     * TUI — the channel gets the human text, joined.
     *
     * @param array<string,mixed> $result
     */
    private function commandResultText(array $result): string
    {
        $parts = [];
        foreach ((array) ($result['rows'] ?? []) as $row) {
            if (!\is_array($row) || (bool) ($row['uiOnly'] ?? false)) {
                continue;
            }
            $content = \trim((string) ($row['content'] ?? ''));
            if ($content !== '') {
                $parts[] = $content;
            }
        }
        $text = \implode("\n\n", $parts);

        return $text === '' ? 'Done.' : $text;
    }

    /**
     * Operator-written regex doors. A pattern that fails to compile is a
     * misconfiguration, and misconfigurations fail CLOSED here: an ask
     * silently escaping its filter is the worse accident.
     */
    private function regexPasses(string $pattern, string $haystack): bool
    {
        $result = @\preg_match('#' . \str_replace('#', '\#', $pattern) . '#i', $haystack);
        if ($result === false) {
            $this->log->warn('approval_filter_bad_pattern', ['pattern' => $pattern]);

            return false;
        }

        return $result === 1;
    }
}

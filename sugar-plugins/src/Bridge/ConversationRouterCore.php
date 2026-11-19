<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Bridge;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * The platform-neutral middle of a chat bridge: channel inbounds become
 * session prompts, serve events become channel output, and an approval ask is
 * presented on whichever platform its conversation belongs to (docs/SPEC.md §9.3's
 * one rule: "an incoming message is a prompt, an outgoing event is a reply").
 *
 * Delivery choice (docs/SPEC.md §9.3's queue/debounce mapping): idle → `queue` (the
 * server admits it as a fresh turn); busy + mention → `steer` (hand it to
 * the running turn, the protocol's own vocabulary); busy without a
 * mention → `queue` (park it after the turn).
 *
 * DURABLE CURSOR LAW: a durable event whose seq is at-or-below the stored
 * cursor has already been APPLIED (channel message sent, approval shown),
 * so a replay advances nothing and outputs nothing. Approvals ride
 * differently: a still-OPEN ask is re-presented from the subscribe result's
 * `pendingAsks` after every (re)follow, because the human may not have
 * answered yet. Re-presenting an ask that was answered elsewhere is harmless
 * by `permission.respond`'s first-valid-answer-wins law.
 *
 * APPROVAL POSTURE (docs/SPEC.md §7.9): `policy['approvals']` shapes presentation.
 * `enabled: false` falls back to the same-chat `/approve` text, `target`
 * restricts buttons to dm/channel conversations, `sessionFilter`/`agentFilter`
 * are regex doors, and `approvers` is the answer roster enforced by
 * {@see answerApproval()}. Behind the roster rides the C-1 answer gate: every
 * answer must prove conversation binding, and unbound answers are refused
 * fail-closed before they reach the permission response.
 *
 * EXTENSION. A bundle extends this class and supplies its platform code
 * through the protected hooks: {@see failureReason()} for error text,
 * {@see sessionName()} for the session grammar, {@see deliver()} and
 * {@see deliverReply()} for the reply path, {@see sendPrompt()} for the prompt
 * door, and the event and command hooks. The hooks default to the shared
 * behavior, so a bundle overrides only where its platform differs.
 * {@see channelFaces()} and {@see commandResultText()} have no shared default:
 * each bundle supplies its own, so the user-facing fallback text ('Done.') rides
 * that bundle's catalog review and never lives in this package.
 *
 * MUTABLE ON PURPOSE: it mirrors live session state.
 */
abstract class ConversationRouterCore
{
    /** Verdict words a text `/approve` or a button answer may name, mapped to the permission reply. */
    public const VERDICTS = [
        'once' => 'once',
        'always' => 'always',
        'deny' => 'reject',
    ];

    /** @var array<string, PlatformFace> "<platform>:<account>" (plus a bare "<platform>" fallback alias) → face */
    protected array $channels = [];

    /** @var array<string, PromiseInterface<string>> in-flight session.create continuations, keyed by binding key */
    protected array $creating = [];

    /** @var array<string, string> sessionId → serve status word */
    protected array $busy = [];

    /** @var array<string, true> askId → presented (dedupe double deliveries) */
    protected array $presentedAsks = [];

    /** @var array<string, string> askId → sessionId (an answer names only the ask) */
    protected array $askSession = [];

    /** @var array<string, string> askId → per-ask answer nonce minted at presentation (C-1) */
    protected array $askNonce = [];

    /** @var array<string, string> askId → conversation key the ask was presented on (C-1 binding) */
    protected array $askConversation = [];

    /** @var array<string, array<string,mixed>> askId → presentation ref (where refusal lines are delivered) */
    protected array $askRef = [];

    protected ?QueueGate $queueGate = null;

    /** @var \Closure(string, array<string,mixed>, list<string>): void|null */
    protected ?\Closure $pairHandler = null;

    /**
     * @param \Closure(string, array<string,mixed>): string $translate resolves a key relative to the bundle's catalog, e.g. "session.could_not_open"
     */
    public function __construct(
        protected readonly ServeClient $serve,
        protected readonly SessionStore $map,
        protected readonly BridgeLog $log,
        protected readonly TextGuard $safety,
        protected readonly ChunkSplitter $chunker,
        protected readonly LoopInterface $loop,
        protected readonly \Closure $translate,
        protected readonly array $policy = [],
    ) {
        $serve->onEvent(fn (array $envelope) => $this->handleEvent($envelope));
        $serve->onFollowResult(function (string $sessionId, array $result): void {
            $this->consumeFollowResult($sessionId, $result);
        });
    }

    /**
     * Register a platform face. Multi-account bundles register one face per
     * account under the same platform (docs/SPEC.md §7.11): conversation keys carry the
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

    /** The face that owns a docs/SPEC.md §9.1 conversation key. */
    public function channelFor(string $conversationKey): ?PlatformFace
    {
        $parts = ConversationKey::parse($conversationKey);

        return $this->channels[$parts['platform'] . ':' . $parts['account']] ?? $this->channels[$parts['platform']] ?? null;
    }

    /** @return list<PlatformFace> */
    public function allChannels(): array
    {
        return \array_values($this->channels);
    }

    /**
     * First-delivery claim for a platform message id. Faces call this before
     * dispatching anything that is not idempotent (commands), since inbound()
     * only claims prompts.
     */
    public function claimInbound(string $platform, string $inboundId): bool
    {
        return $this->map->claimInbound($platform, $inboundId);
    }

    /** Release the crash window: the claimed inbound reached its dispatch stage (043). */
    public function confirmInbound(string $platform, string $inboundId): void
    {
        $this->map->confirmInbound($platform, $inboundId);
    }

    /** (042) an archived thread releases its binding so the sidecar stops growing monotonically. */
    public function releaseBinding(string $key): void
    {
        $this->map->forget($key);
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

        $this->sessionFor($key, $ref)->then(function (string $sessionId) use ($platform, $key, $ref, $text, $isMention, $channel, $images, $documents, $inboundId): void {
            $confirmFirst = $this->confirmsInboundFirst();
            if ($confirmFirst && $inboundId !== null) {
                $this->map->confirmInbound($platform, $inboundId);
            }
            $this->map->rememberConversation($sessionId, $key, $ref);
            $this->dispatch($channel, $ref, $sessionId, $key, $text, $isMention, $images, $documents);
            // (043) post-enqueue is the honest "we have it now" moment: the
            // dispatch synchronously reached the queue/prompt stage, so a
            // later redelivery must suppress even across restarts.
            if (!$confirmFirst && $inboundId !== null) {
                $this->map->confirmInbound($platform, $inboundId);
            }
        }, fn (\Throwable $e) => $this->failWith($channel, $ref, $this->t('session.could_not_open', ['error' => $this->failureReason($e)])));
    }

    /**
     * Route one admitted inbound: the docs/SPEC.md §9.2 queue vocabulary decides arrival.
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
                    fn (\Throwable $e) => $this->failWith($channel, $ref, $this->t('turn.cancel_failed', ['error' => $this->failureReason($e)])),
                );
            $channel->typing($ref);

            return;
        }

        $delivery = $busy && $isMention ? 'steer' : 'queue';

        if ($delivery === 'queue' && $mode === 'collect' && $this->queueGate !== null && $images === [] && $documents === [] && $text !== '') {
            //  collect coalesces plain queued text; media always rides its own
            //  prompt — the serve media caps (docs/SPEC.md §9.4) forbid surprise merges.
            $dropped = $this->queueGate->push($key, $fenced, function (array $items) use ($channel, $ref, $sessionId): void {
                $this->sendPrompt($channel, $ref, $sessionId, \implode("\n\n", $items), 'queue');
                $channel->typing($ref);
            });

            if ($dropped > 0) {
                $this->deliver($channel, $ref, $this->t('router.dropped_queued', ['count' => (string) $dropped]));
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
                // conversation out of its D6 policy binding (and, worse, a
                // title typed 'agent:...' would have tripped the core's
                // fail-closed refusingAll). Positional args are advisory.
                $this->serve->createSession($this->sessionName($key))
                    ->then(function (array $created) use ($key, $ref, $channel): void {
                        $sessionId = (string) ($created['id'] ?? '');
                        if ($sessionId === '') {
                            $this->failWith($channel, $ref, $this->t('session.no_id'));

                            return;
                        }
                        $this->map->bind($key, $sessionId);
                        $this->map->rememberConversation($sessionId, $key, $ref);
                        $this->followNow($sessionId, $key);
                        $this->deliver($channel, $ref, $this->t('session.new', ['sessionId' => $sessionId]));
                    }, fn (\Throwable $e) => $this->failWith($channel, $ref, $this->t('session.could_not_create', ['error' => $this->failureReason($e)])));
                break;
            case 'stop':
                $sessionId = $this->map->sessionIdFor($key);
                if ($sessionId === null) {
                    $this->deliver($channel, $ref, $this->t('turn.nothing_running'));

                    return;
                }
                $this->serve->cancel($sessionId)
                    ->then(
                        fn () => $this->deliver($channel, $ref, $this->t('turn.cancelled')),
                        fn (\Throwable $e) => $this->failWith($channel, $ref, $this->t('turn.cancel_failed', ['error' => $this->failureReason($e)])),
                    );
                break;
            case 'approve':
                $askId = (string) ($args[0] ?? '');
                $verdict = self::VERDICTS[\strtolower((string) ($args[1] ?? 'once'))] ?? null;
                $sessionId = $this->askSession[$askId] ?? $this->map->sessionIdFor($key);
                if ($askId === '' || $verdict === null || $sessionId === null) {
                    $this->deliver($channel, $ref, $this->t('approve.usage'));

                    return;
                }
                $this->answerApproval($sessionId, $askId, $verdict, $ref['__actor'] ?? ['userId' => 'text-fallback'], '', $key, 'text');
                break;
            case 'model':
                $sessionId = $this->map->sessionIdFor($key);
                if ($sessionId === null) {
                    $this->deliver($channel, $ref, $this->t('attach.none'));

                    return;
                }
                $this->serve->call('command.exec', [
                    'sessionId' => $sessionId,
                    'name' => 'model',
                    'args' => \implode(' ', $args),
                ])->then(
                    fn (array $result) => $this->deliver($channel, $ref, $this->commandResultText($result)),
                    fn (\Throwable $e) => $this->failWith($channel, $ref, $this->t('model.failed', ['error' => $this->failureReason($e)])),
                );
                break;
            case 'pair':
                $handler = $this->pairHandler;
                if ($handler === null) {
                    $this->deliver($channel, $ref, $this->t('pair.not_wired'));

                    return;
                }
                $handler($key, $ref, $args);
                break;
            case 'queue':
                $text = \implode(' ', $args);
                if ($text === '') {
                    $this->deliver($channel, $ref, $this->t('queue.usage'));

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
            $this->eventWithoutChannel($sessionId, $key);

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
                        $candidate->retireApproval($askId, $this->resolvedLine($verdict, $data));
                    }
                }
                break;
            case 'session.status':
                $this->busy[$sessionId] = (string) ($data['status'] ?? 'idle');
                break;
            case 'turn.completed':
                $stop = (string) ($data['stopReason'] ?? '');
                if ($stop === 'error') {
                    $this->deliver($channel, $conversation['ref'], $this->t('turn.error', ['error' => (string) ($data['error'] ?? $this->t('turn.error_unknown'))]));
                }
                $this->turnCompleted($channel, $conversation['ref']);
                break;
            case 'spend_cap.breached':
                $this->deliver($channel, $conversation['ref'], $this->t('turn.spend_cap'));
                break;
            default:
                break;
        }
    }

    private function resolvedLine(string $verdict, array $data): string
    {
        $who = isset($data['note']) && \is_string($data['note']) && $data['note'] !== '' ? " ({$data['note']})" : '';

        return match ($verdict) {
            'once' => $this->t('approve.resolved_once', ['who' => $who]),
            'always' => $this->t('approve.resolved_always', ['who' => $who]),
            'reject' => $this->t('approve.resolved_denied', ['who' => $who]),
            default => $this->t('approve.resolved_default'),
        };
    }

    /**
     * exec-approval posture (docs/SPEC.md §7.9): target/session/agent doors first, then
     * either the button payload or the same-chat text fallback. Approver
     * identity is enforced at answer time in answerApproval() — the
     * configured roster is one wall; the per-ask nonce minted below and
     * the conversation binding recorded beside it are the C-1 answer gate
     * behind it.
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
        $this->deliver($channel, $conversation['ref'], $this->t('approve.hint', ['askId' => (string) $askId]));
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
            $this->refuseNonApprover($askId);
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
     * nonce/conversation gate in answerApproval() refuses a stale button
     * from a dead process fail-closed (the re-follow re-presents the ask
     * with a fresh card), and a text answer must arrive on the session's
     * own conversation.
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
     * conversation; the button/card face can never pass that way, because
     * the nonce died with the presenting process and old payloads parse
     * without one.
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
            $this->deliver($channel, $ref, $this->t('approval.always_needs_approver'));
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
            $this->deliver($channel, $ref, $this->t('approval.refused'));
        }
    }

    // ── plumbing ────────────────────────────────────────────────────────

    /**
     * Resolve the session a binding key rides, minting it on first contact.
     * Single-flight: concurrent first-contacts share one in-flight create
     * per key, and both arms clear the memo, so a rejection never sticks.
     *
     * @return PromiseInterface<string>
     */
    protected function sessionFor(string $key, array $ref): PromiseInterface
    {
        $existing = $this->map->sessionIdFor($key);
        if ($existing !== null) {
            return \React\Promise\resolve($existing);
        }
        if (isset($this->creating[$key])) {
            return $this->creating[$key];
        }

        return $this->creating[$key] = $this->serve->createSession($this->sessionName($key))->then(
            function (array $created) use ($key, $ref): string {
                unset($this->creating[$key]);
                $sessionId = (string) ($created['id'] ?? '');
                if ($sessionId === '') {
                    throw new \RuntimeException('session.create returned no id');
                }
                // Re-check INSIDE the fulfilled arm: if the key got bound while
                // this create rode the wire (/new, or a parallel adopter), the
                // racer ADOPTS the live binding — never overwrites it.
                $bound = $this->map->sessionIdFor($key);
                if ($bound !== null) {
                    return $bound;
                }
                $this->map->bind($key, $sessionId);
                $this->map->rememberConversation($sessionId, $key, $ref);
                $this->followNow($sessionId, $key);

                return $sessionId;
            },
            function (\Throwable $error) use ($key): never {
                unset($this->creating[$key]);
                throw $error;
            },
        );
    }

    /**
     * The docs/SPEC.md §2.3 session grammar, stamped onto the `session.create` name
     * field (zero protocol change) so the core's ChannelToolPolicyService can
     * resolve this conversation's tool policy AT ADMISSION. A guild or thread
     * conversation is a GROUP session; a per-peer DM ladder entry is a DM
     * session whose tail IS the sender; the `dm:main` aggregate, which folds
     * many humans into one session, is honestly a CHANNEL session. The agentId
     * segment is a placeholder: policy is keyed on channel + kind + id.
     *
     * A bundle whose conversation ids carry their own separators overrides this.
     */
    protected function sessionName(string $key): string
    {
        $parts = ConversationKey::parse($key);
        $platform = $parts['platform'];
        $rest = $parts['rest'];
        if (\str_starts_with($rest, 'dm:')) {
            if ($rest === 'dm:main') {
                return "agent:main:{$platform}:channel:main";
            }
            // Every per-peer DM shape ends with the peer id.
            $segments = \explode(':', $rest);

            return "agent:main:{$platform}:dm:" . $segments[\count($segments) - 1];
        }

        return "agent:main:{$platform}:group:{$rest}";
    }

    protected function followNow(string $sessionId, string $key): void
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
     * snapshot window, and snapshot message rows carry no seq, so the honest
     * shape of "never cursor-skip on RESET" is: measure the replayed-in gap,
     * disclose it loudly (error log + one notice into the bound conversation),
     * and only THEN advance the session cursor to throughSeq. A first open
     * (cursor 0) has claimed nothing, so nothing is skipped and no alarm is raised.
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
            $this->deliver($channel, $conversation['ref'], $this->t('replay.gap', ['from' => (string) ($seen + 1), 'to' => (string) $through, 'count' => (string) $dropped]));
        }

        $this->map->noteSeqForSession($sessionId, $through);
    }

    /**
     * Ordered chunk chain (candy-discord-011): a reply's chunks must land in the
     * order they were written, so each chunk rides the previous chunk's
     * settlement. The FIRST failure stops the walk with one deliver_failed row
     * naming the truncation (chunk k of N), and nothing is sent after it.
     *
     * A bundle whose platform needs a different chain (per-conversation tails,
     * for example) overrides this method.
     */
    protected function deliver(PlatformFace $channel, array $ref, string $text): void
    {
        $chunks = \array_values($this->chunker->chunk($this->safety->outbound($text), $channel->textChunkLimit(), $channel->maxLinesPerMessage()));
        $sequence = \React\Promise\resolve(null);
        $failed = false;
        foreach ($chunks as $index => $chunk) {
            $sequence = $sequence->then(function () use ($channel, $ref, $chunk, $index, $chunks, &$failed): PromiseInterface {
                if ($failed) {
                    return \React\Promise\resolve(null);
                }

                return $channel->deliver($ref, $chunk)->then(null, function (\Throwable $error) use ($index, $chunks, &$failed): void {
                    $failed = true;
                    $this->log->warn('deliver_failed', [
                        'error' => $error->getMessage(),
                        'chunk' => $index + 1,
                        'of' => \count($chunks),
                        'stopped' => true,
                    ]);
                });
            });
        }
    }

    /**
     * The final reply of a turn. The shared behavior is the plain chunk chain;
     * a bundle with a progressive reply placeholder overrides this.
     */
    protected function deliverReply(PlatformFace $channel, array $ref, string $text): void
    {
        $this->deliver($channel, $ref, $text);
    }

    /**
     * Hook after a turn settles (a turn.completed event, error or not). A
     * bundle with a progressive reply placeholder closes it here.
     */
    protected function turnCompleted(PlatformFace $channel, array $ref): void
    {
    }

    /**
     * Hook for a durable event the cursor has already counted but no face
     * owns. It is a silent no-op on an at-least-once ledger; a bundle may say so.
     */
    protected function eventWithoutChannel(string $sessionId, string $key): void
    {
    }

    /**
     * Whether an admitted inbound is confirmed before it is dispatched, not
     * after. Confirming after dispatch (the default) means a crash mid-dispatch
     * re-admits the redelivery; confirming first keeps a crashed dispatch from
     * ever being retried.
     */
    protected function confirmsInboundFirst(): bool
    {
        return false;
    }

    /**
     * Refusal text shown in the ask's own conversation when an answer comes
     * from outside the approver roster. The bundle decides whether to say so.
     */
    protected function refuseNonApprover(string $askId): void
    {
        $askKey = $this->askConversation[$askId] ?? null;
        $askRef = $this->askRef[$askId] ?? null;
        if ($askKey !== null && \is_array($askRef)) {
            $channel = $this->channelFor($askKey);
            if ($channel !== null) {
                $this->deliver($channel, $askRef, $this->t('approval.not_yours'));
            }
        }
    }

    /**
     * Chat-safe reason for a failure. The shared default is the exception's
     * message; a bundle whose errors carry a user-facing rendering overrides it.
     */
    protected function failureReason(\Throwable $e): string
    {
        return $e->getMessage();
    }

    /**
     * Translate a key relative to the bundle's catalog. The bundle's translator
     * prefixes its own namespace, so the core never names a catalog.
     *
     * @param array<string,mixed> $params
     */
    protected function t(string $key, array $params = []): string
    {
        return ($this->translate)($key, $params);
    }

    protected function failWith(PlatformFace $channel, array $ref, string $message): void
    {
        $this->log->error('bridge_failure', ['channel' => $channel->name(), 'error' => $message]);
        $this->deliver($channel, $ref, $message);
    }

    /**
     * The collect-mode coalescing gate (docs/SPEC.md §9.2). Swapped in by the channel
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
    abstract protected function channelFaces(): array;

    /**
     * One prompt out the serve door, media included. A media refusal (the
     * serve re-sniffs bytes and caps totals well below the platform's file
     * size) gets exactly one retry without attachments plus a disclosed
     * notice: the text was never the problem, and silent drops are not an option.
     *
     * Never rejects: a failed prompt answers the user once through the
     * delivery boundary and leaves one content-free prompt_refused row behind.
     *
     * @param list<array{name?:string,mime:string,data:string}> $images
     * @param list<array{name?:string,mime:string,data:string}> $documents
     * @return PromiseInterface<mixed>
     */
    protected function sendPrompt(PlatformFace $channel, array $ref, string $sessionId, string $fenced, string $delivery, array $images = [], array $documents = []): PromiseInterface
    {
        if ($images === [] && $documents === []) {
            return $this->serve->send($sessionId, $fenced, $delivery)->then(
                fn () => $this->log->info('prompt_admitted', ['session' => $sessionId, 'delivery' => $delivery]),
                function (\Throwable $error) use ($channel, $ref, $sessionId): void {
                    $this->refusePrompt($channel, $ref, $sessionId, $error);
                },
            );
        }

        return $this->serve->send($sessionId, $fenced, $delivery, null, $images, $documents)->then(
            fn () => $this->log->info('prompt_admitted', ['session' => $sessionId, 'delivery' => $delivery, 'media' => 1]),
            function (\Throwable $error) use ($channel, $ref, $sessionId, $fenced, $delivery): PromiseInterface {
                if (!$this->isMediaRefusal($error)) {
                    $this->refusePrompt($channel, $ref, $sessionId, $error);

                    return \React\Promise\resolve(null);
                }
                $this->log->warn('media_refused', ['session' => $sessionId, 'kind' => $this->refusalKind($error)]);
                $this->deliver($channel, $ref, $this->t('media.refused'));

                return $this->serve->send($sessionId, $fenced, $delivery)->then(null, function (\Throwable $retryError) use ($channel, $ref, $sessionId): void {
                    $this->refusePrompt($channel, $ref, $sessionId, $retryError);
                });
            },
        );
    }

    /**
     * Whether a refused prompt was refused for its media alone, so one text-only
     * retry is worth making. The shared default knows no media family, so it
     * never retries; a bundle that knows its serve's error kinds overrides it.
     */
    protected function isMediaRefusal(\Throwable $error): bool
    {
        return false;
    }

    /** The refusal kind named in a log row. The shared default names none. */
    protected function refusalKind(\Throwable $error): string
    {
        return 'unknown';
    }

    /**
     * Terminal arm of sendPrompt: disclose to the user, log kind-only.
     */
    private function refusePrompt(PlatformFace $channel, array $ref, string $sessionId, \Throwable $error): void
    {
        $this->log->warn('prompt_refused', ['session' => $sessionId, 'kind' => $this->refusalKind($error)]);
        $this->failWith($channel, $ref, $this->t('prompt.failed', ['error' => $this->failureReason($error)]));
    }

    /**
     * command.exec returns {rows: [{role, content, uiOnly}], effects: []}
     * (CommandResult::toArray). uiOnly rows are transcript chrome for the
     * TUI — the channel gets the human text, joined. A bundle supplies the
     * empty-result fallback in its own catalog.
     *
     * @param array<string,mixed> $result
     */
    abstract protected function commandResultText(array $result): string;

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

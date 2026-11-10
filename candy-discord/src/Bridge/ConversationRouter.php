<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Bridge;

use CandyCode\Bundles\Discord\Candy\ServeBridge;
use CandyCode\Bundles\Discord\Discord\Buttons;
use CandyCode\Bundles\Discord\Support\Keys;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Support\OutboundChunker;
use CandyCode\Bundles\Discord\Support\SessionMap;
use CandyCode\Bundles\Discord\Support\TextSafety;
use React\EventLoop\LoopInterface;
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
 * MUTABLE ON PURPOSE: it mirrors live session state.
 */
final class ConversationRouter
{
    /** @var array<string, ChannelInterface> */
    private array $channels = [];

    /** @var array<string, string> sessionId → serve status word */
    private array $busy = [];

    /** @var array<string, true> askId → presented (dedupe double deliveries) */
    private array $presentedAsks = [];

    /** @var array<string, string> askId → sessionId (an answer names only the ask) */
    private array $askSession = [];

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
            foreach ((array) ($result['pendingAsks'] ?? []) as $ask) {
                if (\is_array($ask)) {
                    $this->presentApproval($sessionId, $ask);
                }
            }
        });
    }

    public function addChannel(ChannelInterface $channel): void
    {
        $this->channels[$channel->name()] = $channel;
    }

    public function channel(string $name): ?ChannelInterface
    {
        return $this->channels[$name] ?? null;
    }

    /** @return list<ChannelInterface> */
    public function allChannels(): array
    {
        return \array_values($this->channels);
    }

    /**
     * One inbound human message.
     *
     * @param array<string,mixed> $ref the adapter's reply address
     */
    public function inbound(string $platform, string $key, array $ref, string $text, bool $isMention, ?string $inboundId = null): void
    {
        if ($inboundId !== null && !$this->map->claimInbound($platform, $inboundId)) {
            $this->log->debug('inbound_duplicate', ['platform' => $platform]);

            return;
        }
        $channel = $this->channel($platform);
        if ($channel === null) {
            $this->log->warn('inbound_without_channel', ['platform' => $platform]);

            return;
        }

        $this->sessionFor($key, $ref)->then(function (string $sessionId) use ($key, $ref, $text, $isMention, $channel): void {
            $this->map->rememberConversation($sessionId, $key, $ref);
            $delivery = ($this->busy[$sessionId] ?? 'idle') !== 'idle' && $isMention ? 'steer' : 'queue';
            $this->serve->send($sessionId, $this->safety->fence($text), $delivery)
                ->then(
                    function (array $admitted) use ($sessionId): void {
                        $this->log->info('prompt_admitted', [
                            'session' => $sessionId,
                            'admitted' => (string) ($admitted['admitted'] ?? '?'),
                        ]);
                    },
                    fn (\Throwable $e) => $this->failWith($channel, $ref, 'Could not deliver that prompt: ' . $e->getMessage()),
                );
            $channel->typing($ref);
        }, fn (\Throwable $e) => $this->failWith($channel, $ref, 'Could not open a session: ' . $e->getMessage()));
    }

    /** @param array{name:string, args:list<string>} $command */
    public function runCommand(string $platform, string $key, array $ref, array $command): void
    {
        $channel = $this->channel($platform);
        if ($channel === null) {
            return;
        }
        [$name, $args] = [$command['name'], $command['args']];

        switch ($name) {
            case 'new':
                $this->serve->createSession($args[0] ?? null)
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
                $verdict = Buttons::VERDICTS[\strtolower((string) ($args[1] ?? 'once'))] ?? null;
                $sessionId = $this->askSession[$askId] ?? $this->map->sessionIdFor($key);
                if ($askId === '' || $verdict === null || $sessionId === null) {
                    $this->deliver($channel, $ref, 'Usage: `/approve <askId> [once|always|deny]` (the ask id is in the approval message).');

                    return;
                }
                $this->answerApproval($sessionId, $askId, $verdict, $ref['__actor'] ?? ['userId' => 'text-fallback']);
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
        $channel = $this->channel(Keys::parse($key)['platform']);

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
                    $this->deliver($channel, $conversation['ref'], $content);
                }
                break;
            case 'permission.requested':
                $this->presentApproval($sessionId, $data);
                break;
            case 'permission.resolved':
                $askId = (string) ($data['askId'] ?? '');
                if ($askId !== '') {
                    unset($this->presentedAsks[$askId], $this->askSession[$askId]);
                    $verdict = (string) ($data['reply'] ?? 'resolved');
                    foreach ($this->channels as $candidate) {
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

    /** @param array<string,mixed> $ask */
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
        $channel = $this->channel(Keys::parse($conversation['key'])['platform']);
        if ($channel === null) {
            return;
        }
        $this->presentedAsks[$askId] = true;
        $this->askSession[$askId] = $sessionId;
        $channel->promptApproval($conversation['ref'], $sessionId, $ask);
    }

    /**
     * A human answered (button press, card submit, or the text fallback).
     *
     * @param array<string,mixed> $actor
     */
    public function answerApproval(string $sessionId, string $askId, string $verdict, array $actor): void
    {
        $approvers = (array) ($this->policy['approvers'] ?? []);
        $userId = (string) ($actor['userId'] ?? '');
        if ($approvers !== [] && !\in_array($userId, \array_map('strval', $approvers), true)) {
            $this->log->warn('approval_by_non_approver', ['ask' => $askId]);
            $this->serve->respondPermission($sessionId, $askId, 'reject', "refused: {$userId} is not an approver")
                ->then(null, fn (\Throwable $e) => $this->log->warn('approval_refuse_failed', ['error' => $e->getMessage()]));

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
     * The ask-first spelling an adapter reaches with only an askId: the
     * session is whatever the ask was presented on. Unknown asks (answered
     * after a restart, before the re-follow landed) resolve through
     * `permission.pending` so a stale button still answers correctly.
     *
     * @param array<string,mixed> $actor
     */
    public function answerByAskId(string $askId, string $verdict, array $actor): void
    {
        $sessionId = $this->askSession[$askId] ?? null;
        if ($sessionId !== null) {
            $this->answerApproval($sessionId, $askId, $verdict, $actor);

            return;
        }
        $this->serve->call('permission.pending', [])->then(
            function (array $r) use ($askId, $verdict, $actor): void {
                foreach ((array) ($r['pending'] ?? $r['asks'] ?? []) as $entry) {
                    if (\is_array($entry) && (string) ($entry['askId'] ?? '') === $askId) {
                        $sid = (string) ($entry['sessionId'] ?? '');
                        if ($sid !== '') {
                            $this->askSession[$askId] = $sid;
                            $this->answerApproval($sid, $askId, $verdict, $actor);

                            return;
                        }
                    }
                }
                $this->log->warn('answer_for_unknown_ask', ['ask' => $askId]);
            },
            fn (\Throwable $e) => $this->log->warn('permission_pending_failed', ['error' => $e->getMessage()]),
        );
    }

    // ── plumbing ────────────────────────────────────────────────────────

    /** @return PromiseInterface<string> */
    private function sessionFor(string $key, array $ref): PromiseInterface
    {
        $existing = $this->map->sessionIdFor($key);
        if ($existing !== null) {
            return \React\Promise\resolve($existing);
        }

        return $this->serve->createSession()->then(function (array $created) use ($key, $ref): string {
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

    private function followNow(string $sessionId, string $key): void
    {
        $cursor = $this->map->cursorForSession($sessionId);
        $this->serve->follow($sessionId, $cursor > 0 ? $cursor : null, fn (): int => $this->map->cursorForSession($sessionId))
            ->then(function (array $result) use ($sessionId): void {
                foreach ((array) ($result['pendingAsks'] ?? []) as $ask) {
                    if (\is_array($ask)) {
                        $this->presentApproval($sessionId, $ask);
                    }
                }
            }, fn (\Throwable $e) => $this->log->warn('follow_failed', ['session' => $sessionId, 'error' => $e->getMessage()]));
    }

    private function deliver(ChannelInterface $channel, array $ref, string $text): void
    {
        foreach (OutboundChunker::split($this->safety->outbound($text), $channel->textChunkLimit(), $channel->maxLinesPerMessage()) as $chunk) {
            $channel->deliver($ref, $chunk)->then(null, fn (\Throwable $e) => $this->log->warn('deliver_failed', ['error' => $e->getMessage()]));
        }
    }

    private function failWith(ChannelInterface $channel, array $ref, string $message): void
    {
        $this->log->error('bridge_failure', ['channel' => $channel->name(), 'error' => $message]);
        $this->deliver($channel, $ref, $message);
    }
}

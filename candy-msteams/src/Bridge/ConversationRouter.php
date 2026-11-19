<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Bridge;

use CandyCode\Bundles\MsTeams\Candy\ServeBridge;
use CandyCode\Bundles\MsTeams\Candy\ServeError;
use CandyCode\Bundles\MsTeams\MsTeams\Commands;
use CandyCode\Bundles\MsTeams\Support\Keys;
use CandyCode\Bundles\MsTeams\Support\Log;
use CandyCode\Bundles\MsTeams\Support\OutboundChunker;
use CandyCode\Bundles\MsTeams\Support\SessionMap;
use CandyCode\Bundles\MsTeams\Support\TextSafety;
use CandyCode\Bundles\MsTeams\Lang;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Plugins\Bridge\ConversationRouterCore;

/**
 * The Teams router: the shared {@see ConversationRouterCore} routing with the
 * Teams platform behavior layered on top through its hooks.
 *
 * What is Teams-specific here: per-conversation delivery chains (a reply's
 * chunks stay ordered within one conversation, and a failed chunk does not
 * stop the rest), the StreamingFace placeholder path for the final reply,
 * the `/help`, `/status` and `/cost` commands, the proactive conversation
 * memory the notify door reads, and the Teams session grammar with its
 * colon-encoded conversation ids. Approvals keep the core's roster and
 * binding walls; an answer from outside the roster is refused on the
 * permission wire without a chat line, which is why {@see refuseNonApprover()}
 * is empty here.
 *
 * MUTABLE ON PURPOSE: it mirrors live session state.
 */
final class ConversationRouter extends ConversationRouterCore
{
    /** @var array<string, PromiseInterface<void>> platform → tail of that face's chunk chain (per conversation below) */
    private array $deliveryTails = [];

    /** The concrete session map, for the Teams-only conversation memory the core does not carry. */
    private readonly SessionMap $teamsMap;

    public function __construct(
        ServeBridge $serve,
        SessionMap $map,
        Log $log,
        TextSafety $safety,
        LoopInterface $loop,
        array $policy = [],
    ) {
        parent::__construct(
            $serve,
            $map,
            $log,
            $safety,
            new OutboundChunker(),
            $loop,
            static fn (string $key, array $params = []): string => Lang::t(self::catalogKey($key), $params),
            $policy,
        );
        $this->teamsMap = $map;
    }

    /** msteams-25: confirm at enqueue, so the claim only becomes permanent once dispatch is underway. */
    protected function confirmsInboundFirst(): bool
    {
        return true;
    }

    /**
     * An answer from outside the roster gets no chat line on Teams: the ask
     * stays visible to its real approvers and the permission wire carries the refusal.
     */
    protected function refuseNonApprover(string $askId): void
    {
    }

    /**
     * A progressive placeholder whose reply never came must not keep ticking;
     * a reply that already settled it makes this a no-op.
     */
    protected function turnCompleted(\SugarCraft\Plugins\Bridge\PlatformFace $channel, array $ref): void
    {
        if ($channel instanceof StreamingFace) {
            $channel->abandonReply($ref);
        }
    }

    /**
     * msteams-19 (021): the cursor has ALREADY advanced past this seq, so the
     * event is counted but seen by no face. That is a silent no-op on an
     * at-least-once ledger, so it says its name.
     */
    protected function eventWithoutChannel(string $sessionId, string $key): void
    {
        $this->log->debug('durable_event_dropped_no_channel', ['session' => $sessionId, 'key' => $key]);
    }

    /**
     * `/help`, `/status` and `/cost` are Teams commands; everything else is the core's.
     *
     * @param array{name:string, args:list<string>} $command
     */
    public function runCommand(string $platform, string $key, array $ref, array $command): void
    {
        $name = $command['name'];
        if (!\in_array($name, ['help', 'status', 'cost'], true)) {
            parent::runCommand($platform, $key, $ref, $command);

            return;
        }
        $channel = $this->channelFor($key);
        if ($channel === null) {
            return;
        }
        if ($name === 'help') {
            $this->deliver($channel, $ref, Commands::helpText());

            return;
        }
        $this->sessionReport($channel, $key, $ref, $name);
    }

    /**
     * Resolve the session a binding key rides, minting it on first contact.
     *
     * SINGLE-FLIGHT (msteams-15): concurrent first-contacts share ONE
     * in-flight promise per key — the TokenBroker::token() memo is the
     * house pattern. The cleaner arms are attached at creation time, so
     * they run on EVERY settle path: a success leaves the memo empty with
     * the binding in place (the `existing` door catches followers from
     * then on), and a failure leaves no trace — the next inbound mints a
     * fresh attempt instead of riding a poisoned promise forever.
     *
     * @return PromiseInterface<string>
     */
    protected function sessionFor(string $key, array $ref): PromiseInterface
    {
        $existing = $this->map->sessionIdFor($key);
        if ($existing !== null) {
            return \React\Promise\resolve($existing);
        }

        $pending = $this->creating[$key] ?? null;
        if ($pending !== null) {
            return $pending;
        }

        $memo = $this->serve->createSession($this->sessionName($key))
            ->then(function (array $created) use ($key, $ref): string {
                $sessionId = (string) ($created['id'] ?? '');
                if ($sessionId === '') {
                    throw new \RuntimeException('session.create returned no id');
                }
                $this->map->bind($key, $sessionId);
                $this->map->rememberConversation($sessionId, $key, $ref);
                $this->followNow($sessionId, $key);

                return $sessionId;
            })
            ->then(
                function (string $sessionId) use ($key): string {
                    unset($this->creating[$key]);

                    return $sessionId;
                },
                function (\Throwable $e) use ($key): never {
                    unset($this->creating[$key]);

                    throw $e;
                }
            );
        $this->creating[$key] = $memo;

        return $memo;
    }

    /**
     * Stamp the docs/SPEC.md §2.3 session grammar onto the existing `session.create` name
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
    protected function sessionName(string $key): string
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

    /**
     * Chunks of one reply go out strictly in order: each waits for the one
     * before it to settle (a 429 retry included), so an out-of-order platform
     * completion can never reorder the answer. Chains are per conversation,
     * so unrelated conversations never queue behind each other.
     */
    protected function deliver(\SugarCraft\Plugins\Bridge\PlatformFace $channel, array $ref, string $text): void
    {
        $chain = $channel->name() . '|' . (string) ($ref['conversationId'] ?? '');
        $tail = $this->deliveryTails[$chain] ?? \React\Promise\resolve(null);
        foreach ($this->chunker->chunk($this->safety->outbound($text), $channel->textChunkLimit(), $channel->maxLinesPerMessage()) as $chunk) {
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
    protected function deliverReply(\SugarCraft\Plugins\Bridge\PlatformFace $channel, array $ref, string $text): void
    {
        if (!$channel instanceof StreamingFace || !$channel->hasOpenReply($ref)) {
            $this->deliver($channel, $ref, $text);

            return;
        }
        $chain = $channel->name() . '|' . (string) ($ref['conversationId'] ?? '');
        $tail = $this->deliveryTails[$chain] ?? \React\Promise\resolve(null);
        $chunks = $this->chunker->chunk($this->safety->outbound($text), $channel->textChunkLimit(), $channel->maxLinesPerMessage());
        $this->deliveryTails[$chain] = $tail
            ->then(static fn () => $channel->finishReply($ref, $chunks))
            ->then(null, fn (\Throwable $e) => $this->log->warn('deliver_failed', ['error' => $e->getMessage()]));
    }

    /**
     * One prompt out the serve door, media included. A media refusal (the
     * serve re-sniffs bytes and caps totals below the platform ceilings)
     * gets exactly one retry without attachments plus a disclosed notice —
     * the text was never the problem, and silent drops are not an option.
     *
     * @return PromiseInterface<mixed>
     */
    protected function sendPrompt(\SugarCraft\Plugins\Bridge\PlatformFace $channel, array $ref, string $sessionId, string $fenced, string $delivery, array $images = [], array $documents = []): PromiseInterface
    {
        if ($channel instanceof StreamingFace) {
            $channel->beginReply($ref);
        }

        // A prompt the serve never admitted must NOT end as silence: the
        // placeholder closes (refresh tick stops) and the chat gets one
        // visible line. The wire error text stays log-only (SPEC copy law:
        // chat gets a generic per-family sentence, never the raw kind).
        // Callers do not chain on this promise, so the rejection ends here.
        return $this->sendPromptOnWire($channel, $ref, $sessionId, $fenced, $delivery, $images, $documents)->then(
            null,
            function (\Throwable $error) use ($channel, $ref, $sessionId): void {
                if ($channel instanceof StreamingFace) {
                    $channel->abandonReply($ref);
                }
                $this->log->warn('prompt_refused', ['session' => $sessionId, 'error' => $error->getMessage()]);
                $this->deliver($channel, $ref, '⛔ Your message never reached the agent — it was refused. Try again; if this keeps happening the served session may need /new.');
            },
        );
    }

    /** @return PromiseInterface<mixed> */
    private function sendPromptOnWire(\SugarCraft\Plugins\Bridge\PlatformFace $channel, array $ref, string $sessionId, string $fenced, string $delivery, array $images, array $documents): PromiseInterface
    {
        if ($images === [] && $documents === []) {
            return $this->serve->send($sessionId, $fenced, $delivery)->then(
                fn () => $this->log->info('prompt_admitted', ['session' => $sessionId, 'delivery' => $delivery]),
            );
        }

        return $this->serve->send($sessionId, $fenced, $delivery, null, $images, $documents)->then(
            fn () => $this->log->info('prompt_admitted', ['session' => $sessionId, 'delivery' => $delivery, 'media' => 1]),
            function (\Throwable $error) use ($channel, $ref, $sessionId, $fenced, $delivery): PromiseInterface {
                if (!$this->isMediaRefusal($error)) {
                    throw $error;
                }
                $this->log->warn('media_refused', ['session' => $sessionId, 'kind' => $error instanceof ServeError ? $error->kind : 'unknown']);
                $this->deliver($channel, $ref, Lang::t('channels.msteams.media.refused'));

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
    protected function isMediaRefusal(\Throwable $error): bool
    {
        return $error instanceof ServeError
            && (\str_starts_with($error->kind, 'image') || \str_starts_with($error->kind, 'document'));
    }

    /**
     * Distinct faces (the '<platform>:<account>' map also carries bare
     * platform fallback aliases — retiring an approval must touch each
     * face once, not once per alias). The bundle owns this copy, so the
     * reply fallback below rides this bundle's catalog review.
     *
     * @return list<\SugarCraft\Plugins\Bridge\PlatformFace>
     */
    protected function channelFaces(): array
    {
        $seen = [];
        foreach ($this->channels as $face) {
            $seen[\spl_object_id($face)] = $face;
        }

        return \array_values($seen);
    }

    /**
     * command.exec returns {rows: [{role, content, uiOnly}], effects: []}
     * (CommandResult::toArray). uiOnly rows are transcript chrome for the
     * TUI — the channel gets the human text, joined.
     *
     * @param array<string,mixed> $result
     */
    protected function commandResultText(array $result): string
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
     * `/status` and `/cost`: one read of the session's own snapshot. The
     * host reports status, queue and spend there; nothing here is estimated.
     */
    private function sessionReport(\SugarCraft\Plugins\Bridge\PlatformFace $channel, string $key, array $ref, string $name): void
    {
        $sessionId = $this->map->sessionIdFor($key);
        if ($sessionId === null) {
            $this->deliver($channel, $ref, Lang::t('channels.msteams.session.none_attached'));

            return;
        }
        $this->serve->call('session.get', ['sessionId' => $sessionId, 'limit' => 1])->then(
            function (array $snapshot) use ($channel, $ref, $sessionId, $name): void {
                $this->deliver($channel, $ref, $name === 'cost'
                    ? self::costText($snapshot)
                    : $this->statusText($sessionId, $snapshot));
            },
            fn (\Throwable $e) => $this->failWith($channel, $ref, Lang::t('channels.msteams.session.could_not_read', ['error' => $e->getMessage()])),
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
            Lang::t('channels.msteams.status.heading'),
            Lang::t('channels.msteams.status.session', ['sessionId' => $sessionId]),
            Lang::t('channels.msteams.status.state', ['state' => $state]),
        ];
        if (\is_string($snapshot['model'] ?? null) && $snapshot['model'] !== '') {
            $lines[] = Lang::t('channels.msteams.status.model', ['model' => (string) $snapshot['model']]);
        }
        $lines[] = Lang::t('channels.msteams.status.queue_mode', ['mode' => $mode]);
        $lines[] = Lang::t('channels.msteams.status.queued', ['queued' => (string) $queued]);
        $lines[] = Lang::t('channels.msteams.status.approvals', ['pending' => (string) $pending])
            . ($pending > 0 ? Lang::t('channels.msteams.status.approvals_hint') : '');

        return \implode("\n", $lines);
    }

    /** @param array<string,mixed> $snapshot */
    private static function costText(array $snapshot): string
    {
        $spent = $snapshot['spentUsd'] ?? null;
        if (!\is_int($spent) && !\is_float($spent)) {
            return Lang::t('channels.msteams.cost.not_reported');
        }
        $unpriced = (bool) ($snapshot['spendUnpriced'] ?? false);

        return Lang::t('channels.msteams.cost.spend', ['amount' => \sprintf('%.4f', (float) $spent)])
            . ($unpriced ? Lang::t('channels.msteams.cost.lower_bound') : '');
    }

    /**
     * The conversation reply-address store the proactive-notify door reads.
     * Keyed by account + Teams conversation id, never by session.
     *
     * @param array<string,mixed> $ref
     */
    public function rememberTeamsConversation(string $account, string $conversationId, array $ref): void
    {
        $this->teamsMap->rememberTeamsConversation($account, $conversationId, $ref);
    }

    /** @return array<string,mixed>|null */
    public function teamsConversation(string $account, string $conversationId): ?array
    {
        return $this->teamsMap->teamsConversation($account, $conversationId);
    }

    /**
     * The catalog key a core-relative key names. Each key is spelled out here, so the
     * catalog guard sees every key the router asks for; an unlisted key falls back to
     * the channels.msteams namespace.
     */
    private static function catalogKey(string $key): string
    {
        return match ($key) {
            'session.could_not_open' => 'channels.msteams.session.could_not_open',
            'turn.cancel_failed' => 'channels.msteams.turn.cancel_failed',
            'router.dropped_queued' => 'channels.msteams.router.dropped_queued',
            'session.no_id' => 'channels.msteams.session.no_id',
            'session.new' => 'channels.msteams.session.new',
            'session.could_not_create' => 'channels.msteams.session.could_not_create',
            'turn.nothing_running' => 'channels.msteams.turn.nothing_running',
            'turn.cancelled' => 'channels.msteams.turn.cancelled',
            'approve.usage' => 'channels.msteams.approve.usage',
            'attach.none' => 'channels.msteams.attach.none',
            'model.failed' => 'channels.msteams.model.failed',
            'pair.not_wired' => 'channels.msteams.pair.not_wired',
            'queue.usage' => 'channels.msteams.queue.usage',
            'turn.error' => 'channels.msteams.turn.error',
            'turn.error_unknown' => 'channels.msteams.turn.error_unknown',
            'turn.spend_cap' => 'channels.msteams.turn.spend_cap',
            'approve.resolved_once' => 'channels.msteams.approve.resolved_once',
            'approve.resolved_always' => 'channels.msteams.approve.resolved_always',
            'approve.resolved_denied' => 'channels.msteams.approve.resolved_denied',
            'approve.resolved_default' => 'channels.msteams.approve.resolved_default',
            'approve.hint' => 'channels.msteams.approve.hint',
            'approval.always_needs_approver' => 'channels.msteams.approval.always_needs_approver',
            'approval.refused' => 'channels.msteams.approval.refused',
            'replay.gap' => 'channels.msteams.replay.gap',
            'media.refused' => 'channels.msteams.media.refused',
            default => 'channels.msteams.' . $key,
        };
    }
}

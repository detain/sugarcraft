<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Backend\QueueMode;
use SugarCraft\Crush\Host\SessionHost;
use SugarCraft\Crush\Host\SubmitOptions;
use SugarCraft\Crush\Host\TurnTicket;
use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;
use SugarCraft\Crush\Protocol\SessionFeed;
use SugarCraft\Crush\Server\ServerConfig;

/**
 * Prompting (Appendix O §6.3 "turn", §6.6): send, steer, interrupt, cancel,
 * and the queue behind a running turn.
 *
 * `session.send` SEPARATES ADMISSION FROM THE TRANSCRIPT. Its answer says how
 * the prompt was admitted — `started` (a new turn, with its `turnId`),
 * `queued` (behind the running turn, with the `queueId` `session.dequeue`
 * takes), `steered` (handed to the running turn at its next step, and held as
 * a follow-up so it is never lost), or `pending` (parked behind off-thread
 * work such as a forked hook) — while the prompt's own row arrives as the
 * durable `message.created` event, under its seq.
 *
 * Delivery while a turn runs: `queue` (the default), `steer`, or `interrupt`
 * (cancel the running turn, then send). The server caps the turns running at
 * once (`server.maxConcurrentTurns`); a prompt that would start one more is
 * refused `busy`, retryable.
 */
final class TurnMethods
{
    private const DELIVERY = ['queue' => QueueMode::Followup, 'steer' => QueueMode::Steer, 'interrupt' => QueueMode::Interrupt];

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('session.send', Scope::Write, 'Send a prompt: start a turn, or queue / steer / interrupt the running one.', self::send(...), true));
        $registry->add(MethodSpec::new('session.cancel', Scope::Write, 'Cancel the running turn (hard, or soft at the next step boundary).', self::cancel(...), true));
        $registry->add(MethodSpec::new('session.queue', Scope::Read, 'The prompts queued behind the running turn.', self::queue(...)));
        $registry->add(MethodSpec::new('session.dequeue', Scope::Write, 'Remove a queued prompt.', self::dequeue(...), true));
    }

    /** @return array<string, mixed> */
    private static function send(CallContext $call, Params $params): array
    {
        if ($call->server->isDraining()) {
            throw RpcError::of(ErrorCode::Busy, 'the server is shutting down', 'draining');
        }
        $host = $call->host(SessionMethods::sessionId($params));
        $text = $params->string('text', ServerConfig::MAX_CLIENT_MESSAGE_BYTES);
        $delivery = self::DELIVERY[$params->enum('delivery', \array_keys(self::DELIVERY), 'queue')];

        $key = $params->optionalString('idempotencyKey', 64);

        return self::admit($call, $host, $text, $delivery, $key);
    }

    /**
     * Submit $text to $host and answer how it was admitted (the shape
     * `session.send` and `command.exec` share).
     *
     * @return array<string, mixed>
     *
     * @throws RpcError busy past the concurrent-turn cap; conflict / refused
     */
    public static function admit(CallContext $call, SessionHost $host, string $text, QueueMode $delivery, ?string $key = null): array
    {
        $limit = $call->server->config()->maxConcurrentTurns;
        if (!$host->isBusy() && $call->server->turnsRunning() >= $limit) {
            throw RpcError::of(ErrorCode::Busy, \sprintf('%d turns are already running', $limit), 'too_many_turns', ['retryAfterMs' => 1000]);
        }

        $ticket = $host->submit($text, SubmitOptions::new()->withDelivery($delivery)->withIdempotencyKey($key));
        $feed = $call->feed($host);

        return match ($ticket->admitted) {
            TurnTicket::STARTED => \array_filter([
                'admitted' => TurnTicket::STARTED,
                'turnId' => $ticket->turnId,
                'messageId' => $ticket->messageId,
            ], static fn (mixed $value): bool => $value !== null),
            TurnTicket::QUEUED => [
                'admitted' => TurnTicket::QUEUED,
                'queuePosition' => $ticket->position,
                'queueId' => $feed->queued(\trim($text), $ticket->position),
            ],
            TurnTicket::STEERED => self::steered($feed, $ticket, \trim($text)),
            TurnTicket::PENDING => ['admitted' => TurnTicket::PENDING],
            default => throw RpcError::of(ErrorCode::Conflict, (string) ($ticket->reason ?? 'the prompt was refused'), 'refused'),
        };
    }

    /** @return array<string, mixed> */
    private static function steered(SessionFeed $feed, TurnTicket $ticket, string $text): array
    {
        $feed->steered($ticket->turnId, (string) $ticket->steerId);

        return \array_filter([
            'admitted' => TurnTicket::STEERED,
            'turnId' => $ticket->turnId,
            'steerId' => $ticket->steerId,
            'queuePosition' => $ticket->position,
            // Held as a follow-up too: it goes out after the turn unless the
            // turn read it first, and then leaves the queue (`turn.dequeued`).
            'queueId' => $feed->queued($text, $ticket->position),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private static function cancel(CallContext $call, Params $params): array
    {
        $host = $call->host(SessionMethods::sessionId($params));
        $turnId = $params->optionalString('turnId', 64);
        $mode = $params->enum('mode', ['hard', 'soft'], 'hard');
        $cleared = $params->bool('clearQueue') ? $host->clearQueue() : 0;

        if ($turnId !== null && $turnId !== $host->turnId()) {
            $cancelled = false;
        } else {
            $cancelled = $mode === 'soft' ? $host->cancelSoft() : $host->cancel($turnId);
        }
        if ($cleared > 0) {
            $call->feed($host)->reconcileQueue('removed');
        }

        return ['cancelled' => $cancelled, 'mode' => $mode, 'cleared' => $cleared];
    }

    /** @return array<string, mixed> */
    private static function queue(CallContext $call, Params $params): array
    {
        $host = $call->host(SessionMethods::sessionId($params));

        return ['items' => $call->feed($host)->queueEntries()];
    }

    /** @return array<string, mixed> */
    private static function dequeue(CallContext $call, Params $params): array
    {
        $host = $call->host(SessionMethods::sessionId($params));
        $queueId = $params->string('queueId', 32);
        if (!$call->feed($host)->dequeue($queueId)) {
            throw RpcError::notFound(\sprintf('no queued prompt %s', $queueId), 'queue_entry_not_found');
        }

        return ['dequeued' => true];
    }
}

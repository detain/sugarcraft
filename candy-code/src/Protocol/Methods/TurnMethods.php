<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Attachment;
use SugarCraft\Code\Attachments\FileMentions;
use SugarCraft\Code\AttachmentType;
use SugarCraft\Code\Backend\QueueMode;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SubmitOptions;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\SessionFeed;
use SugarCraft\Code\Server\ServerConfig;

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
 *
 * `session.send` also carries `images` (WEB-5b): up to four pasted base64
 * rasters, sniffed at the door and attached to the prompt that STARTS a turn.
 * The queue and steer lanes hold text (their `session.queue` rows are
 * strings), so images sent while a turn runs are refused `images_while_busy`
 * rather than silently dropped.
 */
final class TurnMethods
{
    /** A request's `delivery` => what the host does with the prompt while a turn runs (`bg.inject` takes it too). */
    public const DELIVERY = ['queue' => QueueMode::Followup, 'steer' => QueueMode::Steer, 'interrupt' => QueueMode::Interrupt];

    /** Raster kinds `session.send`'s `images` may declare; the handler re-sniffs and this list must match what {@see FileMentions::sniffImage()} recognizes. */
    public const PASTED_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** How many base64 images one `session.send` may carry (WEB-5b, the composer paste strip). */
    public const MAX_IMAGES = 4;

    /** Decoded-byte ceiling across every image of one `session.send` (per image stays {@see FileMentions::IMAGE_MAX_BYTES}). */
    public const MAX_IMAGE_TOTAL_BYTES = 10 * 1024 * 1024;

    /**
     * The exact base64 length of an at-ceiling image
     * ({@see FileMentions::IMAGE_MAX_BYTES} decoded bytes, three chars per
     * four): the ingress cap and the handler's byte check then bound the
     * same size, so an oversized paste always gets the named error, never a
     * generic one. Pure-integer const arithmetic — casts are not valid in
     * constant expressions.
     */
    public const MAX_IMAGE_B64_CHARS = (FileMentions::IMAGE_MAX_BYTES + 2 - ((FileMentions::IMAGE_MAX_BYTES + 2) % 3)) / 3 * 4;

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
        $images = self::decodeImages($params);
        // The host's queue and steer lane carry TEXT only (`session.queue`
        // rows are strings), so an image cannot ride a prompt that does not
        // start its turn. `interrupt` clears the running turn first and the
        // images go with the new one — that is the busy path that admits them.
        if ($images !== [] && $host->isBusy() && $delivery !== QueueMode::Interrupt) {
            throw RpcError::of(
                ErrorCode::Conflict,
                'images ride only a prompt that starts a turn; wait for the running turn, or send with delivery "interrupt"',
                'images_while_busy',
            );
        }
        // A leading `/word` or `!cmd` is routed by the host BEFORE the prose
        // path that attaches images — a command body or a shell run has no
        // attachment leg to miss them into. Refuse loudly what would
        // otherwise drop silently.
        $lead = \ltrim($text);
        if ($images !== [] && (\str_starts_with($lead, '/') || \str_starts_with($lead, '!'))) {
            throw RpcError::invalidParams('images ride a prose prompt; a /command or !bang takes none', 'images_with_command');
        }

        return self::admit($call, $host, $text, $delivery, $key, true, $images);
    }

    /**
     * Decode `session.send`'s `images` into Image attachments (WEB-5b). The
     * declared mime is a claim, never a fact: the bytes are re-sniffed
     * ({@see FileMentions::sniffImage()}) and the SNIFFED type is what rides
     * the attachment, so a data-URL string, an svg, or any bytes that are not
     * a real raster image are refused at the door instead of reaching a
     * provider wire as an image block whose media_type lies. The per-field
     * text budget ({@see ServerConfig::MAX_CLIENT_MESSAGE_BYTES}) is untouched
     * by this; images carry their own decoded-byte budget, and the transport
     * frame was raised for them ({@see ServerConfig::MAX_CLIENT_FRAME_BYTES}).
     *
     * @return list<Attachment>
     *
     * @throws RpcError invalidParams on any entry that is not a real image
     */
    private static function decodeImages(Params $params): array
    {
        $raw = $params->raw('images');
        if ($raw === null) {
            return [];
        }
        if (!\is_array($raw) || !\array_is_list($raw)) {
            throw RpcError::invalidParams('images must be an array of {name?, mime, data}', 'images_not_a_list');
        }

        $attachments = [];
        $total = 0;
        foreach ($raw as $image) {
            $bytes = \is_array($image) ? \base64_decode((string) ($image['data'] ?? ''), true) : false;
            $sniffed = $bytes === false || $bytes === '' ? null : FileMentions::sniffImage($bytes);
            if ($bytes === false || $bytes === '') {
                throw RpcError::invalidParams('each image must carry strict base64 in data', 'image_not_base64');
            }
            if ($sniffed === null) {
                throw RpcError::invalidParams('each image must carry real png/jpeg/gif/webp bytes', 'image_not_decodable');
            }
            if ($sniffed !== (string) ($image['mime'] ?? '')) {
                throw RpcError::invalidParams(\sprintf('the image declared %s but carries %s bytes', (string) ($image['mime'] ?? ''), $sniffed), 'image_mime_mismatch');
            }
            if (\strlen($bytes) > FileMentions::IMAGE_MAX_BYTES) {
                throw RpcError::invalidParams(\sprintf('an image is %d decoded bytes; the ceiling is %d', \strlen($bytes), FileMentions::IMAGE_MAX_BYTES), 'image_too_large');
            }
            $total += \strlen($bytes);
            if ($total > self::MAX_IMAGE_TOTAL_BYTES) {
                throw RpcError::invalidParams(\sprintf('the images together exceed %d decoded bytes', self::MAX_IMAGE_TOTAL_BYTES), 'image_budget_exceeded');
            }
            // basename() only: a client-supplied name is a chip label, never a
            // path. Without one, the sniffed subtype names the paste.
            $name = \basename((string) ($image['name'] ?? ''));
            if ($name === '' || $name === '.' || $name === '..') {
                $name = 'pasted-image.' . \substr($sniffed, \strlen('image/'));
            }
            $attachments[] = new Attachment($name, AttachmentType::Image, $bytes, $sniffed);
        }

        return $attachments;
    }

    /**
     * Submit $text to $host and answer how it was admitted (the shape
     * `session.send`, `command.exec` and `bg.inject` share). $resolveMentions
     * is false for text the user did not type — a background session's
     * answer, quoted — whose `@path` tokens are the model's, not a request to
     * attach a file. $attachments (WEB-5b) are images the sender pasted
     * alongside the text, already decoded and sniffed by the handler; the
     * other two callers carry none.
     *
     * @param list<Attachment> $attachments
     *
     * @return array<string, mixed>
     *
     * @throws RpcError busy past the concurrent-turn cap; conflict / refused
     */
    public static function admit(CallContext $call, SessionHost $host, string $text, QueueMode $delivery, ?string $key = null, bool $resolveMentions = true, array $attachments = []): array
    {
        $limit = $call->server->config()->maxConcurrentTurns;
        if (!$host->isBusy() && $call->server->turnsRunning() >= $limit) {
            throw RpcError::of(ErrorCode::Busy, \sprintf('%d turns are already running', $limit), 'too_many_turns', ['retryAfterMs' => 1000]);
        }

        $ticket = $host->submit($text, SubmitOptions::new()->withDelivery($delivery)->withIdempotencyKey($key)->withResolveMentions($resolveMentions)->withAttachments($attachments));
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
            // ISS-056: a built-in or `!bang` that ran here was admitted — it
            // simply never becomes a model turn, so it answers, not refuses.
            TurnTicket::HANDLED => ['admitted' => TurnTicket::HANDLED],
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

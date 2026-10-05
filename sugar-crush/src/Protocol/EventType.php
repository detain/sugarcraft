<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

use SugarCraft\Crush\Host\BackgroundEvents;
use SugarCraft\Crush\Host\SessionEvent;

/**
 * The `sugarcrush.v1` event catalogue (Appendix O §6.5): every event `type`
 * this server sends, whether it is durable, and whether it belongs to a
 * session or to the server.
 *
 * DURABLE events of a session are written to its `session_events` log before
 * anyone hears them, carry the `seq` the log gave them, and are what a
 * reconnecting client replays. EPHEMERAL ones (deltas, ticks) are live only
 * and never carry a seq. Server-scope events carry `sessionId: null` and no
 * seq either: there is no server log to replay, so a client that missed one
 * re-reads the state it describes (`session.list` for the `session.*` trio,
 * `permission.pending` for `permission.asked` / `permission.settled`).
 *
 * `server.hello`'s `features.events` and the generated schema both come from
 * {@see CATALOGUE}; {@see SessionEvent::DURABLE_TYPES} is the host's own copy
 * of the durable column, and `EventTypeTest` pins the two together.
 */
final class EventType
{
    public const SCOPE_SESSION = 'session';
    public const SCOPE_SERVER = 'server';

    public const SESSION_CREATED = 'session.created';
    public const SESSION_UPDATED = 'session.updated';
    public const SESSION_DELETED = 'session.deleted';
    public const PERMISSION_ASKED = 'permission.asked';
    public const PERMISSION_SETTLED = 'permission.settled';
    public const ASSISTANT_NARRATION = 'assistant.narration';
    public const SERVER_TICK = 'server.tick';
    public const SERVER_SHUTDOWN = 'server.shutdown';
    public const SERVER_OVERFLOW = 'server.overflow';

    /**
     * type => [durable, scope, what it says]
     *
     * @var array<string, array{0: bool, 1: string, 2: string}>
     */
    public const CATALOGUE = [
        SessionEvent::MESSAGE_CREATED => [true, self::SCOPE_SESSION, 'A transcript row was added: the prompt, a notice, a summary.'],
        SessionEvent::SESSION_STATUS => [true, self::SCOPE_SESSION, 'The session became idle, busy or waiting_permission.'],
        SessionEvent::TURN_STARTED => [true, self::SCOPE_SESSION, 'A turn began.'],
        SessionEvent::TURN_STEP => [false, self::SCOPE_SESSION, 'The running turn reached a step boundary.'],
        SessionEvent::TURN_STEERED => [true, self::SCOPE_SESSION, 'A steering message was handed to the running turn.'],
        SessionEvent::TURN_QUEUED => [true, self::SCOPE_SESSION, 'A prompt was queued behind the running turn.'],
        SessionEvent::TURN_DEQUEUED => [true, self::SCOPE_SESSION, 'A queued prompt left the queue (sent or removed).'],
        SessionEvent::TURN_COMPLETED => [true, self::SCOPE_SESSION, 'A turn ended, with its stopReason.'],
        SessionEvent::ASSISTANT_DELTA => [false, self::SCOPE_SESSION, 'Streamed reply text.'],
        SessionEvent::REASONING_DELTA => [false, self::SCOPE_SESSION, 'Streamed reasoning text.'],
        self::ASSISTANT_NARRATION => [false, self::SCOPE_SESSION, 'The tail of the reply so far, at most every 2 s, for narration subscriptions.'],
        SessionEvent::ASSISTANT_COMPLETED => [true, self::SCOPE_SESSION, 'The reply, complete; repairs any delta a client dropped.'],
        SessionEvent::TOOL_STARTED => [true, self::SCOPE_SESSION, 'A tool call started.'],
        SessionEvent::TOOL_FINISHED => [true, self::SCOPE_SESSION, 'A tool call finished (content capped; tool.output has the rest).'],
        SessionEvent::PERMISSION_REQUESTED => [true, self::SCOPE_SESSION, 'A tool call is waiting for an answer (permission.respond).'],
        SessionEvent::PERMISSION_RESOLVED => [true, self::SCOPE_SESSION, 'A question was answered or cancelled.'],
        SessionEvent::SUBAGENT_STARTED => [true, self::SCOPE_SESSION, 'A delegated sub-agent started.'],
        SessionEvent::SUBAGENT_PROGRESS => [false, self::SCOPE_SESSION, 'A delegated sub-agent made progress.'],
        SessionEvent::SUBAGENT_FINISHED => [true, self::SCOPE_SESSION, 'A delegated sub-agent finished.'],
        SessionEvent::USAGE_UPDATED => [true, self::SCOPE_SESSION, 'Token and cost usage changed.'],
        SessionEvent::TODO_UPDATED => [true, self::SCOPE_SESSION, 'A Todo call rewrote the session\'s todo list; carries the whole list.'],
        SessionEvent::SPEND_CAP_BREACHED => [true, self::SCOPE_SESSION, 'The session spend cap stopped the turn.'],
        SessionEvent::COMPACTION_COMPLETED => [true, self::SCOPE_SESSION, 'The history was compacted before a turn.'],
        self::SESSION_CREATED => [false, self::SCOPE_SERVER, 'A session was created.'],
        self::SESSION_UPDATED => [false, self::SCOPE_SERVER, 'A session was renamed, its mode changed, or its status changed (idle, busy, waiting_permission).'],
        self::SESSION_DELETED => [false, self::SCOPE_SERVER, 'A session was deleted.'],
        self::PERMISSION_ASKED => [false, self::SCOPE_SERVER, 'A question was put in an open session: permission.requested with its sessionId, for every client, following the session or not.'],
        self::PERMISSION_SETTLED => [false, self::SCOPE_SERVER, 'A question of an open session was answered or cancelled (permission.resolved, for every client).'],
        BackgroundEvents::STARTED => [false, self::SCOPE_SERVER, 'A background session started, or one an earlier server or TUI left running was re-adopted.'],
        BackgroundEvents::STATUS => [false, self::SCOPE_SERVER, 'A background session\'s status changed (running, stalled, …).'],
        BackgroundEvents::COMPLETED => [false, self::SCOPE_SERVER, 'A background session settled; bg.output reads its answer, bg.inject sends it to a session.'],
        self::SERVER_TICK => [false, self::SCOPE_SERVER, 'Liveness, every tickIntervalMs.'],
        self::SERVER_SHUTDOWN => [false, self::SCOPE_SERVER, 'The server is stopping.'],
        self::SERVER_OVERFLOW => [false, self::SCOPE_SERVER, 'This client fell behind; ephemeral events were dropped — resubscribe.'],
    ];

    private function __construct()
    {
    }

    /** @return list<string> every event type, sorted */
    public static function all(): array
    {
        $types = \array_keys(self::CATALOGUE);
        \sort($types);

        return $types;
    }

    public static function isKnown(string $type): bool
    {
        return isset(self::CATALOGUE[$type]);
    }

    public static function isDurable(string $type): bool
    {
        return self::CATALOGUE[$type][0] ?? false;
    }

    public static function scope(string $type): string
    {
        return self::CATALOGUE[$type][1] ?? self::SCOPE_SESSION;
    }

    public static function description(string $type): string
    {
        return self::CATALOGUE[$type][2] ?? '';
    }
}

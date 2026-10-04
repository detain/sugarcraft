<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Schema;

use SugarCraft\Crush\Host\BackgroundEvents;
use SugarCraft\Crush\Host\SessionEvent as E;
use SugarCraft\Crush\Protocol\EventType;
use SugarCraft\Crush\Protocol\Schema\Definitions as D;

/**
 * The `data` of every `sugarcrush.v1` event type (roadmap O-3c). Every type
 * in {@see EventType::CATALOGUE} has a row, and `ProtocolSchemaDriftTest`
 * checks the events a real session produces against them.
 */
final class EventSchemas
{
    /** @var array<string, Schema>|null */
    private static ?array $all = null;

    private function __construct()
    {
    }

    /** @return array<string, Schema> type => data schema, sorted by type */
    public static function all(): array
    {
        return self::$all ??= self::build();
    }

    public static function data(string $type): ?Schema
    {
        return self::all()[$type] ?? null;
    }

    /** @return array<string, Schema> */
    private static function build(): array
    {
        $row = ['messageId' => Schema::string(), 'ref' => Schema::integer(0)];
        $delta = Schema::object([
            'text' => Schema::string(),
            'partId' => Schema::string(),
            'offset' => Schema::integer(0)->describe('Byte offset into the part; a gap means a delta was dropped.'),
        ], ['text', 'partId', 'offset']);
        $subagent = Schema::object([
            'id' => Schema::string(),
            'op' => Schema::string(),
            'name' => Schema::string()->nullable(),
            'task' => Schema::string()->nullable(),
            'parentCallId' => Schema::string()->nullable(),
            'tail' => Schema::any(),
            'outcome' => Schema::any(),
        ], ['id', 'op']);
        $summary = Schema::ref(D::SESSION_SUMMARY);

        $schemas = [
            E::MESSAGE_CREATED => Schema::object([
                ...$row,
                'role' => Schema::string(),
                'kind' => Schema::string(),
                'content' => Schema::string(),
                'createdAt' => Schema::integer(0),
            ], ['messageId', 'role', 'content']),
            E::SESSION_STATUS => Schema::object([
                'status' => Schema::enum(['idle', 'busy', 'waiting_permission']),
            ], ['status']),
            E::TURN_STARTED => Schema::object(['turnId' => Schema::string(), 'messageId' => Schema::string()], ['turnId']),
            E::TURN_STEP => Schema::object([
                'step' => Schema::integer(0),
                'maxSteps' => Schema::integer(0),
                'context' => Schema::map(Schema::any())->nullable(),
            ], ['step', 'maxSteps']),
            E::TURN_STEERED => Schema::object(['turnId' => Schema::string(), 'steerId' => Schema::string()], ['steerId']),
            E::TURN_QUEUED => Schema::object([
                'queueId' => Schema::string(32),
                'text' => Schema::string(),
                'position' => Schema::integer(1),
            ], ['queueId', 'text', 'position']),
            E::TURN_DEQUEUED => Schema::object([
                'queueId' => Schema::string(32),
                'text' => Schema::string(),
                'reason' => Schema::enum(['sent', 'removed']),
            ], ['queueId', 'reason']),
            E::TURN_COMPLETED => Schema::object([
                'stopReason' => Schema::enum([E::STOP_END_TURN, E::STOP_MAX_STEPS, E::STOP_LENGTH, E::STOP_CANCELLED, E::STOP_SPEND_CAP, E::STOP_ERROR]),
                'error' => Schema::string(),
                'messageId' => Schema::string(),
            ], ['stopReason']),
            E::ASSISTANT_DELTA => $delta,
            E::REASONING_DELTA => $delta,
            EventType::ASSISTANT_NARRATION => Schema::object(['partId' => Schema::string(), 'tail' => Schema::string(4096)], ['partId', 'tail']),
            E::ASSISTANT_COMPLETED => Schema::object([
                ...$row,
                'content' => Schema::string(),
                'reasoning' => Schema::string(),
                'lengthStopped' => Schema::boolean(),
                'stepsTruncated' => Schema::boolean(),
                'usage' => Schema::ref(D::USAGE),
            ], ['content', 'lengthStopped', 'stepsTruncated']),
            E::TOOL_STARTED => Schema::object([
                ...$row,
                'toolCallId' => Schema::string(),
                'name' => Schema::string(),
                'arguments' => Schema::map(Schema::any()),
            ], ['toolCallId', 'name']),
            E::TOOL_FINISHED => Schema::object([
                ...$row,
                'toolCallId' => Schema::string(),
                'name' => Schema::string(),
                'isError' => Schema::boolean(),
                'durationMs' => Schema::integer(0),
                'content' => Schema::string()->describe('At most 256 KiB; tool.output reads the rest.'),
                'truncated' => Schema::boolean(),
                'diff' => Schema::string(),
                'denial' => Schema::object(['kind' => Schema::string(), 'reason' => Schema::string()], ['kind']),
                'replaces' => Schema::string(),
            ], ['toolCallId', 'name', 'isError', 'content']),
            E::PERMISSION_REQUESTED => Schema::ref(D::PENDING_ASK),
            E::PERMISSION_RESOLVED => Schema::object([
                'askId' => Schema::string(),
                'reply' => Schema::enum(['once', 'always', 'reject']),
                'note' => Schema::string(),
                'cancelled' => Schema::boolean(),
            ], ['askId']),
            E::SUBAGENT_STARTED => $subagent,
            E::SUBAGENT_PROGRESS => $subagent,
            E::SUBAGENT_FINISHED => $subagent,
            E::USAGE_UPDATED => Schema::object([
                'step' => Schema::integer(0),
                'usage' => Schema::ref(D::USAGE)->nullable(),
                'turnUsage' => Schema::ref(D::USAGE)->nullable(),
            ], ['step']),
            E::SPEND_CAP_BREACHED => Schema::object([
                'calls' => Schema::integer(0),
                'spent' => Schema::number(0),
                'cap' => Schema::number(0),
            ], ['calls', 'spent', 'cap']),
            E::COMPACTION_COMPLETED => Schema::object([
                'kind' => Schema::enum(['llm', 'heuristic', 'truncate']),
                'before' => Schema::integer(0),
                'after' => Schema::integer(0),
                'savedPct' => Schema::number(0),
            ], ['kind', 'before', 'after']),
            BackgroundEvents::STARTED => Schema::ref(D::BACKGROUND_SESSION),
            BackgroundEvents::STATUS => Schema::object([
                'bgId' => Schema::string(),
                'status' => Schema::string(),
                'previous' => Schema::string(),
            ], ['bgId', 'status', 'previous']),
            BackgroundEvents::COMPLETED => Schema::ref(D::BACKGROUND_SESSION),
            EventType::SESSION_CREATED => $summary,
            EventType::SESSION_UPDATED => $summary,
            EventType::SESSION_DELETED => Schema::object(['id' => Schema::ref(D::SESSION_ID)], ['id']),
            EventType::SERVER_TICK => Schema::object(['now' => Schema::integer(0), 'turnsRunning' => Schema::integer(0)], ['now', 'turnsRunning']),
            EventType::SERVER_SHUTDOWN => Schema::object(['reason' => Schema::string(), 'graceSeconds' => Schema::number(0)], ['reason', 'graceSeconds']),
            EventType::SERVER_OVERFLOW => Schema::object([
                'dropped' => Schema::integer(0),
                'action' => Schema::enum(['resubscribe']),
            ], ['dropped', 'action']),
        ];
        \ksort($schemas);

        return $schemas;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Schema;

use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;

/**
 * The shapes several methods and events share, published once under the
 * schema's `$defs` and referenced by name ({@see Schema::ref()}).
 */
final class Definitions
{
    public const SESSION_ID = 'SessionId';
    public const PERMISSION_MODE = 'PermissionMode';
    public const SESSION_SUMMARY = 'SessionSummary';
    public const PENDING_ASK = 'PendingAsk';
    public const QUEUE_ENTRY = 'QueueEntry';
    public const SNAPSHOT = 'SessionSnapshot';
    public const SUBSCRIPTION = 'Subscription';
    public const ADMISSION = 'Admission';
    public const MEMORY_ENTRY = 'MemoryEntry';
    public const ENVELOPE = 'EventEnvelope';
    public const ERROR = 'Error';
    public const USAGE = 'Usage';
    public const BACKGROUND_SESSION = 'BackgroundSession';

    private function __construct()
    {
    }

    /** @return array<string, Schema> name => schema, sorted by name */
    public static function all(): array
    {
        $defs = [
            self::SESSION_ID => Schema::string(128)->describe('A session id: letters, digits, ".", "_" and "-".'),
            self::PERMISSION_MODE => Schema::enum(\array_map(static fn (PermissionMode $mode): string => $mode->value, PermissionMode::cases())),
            self::SESSION_SUMMARY => Schema::object([
                'id' => Schema::ref(self::SESSION_ID),
                'name' => Schema::string()->nullable(),
                'provider' => Schema::string()->nullable(),
                'model' => Schema::string()->nullable(),
                'kind' => Schema::string()->nullable(),
                'parentId' => Schema::string()->nullable(),
                'createdAt' => Schema::string()->nullable(),
                'updatedAt' => Schema::string()->nullable(),
                'turns' => Schema::integer(0)->nullable(),
                'preview' => Schema::string()->nullable(),
                'open' => Schema::boolean(),
                'status' => Schema::enum(['closed', 'idle', 'busy', 'waiting_permission']),
                'permissionMode' => Schema::ref(self::PERMISSION_MODE)->nullable(),
                'spentUsd' => Schema::number(0)->nullable(),
                'root' => Schema::string()->describe('Set by the serve gateway on a session another project root\'s workspace host answered for (roadmap O-7).'),
            ], ['id', 'open', 'status']),
            self::PENDING_ASK => Schema::object([
                'askId' => Schema::string(16),
                'toolCallId' => Schema::string(),
                'tool' => Schema::string(),
                'arguments' => Schema::map(Schema::any()),
                'reason' => Schema::string(),
                'source' => Schema::string()->describe('`gate`, or `hook:<names>`'),
                'mode' => Schema::string(),
                'options' => Schema::arrayOf(Schema::enum(['once', 'always', 'reject'])),
                'alwaysScope' => Schema::map(Schema::string()),
            ], ['askId', 'toolCallId', 'tool', 'arguments', 'options']),
            self::QUEUE_ENTRY => Schema::object([
                'queueId' => Schema::string(32),
                'text' => Schema::string(),
                'position' => Schema::integer(1),
            ], ['queueId', 'text', 'position']),
            self::SNAPSHOT => Schema::object([
                'sessionId' => Schema::ref(self::SESSION_ID),
                'status' => Schema::enum(['idle', 'busy', 'waiting_permission']),
                'turnId' => Schema::string(),
                'messages' => Schema::arrayOf(Schema::map(Schema::any()))->describe('Transcript rows, as they are saved.'),
                'queued' => Schema::arrayOf(Schema::string()),
                'spentUsd' => Schema::number(0),
                'lastSeq' => Schema::integer(0),
                'permissionMode' => Schema::ref(self::PERMISSION_MODE)->nullable(),
                'pendingAsks' => Schema::arrayOf(Schema::ref(self::PENDING_ASK)),
                'queue' => Schema::arrayOf(Schema::ref(self::QUEUE_ENTRY)),
                'subagents' => Schema::arrayOf(Schema::map(Schema::any())),
            ], ['sessionId', 'status', 'messages', 'lastSeq', 'pendingAsks', 'queue']),
            self::SUBSCRIPTION => Schema::oneOf(
                Schema::object([
                    'reset' => Schema::enum([true]),
                    'snapshot' => Schema::ref(self::SNAPSHOT),
                    'throughSeq' => Schema::integer(0),
                    'pendingAsks' => Schema::arrayOf(Schema::ref(self::PENDING_ASK)),
                ], ['reset', 'snapshot', 'throughSeq', 'pendingAsks'])->describe('Caught up from a snapshot; live from throughSeq.'),
                Schema::object([
                    'fromSeq' => Schema::integer(1),
                    'throughSeq' => Schema::integer(0),
                    'pendingAsks' => Schema::arrayOf(Schema::ref(self::PENDING_ASK)),
                ], ['fromSeq', 'throughSeq', 'pendingAsks'])->describe('Replaying from fromSeq through throughSeq, then live.'),
            ),
            self::ADMISSION => Schema::object([
                'admitted' => Schema::enum(['started', 'queued', 'steered', 'pending']),
                'turnId' => Schema::string(),
                'messageId' => Schema::string(),
                'steerId' => Schema::string(),
                'queuePosition' => Schema::integer(1),
                'queueId' => Schema::string(32),
            ], ['admitted']),
            self::MEMORY_ENTRY => Schema::object([
                'id' => Schema::string(),
                'type' => Schema::string(),
                'tags' => Schema::arrayOf(Schema::string()),
                'scope' => Schema::enum(['user', 'project', 'agent']),
                'content' => Schema::string(),
                'createdAt' => Schema::string(),
                'modifiedAt' => Schema::string(),
            ], ['id', 'scope', 'content']),
            self::ENVELOPE => Schema::object([
                'sessionId' => Schema::ref(self::SESSION_ID)->nullable(),
                'seq' => Schema::integer(1)->describe('Durable session events only; gap-free per session.'),
                'type' => Schema::string(),
                'ts' => Schema::integer(0)->describe('Milliseconds since the epoch.'),
                'turnId' => Schema::string(),
                'durable' => Schema::boolean(),
                'data' => Schema::map(Schema::any()),
                'root' => Schema::string()->describe('Set by the serve gateway on an event relayed from another project root\'s workspace host (roadmap O-7).'),
            ], ['sessionId', 'type', 'ts', 'durable', 'data']),
            self::ERROR => Schema::object([
                'code' => Schema::integer(),
                'message' => Schema::string(),
                'data' => Schema::object([
                    'kind' => Schema::string(),
                    'retryable' => Schema::boolean(),
                    'retryAfterMs' => Schema::integer(0),
                ], ['kind']),
            ], ['code', 'message', 'data']),
            self::BACKGROUND_SESSION => Schema::object([
                'bgId' => Schema::string(),
                'name' => Schema::string(),
                'task' => Schema::string(),
                'status' => Schema::enum(\array_map(static fn (BackgroundSessionStatus $status): string => $status->value, BackgroundSessionStatus::cases())),
                'agent' => Schema::string(),
                'model' => Schema::string(),
                'tags' => Schema::arrayOf(Schema::string()),
                'workingDirectory' => Schema::string(),
                'createdAt' => Schema::string(),
                'completedAt' => Schema::string()->nullable(),
                'tokensUsed' => Schema::integer(0),
                'costUsd' => Schema::number(0),
                'error' => Schema::string()->nullable(),
                'outputBytes' => Schema::integer(0)->describe('bg.output reads the output itself, from an offset.'),
                'forkedSessionId' => Schema::string()->nullable()->describe('The stored session a /fork session continues; null for /bg.'),
                'adopted' => Schema::boolean()->describe('Re-adopted at boot from an earlier server or TUI.'),
            ], ['bgId', 'name', 'task', 'status', 'createdAt', 'outputBytes', 'adopted'])->describe('A background (/bg) session; never its output.'),
            self::USAGE => Schema::object([
                'totalTokens' => Schema::integer(0),
                'inputTokens' => Schema::integer(0)->nullable(),
                'outputTokens' => Schema::integer(0)->nullable(),
                'cacheReadTokens' => Schema::integer(0)->nullable(),
                'cacheCreationTokens' => Schema::integer(0)->nullable(),
                'reasoningTokens' => Schema::integer(0)->nullable(),
                'costUsd' => Schema::number(0),
            ]),
        ];
        \ksort($defs);

        return $defs;
    }
}

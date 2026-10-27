<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Schema;

use SugarCraft\Code\Media\MediaKind;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Background\BackgroundSessionStatus;

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
    public const MEDIA_REF = 'MediaRef';
    public const CONTEXT_SOURCES = 'ContextSources';
    public const TRUST_PROMPT = 'TrustPrompt';

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
                // C-SES-2.c: the FTS5 body-match snippet, present only on a
                // row read through `session.list {query}` with the full-text
                // path answering; null everywhere else.
                'snippet' => Schema::string()->nullable(),
                'open' => Schema::boolean(),
                'status' => Schema::enum(['closed', 'idle', 'busy', 'waiting_permission']),
                'permissionMode' => Schema::ref(self::PERMISSION_MODE)->nullable(),
                'spentUsd' => Schema::number(0)->nullable(),
                // The ≥ companion of spentUsd (SES-1 law): true means the
                // figure is a lower bound. Read off the row's sticky
                // cost_unpriced cache, so it holds for closed sessions too.
                'spendUnpriced' => Schema::boolean(),
                'root' => Schema::string()->describe('Set by the serve gateway on a session another project root\'s workspace host answered for (roadmap O-7).'),
            ], ['id', 'open', 'status']),
            self::PENDING_ASK => Schema::object([
                'askId' => Schema::string(16),
                'toolCallId' => Schema::string(),
                'tool' => Schema::string(),
                'arguments' => Schema::map(Schema::any()),
                'reason' => Schema::string(),
                'source' => Schema::string()->describe('`gate`, `hook:<names>`, or `tool:<name>` for a question the call puts itself (`AskUser`, `PlanExit`)'),
                'mode' => Schema::string(),
                'options' => Schema::arrayOf(Schema::enum(['once', 'always', 'reject'])),
                'alwaysScope' => Schema::map(Schema::string()),
                'diff' => Schema::string()->describe('C-SEC-2.b: server-computed unified diff of the proposed call, confined to the session root and capped at 32 KiB (beyond it, an omitted note naming the change size, never silence); absent when the call has no previewable outcome. Has real file line numbers, unlike a client-minted snippet diff.'),
                'origin' => Schema::object([
                    'agentId' => Schema::string(),
                    'agentName' => Schema::string(),
                    'parentCallId' => Schema::string(),
                ], ['agentId'])->describe('The delegated run that asked, when a sub-agent\'s question was relayed; absent for the turn\'s own.'),
            ], ['askId', 'toolCallId', 'tool', 'arguments', 'options']),
            self::TRUST_PROMPT => Schema::object([
                'askId' => Schema::string(16),
                'kind' => Schema::enum(['trust'])->describe('WEB-3b: the marker that makes a `permission.asked` payload a project-trust prompt — answered by `context.trust`; `permission.respond` never sees these (they are not in the turn\'s question registry).'),
                'target' => Schema::enum(['mcp'])->describe('What a grant would enable — today only the project `.mcp.json` census verdict, the one launch-time trust with a user-tier grant writer.'),
                'root' => Schema::string(4096)->describe('The project root the prompt is about — the exact string a `trustedProjectMcp` entry becomes.'),
                'reason' => Schema::string()->describe('Why the prompt was put, for the panel line.'),
                'status' => Schema::enum(['asked', 'granted', 'rejected'])->describe('`asked` while open; one answer settles it for the process (the once-per-untrusted-root law).'),
                'persisted' => Schema::boolean()->describe('True when the answer wrote the user-tier trust list; a session grant or a refusal leaves it false.'),
            ], ['askId', 'kind', 'target', 'root', 'status']),
            self::QUEUE_ENTRY => Schema::object([
                'queueId' => Schema::string(32),
                'text' => Schema::string(),
                'position' => Schema::integer(1),
            ], ['queueId', 'text', 'position']),
            self::SNAPSHOT => Schema::object([
                'sessionId' => Schema::ref(self::SESSION_ID),
                'status' => Schema::enum(['idle', 'busy', 'waiting_permission']),
                'turnId' => Schema::string(),
                'messages' => Schema::arrayOf(Schema::map(Schema::any()))->describe('Transcript rows, as they are saved; a paged snapshot carries only its window.'),
                'totalMessages' => Schema::integer(0)->describe('Paged snapshots: every row the session holds.'),
                'oldestIndex' => Schema::integer(0)->describe('Paged snapshots: index of the first carried row in the full history; appending never shifts it.'),
                'hasMoreOlder' => Schema::boolean()->describe('Paged snapshots: older rows exist; ask session.get with beforeIndex = oldestIndex.'),
                'queued' => Schema::arrayOf(Schema::string()),
                'spentUsd' => Schema::number(0),
                // The snapshot side of the same SES-1 ≥ law (docs-fix-1023
                // S2): SessionSnapshot has emitted this key since the SES-1
                // weld while the schema never named it — a typed client could
                // not see the bit. Present only when true.
                'spendUnpriced' => Schema::boolean()->describe('True only when the session billed a model with no price on file; spentUsd is then a lower bound. Absent means priced.'),
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
                'admitted' => Schema::enum(['started', 'queued', 'steered', 'pending', 'handled']),
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
                'source' => Schema::enum(['home', 'repo'])->describe('Which store holds the note: the repository\'s .candy-code/memory/ or the home directory.'),
            ], ['id', 'scope', 'content', 'source']),
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
                // SES-1 ≥ law on the bg row: present ONLY when the daemon
                // billed an unpriced model, so costUsd is a lower bound
                // ({@see SessionSnapshot::shape()} carries the same bit on
                // the snapshot); absent means priced.
                'spendUnpriced' => Schema::boolean()->describe('True only when the session\'s model had no price on file; absent means the cost is an exact bill.'),
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
                // SES-1 disclosure on the live wire: the model a frame was
                // billed against when no price is on file, null otherwise.
                // Usage::toArray() has carried it since the ≥ law; naming it
                // here is what lets the web ≥ badge fire mid-turn instead of
                // only from the next snapshot (C-SES-2.c).
                'unpricedModel' => Schema::string()->nullable(),
            ]),
            self::MEDIA_REF => Schema::object([
                'artifactId' => Schema::string()->describe('MediaStore artifact id: the stored name minus its final extension.'),
                'kind' => Schema::enum(\array_map(static fn (MediaKind $kind): string => $kind->value, MediaKind::cases())),
                'path' => Schema::string()->describe('Server-local path to the stored file — a reference, never the bytes (Appendix C-7).'),
                'bytes' => Schema::integer(0)->describe('Size as the tool delivered it; 0 when the event carries only a path.'),
                'mime' => Schema::string()->describe('Media type inferred from the stored extension.'),
                'infotextDigest' => Schema::string()->describe('SHA-256 of the PNG infotext embedded in the artifact, when one was readable.'),
                'indexLabel' => Schema::string()->describe('Batch slot label, when a multi-artifact result names its rows.'),
            ], ['artifactId', 'kind', 'path', 'bytes', 'mime'])->describe('A media artifact a finished tool produced — identity and path only; bytes never ride the journal (Appendix C-7).'),
            self::CONTEXT_SOURCES => Schema::object([
                'instructionFiles' => Schema::arrayOf(Schema::object([
                    'path' => Schema::string(4096),
                    'bytes' => Schema::integer(0),
                    'tier' => Schema::enum(['project', 'personal']),
                ], ['path', 'bytes', 'tier']))->nullable()->describe('The documents the system prompt splices — names and sizes, bodies never (roadmap VIS-2a); null when this server could not price them.'),
                'memorySections' => Schema::arrayOf(Schema::ref(self::MEMORY_ENTRY))->describe('Every note of both stores across all scopes, the `memory.list` grouping rule (repo rows first).'),
                'skills' => Schema::arrayOf(Schema::object([
                    'name' => Schema::string(),
                    'tier' => Schema::string()->describe('Who put the file there — a SkillOrigin value, not the format.'),
                    'enabled' => Schema::boolean()->describe('False when the `disabledSkills` setting withheld it.'),
                ], ['name', 'tier', 'enabled']))->describe('The skills registry, as the prompt listing sees it.'),
                'mcp' => Schema::object([
                    'status' => Schema::string()->describe('The config-level verdict of the read-never-run census (`Bootstrap::mcpServerInventory`): trusted, refused, or absent. Nothing is started to answer this.'),
                    'error' => Schema::string()->nullable(),
                    'skipped' => Schema::arrayOf(Schema::string()),
                    'servers' => Schema::arrayOf(Schema::object([
                        'name' => Schema::string(),
                        'transport' => Schema::string(),
                        'trust' => Schema::enum(['trusted'])->describe('Rows exist only while the file is trusted; the http entry\'s URL stays off the wire (the `server.hello` media restraint, restated).'),
                    ], ['name', 'transport', 'trust'])),
                ], ['status', 'error', 'skipped', 'servers'])->describe('The `.mcp.json` declaration as `candy-code mcp list` reads it, minus per-entry details.'),
                'untrusted' => Schema::arrayOf(Schema::object([
                    'path' => Schema::string(4096),
                    'reason' => Schema::string(),
                ], ['path', 'reason']))->describe('Instruction paths the loader refused and why.'),
                'trust' => Schema::ref(self::TRUST_PROMPT)->nullable()->describe('WEB-3b: this root\'s trust prompt — measured here is where it is DISCOVERED: an untrusted project `.mcp.json` verdict mints the prompt (once per root) and server-scopes it as `permission.asked`; null while the verdicts need no grant or one was already settled.'),
                'breakdown' => Schema::object([
                    'window' => Schema::integer(0),
                    'sections' => Schema::arrayOf(Schema::object([
                        'label' => Schema::string(),
                        'stability' => Schema::string(),
                        'sections' => Schema::integer(0),
                        'bytes' => Schema::integer(0),
                        'tokens' => Schema::integer(0),
                    ], ['label', 'stability', 'sections', 'bytes', 'tokens']))->nullable(),
                    'toolCount' => Schema::integer(0)->nullable(),
                    'toolTokens' => Schema::integer(0)->nullable(),
                    'historyTokens' => Schema::integer(0),
                    'historyMessages' => Schema::integer(0),
                    'uiOnlyRows' => Schema::integer(0),
                    'largest' => Schema::arrayOf(Schema::object([
                        'index' => Schema::integer(1),
                        'role' => Schema::string(),
                        'tokens' => Schema::integer(0),
                        'preview' => Schema::string(),
                    ], ['index', 'role', 'tokens', 'preview'])),
                    'lastCache' => Schema::object(['read' => Schema::integer(0), 'prompt' => Schema::integer(0)], ['read', 'prompt'])->nullable(),
                    'sessionCache' => Schema::object(['read' => Schema::integer(0), 'prompt' => Schema::integer(0), 'replies' => Schema::integer(0)], ['read', 'prompt', 'replies'])->nullable(),
                    'pruning' => Schema::object([
                        'mode' => Schema::string(),
                        'sessionMode' => Schema::boolean(),
                        'outputs' => Schema::integer(0),
                        'outputTokens' => Schema::integer(0),
                        'files' => Schema::string()->nullable(),
                        'contextRows' => Schema::integer(0),
                        'contextRowTokens' => Schema::integer(0),
                        'block' => Schema::object([
                            'id' => Schema::integer(0),
                            'compressed' => Schema::integer(0),
                            'summary' => Schema::integer(0),
                        ], ['id', 'compressed', 'summary'])->nullable(),
                        'rows' => Schema::arrayOf(Schema::object([
                            'ref' => Schema::integer(0)->nullable(),
                            'tool' => Schema::string(),
                            'reason' => Schema::string(),
                            'by' => Schema::string(),
                            'tokens' => Schema::integer(0),
                        ], ['tool', 'reason', 'by', 'tokens'])),
                    ], ['mode', 'sessionMode', 'outputs', 'outputTokens', 'contextRows', 'contextRowTokens', 'rows'])->nullable(),
                    'cacheBreaks' => Schema::object([
                        'breaks' => Schema::integer(0),
                        'last' => Schema::object(['from' => Schema::integer(0), 'to' => Schema::integer(0)], ['from', 'to'])->nullable(),
                    ], ['breaks'])->nullable(),
                    // P-CTX.f: OPTIONAL members — older servers never send
                    // them and older clients never read them; every row below
                    // is the SAME measured tokens the per-layer rows carry,
                    // attributed by category ({@see \SugarCraft\Code\Context\ContextCategories}).
                    'categories' => Schema::arrayOf(Schema::object([
                        'key' => Schema::string()->describe('The {@see \SugarCraft\Code\Context\ContextCategory} case value — stable vocabulary; `label` is the translated display name.'),
                        'label' => Schema::string(),
                        'tokens' => Schema::integer(0)->nullable()->describe('Estimated tokens billed to this category, null when not measured — never a fabricated zero.'),
                        'share' => Schema::integer(0)->nullable()->describe('`tokens` as a whole percent of `window`; null exactly when `tokens` is.'),
                        'sub' => Schema::arrayOf(Schema::object([
                            'label' => Schema::string()->describe('Server name, document path, or `#<index> <role>` for a message row.'),
                            'tokens' => Schema::integer(0)->nullable(),
                            'bytes' => Schema::integer(0)->nullable()->describe('Set instead of `tokens` for instruction documents — priced by size, bodies never.'),
                            'personal' => Schema::boolean()->describe('True when the document came from the personal tier.'),
                            'note' => Schema::string()->nullable()->describe('Replaces the figures with the reason there are none (the never-sent MCP instructions row).'),
                        ], ['label', 'tokens', 'bytes', 'personal', 'note'])),
                    ], ['key', 'label', 'tokens', 'share', 'sub']))->describe('The `/context` `By category` block (roadmap P-CTX.a / P-CTX.f): every measured token attributed by owner. Summing law — all rows except `autocompact-buffer` and `free-space` equal `window` less the reservation and what is left; content + buffer + free-space is the window.'),
                    'buffer' => Schema::integer(0)->nullable()->describe('The autocompact reservation — room between where background compaction fires and the window end; 0 when compaction is off, null when this session reports no compaction config.'),
                    'mcpServers' => Schema::arrayOf(Schema::object([
                        'name' => Schema::string(),
                        'count' => Schema::integer(0),
                        'tokens' => Schema::integer(0),
                    ], ['name', 'count', 'tokens']))->nullable()->describe('The `mcp__<server>__*` bridges grouped per server — the MCP half of `toolTokens`, measured in the same single pass; null when the tool list is unknown.'),
                ], ['window', 'sections', 'toolCount', 'toolTokens', 'historyTokens', 'historyMessages', 'uiOnlyRows', 'largest', 'lastCache', 'sessionCache'])->describe('The `/context` figures ({@see \SugarCraft\Code\Context\ContextBreakdown}) — same measurement, same single writer (WEB-3a).'),
            ], ['instructionFiles', 'memorySections', 'skills', 'mcp', 'untrusted', 'breakdown'])->describe('WEB-3a: what the session\'s context is built from — structure for the panel `/context` prints as text.'),
        ];
        \ksort($defs);

        return $defs;
    }
}

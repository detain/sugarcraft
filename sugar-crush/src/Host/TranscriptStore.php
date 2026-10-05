<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\DebouncedTranscriptWriter;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionLock;
use SugarCraft\Crush\Session\SessionMeta;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Todo\TodoList;
use SugarCraft\Crush\ToolResult;

/**
 * A session's transcript on disk: saving it (debounced or now), loading it
 * back with its crash repairs, the single-writer lock on it, and the identity
 * of each of its rows (roadmap O-2b, Appendix O §4.2 "Persistence and
 * checkpoints" → `Host\TranscriptStore`).
 *
 * EXTRACTED FROM CHAT, NOT BESIDE IT. This was `Chat::persistTranscript()`,
 * `loadTranscript()`, `reviveTranscriptMessage()`, `reviveCheckpointMessage()`
 * and the lock calls in `relockedForCurrentSession()`, each reaching straight
 * into {@see EnhancedSessionStore}. A host that runs sessions without a
 * screen needs exactly that and none of the rest of Chat, so it lives here
 * and Chat delegates — the TUI and a server cannot drift apart on what a
 * resumed transcript looks like. Chat reaches it through the
 * {@see WorkspaceContext::service()} locator (O-2a), not a constructor
 * parameter, and binds it to its own lineage's writer ({@see withWriter()}):
 * Chat's flush tick and shutdown flush watch that writer, so a snapshot
 * scheduled here is one they see.
 *
 * LIVE ROWS HAVE STABLE IDENTITIES. A row minted in this process carries no
 * id/ref until the session is reloaded — Chat is immutable and never holds a
 * stamped copy — but the store remembers what it gave the row, keyed by the
 * row's {@see Message::rowKey()} token, which every wither carries forward.
 * {@see identityOf()} reads it, {@see identify()} allocates it ahead of the
 * first save (for an event that must name the row now), and every save keeps
 * it. Before this the memo was keyed by instance, so a placeholder finished
 * by a wither, or a tool row stamped with its step, was saved under a fresh
 * ref each time (the W1-b/W2-a handoff).
 *
 * THE CONTEXT LEDGER rides beside the transcript (roadmap 2.2-2,
 * {@see loadLedger()} / {@see saveLedger()}): what the session's turns have
 * pruned out of the model's view belongs to the session, and is forked,
 * checkpointed and deleted with it.
 *
 * THE TODO LIST rides there too (roadmap 3.C, {@see loadTodos()} /
 * {@see saveTodos()}), in the session metadata row's `tasks` slot that had
 * been written by nothing until it: the agent's checklist is session state,
 * and it must outlive the compaction that summarises the call which wrote it.
 *
 * A store that is not an {@see EnhancedSessionStore} (or none) persists
 * nothing and loads nothing, the way Chat always degraded.
 */
final class TranscriptStore
{
    private function __construct(
        private readonly ?EnhancedSessionStore $store,
        private readonly DebouncedTranscriptWriter $writer,
        private readonly ?EventLog $events,
    ) {
    }

    /**
     * A transcript store over $store. Pass the writer whose pending snapshot
     * a host flushes; null builds one of its own.
     */
    public static function new(
        SessionStore|EnhancedSessionStore|null $store,
        ?DebouncedTranscriptWriter $writer = null,
        ?EventLog $events = null,
    ): self {
        $enhanced = $store instanceof EnhancedSessionStore ? $store : null;

        return new self(
            $enhanced,
            $writer ?? new DebouncedTranscriptWriter(),
            $events ?? ($enhanced !== null ? EventLog::new($enhanced) : null),
        );
    }

    /**
     * A copy that schedules through $writer — how Chat binds the workspace's
     * store to the writer its own flush tick watches.
     */
    public function withWriter(DebouncedTranscriptWriter $writer): self
    {
        return $writer === $this->writer ? $this : new self($this->store, $writer, $this->events);
    }

    /** The store transcripts are written to, or null when none persists. */
    public function store(): ?EnhancedSessionStore
    {
        return $this->store;
    }

    /** The debounced writer {@see schedule()} hands snapshots to. */
    public function writer(): DebouncedTranscriptWriter
    {
        return $this->writer;
    }

    /** The durable event log beside the transcripts, or null without a store. */
    public function events(): ?EventLog
    {
        return $this->events;
    }

    /** Whether this store persists anything at all. */
    public function persists(): bool
    {
        return $this->store !== null;
    }

    /**
     * Hand $history to the debounced writer as $sessionId's newest unsaved
     * transcript; see {@see DebouncedTranscriptWriter} for when it is written
     * and what a crash can lose.
     *
     * @param list<Message> $history
     */
    public function schedule(string $sessionId, array $history): void
    {
        if ($this->store !== null) {
            $this->writer->schedule($this->store, $sessionId, $history);
        }
    }

    /**
     * Write $history as $sessionId's transcript now, flushing any other
     * pending snapshot first so the two cannot land out of order.
     *
     * @param list<Message> $history
     *
     * @throws \Throwable whatever the store throws: unlike the debounced path,
     *                    a caller that saves synchronously wants to know
     */
    public function save(string $sessionId, array $history): void
    {
        if ($this->store === null) {
            return;
        }

        $this->writer->flush();
        $this->store->saveTranscript($sessionId, $history);
    }

    /** Write any snapshot still waiting on its debounce tick, now. */
    public function flush(): void
    {
        $this->writer->flush();
    }

    /** True while a snapshot is waiting to be written. */
    public function hasPending(): bool
    {
        return $this->writer->hasPending();
    }

    /**
     * The saved transcript of $sessionId as Messages, each "running"
     * placeholder healed ({@see reviveRow()}), or [] when the store has none
     * or cannot say.
     *
     * @return list<Message>
     */
    public function load(string $sessionId): array
    {
        if ($this->store === null) {
            return [];
        }

        try {
            $rows = $this->store->loadTranscript($sessionId) ?? [];
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (array $row): Message => self::reviveRow($row), $rows);
    }

    /**
     * $sessionId's saved context ledger (roadmap 2.2-2), or null when none
     * was saved, the store cannot say, or nothing persists.
     */
    public function loadLedger(string $sessionId): ?ContextLedger
    {
        if ($this->store === null) {
            return null;
        }

        try {
            return $this->store->loadContextLedger($sessionId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Save $ledger as $sessionId's context ledger, now. False when nothing
     * persists or the store refused it — never thrown: the ledger is a
     * cache-stability aid, and a turn is never failed over one.
     */
    public function saveLedger(string $sessionId, ContextLedger $ledger): bool
    {
        if ($this->store === null) {
            return false;
        }

        try {
            return $this->store->saveContextLedger($sessionId, $ledger);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * $sessionId's saved todo list (roadmap 3.C), read from
     * {@see SessionMeta::$tasks}, or null when the session has no metadata
     * row, the store cannot say, or nothing persists.
     */
    public function loadTodos(string $sessionId): ?TodoList
    {
        if ($this->store === null) {
            return null;
        }

        try {
            $meta = $this->store->getSessionMeta($sessionId);
        } catch (\Throwable) {
            return null;
        }

        return $meta === null ? null : TodoList::fromArray($meta->tasks);
    }

    /**
     * Save $todos as $sessionId's todo list, now — into the metadata row's
     * `tasks` slot, keeping the row's other fields. False when nothing
     * persists or the store refused it; never thrown, for the reason
     * {@see saveLedger()} gives: a turn is never failed over its checklist.
     */
    public function saveTodos(string $sessionId, TodoList $todos): bool
    {
        if ($this->store === null) {
            return false;
        }

        try {
            $meta = $this->store->getSessionMeta($sessionId) ?? SessionMeta::new($sessionId);
            $this->store->saveSessionMeta(
                $meta->withTasks($todos->toArray())->withLastActivity(new \DateTimeImmutable()),
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * $sessionId's remembered "always" grants as a grant map
     * ({@see \SugarCraft\Crush\Permissions\SessionPermissionMemo::fromGrants()}),
     * or [] when nothing persists or the store cannot say.
     *
     * @return array<string, true>
     */
    public function loadPermissionGrants(string $sessionId): array
    {
        if ($this->store === null) {
            return [];
        }

        try {
            return array_fill_keys($this->store->permissionGrants($sessionId), true);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Save $grants (a grant map) as $sessionId's remembered grants, now.
     * False when nothing persists or the store refused it; never thrown — a
     * grant that is not saved is asked again after a resume, nothing worse.
     *
     * @param array<string, bool> $grants
     */
    public function savePermissionGrants(string $sessionId, array $grants): bool
    {
        if ($this->store === null) {
            return false;
        }

        try {
            return $this->store->savePermissionGrants($sessionId, array_keys(array_filter(
                $grants,
                static fn (mixed $granted, mixed $key): bool => $granted === true && is_string($key),
                ARRAY_FILTER_USE_BOTH,
            )));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Take the single-writer lock on $sessionId (audit SES-3(b)), or null when
     * another process holds it — or when there is no store to lock under.
     */
    public function lock(string $sessionId): ?SessionLock
    {
        return $this->store?->lockSession($sessionId);
    }

    /** The pid holding $sessionId's lock, when it can be told. */
    public function lockHolder(string $sessionId): ?int
    {
        return $this->store?->sessionLockHolder($sessionId);
    }

    /**
     * The `[id, ref]` $message's row has: the one it carries, else the one a
     * save (or {@see identify()}) gave it — the same across every wither of
     * the row. Null while the row has never been saved or identified, and
     * always without a store.
     *
     * @return array{0: string, 1: int}|null
     */
    public function identityOf(Message $message): ?array
    {
        if ($this->store === null) {
            return $message->id !== null && $message->ref !== null ? [$message->id, $message->ref] : null;
        }

        return $this->store->identityOf($message);
    }

    /**
     * $message's identity in $sessionId, allocated now when its row has none,
     * and kept by every later save. Null without a store.
     *
     * @return array{0: string, 1: int}|null
     */
    public function identify(string $sessionId, Message $message): ?array
    {
        return $this->store?->assignIdentity($sessionId, $message);
    }

    /**
     * Rebuild one saved transcript row for a resume: every field
     * {@see Message::fromArray()} can restore — tool calls and results,
     * reasoning, usage, identity — with one repair. A "running" placeholder
     * in a saved transcript is a tool call whose process is gone, so it comes
     * back as the same "interrupted" error row {@see reviveCheckpointRow()}
     * makes for `/rewind`; left as a placeholder it would spin forever for a
     * result that cannot arrive.
     *
     * @param array<string, mixed> $row
     */
    public static function reviveRow(array $row): Message
    {
        $pendingId = $row['pendingToolCallId'] ?? null;
        if (\is_string($pendingId) && $pendingId !== '') {
            return self::reviveCheckpointRow($row);
        }

        return Message::fromArray($row);
    }

    /**
     * Rebuild one checkpointed history row, healing a checkpoint taken while
     * a tool call was still in flight (crush_feat.md §1 E7). See
     * {@see Chat::reviveCheckpointMessage()}, which delegates here and keeps
     * the full account of why each role and the placeholder are handled the
     * way they are.
     *
     * @param array<string, mixed> $row one raw checkpoint message, as
     *                                  {@see EnhancedSessionStore::saveCheckpoint()}
     *                                  serialised it
     */
    public static function reviveCheckpointRow(array $row): Message
    {
        $content = \is_string($row['content'] ?? null) ? $row['content'] : '';
        $pendingId = $row['pendingToolCallId'] ?? null;

        if (\is_string($pendingId) && $pendingId !== '') {
            return self::interruptedToolCallRow($content, $pendingId, Chat::INTERRUPTED_TOOL_CALL);
        }

        $message = match ($row['role'] ?? '') {
            'assistant' => Message::assistant($content),
            'system'    => Message::system($content),
            default     => Message::user($content),
        };

        // A command echo or notice must come back off a `/rewind` as UI-only
        // as it went in (audit 15b-03), or the restore would put it on the wire.
        return ($row['uiOnly'] ?? false) === true ? $message->withUiOnly() : $message;
    }

    /**
     * The "this call lost its runner" row: a synthetic assistant turn whose
     * error tool_result answers $callId, so the next request's wire has no
     * `tool_use` left unanswered. One builder for every heal path — a
     * restart ({@see Chat::INTERRUPTED_TOOL_CALL}) and a user cancel
     * ({@see Chat::CANCELLED_TOOL_CALL}) — so they render and serialise
     * identically and differ only in $reason.
     */
    public static function interruptedToolCallRow(string $content, string $callId, string $reason): Message
    {
        return Message::assistant($reason)
            ->withToolResults([ToolResult::error(
                $content !== '' ? $content : $callId,
                $reason,
                $callId,
            )]);
    }
}

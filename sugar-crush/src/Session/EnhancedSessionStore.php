<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Session;

use PDO;
use SugarCraft\Crush\Message;

/**
 * SQLite session persistence with enhanced metadata tracking and checkpointing.
 *
 * This class uses composition (wrapping) rather than inheritance to extend
 * SessionStore with columns for session summary, tasks, modified files,
 * agent states, and checkpoint snapshots to enable meaningful session
 * resumption, context replay, and /rewind functionality.
 *
 * Mirrors charmbracelet/charmbracelet enhanced session storage.
 */
final class EnhancedSessionStore
{
    private PDO $pdo;

    /** Wrapped session store for base session operations. */
    private SessionStore $sessionStore;

    public function __construct(string $dbPath)
    {
        /** @var \WeakMap<object, string> */
        $this->messageHashes = new \WeakMap();

        // Use umask 0077 before touching filesystem to ensure database files
        // are created with restrictive permissions (same as SessionStore).
        $previousUmask = umask(0077);
        try {
            // Create the base session store for fundamental session operations.
            // This initializes the base schema (sessions, messages, tool_calls).
            $this->sessionStore = new SessionStore($dbPath);

            // Share the same PDO connection for enhanced tables.
            // Both stores use the same SQLite database file.
            $this->pdo = $this->sessionStore->getPdo();

            $this->initEnhancedSchema();
        } finally {
            umask($previousUmask);
        }
    }

    // =======================================================================
    // Delegation to SessionStore (base session operations)
    // =======================================================================

    public function createSession(string $id, string $provider, string $model, ?string $systemPrompt = null, ?string $name = null): void
    {
        $this->sessionStore->createSession($id, $provider, $model, $systemPrompt, $name);
    }

    public function getSession(string $id): ?array
    {
        return $this->sessionStore->getSession($id);
    }

    public function getSessionByName(string $name): ?array
    {
        return $this->sessionStore->getSessionByName($name);
    }

    public function renameSession(string $id, string $name): void
    {
        $this->sessionStore->renameSession($id, $name);
    }

    public function forkSession(string $id): string
    {
        return $this->sessionStore->forkSession($id);
    }

    public function updateSession(string $id): void
    {
        $this->sessionStore->updateSession($id);
    }

    public function deleteSession(string $id): void
    {
        $this->sessionStore->deleteSession($id);
        // The FK cascade took this session's checkpoint_blobs rows with it, so
        // every id this instance had interned for it is now dangling. See
        // internMessages(): ANY blob deletion has to invalidate the cache, not
        // just the GC's own.
        $this->forgetInternedBlobs($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(int $limit = 20): array
    {
        return $this->sessionStore->listSessions($limit);
    }

    public function addMessage(string $sessionId, array $message): int
    {
        return $this->sessionStore->addMessage($sessionId, $message);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMessages(string $sessionId): array
    {
        return $this->sessionStore->getMessages($sessionId);
    }

    public function addToolCall(string $sessionId, int $messageId, array $toolCall): void
    {
        $this->sessionStore->addToolCall($sessionId, $messageId, $toolCall);
    }

    public function pruneSessions(int $daysOld = 30, ?string $exemptSessionId = null): int
    {
        $pruned = $this->sessionStore->pruneSessions($daysOld, $exemptSessionId);
        if ($pruned > 0) {
            // Same cascade as deleteSession(), for a set of ids the caller
            // never named.
            $this->blobIds = [];
        }

        return $pruned;
    }

    /**
     * @return array<int, array{id: string, name: ?string, updated_at: string, messages: int}>
     */
    public function pruneReport(): array
    {
        return $this->sessionStore->pruneReport();
    }

    private function initEnhancedSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS session_meta (
                session_id TEXT PRIMARY KEY,
                summary TEXT DEFAULT \'\',
                tasks TEXT DEFAULT \'[]\',
                modified_files TEXT DEFAULT \'[]\',
                agent_states TEXT DEFAULT \'[]\',
                last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        ');

        // Index for efficient last_activity queries (session index sorted by recency)
        $this->pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_session_meta_last_activity
            ON session_meta(last_activity DESC)
        ');

        // Checkpoints table for /rewind functionality
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS checkpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                "index" INTEGER NOT NULL,
                state_data TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        ');

        // Index for efficient checkpoint lookup by session and index
        $this->pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_checkpoints_session_index
            ON checkpoints(session_id, "index" DESC)
        ');

        // Content-addressed message bodies shared by every checkpoint of a
        // session — see saveCheckpoint() for why checkpoints stopped storing
        // the conversation inline. Created here rather than in a one-shot
        // migration because initEnhancedSchema() runs on every construction,
        // so an existing database picks the table up the next time it opens
        // and keeps reading its old inline checkpoints meanwhile.
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS checkpoint_blobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                hash TEXT NOT NULL,
                payload TEXT NOT NULL,
                UNIQUE (session_id, hash),
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        ');
    }

    /**
     * Get enhanced metadata for a session.
     */
    public function getSessionMeta(string $sessionId): ?SessionMeta
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM session_meta WHERE session_id = ?
        ');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return new SessionMeta(
            sessionId: $row['session_id'],
            summary: $row['summary'],
            tasks: json_decode($row['tasks'], true) ?: [],
            modifiedFiles: json_decode($row['modified_files'], true) ?: [],
            agentStates: json_decode($row['agent_states'], true) ?: [],
            lastActivity: new \DateTimeImmutable($row['last_activity']),
        );
    }

    /**
     * Save enhanced metadata for a session.
     */
    public function saveSessionMeta(SessionMeta $meta): void
    {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO session_meta
                (session_id, summary, tasks, modified_files, agent_states, last_activity)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $meta->sessionId,
            $meta->summary,
            json_encode($meta->tasks),
            json_encode($meta->modifiedFiles),
            json_encode($meta->agentStates),
            $meta->lastActivity->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * List sessions with their enhanced metadata, ordered by last activity.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSessionsWithMeta(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('
            SELECT s.*, sm.summary, sm.tasks, sm.modified_files, sm.agent_states, sm.last_activity
            FROM sessions s
            LEFT JOIN session_meta sm ON s.id = sm.session_id
            ORDER BY COALESCE(sm.last_activity, s.updated_at) DESC
            LIMIT ?
        ');
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            $row['tasks'] = $row['tasks'] ? json_decode($row['tasks'], true) : [];
            $row['modified_files'] = $row['modified_files'] ? json_decode($row['modified_files'], true) : [];
            $row['agent_states'] = $row['agent_states'] ? json_decode($row['agent_states'], true) : [];
            return $row;
        }, $rows);
    }

    // =======================================================================
    // Checkpoint management (for /rewind functionality)
    // =======================================================================

    private const MAX_CHECKPOINTS_PER_SESSION = 100;

    /**
     * Envelope key marking a `state_data` payload as message-referencing
     * rather than a full inline snapshot. Its presence is what distinguishes
     * the two on read; rows written before this existed have no such key and
     * are still decoded verbatim.
     */
    private const CHECKPOINT_ENVELOPE_VERSION = '__cpv';

    /** Envelope key holding the ordered list of `checkpoint_blobs.id`. */
    private const CHECKPOINT_ENVELOPE_MESSAGES = '__cpm';

    /** Envelope key holding the rest of the chat state, `messages` nulled. */
    private const CHECKPOINT_ENVELOPE_STATE = '__cps';

    /**
     * Interned message bodies, `sessionId => [sha256 => checkpoint_blobs.id]`.
     *
     * Keeps the steady-state cost of {@see saveCheckpoint()} proportional to
     * the messages the turn actually ADDED rather than to the whole history:
     * every message already checkpointed is a cache hit and issues no query
     * at all. Cold on the first save of a process, which costs one batched
     * SELECT.
     *
     * Bounded to {@see MAX_CACHED_SESSIONS} sessions, least-recently-interned
     * evicted first. A single session's map grows with the messages this
     * PROCESS has checkpointed (~80 B each) and is released when the session
     * falls out of the window — nothing else releases it, because a store
     * instance is never told a session is finished.
     *
     * @var array<string, array<string, int>>
     */
    private array $blobIds = [];

    /**
     * Value of `PRAGMA data_version` when {@see $blobIds} was last known to
     * match the database. See {@see forgetInternedBlobsIfStale()}.
     */
    private ?string $blobDataVersion = null;

    /**
     * Content hash of every message object this process has already encoded,
     * keyed by the object itself.
     *
     * ## What this buys, measured
     *
     * {@see $blobIds} took the DISK cost of a checkpoint down to the messages
     * the turn actually added — `INSERT OR IGNORE` writes only new blobs. It
     * did nothing for the CPU cost, because {@see internMessages()} still had
     * to `json_encode()` and `sha256` EVERY message in the history to work out
     * which ones those were. That is O(N) work per turn and O(N²) per session,
     * paid synchronously inside `Chat::submit()` — i.e. between the user
     * pressing Enter and the request going out.
     *
     * On a 400-turn history of 32 KB messages (an ordinary size once tool
     * results are in the transcript) that measured 14 ms of `json_encode` plus
     * 38 ms of `sha256` — 52 ms of dead time on every prompt, ~10 s over the
     * session. A hit here costs 25 µs for the same 400 messages.
     *
     * ⚠️ MEASURE THE ENCODE THE WAY THE LOOP DOES IT. A review re-measured
     * this and reported the encode half as 7.5 ms, i.e. half what is recorded
     * above. That benchmark encoded and threw the payload away;
     * {@see internMessages()} KEEPS it (`$fresh[$hash] = $payload`), so it can
     * insert the bytes for a missing blob without a second encode. Retaining
     * 400 × 32 KB is the other ~7 ms, and it is real work this path performs.
     * Re-measured three ways on the same box: encode-and-discard 7.40 ms,
     * encode-and-retain 14.38 ms, and the faithful
     * encode+`sha256`+retain loop 49.72 ms against `sha256` alone at
     * 37.89 ms — 11.8 ms of encode attributable inside the real loop. The
     * figures above stand; do not "correct" them to the discarded-payload
     * number.
     *
     * ## Why object identity is a sound key
     *
     * {@see Message} is `final` and every property is `readonly`, as is every
     * type reachable from one: `Attachment` and `Usage` are `readonly class`,
     * `ToolCall` and `ToolResult` declare every property `readonly`. So a
     * given instance's JSON encoding is fixed for its lifetime and this map
     * can never go stale:
     *
     *   - EDITING a message produces a DIFFERENT instance — `with*()`
     *     constructs a new `Message` — which misses the memo and is encoded.
     *   - REWINDING replaces the history list wholesale; the instances that
     *     fall out take their entries with them, because a `WeakMap` holds its
     *     keys weakly.
     *   - Anything that is NOT a `Message` — a plain array, or an arbitrary
     *     object an embedder checkpoints — is not memoised; it takes the
     *     encode path every time, exactly as before.
     *
     * THE GUARD MUST MATCH THE PROOF, and for one revision it did not: the
     * memo was written under `is_object()` while the argument above is about
     * `Message`. Every clause of the proof is a claim about THAT type, and
     * nothing constrains an object an embedder hands to
     * {@see saveCheckpoint()} — `EnhancedSessionStore` is a public seam, and
     * `Chat` merely happens to pass `list<Message>`. A mutable object was
     * therefore pinned to its first-seen encoding for the rest of the
     * process: checkpoint, mutate, checkpoint again, and the second
     * checkpoint restored the FIRST value, silently. That is a corruption the
     * pre-memo code could not produce. `messageFingerprint()` now tests
     * `instanceof Message`, and `CheckpointStorageTest` pins it with a
     * mutable object whose two checkpoints must differ.
     *
     * ## Why the HASH and not the payload
     *
     * Caching the encoded payload would cut the remaining encode on the cold
     * path too, at the price of holding a second full copy of the history in
     * memory. The hash is 64 bytes and is all the steady-state path needs:
     * a hash already in {@see $blobIds} never needs its payload again. The
     * rare hash that is NOT (first save of a process, or another connection
     * bumped `data_version`) re-encodes lazily, at which point it was going to
     * pay for the round trip anyway.
     *
     * @var \WeakMap<object, string>
     */
    private \WeakMap $messageHashes;

    /**
     * How many sessions {@see $blobIds} keeps maps for.
     *
     * One live conversation plus room for the sessions `/branch`, `/session`
     * and background runs move between inside one process.
     */
    private const MAX_CACHED_SESSIONS = 4;

    /**
     * Save a checkpoint snapshot for the session.
     *
     * Checkpoints are written once per turn by {@see
     * \SugarCraft\Crush\Chat::submit()} and read back only by `/rewind`, so
     * the feature's contract is "every turn is individually rewindable, up to
     * MAX_CHECKPOINTS_PER_SESSION turns back". That contract is preserved
     * exactly — what changed is where the bytes go.
     *
     * This used to `json_encode($chatState)` in full, meaning turn N wrote
     * the whole N-message history again: O(N²) bytes over a session, and the
     * single largest write amplifier in the store. Messages are now stored
     * once each in `checkpoint_blobs`, addressed by the SHA-256 of their
     * encoded form, and the checkpoint row keeps only the ordered list of
     * blob ids. A turn therefore writes its new messages plus a short id
     * list, so total message BYTES over a session are O(N).
     *
     * The envelope is not: its id list is itself O(N) per checkpoint, so total
     * bytes written stay Θ(N²) with a much smaller constant — a few bytes of
     * decimal id per message instead of the message. Measured on distinct
     * 200-byte bodies that is 18× fewer bytes written at 50 turns and 46× at
     * 400, with the envelope 77% of the total by then; the factor moves with
     * both turn count and message size.
     * Removing that last quadratic term needs a checkpoint format that can
     * reference a RANGE of blobs, which is a schema change and a separate
     * piece of work.
     *
     * Content addressing rather than a delta chain against the previous
     * checkpoint, for two reasons. First, history is not strictly
     * append-only: a `tool_running` placeholder is REPLACED in place when its
     * result lands, and `/compact` rewrites history wholesale — a
     * prefix-delta would degrade to a full snapshot on exactly the turns that
     * matter most, while content addressing just reuses the messages that did
     * not change. Second, a chain makes each checkpoint depend on its
     * predecessor, and `pruneOldCheckpoints()` deletes oldest-first, so every
     * prune would have to re-materialise the new oldest checkpoint as a full
     * snapshot — reintroducing O(N²) past the retention limit and giving one
     * corrupt row a blast radius over every checkpoint above it. Here each
     * checkpoint row is independent: it names blobs, and blobs never change.
     *
     * A throttle ("checkpoint every K turns") was rejected outright: it is
     * the one change that would silently alter what `/rewind 1` means, and
     * losing up to K turns of undo is a worse trade than a slightly larger
     * schema when an O(N) representation is available for the same
     * guarantees. That rejection still stands, and it is worth being precise
     * about what it did and did not buy: content addressing made the WRITE
     * O(N)-total, but the work of DECIDING what to write — encoding and
     * hashing every message in the history — stayed O(N) per turn and so
     * O(N²) per session, and it is paid inside `update()`. {@see
     * $messageHashes} is what makes that half proportional to the turn as
     * well, again without touching what a checkpoint means.
     *
     * @param string $sessionId The session ID
     * @param array $chatState The chat state to snapshot (messages, input buffer, agent context)
     * @return int The checkpoint index that was assigned
     *
     * @throws \JsonException on a state that cannot be encoded — loudly, so a
     *         checkpoint is skipped rather than stored with a hole in it
     */
    public function saveCheckpoint(string $sessionId, array $chatState): int
    {
        // Get the next index for this session
        $stmt = $this->pdo->prepare('
            SELECT COALESCE(MAX("index"), -1) + 1 FROM checkpoints WHERE session_id = ?
        ');
        $stmt->execute([$sessionId]);
        $nextIndex = (int) $stmt->fetchColumn();
        // fetchColumn() leaves the cursor open, which holds a read transaction
        // for as long as the statement lives. SQLite will not run its
        // automatic WAL checkpoint at a COMMIT taken while a reader is open,
        // so leaving this cursor across the INSERT below meant the WAL never
        // truncated and `session.db-wal` grew without bound for the life of
        // the process — the "files reaching hundreds of MB" symptom this whole
        // item is about. One turn per line, and every line held one open.
        $stmt->closeCursor();

        // Insert the new checkpoint
        $insertStmt = $this->pdo->prepare('
            INSERT INTO checkpoints (session_id, "index", state_data, created_at)
            VALUES (?, ?, ?, ?)
        ');
        $insertStmt->execute([
            $sessionId,
            $nextIndex,
            $this->encodeCheckpoint($sessionId, $chatState),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        // Enforce the 100 checkpoint limit: delete oldest checkpoints if over limit
        $this->pruneOldCheckpoints($sessionId, self::MAX_CHECKPOINTS_PER_SESSION);

        return $nextIndex;
    }

    /**
     * Serialise one chat state into a `state_data` payload.
     *
     * A state with no `messages` list has nothing to intern and is stored
     * inline exactly as before — the envelope is only worth its constant
     * overhead when there is a history to share.
     *
     * `messages` is nulled in place rather than removed from the state array
     * so the key keeps its original position; {@see decodeCheckpoint()}
     * writes the rehydrated list straight back into that slot, and callers
     * that compare a round-tripped state against the one they saved (the
     * checkpoint tests do, with `assertSame`) see identical key order.
     *
     * @param array<string, mixed> $chatState
     *
     * @throws \JsonException on a state that cannot be encoded at all
     */
    private function encodeCheckpoint(string $sessionId, array $chatState): string
    {
        $messages = $chatState['messages'] ?? null;
        if (!is_array($messages)) {
            return self::encodeJson($chatState);
        }

        // The messages go down to internMessages() UNENCODED. Encoding them
        // here is what made every turn cost a full pass over the history; see
        // {@see $messageHashes} for the measurement and for why an
        // identity-keyed memo is sound.
        $interned = $this->internMessages($sessionId, array_values($messages));

        $chatState['messages'] = null;

        return self::encodeJson([
            self::CHECKPOINT_ENVELOPE_VERSION  => 1,
            self::CHECKPOINT_ENVELOPE_MESSAGES => $interned,
            self::CHECKPOINT_ENVELOPE_STATE    => $chatState,
        ]);
    }

    /**
     * This message's content hash, encoding it only if this process has not
     * already done so for this exact instance.
     *
     * Returns the payload alongside the hash ONLY when it had to be produced;
     * a memo hit returns null there, and a caller that then discovers it needs
     * the bytes after all asks {@see encodeJson()} again. Deliberate: holding
     * the payloads would double the memory cost of a history for a saving the
     * steady-state path never collects, since a hash already in
     * {@see $blobIds} is never re-inserted.
     *
     * @return array{0: string, 1: ?string} [hash, payload-if-freshly-encoded]
     *
     * @throws \JsonException
     */
    private function messageFingerprint(mixed $message): array
    {
        // `instanceof Message`, NOT `is_object()`. The memo's soundness proof
        // is a proof about {@see Message} specifically — that type and every
        // type reachable from it is deeply immutable, so an instance's
        // encoding is fixed for its lifetime. `is_object()` extended the memo
        // to objects that proof says nothing about, and a MUTABLE one was
        // then silently checkpointed at its first-seen contents forever:
        // save, mutate the object, save again, and the second checkpoint
        // restored the FIRST value. That is a corruption the pre-memo code
        // could not produce, because it re-encoded every message every time.
        $memo = $message instanceof Message ? ($this->messageHashes[$message] ?? null) : null;
        if ($memo !== null) {
            return [$memo, null];
        }

        $payload = self::encodeJson($message);
        $hash = hash('sha256', $payload);

        if ($message instanceof Message) {
            $this->messageHashes[$message] = $hash;
        }

        return [$hash, $payload];
    }

    /**
     * Encode one checkpoint fragment.
     *
     * WHY the flags: `json_encode()` returns `false` — not a partial string —
     * on a single invalid UTF-8 byte, and `(string) false` is `''`. A message
     * body that hit that path was stored as the empty string, hashed as the
     * empty string (so EVERY un-encodable message in the session collapsed
     * onto one shared blob), and came back from `json_decode('')` as `null`,
     * punching exactly the hole in the restored history that
     * {@see decodeCheckpoint()} promises never to return. `/rewind` then died
     * in its catch-all on `Argument #1 ($m) must be of type array, null
     * given`. Tool results reach history verbatim — `Chat::finishToolCalls()`
     * does not transcode them — so 64 raw bytes out of any binary-emitting
     * command is enough to trigger it.
     *
     * `JSON_INVALID_UTF8_SUBSTITUTE` makes that case WORK (U+FFFD in, message
     * still distinct, still restorable) and `JSON_THROW_ON_ERROR` makes any
     * other encode failure loud at the call site instead of silently empty.
     * The same pair, for the same reason, guards
     * {@see \SugarCraft\Crush\Cli\NonInteractive::encodeDocument()}.
     *
     * @throws \JsonException
     */
    private static function encodeJson(mixed $value): string
    {
        $json = json_encode($value, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);

        // Unreachable with JSON_THROW_ON_ERROR set; asserted rather than cast,
        // because the silent `(string)` cast IS the bug described above.
        return $json === false
            ? throw new \JsonException('json_encode() returned false without raising')
            : $json;
    }

    /**
     * Inverse of {@see encodeCheckpoint()}.
     *
     * Returns null when the envelope references a blob that is no longer on
     * disk. That is unreconstructable state, and reporting it as a missing
     * checkpoint (which every caller already handles) is honest, where
     * silently returning a history with a hole punched in it would not be.
     *
     * @return array<string, mixed>|null
     */
    private function decodeCheckpoint(string $stateData): ?array
    {
        $decoded = json_decode($stateData, true);
        if (!is_array($decoded)) {
            return null;
        }

        // Pre-envelope rows stored the whole state inline; they still read
        // back byte-for-byte as what they were written from.
        if (($decoded[self::CHECKPOINT_ENVELOPE_VERSION] ?? null) !== 1) {
            return $decoded;
        }

        $ids = $decoded[self::CHECKPOINT_ENVELOPE_MESSAGES] ?? null;
        $state = $decoded[self::CHECKPOINT_ENVELOPE_STATE] ?? null;
        if (!is_array($ids) || !is_array($state)) {
            return null;
        }

        $payloads = $this->loadBlobs(array_map('intval', $ids));
        if ($payloads === null) {
            return null;
        }

        $state['messages'] = $payloads;

        return $state;
    }

    /**
     * Map message bodies onto `checkpoint_blobs` ids, inserting the ones this
     * session has never checkpointed before.
     *
     * The cache is only trusted for as long as no OTHER connection has
     * written to the file — see {@see forgetInternedBlobsIfStale()} for why
     * that matters and what it costs.
     *
     * TWO caches meet here and they invalidate on different things.
     * {@see $blobIds} answers "does this hash have a row?" and is dropped
     * whenever the database moves under us. {@see $messageHashes} answers
     * "what is this object's hash?" and never needs dropping at all, because
     * the objects are immutable — a database write cannot change what a
     * `Message` in memory encodes to. Conflating them would have thrown away
     * the expensive half (the encode) to invalidate the cheap half (an int).
     *
     * @param list<mixed> $messages message bodies, in history order
     * @return list<int> blob ids, in the same order
     */
    private function internMessages(string $sessionId, array $messages): array
    {
        $this->forgetInternedBlobsIfStale();

        $known = $this->blobIds[$sessionId] ?? [];

        $hashes = [];
        /** @var array<string, mixed> $missing hash => the message it came from */
        $missing = [];
        /** @var array<string, string> $fresh hash => payload, for the ones encoded this call */
        $fresh = [];
        foreach ($messages as $message) {
            [$hash, $payload] = $this->messageFingerprint($message);
            $hashes[] = $hash;
            if ($payload !== null) {
                $fresh[$hash] = $payload;
            }
            if (!isset($known[$hash])) {
                $missing[$hash] = $message;
            }
        }

        if ($missing !== []) {
            // A blob may already be on disk from an earlier process even
            // though this instance's cache is cold, so look before inserting.
            foreach ($this->lookupBlobIds($sessionId, array_keys($missing)) as $hash => $id) {
                $known[$hash] = $id;
                unset($missing[$hash]);
            }
        }

        if ($missing !== []) {
            $insert = $this->pdo->prepare('
                INSERT OR IGNORE INTO checkpoint_blobs (session_id, hash, payload)
                VALUES (?, ?, ?)
            ');
            foreach ($missing as $hash => $message) {
                // A memo hit that reaches here is a hash whose blob this
                // process has not placed on disk (cold cache, or another
                // connection invalidated it) — the one case where the payload
                // has to be regenerated. Encoding is deterministic for an
                // immutable message, so the bytes match the hash by
                // construction.
                $insert->execute([$sessionId, $hash, $fresh[$hash] ?? self::encodeJson($message)]);
            }
            // Re-read rather than trusting lastInsertId(): OR IGNORE leaves it
            // stale on a row that lost a race with another connection.
            foreach ($this->lookupBlobIds($sessionId, array_keys($missing)) as $hash => $id) {
                $known[$hash] = $id;
            }
        }

        // Re-inserted last so the eviction order below is
        // least-recently-interned first.
        unset($this->blobIds[$sessionId]);
        $this->blobIds[$sessionId] = $known;
        while (count($this->blobIds) > self::MAX_CACHED_SESSIONS) {
            array_shift($this->blobIds);
        }

        $ids = [];
        foreach ($hashes as $hash) {
            $ids[] = $known[$hash] ?? 0;
        }

        return $ids;
    }

    /**
     * Drop every interned id when another connection has written to the file.
     *
     * The cache maps a body hash to a `checkpoint_blobs.id`, and those ids are
     * only stable while nothing deletes blobs. `collectCheckpointBlobs()`
     * clears the map on the instance that ran the GC, but two sugar-crush
     * terminals resume the SAME session — `Bootstrap::seedSession()` takes
     * `listSessions(1)[0]`, the globally most recent row — so a `/rewind` in
     * one process silently invalidated the other's cache. That process then
     * wrote envelopes naming deleted ids, and `decodeCheckpoint()` (correctly)
     * reported every one of them as "Checkpoint N not found": `/rewind` dead
     * for the rest of that process's life, with `/rewind` the table's only
     * consumer.
     *
     * `PRAGMA data_version` is the same invalidation `listSessions()` already
     * uses and reads only the database header, so the check is O(1) per save.
     * It changes for other-connection writes and NOT for ours, which is
     * exactly the split needed here: the GC, `deleteSession()` and
     * `pruneSessions()` clear their own instance directly (a same-connection
     * delete moves nothing), and everyone else's writes land here.
     *
     * Dropping rather than re-validating: a re-validation would have to
     * re-read every cached hash, which IS the cold path, so it would cost the
     * same and only complicate the invariant. The cache is kept — it is what
     * keeps the common single-process turn off a full-history hash lookup —
     * and a second live terminal pays one batched `lookupBlobIds()` per turn,
     * the same order as the envelope that turn writes anyway.
     *
     * A blob deleted between this check and the INSERT below is still
     * possible in principle; SQLite serialises the two writers, so the loser
     * re-interns on its next turn instead of staying wrong forever, and a
     * checkpoint that cannot be read is refused rather than silently
     * truncated.
     */
    private function forgetInternedBlobsIfStale(): void
    {
        $stmt = $this->pdo->query('PRAGMA data_version');
        $dataVersion = (string) $stmt->fetchColumn();
        $stmt->closeCursor();

        if ($this->blobDataVersion !== $dataVersion) {
            $this->blobIds = [];
            $this->blobDataVersion = $dataVersion;
        }
    }

    /** Forget one session's interned ids, e.g. after its blobs were deleted. */
    private function forgetInternedBlobs(string $sessionId): void
    {
        unset($this->blobIds[$sessionId]);
    }

    /**
     * @param list<string> $hashes
     * @return array<string, int> hash => id, for the hashes that exist
     */
    private function lookupBlobIds(string $sessionId, array $hashes): array
    {
        $found = [];
        // Chunked because SQLite caps bound parameters per statement and a
        // long session's history can exceed that on a cold first save.
        foreach (array_chunk($hashes, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("
                SELECT hash, id FROM checkpoint_blobs
                WHERE session_id = ? AND hash IN ({$placeholders})
            ");
            $stmt->execute([$sessionId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $found[(string) $row['hash']] = (int) $row['id'];
            }
        }

        return $found;
    }

    /**
     * @param list<int> $ids
     * @return list<mixed>|null decoded message bodies in the given order, or
     *         null when any id has no row
     */
    private function loadBlobs(array $ids): ?array
    {
        if ($ids === []) {
            return [];
        }

        $byId = [];
        foreach (array_chunk(array_unique($ids), 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("
                SELECT id, payload FROM checkpoint_blobs WHERE id IN ({$placeholders})
            ");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byId[(int) $row['id']] = json_decode((string) $row['payload'], true);
            }
        }

        $messages = [];
        foreach ($ids as $id) {
            if (!array_key_exists($id, $byId)) {
                return null;
            }
            $messages[] = $byId[$id];
        }

        return $messages;
    }

    /**
     * Retrieve a specific checkpoint by index.
     *
     * @param string $sessionId The session ID
     * @param int $index The checkpoint index
     * @return array|null The checkpoint state data, or null if not found
     */
    public function getCheckpoint(string $sessionId, int $index): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT state_data FROM checkpoints WHERE session_id = ? AND "index" = ?
        ');
        $stmt->execute([$sessionId, $index]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // restoreCheckpoint() DELETEs immediately after calling this, so the
        // read transaction a live cursor holds would straddle that write and
        // suppress the WAL auto-checkpoint — the same trap saveCheckpoint()
        // fell into. Closed explicitly rather than left to $stmt's scope.
        $stmt->closeCursor();

        if (!$row) {
            return null;
        }

        return $this->decodeCheckpoint((string) $row['state_data']);
    }

    /**
     * List recent checkpoints for a session.
     *
     * @param string $sessionId The session ID
     * @param int $limit Maximum number of checkpoints to return (default 100)
     * @return array<int, array{index: int, created_at: string, state_data: array}> Checkpoint summaries
     */
    public function listCheckpoints(string $sessionId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('
            SELECT "index", created_at, state_data
            FROM checkpoints
            WHERE session_id = ?
            ORDER BY "index" DESC
            LIMIT ?
        ');
        $stmt->execute([$sessionId, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            return [
                'index' => (int) $row['index'],
                'created_at' => $row['created_at'],
                'state_data' => $this->decodeCheckpoint((string) $row['state_data']),
            ];
        }, $rows);
    }

    /**
     * Restore a checkpoint and return its state data.
     *
     * @param string $sessionId The session ID
     * @param int $index The checkpoint index to restore
     * @return array|null The restored state data, or null if not found
     */
    public function restoreCheckpoint(string $sessionId, int $index): ?array
    {
        // First verify the checkpoint exists
        $state = $this->getCheckpoint($sessionId, $index);
        if ($state === null) {
            return null;
        }

        // Delete all checkpoints with index >= the restored index (they are now invalid)
        $deleteStmt = $this->pdo->prepare('
            DELETE FROM checkpoints WHERE session_id = ? AND "index" >= ?
        ');
        $deleteStmt->execute([$sessionId, $index]);

        // A rewind is the one moment a user explicitly discards state, so it
        // is also where the messages that state referenced stop being worth
        // keeping. Deliberately NOT done from pruneOldCheckpoints(): that runs
        // on the turn path once a session passes the retention limit, and
        // blob storage is O(total distinct messages) with or without it, so
        // paying a scan every turn would buy nothing.
        $this->collectCheckpointBlobs($sessionId);

        return $state;
    }

    /**
     * Drop `checkpoint_blobs` rows no surviving checkpoint of this session
     * references any more.
     */
    private function collectCheckpointBlobs(string $sessionId): void
    {
        $stmt = $this->pdo->prepare('SELECT state_data FROM checkpoints WHERE session_id = ?');
        $stmt->execute([$sessionId]);

        $live = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $stateData) {
            $decoded = json_decode((string) $stateData, true);
            $ids = is_array($decoded) ? ($decoded[self::CHECKPOINT_ENVELOPE_MESSAGES] ?? null) : null;
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $id) {
                $live[(int) $id] = true;
            }
        }

        if ($live === []) {
            $this->pdo->prepare('DELETE FROM checkpoint_blobs WHERE session_id = ?')->execute([$sessionId]);
        } else {
            // Ids are ints straight out of (int) casts, never user text.
            $keep = implode(',', array_keys($live));
            $this->pdo
                ->prepare("DELETE FROM checkpoint_blobs WHERE session_id = ? AND id NOT IN ({$keep})")
                ->execute([$sessionId]);
        }

        // Ids this instance had interned may have just been deleted. Other
        // instances see it through forgetInternedBlobsIfStale().
        $this->forgetInternedBlobs($sessionId);
    }

    /**
     * Prune old checkpoints to keep the count under the limit.
     */
    private function pruneOldCheckpoints(string $sessionId, int $maxCheckpoints): void
    {
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM checkpoints WHERE session_id = ?');
        $countStmt->execute([$sessionId]);
        $count = (int) $countStmt->fetchColumn();
        // Same open-cursor-blocks-the-WAL-checkpoint trap as saveCheckpoint().
        $countStmt->closeCursor();

        if ($count <= $maxCheckpoints) {
            return;
        }

        // Delete oldest checkpoints to bring count down to maxCheckpoints
        $deleteCount = $count - $maxCheckpoints;
        $deleteStmt = $this->pdo->prepare('
            DELETE FROM checkpoints
            WHERE session_id = ? AND id IN (
                SELECT id FROM checkpoints WHERE session_id = ?
                ORDER BY "index" ASC
                LIMIT ?
            )
        ');
        $deleteStmt->execute([$sessionId, $sessionId, $deleteCount]);
    }
}

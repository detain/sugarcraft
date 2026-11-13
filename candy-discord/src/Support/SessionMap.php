<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Support;

/**
 * The bridge's sidecar: channel↔session bindings, durable cursors, the
 * per-session last-active conversation (outbound reply address), and the
 * inbound dedupe ledger. SQLite over PDO, opened 0600 in the bridge state
 * directory.
 *
 * Key grammar (§9.1): `<platform>:<account>:<channel-or-thread-id>` —
 *   discord:<account>:<channelId>            (a channel, or its thread id)
 *   msteams:<account>:<conversationId>       (personal | groupChat | channel)
 *
 * Cursors: `last_seq` is the highest durable seq this binding has SEEN; the
 * bridge replays from it after every restart and after every serve-socket
 * gap (`session.subscribe {sessionId, afterSeq}` — replay-on-gap is a
 * protocol feature, SessionFeed::subscribe). The map is the bridge's
 * at-least-once guarantee; the serve idempotencyKey replay-cache (5 min /
 * 1000) absorbs re-sends inside it.
 *
 * MUTABLE ON PURPOSE: one open SQLite connection of the daemon.
 */
final class SessionMap
{
    private \PDO $db;

    public function __construct(
        public readonly string $path,
    ) {
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@mkdir($dir, 0o700, true) && !\is_dir($dir)) {
            throw new \RuntimeException("state directory not creatable: {$dir}");
        }
        // WAL sidecars (db-wal, db-shm) are created at the first write in
        // the SHAPE OF THE PROCESS UMASK, and until checkpointed away they
        // carry the very bindings and reply-address rows the main db is
        // kept private for (cc-review-1111 register B-52, the D4 0600
        // law). Open the connection and settle the schema inside a
        // private window so the sidecars are born owner-only; the
        // caller's mask comes back whatever the schema step does.
        $priorUmask = \umask(0o077);
        try {
            $db = new \PDO('sqlite:' . $path);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $db->exec('PRAGMA journal_mode = WAL');
            $db->exec('PRAGMA busy_timeout = 5000');
            $db->exec('PRAGMA synchronous = NORMAL');
            $db->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS bindings (
                    key        TEXT PRIMARY KEY,
                    session_id TEXT NOT NULL,
                    last_seq   INTEGER NOT NULL DEFAULT 0,
                    peer_id    TEXT,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )
                SQL);
            $db->exec('CREATE INDEX IF NOT EXISTS bindings_session ON bindings (session_id)');
            $db->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS last_conversation (
                    session_id TEXT PRIMARY KEY,
                    key        TEXT NOT NULL,
                    ref_json   TEXT NOT NULL,
                    updated_at INTEGER NOT NULL
                )
                SQL);
            $db->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS seen_inbound (
                    platform  TEXT NOT NULL,
                    inbound_id TEXT NOT NULL,
                    seen_at   INTEGER NOT NULL,
                    state     TEXT NOT NULL DEFAULT 'pending',
                    PRIMARY KEY (platform, inbound_id)
                )
                SQL);
            // Idempotent column migration: an existing pre-two-state db has
            // no 'state' column and CREATE skipped. -24 wraps schema upkeep
            // in the user_version ladder; until then this keeps boot safe.
            $seenCols = \array_column($db->query('PRAGMA table_info(seen_inbound)')->fetchAll(), 'name');
            if (!\in_array('state', $seenCols, true)) {
                $db->exec("ALTER TABLE seen_inbound ADD COLUMN state TEXT NOT NULL DEFAULT 'pending'");
            }
            // Boot orphan sweep (043): at open time nothing is in flight in
            // THIS process, so every surviving 'pending' row is a message
            // whose dispatch died with a previous process — mark it 'stale'
            // so the platform's redelivery re-processes it instead of
            // hitting the duplicate suppress. Runs inside the private
            // window so the write cannot leak sidecars past the 0600 law.
            $db->exec("UPDATE seen_inbound SET state = 'stale' WHERE state = 'pending'");
        } finally {
            \umask($priorUmask);
        }
        // Re-assert on every piece that EXISTS: prior-era files (the db,
        // or sidecars a crash left behind) were born before this window
        // and only a chmod tightens them. A missing sidecar is a no-op —
        // nothing wrote, so nothing leaked; a chmod that fails against an
        // existing file means the state is not ours to make private, and
        // that is said loudly rather than @-swallowed.
        foreach ([$path, $path . '-wal', $path . '-shm'] as $piece) {
            if (!\file_exists($piece)) {
                continue;
            }
            if (!@\chmod($piece, 0o600)) {
                throw new \RuntimeException("state file could not be made private: {$piece}");
            }
        }
        $this->db = $db;
    }

    /**
     * Bind (or re-bind) a key to a session. When the re-bind SWITCHES
     * sessions the cursor resets to 0: `last_seq` records what this
     * binding has seen of the session it watches, and a fresh session
     * starts its own seq space — carrying the old session's high
     * cursor across would make the router's durable gate swallow the
     * new session's first events (cc-review-1111 M-7 deafness). A
     * same-session re-bind keeps the cursor: it never moves backwards.
     */
    public function bind(string $key, string $sessionId, ?string $peerId = null): void
    {
        $now = \time();
        $stmt = $this->db->prepare(
            'INSERT INTO bindings (key, session_id, last_seq, peer_id, created_at, updated_at)
             VALUES (:key, :session, 0, :peer, :now, :now)
             ON CONFLICT(key) DO UPDATE SET session_id = excluded.session_id,
                 last_seq = CASE WHEN bindings.session_id = excluded.session_id
                                 THEN bindings.last_seq ELSE 0 END,
                 updated_at = excluded.updated_at',
        );
        $stmt->execute(['key' => $key, 'session' => $sessionId, 'peer' => $peerId, 'now' => $now]);
    }

    public function sessionIdFor(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT session_id FROM bindings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : (string) $row['session_id'];
    }

    /** @return list<array{key: string, sessionId: string, lastSeq: int}> */
    public function allBindings(): array
    {
        $rows = $this->db->query('SELECT key, session_id, last_seq FROM bindings ORDER BY key')->fetchAll(\PDO::FETCH_ASSOC);

        return \array_map(
            static fn (array $r): array => ['key' => (string) $r['key'], 'sessionId' => (string) $r['session_id'], 'lastSeq' => (int) $r['last_seq']],
            $rows,
        );
    }

    public function cursor(string $key): int
    {
        $stmt = $this->db->prepare('SELECT last_seq FROM bindings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? 0 : (int) $row['last_seq'];
    }

    /**
     * Greatest-wins cursor move: replayed and live events both pass through,
     * and an out-of-order (re)delivery must never walk a cursor backwards.
     */
    public function noteSeq(string $key, int $seq): void
    {
        $stmt = $this->db->prepare(
            'UPDATE bindings SET last_seq = :seq, updated_at = :now WHERE key = :key AND last_seq < :seq_below',
        );
        $stmt->execute([
            'seq' => $seq,
            'seq_below' => $seq,
            'key' => $key,
            'now' => \time(),
        ]);
    }

    /**
     * Move every binding that shares this session. One session can back
     * several keys (the `main` DM scope collapses them); a cursor that
     * walked per-key would double-answer the collapse.
     */
    public function noteSeqForSession(string $sessionId, int $seq): void
    {
        $stmt = $this->db->prepare(
            'UPDATE bindings SET last_seq = :seq, updated_at = :now WHERE session_id = :session AND last_seq < :seq_below',
        );
        $stmt->execute([
            'seq' => $seq,
            'seq_below' => $seq,
            'session' => $sessionId,
            'now' => \time(),
        ]);
    }

    public function cursorForSession(string $sessionId): int
    {
        $stmt = $this->db->prepare('SELECT MAX(last_seq) FROM bindings WHERE session_id = :session');
        $stmt->execute(['session' => $sessionId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** The most recent conversation a session answered to (outbound address). */
    public function rememberConversation(string $sessionId, string $key, array $ref): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO last_conversation (session_id, key, ref_json, updated_at)
             VALUES (:session, :key, :ref, :now)
             ON CONFLICT(session_id) DO UPDATE SET key = excluded.key, ref_json = excluded.ref_json, updated_at = excluded.updated_at',
        );
        $stmt->execute(['session' => $sessionId, 'key' => $key, 'ref' => (string) \json_encode($ref, \JSON_THROW_ON_ERROR), 'now' => \time()]);
    }

    /** @return array{key: string, ref: array<string, mixed>}|null */
    public function lastConversation(string $sessionId): ?array
    {
        $stmt = $this->db->prepare('SELECT key, ref_json FROM last_conversation WHERE session_id = :session');
        $stmt->execute(['session' => $sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $ref = \json_decode((string) $row['ref_json'], true);

        return ['key' => (string) $row['key'], 'ref' => \is_array($ref) ? $ref : []];
    }

    /**
     * The re-process horizon (043): a 'pending' row older than this cannot
     * belong to a live dispatch — an inbound reaches its confirm within
     * seconds — so it is treated as orphaned even without a boot in
     * between. Re-claims refresh seen_at, so a poison id that keeps
     * crashing only replays once per horizon, and pruneSeen deletes it.
     */
    public const CLAIM_GRACE_SECONDS = 300;

    /**
     * First delivery wins for one platform message id — with a crash
     * window (043): the claim is 'pending' until the dispatch is enqueued
     * (confirmInbound). A redelivery that finds a 'stale' row (the boot
     * orphan sweep's doing) or a 'pending' row past the horizon
     * re-processes; platform at-least-once intent beats bundle-once
     * caution. A redelivery inside the live window (fresh pending, not
     * yet confirmed) still suppresses, as before.
     */
    public function claimInbound(string $platform, string $inboundId, ?int $now = null): bool
    {
        $now ??= \time();
        $stmt = $this->db->prepare(
            "INSERT INTO seen_inbound (platform, inbound_id, seen_at, state) VALUES (:p, :i, :now, 'pending')
             ON CONFLICT(platform, inbound_id) DO NOTHING",
        );
        $stmt->execute(['p' => $platform, 'i' => $inboundId, 'now' => $now]);
        if ($stmt->rowCount() === 1) {
            return true;
        }

        $look = $this->db->prepare('SELECT state, seen_at FROM seen_inbound WHERE platform = :p AND inbound_id = :i');
        $look->execute(['p' => $platform, 'i' => $inboundId]);
        $row = $look->fetch();
        $orphaned = $row !== false && ((string) $row['state'] === 'stale'
            || ((string) $row['state'] === 'pending' && $now - (int) $row['seen_at'] > self::CLAIM_GRACE_SECONDS));
        if (!$orphaned) {
            return false;
        }

        $this->db->prepare('UPDATE seen_inbound SET state = \'pending\', seen_at = :now WHERE platform = :p AND inbound_id = :i')
            ->execute(['now' => $now, 'p' => $platform, 'i' => $inboundId]);

        return true;
    }

    /**
     * The honest "we have it now" moment: the dispatch was enqueued (or the
     * command was handed to its handler), so a later redelivery of this id
     * must suppress even after the process that confirmed it dies.
     */
    public function confirmInbound(string $platform, string $inboundId): void
    {
        $this->db->prepare("UPDATE seen_inbound SET state = 'confirmed' WHERE platform = :p AND inbound_id = :i AND state = 'pending'")
            ->execute(['p' => $platform, 'i' => $inboundId]);
    }

    /** The current state of one inbound claim ('pending'|'confirmed'|'stale'), or null. Test/ops surface. */
    public function inboundState(string $platform, string $inboundId): ?string
    {
        $stmt = $this->db->prepare('SELECT state FROM seen_inbound WHERE platform = :p AND inbound_id = :i');
        $stmt->execute(['p' => $platform, 'i' => $inboundId]);
        $row = $stmt->fetch();

        return $row === false ? null : (string) $row['state'];
    }

    /** Trim the dedupe ledger to $keep days so the sidecar stays bounded. */
    public function pruneSeen(int $days = 7): void
    {
        $stmt = $this->db->prepare('DELETE FROM seen_inbound WHERE seen_at < :cutoff');
        $stmt->execute(['cutoff' => \time() - $days * 86_400]);
    }

    /** @return list<string> */
    public function bindingsOfSession(string $sessionId): array
    {
        $stmt = $this->db->prepare('SELECT key FROM bindings WHERE session_id = :session');
        $stmt->execute(['session' => $sessionId]);

        return \array_map(static fn (array $r): string => (string) $r['key'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function forget(string $key): void
    {
        $stmt = $this->db->prepare('DELETE FROM bindings WHERE key = :key');
        $stmt->execute(['key' => $key]);
    }
}

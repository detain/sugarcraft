<?php

declare(strict_types=1);

namespace SugarCraft\Code\Share;

use RuntimeException;
use SugarCraft\Code\Lang;

use function array_fill;
use function implode;
use function is_file;

/**
 * The `shares` table — self-hosted share pages (SES-3, COMPARISON §6.5).
 *
 * SECURITY MODEL, stated where the data lives. Transcripts hold secrets, so
 * a share page is NEVER a view of the live session: {@see publish()} stores
 * the rendered HTML snapshot captured at an EXPLICIT USER ACT (`/share serve`
 * or the `session.share` RPC). Editing the transcript afterwards changes
 * nothing a reader sees; publishing the new state is a second user act.
 * Expiry and revocation are columns read on EVERY request by
 * `SharePageController`, so revoking lands on the next page load, not the
 * next server restart.
 *
 * The table lives in the session database (`session.db`), which already
 * holds the transcripts the snapshots were rendered from — same file, same
 * 0600-at-creation discipline as {@see \SugarCraft\Code\Session\SessionStore},
 * no second store to leak.
 *
 * The token is the capability: 128 random bits from `random_bytes`, hex
 * encoded, unguessable and NOT derived from the session id. A revoked or
 * expired row answers the same null as a token that never existed — the
 * controller turns every null into the same 404, so the HTTP surface cannot
 * be used to probe which past tokens existed.
 */
final class ShareStore
{
    /** Path prefix the controller serves share pages under: `/s/{token}`. */
    public const URL_PREFIX = 's';

    /** Bytes of entropy in a token — 128 bits, the report's floor. */
    public const TOKEN_BYTES = 16;

    /** Longest lifetime a user act may request (days). */
    public const MAX_EXPIRY_DAYS = 365;

    /**
     * The idempotent migration: creates `shares` when absent, on every
     * publish and revoke. Deleting dead rows is NOT this constant's
     * business — that sweep is {@see sweepInertRows()}, run opportunistically
     * at publish time, never on a background timer: an expired or revoked row
     * is already inert to {@see lookup()}, and a timer would mean serve owns
     * a second clock to reason about.
     */
    private const CREATE_TABLE = 'CREATE TABLE IF NOT EXISTS shares ('
        . 'token TEXT PRIMARY KEY,'
        . 'session_id TEXT NOT NULL,'
        . 'format TEXT NOT NULL,'
        . 'html TEXT NOT NULL,'
        . 'created_at INTEGER NOT NULL,'
        . 'expires_at INTEGER NOT NULL,'
        . 'revoked_at INTEGER NULL'
        . ')';

    /**
     * The statement that revokes every LIVE share published from a chunk of
     * session ids. It lives HERE, beside the table it writes, because
     * `SessionStore::deleteSessionTrees()` runs it inside the very
     * transaction that removes those sessions (C-SES-3): a session that is
     * gone must not leave `/s/{token}` answering for the rest of the expiry
     * window. Copying the SQL into the session store would let the schema
     * and its revocation law drift apart unnoticed.
     *
     * Revoked, not deleted — the rows stay listable through
     * {@see liveAll()} for as long as `sweepInertRows()` has not reaped
     * them, so an audit pass can still see what was shared and when it died.
     *
     * @param int $sessionIdCount number of session-id placeholders in the chunk
     */
    public static function revokeForSessionsSql(int $sessionIdCount): string
    {
        return 'UPDATE shares SET revoked_at = ? WHERE revoked_at IS NULL AND session_id IN ('
            . implode(',', array_fill(0, $sessionIdCount, '?'))
            . ')';
    }

    private function __construct(
        private readonly string $dbPath,
        private readonly \PDO $pdo,
    ) {
    }

    /**
     * A store over the session database at $dbPath. The file is created with
     * the same restrictive umask dance SessionStore uses, so a database that
     * does not exist yet is never briefly world-readable.
     */
    public static function atPath(string $dbPath): self
    {
        $existed = is_file($dbPath);
        $previousUmask = umask(0077);
        try {
            $pdo = \SugarCraft\Code\Support\SqliteConnect::open($dbPath);
            $pdo->exec('PRAGMA journal_mode=WAL');
        } finally {
            umask($previousUmask);
        }
        if (!$existed && is_file($dbPath)) {
            @chmod($dbPath, 0600);
        }

        return new self($dbPath, $pdo);
    }

    public function path(): string
    {
        return $this->dbPath;
    }

    /**
     * Publish one snapshot; returns the row WITH its fresh token.
     *
     * $format is the export format the HTML was rendered through
     * (`ShareSession::FORMAT_*`), kept so the page and the RPC answer can
     * name what they are showing. A PRIMARY KEY collision on a 128-bit token
     * cannot happen without a broken CSPRNG; if the insert nonetheless
     * repeats, publish retries with a fresh token rather than failing.
     */
    public function publish(string $sessionId, string $format, string $html, int $expiryDays): ShareRecord
    {
        if ($expiryDays < 1 || $expiryDays > self::MAX_EXPIRY_DAYS) {
            throw new RuntimeException(Lang::t('cmd.share.bad-days', ['max' => self::MAX_EXPIRY_DAYS]));
        }
        $this->pdo->exec(self::CREATE_TABLE);
        $this->sweepInertRows();

        $now = time();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $record = new ShareRecord(
                token: bin2hex(random_bytes(self::TOKEN_BYTES)),
                sessionId: $sessionId,
                format: $format,
                html: $html,
                createdAt: $now,
                expiresAt: $now + $expiryDays * 86400,
            );
            $statement = $this->pdo->prepare(
                'INSERT INTO shares (token, session_id, format, html, created_at, expires_at, revoked_at)'
                . ' VALUES (:token, :session_id, :format, :html, :created_at, :expires_at, NULL)'
            );
            try {
                $statement->execute([
                    ':token' => $record->token,
                    ':session_id' => $record->sessionId,
                    ':format' => $record->format,
                    ':html' => $record->html,
                    ':created_at' => $record->createdAt,
                    ':expires_at' => $record->expiresAt,
                ]);
            } catch (\PDOException) {
                // Duplicate token (attempt < 5) or an unusable table racing a
                // schema change — retry with fresh entropy; a persistent
                // failure falls through to the loud throw below.
                continue;
            }

            return $record;
        }

        throw new RuntimeException(Lang::t('cmd.share.store-unavailable', ['path' => $this->dbPath]));
    }

    /**
     * The live row for $token, or null for unknown, revoked AND expired
     * alike — one answer, so the caller cannot tell the three apart.
     *
     * Never throws: a database without the shares table (this process is
     * reading a session.db no `/share serve` ever touched) answers null,
     * which is the correct fail-safe for a public HTTP surface.
     */
    public function lookup(string $token): ?ShareRecord
    {
        if (preg_match('/^[0-9a-f]{' . (self::TOKEN_BYTES * 2) . '}$/', $token) !== 1) {
            return null;
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT token, session_id, format, html, created_at, expires_at FROM shares'
                . ' WHERE token = :token AND revoked_at IS NULL AND expires_at > :now'
            );
            $statement->execute([':token' => $token, ':now' => time()]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }

        return new ShareRecord(
            token: (string) $row['token'],
            sessionId: (string) $row['session_id'],
            format: (string) $row['format'],
            html: (string) $row['html'],
            createdAt: (int) $row['created_at'],
            expiresAt: (int) $row['expires_at'],
        );
    }

    /**
     * Revoke by token. True only when a LIVE row flipped; revoking an
     * unknown, already-revoked or expired token answers false without
     * distinguishing them (same no-oracle law as {@see lookup()}).
     */
    public function revoke(string $token): bool
    {
        if (preg_match('/^[0-9a-f]{' . (self::TOKEN_BYTES * 2) . '}$/', $token) !== 1) {
            return false;
        }
        $this->pdo->exec(self::CREATE_TABLE);
        $statement = $this->pdo->prepare('UPDATE shares SET revoked_at = :now WHERE token = :token AND revoked_at IS NULL');
        $statement->execute([':token' => $token, ':now' => time()]);

        return $statement->rowCount() > 0;
    }

    /**
     * Live shares published from one session, newest first — what
     * `/share revoke` without a token lists when there is more than one to
     * choose between.
     *
     * @return list<ShareRecord>
     */
    public function liveForSession(string $sessionId): array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT token, session_id, format, html, created_at, expires_at FROM shares'
                . ' WHERE session_id = :session_id AND revoked_at IS NULL AND expires_at > :now'
                . ' ORDER BY created_at DESC, rowid DESC'
            );
            $statement->execute([':session_id' => $sessionId, ':now' => time()]);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }

        return array_map(
            static fn(array $row): ShareRecord => new ShareRecord(
                token: (string) $row['token'],
                sessionId: (string) $row['session_id'],
                format: (string) $row['format'],
                html: (string) $row['html'],
                createdAt: (int) $row['created_at'],
                expiresAt: (int) $row['expires_at'],
            ),
            $rows,
        );
    }

    /**
     * Every LIVE share across ALL sessions, newest first, each paired with
     * its owning session's name when that row still exists.
     *
     * The join is the whole point (C-SES-3): deleting a session revokes its
     * shares rather than erasing them, and rows can outlive their session
     * by other paths too — a foreign or hand-edited database. A live share
     * whose session row is gone (`orphan`) is exactly what an operator
     * needs to SEE to decide to revoke it, so this lists it instead of
     * hiding it; on a database without any `sessions` table every row
     * reports that way, which is honest: nothing here proves those owners
     * exist.
     *
     * Never throws, the same law {@see lookup()} follows for a public
     * surface: a database without the shares table answers the empty list.
     *
     * @return list<array{record: ShareRecord, sessionName: ?string, orphan: bool}>
     */
    public function liveAll(): array
    {
        $joined = $this->hasTable('sessions');
        try {
            $statement = $this->pdo->prepare(
                'SELECT shares.token, shares.session_id, shares.format, shares.html,'
                . ' shares.created_at, shares.expires_at,'
                . ($joined ? 'sessions.id AS session_row, sessions.name AS session_name FROM shares'
                    : 'NULL AS session_row, NULL AS session_name FROM shares')
                . ($joined ? ' LEFT JOIN sessions ON sessions.id = shares.session_id' : '')
                . ' WHERE shares.revoked_at IS NULL AND shares.expires_at > :now'
                . ' ORDER BY shares.created_at DESC, shares.rowid DESC'
            );
            $statement->execute([':now' => time()]);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }

        return array_map(
            static fn(array $row): array => [
                'record' => new ShareRecord(
                    token: (string) $row['token'],
                    sessionId: (string) $row['session_id'],
                    format: (string) $row['format'],
                    html: (string) $row['html'],
                    createdAt: (int) $row['created_at'],
                    expiresAt: (int) $row['expires_at'],
                ),
                'sessionName' => $row['session_name'] === null ? null : (string) $row['session_name'],
                'orphan' => $row['session_row'] === null,
            ],
            $rows,
        );
    }

    /**
     * Whether the connected database carries $table. `liveAll()` asks for
     * `sessions` so the join can be skipped on a shares-only file instead
     * of erroring into the empty answer — the empty answer would hide even
     * the rows that ARE listable.
     */
    private function hasTable(string $table): bool
    {
        try {
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            $statement->execute([$table]);

            return (int) $statement->fetchColumn() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    /** Delete rows no lookup could ever return again (expired or revoked). */
    private function sweepInertRows(): void
    {
        $statement = $this->pdo->prepare('DELETE FROM shares WHERE expires_at <= :now OR revoked_at IS NOT NULL');
        $statement->execute([':now' => time()]);
    }
}

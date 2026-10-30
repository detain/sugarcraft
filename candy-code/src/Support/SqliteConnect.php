<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use PDO;

/**
 * The ONE SQLite connect arm for `src/`: open, exceptions on, busy wait armed.
 *
 * SES-1. `SessionStore::immediateTransaction()` has always documented that a
 * lost write race "waits on the connection's busy timeout" — but no connect
 * site in this tree ever set one, so the wait it describes was the pdo_sqlite
 * build default (measured 60000 ms on this host's 8.3.6; 0 on stock SQLite;
 * a number the application never chose). Meanwhile the concurrent shapes the
 * waves shipped — two TUIs on one file, `candy-code serve` plus a live
 * terminal — hit `SQLITE_BUSY` on writes, and `EnhancedSessionStore::appendUsageRecord()`'s
 * fail-soft catch turned that into a silently dropped durable spend row. The
 * docblock's promise becomes a fact at exactly one place: here.
 *
 * PRAGMA rather than `PDO::ATTR_TIMEOUT`: the pragma is readable in the live
 * connection (`PRAGMA busy_timeout` answers it back, which is how the pin
 * measures the arm rather than the source text), it retires the two
 * per-cache `ATTR_TIMEOUT` dials (2 s and 5 s, each its own guess at the same
 * knob), and it is the spelling the docblock has always named.
 *
 * WHAT THIS DOES NOT OWN: journal mode, foreign keys, synchronous, umask —
 * those are per-store decisions and stay at the stores. This arms the floor
 * every store stands on. `Cli\DoctorChecks::pdoDriverProbe()` is the one raw
 * `new \PDO` left in `src/` and must stay raw: its whole job is to throw
 * `could not find driver` for a DSN no driver claims, before any statement
 * could run. The census pin is `tests/Session/SqliteConnectCensusTest`.
 */
final class SqliteConnect
{
    /**
     * How long a write waits for the lock before it is refused, in
     * milliseconds. Five seconds is longer than any healthy transaction this
     * app issues (a full transcript save is tens of milliseconds) and shorter
     * than the pause a stuck turn would otherwise impose — a caller waiting
     * past it has learned something is wrong, not that it is busy.
     */
    public const BUSY_TIMEOUT_MS = 5000;

    /** Open (or create) the database file at $dbPath, armed. */
    public static function open(string $dbPath): PDO
    {
        return self::arm(new PDO("sqlite:" . $dbPath));
    }

    /** Open an arbitrary DSN — the `:memory:` probes — with the same arm. */
    public static function openDsn(string $dsn): PDO
    {
        return self::arm(new PDO($dsn));
    }

    private static function arm(PDO $pdo): PDO
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout=' . self::BUSY_TIMEOUT_MS);

        return $pdo;
    }
}

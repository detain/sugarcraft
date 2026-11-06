<?php

/**
 * Read-only inventory of the session store for `candy-code gc`.
 *
 * Every connection here is opened mode=ro through the sanctioned connect arm
 * (Support\SqliteConnect); the inventory NEVER writes, NEVER prunes and NEVER
 * constructs a session store (whose constructor would run retention pruning —
 * diagnostics NEVER prune). The per-table figures are a LENGTH() heuristic:
 * they sum stored payload lengths, not page-aligned file bytes, so they are
 * labelled as accounting rather than gospel. The reclaimable figure is exact:
 * page_size * freelist_count from the pragma layer.
 */

declare(strict_types=1);

namespace SugarCraft\Code\Session\Gc;

use PDO;
use SugarCraft\Code\Session\SessionLock;
use SugarCraft\Code\Support\SqliteConnect;

final class GcInventory
{
    public const AUTO_VACUUM_NONE = 0;
    public const AUTO_VACUUM_FULL = 1;
    public const AUTO_VACUUM_INCREMENTAL = 2;

    /** @var int cells in the text-mode share bars */
    private const BAR_CELLS = 30;

    /**
     * Byte-accounting rosters in report order — the payload columns of every
     * table the store's own CREATE TABLE statements mint (SessionStore +
     * EnhancedSessionStore + Share/Import/SearchIndex siblings). Columns are
     * intersected with the live PRAGMA table_info at query time, so a store
     * predating an ALTER still accounts correctly; absent tables are listed
     * with present=false rather than failing the run.
     *
     * Public because GcSessionSizes folds the SAME column accounting per
     * session (B2 engine touch, additive only): `gc status` table census and
     * `gc sessions` rows reconcile exactly when they share one roster.
     *
     * @var array<string,list<string>>
     */
    public const TABLE_ROSTERS = [
        'sessions' => ['id', 'provider', 'model', 'system_prompt', 'name', 'metadata', 'kind', 'parent_id', 'parent_call_id', 'agent', 'status', 'title_source', 'cwd', 'git_branch', 'last_preview'],
        'messages' => ['id', 'session_id', 'role', 'content', 'tool_calls', 'tool_results', 'model'],
        'tool_calls' => ['id', 'session_id', 'message_id', 'tool_name', 'tool_args', 'tool_result'],
        'session_transcripts' => ['session_id', 'state_data'],
        'session_events' => ['session_id', 'type', 'payload'],
        'usage_records' => ['id', 'session_id', 'model', 'source'],
        'checkpoints' => ['id', 'session_id', 'state_data'],
        'checkpoint_blobs' => ['id', 'session_id', 'hash', 'payload'],
        'session_meta' => ['session_id', 'summary', 'tasks', 'modified_files', 'agent_states'],
        'context_ledgers' => ['session_id', 'ledger_json'],
        'shares' => ['token', 'session_id', 'format', 'html'],
        'session_imports' => ['source_key', 'session_id', 'items', 'digest'],
        'session_tombstones' => ['id'],
        'session_messages_fts_data' => ['data', 'block'],
    ];

    /**
     * Storage families under the config dir whose bytes belong to sessions.
     *
     * @var list<string>
     */
    private const STORAGE_DIRECTORIES = ['transcripts', 'checkpoints', 'teams', 'media', 'exports', 'cache', 'logs', 'memory', 'sessions'];

    private readonly string $configDir;

    public function __construct(private readonly string $dbPath)
    {
        $this->configDir = \dirname($dbPath);
    }

    /**
     * Full machine-readable inventory. Snake_case keys are the stable JSON
     * layout documented on GcStatus rendering (README + gc subcommand doc).
     *
     * @return array{
     *     db_path:string,
     *     files:array{db:int,wal:int,shm:int,total:int,present:bool},
     *     pragma:array{page_size:int,page_count:int,freelist_count:int,auto_vacuum:int,auto_vacuum_label:string,journal_mode:string},
     *     reclaimable_bytes:int,
     *     tables:list<array{name:string,present:bool,rows:int,bytes:int}>,
     *     sessions:array{total:int,live:int,archived:int,subagents:int,pinned:int,named:int,oldest_created_at:?float,newest_created_at:?float,age_buckets:array{le7:int,d8_30:int,d31_90:int,gt90:int,unknown:int}},
     *     storage:array<string,array{present:bool,bytes:int,files:int,links_skipped:int,lock_files?:int,lock_live?:int,lock_stale?:int}>,
     *     retention_days:int,
     *     flags:array{events_exceed_transcripts:bool},
     *     bar_cells:int
     * }
     */
    public function collect(int $retentionDays): array
    {
        $connection = $this->openReadOnly();
        $pragma = $this->readPragma($connection);
        $files = GcStoreFiles::sizes($this->dbPath);
        $tables = $this->readTables($connection);
        $tableBytes = [];

        foreach ($tables as $row) {
            $tableBytes[$row['name']] = $row['bytes'];
        }

        return [
            'db_path' => $this->dbPath,
            'files' => $files,
            'pragma' => $pragma,
            'reclaimable_bytes' => $pragma['page_size'] * $pragma['freelist_count'],
            'tables' => $tables,
            'sessions' => $this->readSessionCounts($connection),
            'storage' => $this->walkStorage(),
            'retention_days' => $retentionDays,
            'flags' => [
                'events_exceed_transcripts' => ($tableBytes['session_events'] ?? 0) > ($tableBytes['session_transcripts'] ?? 0),
            ],
            'bar_cells' => self::BAR_CELLS,
        ];
    }

    /**
     * Absolute-path -> SQLite file: URI component, escaping the characters
     * that would otherwise change URI semantics. Slashes must survive, so a
     * whole-path rawurlencode is deliberately NOT used.
     */
    public static function encodeFileUriComponent(string $path): string
    {
        return str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], $path);
    }

    /**
     * The ONE sanctioned read-only connect arm for the whole gc engine
     * (B2 engine touch, visibility only): GcSessionSizes and GcOrphanCensus
     * fold over this handle so the engine keeps a single connect site family
     * and the SqliteConnect census sees no second arm.
     */
    public function openReadOnly(): PDO
    {
        return SqliteConnect::openDsn(
            'sqlite:file:' . self::encodeFileUriComponent($this->dbPath) . '?mode=ro',
        );
    }

    /**
     * @return array{page_size:int,page_count:int,freelist_count:int,auto_vacuum:int,auto_vacuum_label:string,journal_mode:string}
     */
    private function readPragma(PDO $connection): array
    {
        $pageSize = (int) $connection->query('PRAGMA page_size')->fetchColumn();
        $autoVacuum = (int) $connection->query('PRAGMA auto_vacuum')->fetchColumn();

        return [
            'page_size' => $pageSize,
            'page_count' => (int) $connection->query('PRAGMA page_count')->fetchColumn(),
            'freelist_count' => (int) $connection->query('PRAGMA freelist_count')->fetchColumn(),
            'auto_vacuum' => $autoVacuum,
            'auto_vacuum_label' => match ($autoVacuum) {
                self::AUTO_VACUUM_FULL => 'FULL',
                self::AUTO_VACUUM_INCREMENTAL => 'INCREMENTAL',
                default => 'NONE',
            },
            'journal_mode' => (string) $connection->query('PRAGMA journal_mode')->fetchColumn(),
        ];
    }

    /**
     * @return list<array{name:string,present:bool,rows:int,bytes:int}>
     */
    private function readTables(PDO $connection): array
    {
        $rows = [];

        foreach (self::TABLE_ROSTERS as $table => $roster) {
            if (!$this->tablePresent($connection, $table)) {
                $rows[] = ['name' => $table, 'present' => false, 'rows' => 0, 'bytes' => 0];
                continue;
            }

            $columns = array_values(array_intersect($this->columnsOf($connection, $table), $roster));
            $sums = [];

            foreach ($columns as $column) {
                $sums[] = 'COALESCE(SUM(LENGTH("' . $column . '")), 0)';
            }

            $sql = 'SELECT COUNT(*)' . ($sums === [] ? '' : ', ' . implode(' + ', $sums)) . ' FROM "' . $table . '"';
            $result = $connection->query($sql)->fetch(PDO::FETCH_NUM);

            $rows[] = [
                'name' => $table,
                'present' => true,
                'rows' => (int) $result[0],
                'bytes' => (int) ($result[1] ?? 0),
            ];
        }

        return $rows;
    }

    public static function tablePresent(PDO $connection, string $table): bool
    {
        $statement = $connection->prepare('SELECT 1 FROM sqlite_master WHERE type = \'table\' AND name = ? LIMIT 1');
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return list<string>
     */
    public static function columnsOf(PDO $connection, string $table): array
    {
        $columns = [];

        foreach ($connection->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[] = (string) $column['name'];
        }

        return $columns;
    }

    /**
     * @return array{total:int,live:int,archived:int,subagents:int,pinned:int,named:int,oldest_created_at:?float,newest_created_at:?float,age_buckets:array{le7:int,d8_30:int,d31_90:int,gt90:int,unknown:int}}
     */
    private function readSessionCounts(PDO $connection): array
    {
        if (!$this->tablePresent($connection, 'sessions')) {
            return [
                'total' => 0, 'live' => 0, 'archived' => 0, 'subagents' => 0,
                'pinned' => 0, 'named' => 0,
                'oldest_created_at' => null, 'newest_created_at' => null,
                'age_buckets' => ['le7' => 0, 'd8_30' => 0, 'd31_90' => 0, 'gt90' => 0, 'unknown' => 0],
            ];
        }

        $summary = $connection->query(
            'SELECT COUNT(*),'
            . ' SUM(CASE WHEN archived_at IS NULL THEN 1 ELSE 0 END),'
            . ' SUM(CASE WHEN archived_at IS NOT NULL THEN 1 ELSE 0 END),'
            . ' SUM(CASE WHEN parent_id IS NOT NULL THEN 1 ELSE 0 END),'
            . ' SUM(CASE WHEN pinned <> 0 THEN 1 ELSE 0 END),'
            . ' SUM(CASE WHEN name IS NOT NULL AND name <> \'\' THEN 1 ELSE 0 END),'
            . ' MIN(created_at), MAX(created_at)'
            . ' FROM sessions',
        )->fetch(PDO::FETCH_NUM);

        $now = time();
        $buckets = ['le7' => 0, 'd8_30' => 0, 'd31_90' => 0, 'gt90' => 0, 'unknown' => 0];
        $created = $connection->query('SELECT created_at FROM sessions')->fetchAll(PDO::FETCH_COLUMN);

        foreach ($created as $value) {
            if ($value === null) {
                $buckets['unknown']++;
                continue;
            }

            $days = (int) floor(((float) $now - (float) $value) / 86400.0);

            if ($days <= 7) {
                $buckets['le7']++;
            } elseif ($days <= 30) {
                $buckets['d8_30']++;
            } elseif ($days <= 90) {
                $buckets['d31_90']++;
            } else {
                $buckets['gt90']++;
            }
        }

        return [
            'total' => (int) $summary[0],
            'live' => (int) ($summary[1] ?? 0),
            'archived' => (int) ($summary[2] ?? 0),
            'subagents' => (int) ($summary[3] ?? 0),
            'pinned' => (int) ($summary[4] ?? 0),
            'named' => (int) ($summary[5] ?? 0),
            'oldest_created_at' => $summary[6] === null ? null : (float) $summary[6],
            'newest_created_at' => $summary[7] === null ? null : (float) $summary[7],
            'age_buckets' => $buckets,
        ];
    }

    /**
     * @return array<string,array{present:bool,bytes:int,files:int,links_skipped:int,lock_files?:int,lock_live?:int,lock_stale?:int}>
     */
    private function walkStorage(): array
    {
        $storage = [];

        foreach (self::STORAGE_DIRECTORIES as $directory) {
            $path = $this->configDir . '/' . $directory;

            if (!is_dir($path)) {
                $storage[$directory] = ['present' => false, 'bytes' => 0, 'files' => 0, 'links_skipped' => 0];
                continue;
            }

            $walk = self::walkDirectory($path, 0);
            $entry = [
                'present' => true,
                'bytes' => $walk['bytes'],
                'files' => $walk['files'],
                'links_skipped' => $walk['links'],
            ];

            if ($directory === SessionLock::DIRECTORY) {
                $locks = self::countLocks($path);
                $entry['lock_files'] = $locks['files'];
                $entry['lock_live'] = $locks['live'];
                $entry['lock_stale'] = $locks['files'] - $locks['live'];
            }

            $storage[$directory] = $entry;
        }

        return $storage;
    }

    /**
     * Recursive byte walk that never follows symlinks (a link is counted as
     * skipped, not walked). Depth is capped defensively against a planted
     * loop through real subdirectories.
     *
     * Public (B2 engine touch, visibility only): GcSessionSizes attributes
     * shadow-repo and journal bytes with THIS walk law, so the storage
     * census and the per-session fold see identical byte semantics.
     *
     * @return array{bytes:int,files:int,links:int}
     */
    public static function walkDirectory(string $directory, int $depth): array
    {
        $bytes = 0;
        $files = 0;
        $links = 0;
        $entries = scandir($directory);

        if ($entries === false) {
            return ['bytes' => 0, 'files' => 0, 'links' => 0];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_link($path)) {
                $links++;
                continue;
            }

            if (is_dir($path)) {
                if ($depth >= 12) {
                    continue;
                }

                $nested = self::walkDirectory($path, $depth + 1);
                $bytes += $nested['bytes'];
                $files += $nested['files'];
                $links += $nested['links'];
                continue;
            }

            if (is_file($path)) {
                $size = filesize($path);
                $bytes += $size === false ? 0 : $size;
                $files++;
            }
        }

        return ['bytes' => $bytes, 'files' => $files, 'links' => $links];
    }

    /**
     * Lock ground truth is the flock itself, never a process-name sweep: a
     * non-blocking shared probe that SUCCEEDS proves no writer holds the
     * lock (stale residue), a refusal proves a live holder.
     *
     * Public (B2 engine touch, visibility plus one additive key): GcOrphanCensus
     * reports the stale half through the SAME probe, never a second flock
     * idiom. The new stale_bytes total is B3's cleanup target surface; the
     * existing files/live consumers (walkStorage) are untouched.
     *
     * @return array{files:int,live:int,stale_bytes:int}
     */
    public static function countLocks(string $sessionsDirectory): array
    {
        $files = 0;
        $live = 0;
        $staleBytes = 0;
        $entries = scandir($sessionsDirectory);

        if ($entries === false) {
            return ['files' => 0, 'live' => 0, 'stale_bytes' => 0];
        }

        foreach ($entries as $entry) {
            if (!str_ends_with($entry, '.lock')) {
                continue;
            }

            $path = $sessionsDirectory . '/' . $entry;

            if (!is_file($path) || is_link($path)) {
                continue;
            }

            $files++;
            $handle = @fopen($path, 'r');

            if ($handle === false) {
                $live++;
                continue;
            }

            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                $live++;
            } else {
                flock($handle, LOCK_UN);
                $staleBytes += (int) (@filesize($path) ?: 0);
            }

            fclose($handle);
        }

        return ['files' => $files, 'live' => $live, 'stale_bytes' => $staleBytes];
    }
}

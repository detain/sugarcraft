<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session\Gc;

use PDO;
use SugarCraft\Code\Share\JsonlTranscriptWriter;

/**
 * Per-session byte fold for `gc sessions` / `gc analyze` (gc-cli-1106 B2).
 *
 * The fold answers one question with one answer shared by both actions:
 * how many bytes does THIS session actually own — database rows folded to
 * their owner plus its on-disk journal and its share of the shadow repo.
 * It reads through the handle GcInventory opened (`mode=ro`), never a
 * second connect arm, never a write.
 *
 * Reconciliation law (pinned by GcSubcommandTest): the fold reuses
 * GcInventory::TABLE_ROSTERS verbatim, so
 *
 *     Σ per-session db_bytes + unattributed_db_bytes
 *         + fts_residual_bytes + tombstone_bytes
 *     == Σ `gc status` table-census bytes
 *
 * exactly. The two census tables the fold does NOT attribute to sessions
 * (session_tombstones — an id has no owner left to bill — and the
 * session_messages_fts_data residual: FTS5 global-tree pages, averages
 * rows, and blocks whose rowid never made it into the bridge) are
 * reported as their own disclosed lines instead of being smeared over
 * sessions with fake precision.
 *
 * FTS attribution heuristic, stated honestly: the shadow table's id is
 * `(rowid << 8) | column`, the bridge session_message_rows.id IS the
 * external-content rowid, and the FTS index carries exactly one indexed
 * column here (session_id/message_id are UNINDEXED), so `CAST(id / 256
 * AS INTEGER)` recovers the rowid. Unmatched and special ids stay in the
 * residual.
 *
 * Shadow-repo attribution scheme (pinned by a test): dirs are named
 * sha1(realpath(root)) per ShadowRepo.php:45 with base = checkpoints/
 * beside the db; a dir's bytes split EVENLY across the live sessions
 * whose cwd hashes to it, with the division remainder (byte-bytes, one
 * apiece) landing on the alphabetically-first owners. A dir no session
 * claims is reported by GcOrphanCensus, never billed here.
 *
 * Journals are attributed by the file-name contract of
 * JsonlTranscriptWriter: transcripts/<project-slug>/<session-id>.jsonl,
 * counted only when the stem is session-shaped (SESSION_ID_PATTERN, the
 * same shape rule SessionLock::pathFor sanitizes to) and the path is a
 * regular non-link file — the no-symlink-follow walk law B1's storage
 * walk uses.
 */
final class GcSessionSizes
{
    /** Session-shaped file stem (SessionLock/JsonlTranscriptWriter id law). */
    public const SESSION_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    /** Shadow directory name shape: sha1 hex (ShadowRepo.php:45). */
    public const SHADOW_HASH_PATTERN = '/^[0-9a-f]{40}$/';

    /** Storage family holding shadow repos, per ShadowRepo base dir. */
    public const SHADOW_DIRECTORY_NAME = 'checkpoints';

    /**
     * @param PDO    $connection Session store handle (EnhancedSessionStore db)
     * @param string $configDir  Home config dir; FTS + tombstones ride their own legs
     */
    public function __construct(
        private readonly PDO $connection,
        private readonly string $configDir,
    ) {
    }

    /**
     * @return array{
     *     sessions:list<array{id:string,created_at:?float,name:?string,kind:?string,parent_id:?string,pinned:bool,archived:bool,cwd:?string,last_preview:?string,db_bytes:int,journal_bytes:int,shadow_bytes:int,shadow_dir_count:int,total_bytes:int}>,
     *     attributed_db_bytes:int,
     *     unattributed_db_bytes:int,
     *     fts_attributed_bytes:int,
     *     fts_residual_bytes:int,
     *     tombstone_bytes:int
     * }
     */
    public function fold(): array
    {
        $headers = $this->readHeaders();

        /** @var array<string,int> $db */
        $db = [];

        foreach ($headers as $row) {
            $db[$row['id']] = 0;
        }

        $unattributed = 0;

        foreach (GcInventory::TABLE_ROSTERS as $table => $roster) {
            if ($table === 'session_tombstones' || $table === 'session_messages_fts_data') {
                continue;
            }

            if (!GcInventory::tablePresent($this->connection, $table)) {
                continue;
            }

            $columns = array_values(array_intersect(GcInventory::columnsOf($this->connection, $table), $roster));

            if ($columns === []) {
                continue;
            }

            $ownerColumn = $table === 'sessions' ? 'id' : 'session_id';
            $sum = self::sumExpression(self::quoteAll($columns));

            if (!in_array($ownerColumn, $columns, true)) {
                // A store whose table cannot name its owner bills the whole
                // table to the disclosed unattributed line — never a guess.
                $statement = $this->connection->prepare('SELECT ' . $sum . ' FROM "' . $table . '"');
                $statement->execute();
                $unattributed += (int) $statement->fetchColumn();

                continue;
            }

            $statement = $this->connection->prepare(
                'SELECT "' . $ownerColumn . '" AS owner, ' . $sum . ' AS bytes'
                . ' FROM "' . $table . '" GROUP BY "' . $ownerColumn . '"',
            );
            $statement->execute();

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $grouped) {
                $owner = $grouped['owner'];
                $bytes = (int) $grouped['bytes'];

                if ($owner !== null && isset($db[(string) $owner])) {
                    $db[(string) $owner] += $bytes;

                    continue;
                }

                $unattributed += $bytes;
            }
        }

        [$ftsAttributed, $ftsResidual] = $this->foldFts($db);
        $tombstoneBytes = $this->censusTable('session_tombstones');
        $journals = self::scanJournals($this->configDir);
        $shadow = self::scanShadowDirectories($this->configDir);
        $shadowShares = $this->splitShadowDirectories($shadow, $headers);

        $sessions = [];
        $attributed = 0;

        foreach ($headers as $row) {
            $dbBytes = $db[$row['id']];
            $journalBytes = $journals[$row['id']] ?? 0;
            $shadowBytes = $shadowShares[$row['id']]['bytes'] ?? 0;
            $shadowCount = $shadowShares[$row['id']]['dirs'] ?? 0;
            $attributed += $dbBytes;

            $sessions[] = [
                'id' => $row['id'],
                'created_at' => $row['created_at'],
                'name' => $row['name'],
                'kind' => $row['kind'],
                'parent_id' => $row['parent_id'],
                'pinned' => (bool) $row['pinned'],
                'archived' => $row['archived_at'] !== null,
                'cwd' => $row['cwd'],
                'last_preview' => $row['last_preview'],
                'db_bytes' => $dbBytes,
                'journal_bytes' => $journalBytes,
                'shadow_bytes' => $shadowBytes,
                'shadow_dir_count' => $shadowCount,
                'total_bytes' => $dbBytes + $journalBytes + $shadowBytes,
            ];
        }

        return [
            'sessions' => $sessions,
            'attributed_db_bytes' => $attributed,
            'unattributed_db_bytes' => $unattributed,
            'fts_attributed_bytes' => $ftsAttributed,
            'fts_residual_bytes' => $ftsResidual,
            'tombstone_bytes' => $tombstoneBytes,
        ];
    }

    /**
     * Journal bytes per session id under transcripts/<slug>/<id>.jsonl.
     * Duplicate ids across slugs sum (a session re-rooted keeps one id);
     * non-session-shaped names are never accused, never counted.
     *
     * Shared by GcOrphanCensus — one scanner, no second copy.
     *
     * @return array<string,int>
     */
    public static function scanJournals(string $configDir): array
    {
        $sizes = [];

        foreach (self::scanJournalPaths($configDir) as $stem => $paths) {
            $bytes = 0;

            foreach ($paths as $path) {
                $bytes += (int) filesize($path);
            }

            $sizes[$stem] = $bytes;
        }

        return $sizes;
    }

    /**
     * The same walk as {@see scanJournals}, but keyed id => the file paths
     * behind it: `gc purge` needs to unlink (B3), not just bill. Duplicate
     * ids across slugs keep every path; the id law is identical, so the two
     * public faces can never disagree about what is a journal.
     *
     * @return array<string,list<string>>
     */
    public static function scanJournalPaths(string $configDir): array
    {
        $root = $configDir . '/' . JsonlTranscriptWriter::DIR_NAME;
        $found = [];

        if (!is_dir($root) || is_link($root)) {
            return $found;
        }

        $slugs = scandir($root);

        if ($slugs === false) {
            return $found;
        }

        foreach ($slugs as $slug) {
            if ($slug === '.' || $slug === '..') {
                continue;
            }

            $slugDir = $root . '/' . $slug;

            if (!is_dir($slugDir) || is_link($slugDir)) {
                continue;
            }

            $entries = scandir($slugDir);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!str_ends_with($entry, '.jsonl')) {
                    continue;
                }

                $stem = substr($entry, 0, -6);

                if (preg_match(self::SESSION_ID_PATTERN, $stem) !== 1) {
                    continue;
                }

                $path = $slugDir . '/' . $entry;

                if (!is_file($path) || is_link($path)) {
                    continue;
                }

                $found[$stem][] = $path;
            }
        }

        return $found;
    }

    /**
     * Shadow repository directories beside the db: name = sha1 hex, bytes
     * through the B1 no-symlink walk, plus dir mtime for the orphan age gate.
     *
     * @return array<string,array{bytes:int,mtime:int}>
     */
    public static function scanShadowDirectories(string $configDir): array
    {
        $root = $configDir . '/' . self::SHADOW_DIRECTORY_NAME;
        $found = [];

        if (!is_dir($root) || is_link($root)) {
            return $found;
        }

        $entries = scandir($root);

        if ($entries === false) {
            return $found;
        }

        foreach ($entries as $entry) {
            if (preg_match(self::SHADOW_HASH_PATTERN, $entry) !== 1) {
                continue;
            }

            $path = $root . '/' . $entry;

            if (!is_dir($path) || is_link($path)) {
                continue;
            }

            $walk = GcInventory::walkDirectory($path, 0);

            $found[$entry] = [
                'bytes' => $walk['bytes'],
                'mtime' => (int) (@filemtime($path) ?: 0),
            ];
        }

        return $found;
    }

    /**
     * The dir-name hash ShadowRepo mints for a work-tree root: sha1 of the
     * resolved path, falling back to the recorded string when the path no
     * longer resolves (a deleted checkout still owns its dir shape).
     */
    public static function hashForCwd(string $cwd): string
    {
        $real = realpath($cwd);

        return sha1($real === false ? $cwd : $real);
    }

    /**
     * @param array<string,array{bytes:int,mtime:int}> $shadow
     * @param list<array{id:string,cwd:?string}> $headers
     * @return array<string,array{bytes:int,dirs:int}>
     */
    private function splitShadowDirectories(array $shadow, array $headers): array
    {
        /** @var array<string,list<string>> $ownersByHash */
        $ownersByHash = [];

        foreach ($headers as $row) {
            $cwd = $row['cwd'];

            if ($cwd === null || $cwd === '') {
                continue;
            }

            $ownersByHash[self::hashForCwd($cwd)][] = $row['id'];
        }

        $shares = [];

        foreach ($shadow as $hash => $dir) {
            $owners = $ownersByHash[$hash] ?? [];

            if ($owners === []) {
                continue;
            }

            sort($owners);
            $count = count($owners);
            $each = intdiv($dir['bytes'], $count);
            $remainder = $dir['bytes'] % $count;

            foreach ($owners as $index => $owner) {
                $bonus = $index < $remainder ? 1 : 0;
                $shares[$owner] ??= ['bytes' => 0, 'dirs' => 0];
                $shares[$owner]['bytes'] += $each + $bonus;
                $shares[$owner]['dirs']++;
            }
        }

        return $shares;
    }

    /**
     * Attribute FTS shadow bytes per live session through the bridge; the
     * census total minus what landed on live sessions is the residual.
     *
     * @param array<string,int> $db live-session owners (mutated with attributed bytes)
     * @return array{0:int,1:int} [attributed-to-live, residual]
     */
    private function foldFts(array &$db): array
    {
        $table = 'session_messages_fts_data';
        $bridge = 'session_message_rows';

        if (!GcInventory::tablePresent($this->connection, $table)) {
            return [0, 0];
        }

        $columns = array_values(array_intersect(
            GcInventory::columnsOf($this->connection, $table),
            GcInventory::TABLE_ROSTERS[$table],
        ));
        $census = $this->censusTable($table);

        if ($columns === [] || !GcInventory::tablePresent($this->connection, $bridge)) {
            return [0, $census];
        }

        $bridgeColumns = GcInventory::columnsOf($this->connection, $bridge);

        if (!in_array('id', $bridgeColumns, true) || !in_array('session_id', $bridgeColumns, true)) {
            return [0, $census];
        }

        $perRow = self::sumExpression(array_map(static fn (string $column): string => 'd."' . $column . '"', $columns));
        $statement = $this->connection->prepare(
            'SELECT r."session_id" AS owner, ' . $perRow . ' AS bytes'
            . ' FROM "' . $table . '" d'
            . ' JOIN "' . $bridge . '" r ON r."id" = CAST(d."id" / 256 AS INTEGER)'
            . ' GROUP BY r."session_id"',
        );
        $statement->execute();

        $attributed = 0;

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $grouped) {
            $owner = $grouped['owner'];

            if ($owner === null || !isset($db[(string) $owner])) {
                continue;
            }

            $bytes = (int) $grouped['bytes'];
            $db[(string) $owner] += $bytes;
            $attributed += $bytes;
        }

        return [$attributed, max(0, $census - $attributed)];
    }

    /**
     * Whole-table census with the shared roster accounting (COUNT + SUM of
     * LENGTH over roster columns that exist on this store).
     */
    private function censusTable(string $table): int
    {
        if (!GcInventory::tablePresent($this->connection, $table)) {
            return 0;
        }

        $columns = array_values(array_intersect(
            GcInventory::columnsOf($this->connection, $table),
            GcInventory::TABLE_ROSTERS[$table],
        ));

        if ($columns === []) {
            return 0;
        }

        $statement = $this->connection->prepare(
            'SELECT ' . self::sumExpression(self::quoteAll($columns)) . ' FROM "' . $table . '"',
        );
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array{id:string,created_at:?float,name:?string,kind:?string,parent_id:?string,pinned:int|null,archived_at:?string,cwd:?string,last_preview:?string}>
     */
    private function readHeaders(): array
    {
        if (!GcInventory::tablePresent($this->connection, 'sessions')) {
            return [];
        }

        $available = array_flip(GcInventory::columnsOf($this->connection, 'sessions'));
        $select = ['"id"'];

        foreach (['created_at', 'name', 'kind', 'parent_id', 'pinned', 'archived_at', 'cwd', 'last_preview'] as $column) {
            $select[] = isset($available[$column]) ? '"' . $column . '"' : 'NULL AS "' . $column . '"';
        }

        $statement = $this->connection->prepare('SELECT ' . implode(', ', $select) . ' FROM "sessions"');
        $statement->execute();

        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            $created = $raw['created_at'];

            $rows[] = [
                'id' => (string) $raw['id'],
                'created_at' => is_numeric($created) ? (float) $created : null,
                'name' => $raw['name'] === null ? null : (string) $raw['name'],
                'kind' => $raw['kind'] === null ? null : (string) $raw['kind'],
                'parent_id' => $raw['parent_id'] === null ? null : (string) $raw['parent_id'],
                'pinned' => $raw['pinned'],
                'archived_at' => $raw['archived_at'] === null ? null : (string) $raw['archived_at'],
                'cwd' => $raw['cwd'] === null ? null : (string) $raw['cwd'],
                'last_preview' => $raw['last_preview'] === null ? null : (string) $raw['last_preview'],
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $identifiers
     * @return list<string>
     */
    private static function quoteAll(array $identifiers): array
    {
        return array_map(static fn (string $identifier): string => '"' . $identifier . '"', $identifiers);
    }

    /**
     * 'COALESCE(SUM(LENGTH(...)), 0) + …' over the given SQL fragments — the
     * exact accounting shape GcInventory::readTables uses, so both legs add
     * alike.
     *
     * @param list<string> $columns
     */
    private static function sumExpression(array $columns): string
    {
        $terms = array_map(
            static fn (string $column): string => 'COALESCE(SUM(LENGTH(' . $column . ')), 0)',
            $columns,
        );

        return implode(' + ', $terms);
    }
}

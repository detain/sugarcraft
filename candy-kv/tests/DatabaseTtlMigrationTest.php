<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Kv\Database;
use SugarCraft\Kv\Tests\Support\SpawnBounded;

/**
 * Regression pins for the TTL epoch migration.
 *
 * Legacy databases stored expires_at as a DATE_ATOM offset string and
 * compared it lexicographically against the reader's local clock — so the
 * SAME row could read expired-while-alive or alive-while-expired depending
 * on the reader's UTC offset. The current schema stores integer epoch seconds and converts
 * legacy rows once on open. These tests build legacy-shaped files with a
 * bare SQLite3 handle (exactly what exists in the wild) and prove the tip
 * reads them by true instant.
 */
final class DatabaseTtlMigrationTest extends TestCase
{
    use SpawnBounded;

    private string $tmpDir;

    protected function setUp(): void
    {
        if (!\extension_loaded('sqlite3')) {
            $this->markTestSkipped('ext-sqlite3 is required.');
        }
        $this->tmpDir = \sys_get_temp_dir() . '/candy-kv-ttl-migration-' . \uniqid('', true);
        \mkdir($this->tmpDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmpDir);
    }

    private static function removeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir()) {
                self::removeTree($item->getPathname());
            } else {
                @\unlink($item->getPathname());
            }
        }
        @\rmdir($dir);
    }

    /**
     * Create a legacy file: the old schema (expires_at TEXT, no epoch),
     * populated with one row per callback.
     *
     * @param list<array{key:string,expires:?string}> $rows
     */
    private function createLegacyDb(string $name, array $rows): string
    {
        $path = $this->tmpDir . "/{$name}.db";
        $sqlite = new \SQLite3($path);
        $sqlite->exec('
            CREATE TABLE entries (
                key         TEXT PRIMARY KEY,
                value       TEXT NOT NULL,
                binary      INTEGER NOT NULL DEFAULT 0,
                created     TEXT NOT NULL,
                modified    TEXT NOT NULL,
                expires_at  TEXT
            )
        ');
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $stmt = $sqlite->prepare(
            'INSERT INTO entries (key, value, binary, created, modified, expires_at)
             VALUES (:key, :value, 0, :created, :modified, :expires_at)'
        );
        foreach ($rows as $row) {
            $stmt->bindValue(':key', $row['key'], \SQLITE3_TEXT);
            $stmt->bindValue(':value', 'v-' . $row['key'], \SQLITE3_TEXT);
            $stmt->bindValue(':created', $now, \SQLITE3_TEXT);
            $stmt->bindValue(':modified', $now, \SQLITE3_TEXT);
            $stmt->bindValue(':expires_at', $row['expires'], $row['expires'] === null ? \SQLITE3_NULL : \SQLITE3_TEXT);
            $stmt->execute();
            $stmt->reset();
        }
        $stmt->close();
        $sqlite->close();

        return $path;
    }

    public function testLegacyRowsMigrateToEpochAndReadByTrueInstant(): void
    {
        $future = (new \DateTimeImmutable('+1 hour'))->format(\DATE_ATOM);
        $past = (new \DateTimeImmutable('-1 hour'))->format(\DATE_ATOM);
        $path = $this->createLegacyDb('mig', [
            ['key' => 'alive', 'expires' => $future],
            ['key' => 'dead', 'expires' => $past],
            ['key' => 'no-expiry', 'expires' => null],
        ]);

        $db = new Database($path, 'mig');

        // Reads honour the true instants, not the string text.
        $this->assertNotNull($db->get('alive'));
        $this->assertNull($db->get('dead'));
        $this->assertNotNull($db->get('no-expiry'));

        // The one-time rewrite actually happened: expiry cells are numeric
        // epochs (the value pass converts the text, the affinity rebuild
        // converts the storage class — kv-128).
        $prop = new \ReflectionProperty(Database::class, 'db');
        $prop->setAccessible(true);
        /** @var \SQLite3 $sqlite */
        $sqlite = $prop->getValue($db);
        $values = [];
        $res = $sqlite->query('SELECT key, expires_at FROM entries');
        while ($row = $res->fetchArray(\SQLITE3_ASSOC)) {
            $values[$row['key']] = $row['expires_at'];
        }
        $this->assertMatchesRegularExpression('/^[0-9]+$/', (string) $values['alive'], 'legacy TEXT expiry must be rewritten to epoch seconds on open');
        $this->assertMatchesRegularExpression('/^[0-9]+$/', (string) $values['dead'], 'expired legacy rows are migrated too (not silently dropped)');
        $this->assertNull($values['no-expiry']);

        // And the migrated epoch matches the original instant (not now+1h drift).
        $alive = $db->get('alive');
        $this->assertNotNull($alive);
        $this->assertSame(
            (new \DateTimeImmutable($future))->getTimestamp(),
            $alive->expiresAt?->getTimestamp(),
            'migration must preserve the expiry instant'
        );

        $db->close();
    }

    public function testCrossOffsetRowsDoNotSelfExpireEarlyOrLate(): void
    {
        // The M3 defect: lexicographic comparison of offset strings.
        //
        // 'alive-despite-early-text' expires an hour from now but is stored
        // on a -12:00 clock, so its local text reads ~11h BEFORE the reader's
        // local text — old `expires_at >= :now` string compare said EXPIRED.
        //
        // 'dead-despite-late-text' expired an hour ago but is stored on a
        // +14:00 clock, so its local text reads AFTER the reader's — old
        // compare said ALIVE, leaking dead entries.
        $early = (new \DateTimeImmutable('+1 hour'))
            ->setTimezone(new \DateTimeZone('-12:00'))
            ->format(\DATE_ATOM);
        $late = (new \DateTimeImmutable('-1 hour'))
            ->setTimezone(new \DateTimeZone('+14:00'))
            ->format(\DATE_ATOM);

        $path = $this->createLegacyDb('offsets', [
            ['key' => 'alive-despite-early-text', 'expires' => $early],
            ['key' => 'dead-despite-late-text', 'expires' => $late],
        ]);

        $db = new Database($path, 'offsets');

        $this->assertNotNull(
            $db->get('alive-despite-early-text'),
            'a row with an hour of true life left must read alive regardless of stored UTC offset'
        );
        $this->assertNull(
            $db->get('dead-despite-late-text'),
            'a row whose true instant has passed must read dead regardless of stored UTC offset'
        );
        $this->assertSame(
            ['alive-despite-early-text'],
            \array_values($db->allKeys()),
            'allKeys must honour true instants'
        );

        $db->close();
    }

    public function testUnparseableLegacyExpiryIsQuarantinedNotBricked(): void
    {
        // kv-022b: the old code threw from the constructor, so the WHOLE
        // vault was unreadable forever, no repair path. G5 default: the
        // row is quarantined to the definitively-expired epoch and the
        // user is warned loudly; the data and every other row survive.
        $future = (new \DateTimeImmutable('+1 hour'))->format(\DATE_ATOM);
        $path = $this->createLegacyDb('garbage', [
            ['key' => 'junk', 'expires' => 'not-a-date'],
            ['key' => 'alive', 'expires' => $future],
        ]);

        $db = new Database($path, 'garbage');

        // The vault opens: healthy rows read normally.
        $this->assertNotNull($db->get('alive'));
        // The quarantined row reads as expired (epoch 0 is universally
        // past) — it is NOT laundered into a forever entry.
        $this->assertNull($db->get('junk'));

        // And it is prunable: prune drops it.
        $this->assertSame(1, $db->pruneExpired());
        $this->assertSame(['alive'], \array_values($db->allKeys()));
        $db->close();
    }

    public function testQuarantineWarningReachesCliStderr(): void
    {
        // "loud" is part of the law: the CLI open surfaces the quarantine
        // on stderr, sanitized like every other untrusted-bytes egress.
        $path = $this->createLegacyDb('default', [
            ['key' => 'junk', 'expires' => "not\x1ba-date"],
        ]);
        $xdg = $this->tmpDir . '/xdg-warn';
        \mkdir($xdg . '/skate', 0o700, true);
        \rename($path, $xdg . '/skate/default.db');

        $run = self::spawnKv(['list'], $xdg);

        $this->assertSame(0, $run['exit'], 'the vault must open: ' . $run['stderr']);
        $this->assertStringContainsString("key 'junk'", $run['stderr']);
        $this->assertStringContainsString('quarantined it as expired', $run['stderr']);
        $this->assertStringNotContainsString("\x1b", $run['stderr'], 'the warning carries no raw control bytes (kv-004 egress law)');
    }

    public function testSignedDigitLegacyCellOpensAsTrueEpoch(): void
    {
        // kv-022a: '-86400' passes Entry::parseExpiry's /^-?[0-9]+$/ but
        // died in the migration's unsigned GLOB + DateTimeImmutable leg.
        // Mirror the reader: signed digit text IS an epoch.
        $path = $this->createLegacyDb('signed', [
            ['key' => 'ancient', 'expires' => '-86400'],
            ['key' => 'other', 'expires' => (new \DateTimeImmutable('+1 hour'))->format(\DATE_ATOM)],
        ]);

        $db = new Database($path, 'signed');

        $this->assertNull($db->get('ancient'), 'epoch -86400 is a true past instant');
        $this->assertNotNull($db->get('other'), 'the rest of the vault stays reachable');

        // Canonical signed spelling is already correct as a VALUE: no text
        // rewrite was needed. The affinity rebuild still moves the STORAGE
        // class to the canonical integer (kv-128): -86400 as an integer.
        $prop = new \ReflectionProperty(Database::class, 'db');
        $prop->setAccessible(true);
        /** @var \SQLite3 $sqlite */
        $sqlite = $prop->getValue($db);
        $this->assertSame(-86400, $sqlite->querySingle("SELECT expires_at FROM entries WHERE key = 'ancient'"));
        $this->assertSame(1, $db->pruneExpired(), 'and the expired signed row prunes');
        $db->close();
    }

    public function testStampShortCircuitsTheScanOnEveryLaterOpen(): void
    {
        // kv-028: first open pays and stamps; later opens pay nothing.
        // The observable proof: after stamping, plant a fresh text-garbage
        // cell through a raw handle. An unstamped open would quarantine
        // it; the stamped open must not even look.
        $path = $this->createLegacyDb('stamp', [
            ['key' => 'alive', 'expires' => (new \DateTimeImmutable('+1 hour'))->format(\DATE_ATOM)],
        ]);
        $db = new Database($path, 'stamp');
        $prop = new \ReflectionProperty(Database::class, 'db');
        $prop->setAccessible(true);
        /** @var \SQLite3 $sqlite */
        $sqlite = $prop->getValue($db);
        $this->assertSame(
            Database::SCHEMA_VERSION,
            (int) $sqlite->querySingle('PRAGMA user_version'),
            'a migrated open stamps the schema version'
        );

        $sqlite->exec("UPDATE entries SET expires_at = 'never-parses' WHERE key = 'alive'");
        $db->close();

        $again = new Database($path, 'stamp');
        /** @var \SQLite3 $sqlite2 */
        $sqlite2 = $prop->getValue($again);
        $this->assertSame(
            'never-parses',
            $sqlite2->querySingle("SELECT expires_at FROM entries WHERE key = 'alive'"),
            'at the current stamp the detection scan does not run — the planted cell is untouched'
        );
        $again->close();
    }

    public function testCasSkipsACellThatChangedUnderTheSnapshot(): void
    {
        // kv-027 belt: the per-rewrite compare-and-set. Drive the exact
        // lost-update shape the old unlocked snapshot allowed: take the
        // snapshot, let another connection commit over the cell, then run
        // the rewrite with the stale value — it must report false and
        // leave the concurrent commit intact.
        $stale = '2026-01-01T00:00:00+00:00';
        $path = $this->createLegacyDb('race', [
            ['key' => 'raced', 'expires' => $stale],
        ]);
        $db = new Database($path, 'race'); // migrates + stamps

        $prop = new \ReflectionProperty(Database::class, 'db');
        $prop->setAccessible(true);
        /** @var \SQLite3 $sqlite */
        $sqlite = $prop->getValue($db);

        // Return the cell to legacy text shape so the scan sees it again.
        $sqlite->exec("UPDATE entries SET expires_at = '{$stale}' WHERE key = 'raced'");

        $collect = new \ReflectionMethod(Database::class, 'collectLegacyExpiryRows');
        $collect->setAccessible(true);
        $pending = $collect->invoke($db);
        $this->assertCount(1, $pending);

        // A concurrent writer commits a newer value.
        $sqlite->exec("UPDATE entries SET expires_at = '2030-06-06T00:00:00+00:00' WHERE key = 'raced'");

        $rewrite = new \ReflectionMethod(Database::class, 'rewriteLegacyExpiryRow');
        $rewrite->setAccessible(true);
        $this->assertFalse(
            $rewrite->invoke($db, 'raced', $stale),
            'a rewrite built on a stale snapshot must lose to the concurrent commit'
        );
        $this->assertSame(
            '2030-06-06T00:00:00+00:00',
            (string) $sqlite->querySingle("SELECT expires_at FROM entries WHERE key = 'raced'"),
            'the concurrent value stands untouched'
        );
        $db->close();
    }

    /**
     * Ordering law: the permission sweep must PRECEDE the migration.
     *
     * A legacy 0644 file is tightened on open — but the one-time expiry
     * rewrite inside init() is itself a write, so a sweep that runs only
     * AFTER init() leaves the exposure window open through the very call
     * that rewrites the schema. A transient window cannot be sampled
     * functionally, so the order is pinned structurally on the
     * constructor's own body (house pattern: structural source-slice
     * ordering pins, cf. MediaStore's fopen<chmod<rename pin).
     */
    public function testPermissionsAreTightenedBeforeTheMigrationWrites(): void
    {
        $method = new \ReflectionMethod(Database::class, '__construct');
        $lines = \file((string) $method->getFileName());
        $this->assertIsArray($lines, 'Database.php must be readable for the ordering pin');
        $body = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $sweep = \strpos($body, '$this->secureFilePermissions(');
        $init = \strpos($body, '$this->init(');
        $this->assertNotFalse($sweep, 'the constructor must sweep permissions');
        $this->assertNotFalse($init, 'the constructor must run init()');
        $this->assertLessThan(
            (int) $init,
            (int) $sweep,
            'a legacy 0644 database must be tightened BEFORE init() writes the expiry migration'
        );

        // Belt: the post-open end state stays 0600 regardless of the mode
        // the legacy file arrived with.
        $path = $this->createLegacyDb('perm', [
            ['key' => 'alive', 'expires' => (new \DateTimeImmutable('+1 hour'))->format(\DATE_ATOM)],
        ]);
        \chmod($path, 0o644);
        $db = new Database($path, 'perm');
        \clearstatcache(true, $path);
        $this->assertSame(
            0,
            \fileperms($path) & 0o077,
            'a world-readable legacy database must not stay group/world accessible after open'
        );
        $db->close();
    }

    public function testFreshDatabaseWritesEpochAndRoundTripsThroughReopen(): void
    {
        $path = $this->tmpDir . '/fresh.db';
        $db = new Database($path, 'fresh');
        // set() binds time()+ttl at WRITE time; re-reading the clock after the
        // round-trip would race the second boundary (observed as a one-flake-in-
        // many suite red). Pin to the integer seconds bracketing the write —
        // still exact second-precision, still caught by the DATE_ATOM mutation.
        $before = \time();
        $db->set('t', 'v', false, 3600);
        $after = \time();
        $db->close();

        $db = new Database($path, 'fresh');
        $entry = $db->get('t');
        $this->assertNotNull($entry);
        $this->assertContains(
            $entry->expiresAt?->getTimestamp(),
            [$before + 3600, $after + 3600],
            'expiry lands on the integer second',
        );
        $db->close();
    }

    public function testSecondPrecisionSetMatchesSqlFilter(): void
    {
        // set() binds time()+ttl as an integer; the SQL predicate must use the
        // same integer clock (mutation bait: mixing a DATE_ATOM :now back in
        // would make CAST comparisons garbage).
        $path = $this->tmpDir . '/precision.db';
        $db = new Database($path, 'precision');
        $entry = $db->set('p', 'v', false, 2);
        $this->assertSame(\time() + 2, $entry->expiresAt?->getTimestamp());
        $this->assertNotNull($db->get('p'), 'a 2-second TTL is alive immediately');
        $db->close();
    }

    /**
     * The in-the-wild story end to end: a legacy database written by an old
     * candy-kv, discovered by the tip BINARY (not a test-constructed
     * Database), is migrated on open and read by true instant.
     */
    public function testTipBinaryReadsLegacyDatabase(): void
    {
        $future = (new \DateTimeImmutable('+1 hour'))->format(\DATE_ATOM);
        $past = (new \DateTimeImmutable('-1 hour'))->format(\DATE_ATOM);
        $legacy = $this->createLegacyDb('default', [
            ['key' => 'alive', 'expires' => $future],
            ['key' => 'dead', 'expires' => $past],
            ['key' => 'no-expiry', 'expires' => null],
        ]);

        // Store() with no dataDir resolves XDG_CONFIG_HOME/skate/default.db —
        // exactly where an old install's file lives.
        $xdg = $this->tmpDir . '/xdg';
        \mkdir($xdg . '/skate', 0o700, true);
        \rename($legacy, $xdg . '/skate/default.db');

        $alive = $this->runBin(['get', 'alive'], $xdg);
        $this->assertSame(0, $alive['exit'], 'legacy alive row: ' . $alive['stderr']);
        $this->assertSame('v-alive', \trim($alive['stdout']));

        $dead = $this->runBin(['get', 'dead'], $xdg);
        $this->assertSame(1, $dead['exit'], 'legacy expired row reads as missing');
        $this->assertSame('', $dead['stdout']);

        $listing = $this->runBin(['list'], $xdg);
        $this->assertSame(0, $listing['exit']);
        $this->assertStringContainsString('alive', $listing['stdout']);
        $this->assertStringContainsString('no-expiry', $listing['stdout']);
        $this->assertStringNotContainsString('dead', $listing['stdout']);

        // The binary's own open persisted the one-time rewrite: expiry cells
        // are numeric epoch storage afterwards (value pass + affinity
        // rebuild) — they no longer carry offset strings in any shape.
        $sqlite = new \SQLite3($xdg . '/skate/default.db');
        $raw = (string) $sqlite->querySingle("SELECT expires_at FROM entries WHERE key='alive'");
        $sqlite->close();
        $this->assertMatchesRegularExpression('/^-?[0-9]+$/', $raw, 'the tip binary must re-encode legacy expiries on open');
        $this->assertSame((new \DateTimeImmutable($future))->getTimestamp(), (int) $raw, 'the rewritten epoch preserves the original instant');
    }

    /**
     * Run `php bin/candy-kv <args...>` with an isolated XDG_CONFIG_HOME.
     *
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int, timedOut: bool}
     */
    private function runBin(array $args, string $xdg): array
    {
        // spawnKv closes stdin so `set` never blocks waiting on a terminal.
        return self::spawnKv($args, $xdg);
    }

    // -------------------------------------------------------------------------
    // kv-128 / kv-079: the affinity rebuild (schema step 2)
    // -------------------------------------------------------------------------

    private function openHandle(Database $db): \SQLite3
    {
        $prop = new \ReflectionProperty(Database::class, 'db');
        $prop->setAccessible(true);
        /** @var \SQLite3 $sqlite */
        return $prop->getValue($db);
    }

    public function testLegacyTextAffinityColumnIsRebuiltToInteger(): void
    {
        $future = (new \DateTimeImmutable('+1 hour'))->format(\DATE_ATOM);
        $path = $this->createLegacyDb('affinity', [
            ['key' => 'alive', 'expires' => $future],
            ['key' => 'never', 'expires' => null],
            ['key' => 'digits', 'expires' => (string) (time() + 7200)],
        ]);

        // Pre-state: the ALTER-add lineage really is a TEXT column.
        $raw = new \SQLite3($path);
        $this->assertSame('TEXT', $raw->querySingle(
            "SELECT type FROM pragma_table_info('entries') WHERE name = 'expires_at'"
        ));
        $raw->close();

        $db = new Database($path, 'affinity');
        $sqlite = $this->openHandle($db);

        // Post-state (acceptance: PRAGMA table_info differs pre/post): the
        // column carries the canonical INTEGER affinity, one logical schema
        // for every lineage.
        $this->assertSame('INTEGER', $sqlite->querySingle(
            "SELECT type FROM pragma_table_info('entries') WHERE name = 'expires_at'"
        ));
        // Storage CLASSES converted, not just spellings: migrated cells are
        // numeric integers, forever cells stay NULL.
        $this->assertSame('integer', $sqlite->querySingle(
            "SELECT typeof(expires_at) FROM entries WHERE key = 'alive'"
        ));
        $this->assertSame('null', $sqlite->querySingle(
            "SELECT typeof(expires_at) FROM entries WHERE key = 'never'"
        ));
        // The rebuild preserved the instant AND every other column...
        $alive = $db->get('alive');
        $this->assertNotNull($alive);
        $this->assertSame((new \DateTimeImmutable($future))->getTimestamp(), $alive->expiresAt?->getTimestamp());
        $this->assertSame('v-digits', $db->get('digits')?->value);
        // ...and the PRIMARY KEY survived the create-copy-swap.
        $this->assertSame(
            Database::SCHEMA_VERSION,
            (int) $sqlite->querySingle('PRAGMA user_version'),
            'the rebuild rides the raised stamp'
        );
        $this->expectException(\SQLite3Exception::class);
        $sqlite->exec("INSERT INTO entries (key, value, binary, created, modified) VALUES ('alive','dupe',0,'now','now')");
        $db->close();
    }

    public function testAffinityIsPermanentEnforcementEvenForRawWriters(): void
    {
        // WHY the rebuild beats the old CAST bridge: affinity converts at
        // WRITE time, so after the schema stamp no writer — not even one
        // bypassing the library with a raw handle — can store digit text
        // in the expiry cell. The bridge only papered over the legacy
        // shape; the column now refuses to hold it.
        $path = $this->createLegacyDb('sticks', [
            ['key' => 'alive', 'expires' => (new \DateTimeImmutable('+1 hour'))->format(\DATE_ATOM)],
        ]);
        $db = new Database($path, 'sticks');
        $sqlite = $this->openHandle($db);

        $sqlite->exec("UPDATE entries SET expires_at = '2000000000' WHERE key = 'alive'");
        $this->assertSame('integer', $sqlite->querySingle(
            "SELECT typeof(expires_at) FROM entries WHERE key = 'alive'"
        ));
        $db->close();

        // And the stamped re-open neither re-runs the migration nor touches
        // the numeric cell (no forever-repeating heuristic walk, kv-028).
        $again = new Database($path, 'sticks');
        $this->assertSame(2000000000, (int) $this->openHandle($again)->querySingle(
            "SELECT expires_at FROM entries WHERE key = 'alive'"
        ));
        $again->close();
    }

    public function testFreshDatabaseCarriesIntegerAffinityAndExpiryIndex(): void
    {
        $path = $this->tmpDir . '/fresh-affinity.db';
        $db = new Database($path, 'fresh-affinity');
        $db->set('x', 'v', false, 3600);
        $sqlite = $this->openHandle($db);

        $this->assertSame('INTEGER', $sqlite->querySingle(
            "SELECT type FROM pragma_table_info('entries') WHERE name = 'expires_at'"
        ));
        // Both lineages now share ONE physical schema (kv-128 was born of
        // them diverging), and both carry the partial TTL index.
        $this->assertSame('1', (string) $sqlite->querySingle(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'entries_expires'"
        ));
        $db->close();
    }

    public function testPruneSeeksTheExpiryIndex(): void
    {
        // kv-079's scaling wall: prune used to be an unindexable CAST
        // predicate — a full-table O(N) DELETE holding the exclusive write
        // lock. The bare comparison under INTEGER affinity lets the partial
        // index answer it. Pinned via the planner's own output.
        $path = $this->tmpDir . '/index-prune.db';
        $db = new Database($path, 'index-prune');
        $sqlite = $this->openHandle($db);
        for ($i = 0; $i < 50; $i++) {
            $db->set(\sprintf('k%02d', $i), 'v', false, $i % 2 === 0 ? 3600 : null);
        }

        $plan = '';
        $result = $sqlite->query(
            'EXPLAIN QUERY PLAN DELETE FROM entries WHERE expires_at IS NOT NULL AND expires_at < ' . (time() + 1)
        );
        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $plan .= $row['detail'] . "\n";
        }
        $this->assertStringContainsString('entries_expires', $plan, 'prune must seek the partial expiry index, plan: ' . $plan);
        $this->assertStringNotContainsString('SCAN entries', $plan, 'a full-scan plan would re-widen the write-lock window (kv-079)');

        $db->close();
    }

    public function testReadPredicatesCarryNoCastBridge(): void
    {
        // kv-079's index win only holds while the liveness predicates stay
        // bare comparisons. The planner optimises `CAST(col AS INTEGER)`
        // away when the affinity already matches (mutation-verified), so an
        // EXPLAIN-only pin could not see a reintroduced bridge spread back
        // over the file; the source itself is pinned instead. The CAST may
        // survive ONLY inside the migration machinery: the value pass's
        // canonical-spelling scan and the rebuild's one-time copy.
        $src = (string) \file_get_contents((new \ReflectionClass(Database::class))->getFileName());

        $this->assertSame(
            2,
            \preg_match_all('/CAST\(expires_at AS INTEGER\)/', $src),
            'only the rebuild copy and the scan-inner CAST may exist; a bridge back into any predicate re-widens the scan'
        );
        $this->assertSame(
            1,
            \preg_match_all('/CAST\(CAST\(expires_at/', $src),
            'exactly one scan-side double-CAST may exist (the value pass detection)'
        );
        // The bare liveness arms: seven `IS NULL OR >= :now` readers/writers
        // (get, delete, list, count-null, count-glob, deleteMany, allKeys,
        // buildGlobQuery share the shape) plus prune's strict `<` complement.
        $this->assertGreaterThanOrEqual(
            8,
            \preg_match_all('/expires_at >= :now/', $src),
            'liveness readers must compare the bare integer column'
        );
        $this->assertSame(
            1,
            \preg_match_all('/expires_at < :now/', $src),
            "prune must delete the bare comparison's exact complement"
        );
    }
}

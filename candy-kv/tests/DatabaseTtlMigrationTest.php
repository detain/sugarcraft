<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Kv\Database;

/**
 * Regression pins for the D4 TTL epoch migration (docs-fix-1023 M3).
 *
 * Pre-D4 databases stored expires_at as a DATE_ATOM offset string and
 * compared it lexicographically against the reader's local clock — so the
 * SAME row could read expired-while-alive or alive-while-expired depending
 * on the reader's UTC offset. D4 stores integer epoch seconds and converts
 * legacy rows once on open. These tests build legacy-shaped files with a
 * bare SQLite3 handle (exactly what exists in the wild) and prove the tip
 * reads them by true instant.
 */
final class DatabaseTtlMigrationTest extends TestCase
{
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
     * Create a pre-D4 file: the old schema (expires_at TEXT, no epoch),
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

        // The one-time rewrite actually happened: expiry cells are pure digits
        // (a legacy TEXT-affinity column stores the epoch as numeric text; a
        // fresh INTEGER column stores an integer — both compare numerically).
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

    public function testUnparseableLegacyExpiryIsRefusedLoudly(): void
    {
        $path = $this->createLegacyDb('garbage', [
            ['key' => 'junk', 'expires' => 'not-a-date'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot migrate expiry');
        new Database($path, 'garbage');
    }

    public function testFreshDatabaseWritesEpochAndRoundTripsThroughReopen(): void
    {
        $path = $this->tmpDir . '/fresh.db';
        $db = new Database($path, 'fresh');
        $db->set('t', 'v', false, 3600);
        $db->close();

        $db = new Database($path, 'fresh');
        $entry = $db->get('t');
        $this->assertNotNull($entry);
        $this->assertSame((int) \time() + 3600, $entry->expiresAt?->getTimestamp(), 'expiry lands on the integer second');
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
     * The in-the-wild story end to end: a pre-D4 database written by an old
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
        // are pure digits afterwards (TEXT affinity may keep them numeric
        // text — what matters is they no longer carry offset strings).
        $sqlite = new \SQLite3($xdg . '/skate/default.db');
        $raw = (string) $sqlite->querySingle("SELECT expires_at FROM entries WHERE key='alive'");
        $sqlite->close();
        $this->assertMatchesRegularExpression('/^[0-9]+$/', $raw, 'the tip binary must re-encode legacy expiries on open');
        $this->assertSame((new \DateTimeImmutable($future))->getTimestamp(), (int) $raw, 'the rewritten epoch preserves the original instant');
    }

    /**
     * Run `php bin/candy-kv <args...>` with an isolated XDG_CONFIG_HOME.
     *
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runBin(array $args, string $xdg): array
    {
        $cmd = \array_merge([\PHP_BINARY, \dirname(__DIR__) . '/bin/candy-kv'], $args);

        $env = \getenv();
        $env['XDG_CONFIG_HOME'] = $xdg;

        $proc = \proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);
        $this->assertIsResource($proc, 'failed to launch the candy-kv CLI');

        // Close stdin so `set` never blocks waiting on a terminal.
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exit = \proc_close($proc);

        return ['stdout' => (string) $stdout, 'stderr' => (string) $stderr, 'exit' => $exit];
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use SugarCraft\Kv\Store;
use SugarCraft\Kv\Support\Ttl;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private string $tmpDir;
    private Store $store;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/candy-kv-store-test-' . \uniqid();
        \mkdir($this->tmpDir, 0o700, true);
        $this->store = new Store($this->tmpDir, 'testdb');
    }

    protected function tearDown(): void
    {
        unset($this->store);
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        $files = \glob("{$dir}/*") ?: [];
        foreach ($files as $f) {
            \is_dir($f) ? $this->removeDir($f) : \unlink($f);
        }
        \rmdir($dir);
    }

    public function testSetAndGet(): void
    {
        $this->store->set('color', 'blue');
        $this->assertSame('blue', $this->store->get('color'));
    }

    public function testGetNonExistentReturnsEmptyString(): void
    {
        $this->assertSame('', $this->store->get('nope'));
    }

    public function testGetNonExistentWithFallback(): void
    {
        $this->assertSame('default', $this->store->get('nope', 'default'));
    }

    public function testEntryReturnsMetadata(): void
    {
        $this->store->set('x', 'y');
        $entry = $this->store->entry('x');
        $this->assertNotNull($entry);
        $this->assertSame('y', $entry->value);
        $this->assertSame('x', $entry->key);
    }

    public function testEntryNullForMissing(): void
    {
        $this->assertNull($this->store->entry('missing'));
    }

    public function testSetAndGetWithDbSuffix(): void
    {
        $this->store->set('token@passwords', 'hunter2');
        $this->assertSame('hunter2', $this->store->get('token@passwords'));
    }

    public function testCrossDatabaseIsolation(): void
    {
        $this->store->set('key@alpha', 'in-alpha');
        $this->store->set('key@beta', 'in-beta');
        $this->assertSame('in-alpha', $this->store->get('key@alpha'));
        $this->assertSame('in-beta', $this->store->get('key@beta'));
    }

    public function testDeleteSingle(): void
    {
        $this->store->set('delme', 'gone');
        $n = $this->store->delete('delme');
        $this->assertSame(1, $n);
        $this->assertSame('', $this->store->get('delme'));
    }

    public function testDeleteGlob(): void
    {
        $this->store->set('temp-1', 'v');
        $this->store->set('temp-2', 'v');
        $this->store->set('keep', 'v');

        $n = $this->store->delete('temp-*');
        $this->assertSame(2, $n);
        $this->assertSame('v', $this->store->get('keep'));
        $this->assertSame('', $this->store->get('temp-1'));
    }

    public function testListDefaultDb(): void
    {
        $this->store->set('a', '1');
        $this->store->set('b', '2');

        $keys = [];
        foreach ($this->store->list() as $e) {
            $keys[] = $e->key;
        }
        $this->assertContains('a', $keys);
        $this->assertContains('b', $keys);
    }

    public function testListFromSpecificDb(): void
    {
        $this->store->set('x@region1', '1');
        $this->store->set('y@region1', '2');

        $keys = [];
        foreach ($this->store->list(null, 'region1') as $e) {
            $keys[] = $e->key;
        }
        $this->assertCount(2, $keys);
    }

    public function testListReverse(): void
    {
        $this->store->set('z', '1');
        $this->store->set('a', '2');

        $keys = [];
        foreach ($this->store->list(null, null, reverse: true) as $e) {
            $keys[] = $e->key;
        }
        $this->assertSame(['z', 'a'], $keys);
    }

    public function testListKeysOnly(): void
    {
        $this->store->set('k1', 'v1');
        $this->store->set('k2', 'v2');

        $keys = [...$this->store->list(null, null, mode: 'keys')];
        $this->assertContains('k1', $keys);
        $this->assertContains('k2', $keys);
    }

    public function testListValuesOnly(): void
    {
        $this->store->set('k1', 'alpha');
        $this->store->set('k2', 'beta');

        $vals = [...$this->store->list(null, null, mode: 'values')];
        $this->assertContains('alpha', $vals);
        $this->assertContains('beta', $vals);
    }

    public function testListRefusesUnknownModeThroughStoreForwarding(): void
    {
        // kv-015: the refusal lives once in Database::list; Store::list
        // forwards, so a typo via the Store door must fail the same way.
        $this->store->set('k1', 'v1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown list mode "all-keys"');

        [...$this->store->list(null, null, mode: 'all-keys')];
    }

    public function testSetFileAndGetFile(): void
    {
        $srcPath = $this->tmpDir . '/source.txt';
        $dstPath = $this->tmpDir . '/dest.txt';
        \file_put_contents($srcPath, "binary-like\x00\xff\xfe");

        $this->store->setFile('myfile', $srcPath);
        $ok = $this->store->getFile('myfile', $dstPath);

        $this->assertTrue($ok);
        $this->assertSame(\file_get_contents($srcPath), \file_get_contents($dstPath));
    }

    public function testListDatabases(): void
    {
        $this->store->set('x@alpha', '1');
        $this->store->set('x@beta', '2');
        $this->store->set('x@testdb', '3');

        $dbs = $this->store->listDatabases();
        $this->assertContains('alpha', $dbs);
        $this->assertContains('beta', $dbs);
        $this->assertContains('testdb', $dbs);
    }

    public function testDeleteDatabase(): void
    {
        $this->store->set('x@wipeout', 'v');
        $this->assertTrue($this->store->deleteDatabase('wipeout'));
        $this->assertNotContains('wipeout', $this->store->listDatabases());
    }

    public function testDataDir(): void
    {
        $this->assertSame($this->tmpDir, $this->store->dataDir());
    }

    public function testEntryIncludesMetadata(): void
    {
        $this->store->set('ts-test', 'val');
        $entry = $this->store->entry('ts-test');
        $this->assertNotNull($entry->createdAt);
        $this->assertNotNull($entry->modifiedAt);
    }

    public function testDeleteByKey(): void
    {
        $this->store->set('tmp-key', 'temp-value');
        $this->assertSame(1, $this->store->delete('tmp-key'));
        $this->assertSame('', $this->store->get('tmp-key'));
    }

    public function testDeleteNonExistentReturnsZero(): void
    {
        $this->assertSame(0, $this->store->delete('does-not-exist'));
    }

    public function testDeleteWithGlobPattern(): void
    {
        $this->store->set('user-alice', 'alice-data');
        $this->store->set('user-bob', 'bob-data');
        $this->store->set('user-carol', 'carol-data');
        $this->store->set('config-x', 'x');
        $deleted = $this->store->delete('user-*');
        $this->assertSame(3, $deleted);
        $this->assertSame('', $this->store->get('user-alice'));
        $this->assertSame('', $this->store->get('user-bob'));
        $this->assertSame('x', $this->store->get('config-x'));
    }

    public function testDeleteDatabaseNonExistentReturnsFalse(): void
    {
        $this->assertFalse($this->store->deleteDatabase('nonexistent-db'));
    }

    public function testSetBinaryAndRetrieve(): void
    {
        $this->store->set('bin', "\x00\xff\xfe\xfd", true);
        $entry = $this->store->entry('bin');
        $this->assertTrue($entry->binary);
        $this->assertSame("\x00\xff\xfe\xfd", $entry->rawValue());
    }

    public function testSetBinaryNonBase64Decoded(): void
    {
        $this->store->set('txt', 'plain-text', false);
        $entry = $this->store->entry('txt');
        $this->assertFalse($entry->binary);
        $this->assertSame('plain-text', $entry->value);
    }

    public function testSetWithTtl(): void
    {
        $this->store->setWithTtl('ttl-key', 'ttl-value', 3600);
        $entry = $this->store->entry('ttl-key');
        $this->assertNotNull($entry);
        $this->assertNotNull($entry->expiresAt);
        $this->assertFalse($entry->isExpired());
    }

    public function testSetWithTtlZeroIsNoOp(): void
    {
        $this->store->setWithTtl('nope', 'val', 0);
        $this->assertSame('', $this->store->get('nope'));
    }

    public function testSetWithTtlNegativeIsNoOp(): void
    {
        $this->store->setWithTtl('neg', 'val', -10);
        $this->assertSame('', $this->store->get('neg'));
    }

    public function testSetPassesTtlToDatabase(): void
    {
        $e = $this->store->set('t', 'v', false, 7200);
        $this->assertNotNull($e->expiresAt);
    }

    public function testSetWithTtlOnExistingKeyOverwrites(): void
    {
        $this->store->set('overwrite-ttl', 'v1');
        $this->store->setWithTtl('overwrite-ttl', 'v2', 60);
        $this->assertSame('v2', $this->store->get('overwrite-ttl'));
        $entry = $this->store->entry('overwrite-ttl');
        $this->assertNotNull($entry->expiresAt);
    }

    public function testEntryReturnsNullForExpiredKey(): void
    {
        $tmpDir = $this->tmpDir;
        $dbPath = $tmpDir . '/testdb.db';
        $db = new \SugarCraft\Kv\Database($dbPath, 'testdb');

        $reflection = new \ReflectionClass($db);
        $prop = $reflection->getProperty('db');
        $prop->setAccessible(true);
        /** @var \SQLite3 $sqlite */
        $sqlite = $prop->getValue($db);

        $past = (new \DateTimeImmutable('-1 hour'))->format(\DATE_ATOM);
        $stmt = $sqlite->prepare(
            'INSERT INTO entries (key, value, binary, created, modified, expires_at)
             VALUES (:key, :value, 0, :now, :now, :expires_at)'
        );
        $stmt->bindValue(':key', 'old-entry', \SQLITE3_TEXT);
        $stmt->bindValue(':value', 'old-value', \SQLITE3_TEXT);
        $stmt->bindValue(':now', (new \DateTimeImmutable())->format(\DATE_ATOM), \SQLITE3_TEXT);
        $stmt->bindValue(':expires_at', $past, \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        $this->assertNull($this->store->entry('old-entry'));
    }

    public function testFuzzyFilterReturnsMatchingEntries(): void
    {
        $this->store->set('production-api', 'v1');
        $this->store->set('staging-api', 'v2');
        $this->store->set('dev-api', 'v3');
        $this->store->set('database-main', 'v4');

        $results = $this->store->fuzzyFilter('prod');
        $this->assertNotEmpty($results);
        $this->assertSame('production-api', $results[0]->key);
    }

    public function testFuzzyFilterEmptyQueryReturnsEmptyArray(): void
    {
        $this->store->set('key1', 'v1');
        $this->assertSame([], $this->store->fuzzyFilter(''));
    }

    public function testFuzzyFilterRanksByScore(): void
    {
        $this->store->set('production-api', 'v1');
        $this->store->set('provisioning', 'v2');
        $this->store->set('dev-api', 'v3');

        $results = $this->store->fuzzyFilter('pro');
        $keys = array_map(static fn($e) => $e->key, $results);
        // "production-api" starts with "pro" and should rank higher
        $this->assertContains('production-api', $keys);
    }

    public function testFuzzyFilterNoMatchReturnsEmptyArray(): void
    {
        $this->store->set('alpha', 'v1');
        $this->store->set('beta', 'v2');
        $this->assertSame([], $this->store->fuzzyFilter('xyz'));
    }

    // ─── Step 1: db-name validation ─────────────────────────────────────────────

    public function testInvalidDbNameTraversalThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database name');
        $this->store->get('key@../../etc/foo');
    }

    public function testInvalidDbNameDotThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database name');
        $this->store->get('key@.');
    }

    public function testInvalidDbNameDotDotThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database name');
        $this->store->get('key@..');
    }

    public function testInvalidDbNameSlashThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database name');
        $this->store->get('key@foo/bar');
    }

    public function testInvalidDbNameBackslashThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database name');
        $this->store->get("key@foo\\bar");
    }

    public function testDbNameWithDotThrows(): void
    {
        // Dots are forbidden in db names (path traversal prevention)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database name');
        $this->store->get('token@example.com');
    }

    // ─── Step 2: @ in entry key rejection ──────────────────────────────────────

    public function testKeyWithAtSignThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database name');
        // user@example.com fails at db name validation (example.com has dot)
        // before reaching the @-in-key check.
        $this->store->set('user@example.com', 'val');
    }

    public function testKeyWithMultipleAtSignsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot contain @');
        $this->store->set('a@b@c', 'val');
    }

    public function testSingleAtSignKeyStillWorks(): void
    {
        // "token@passwords" is the documented usage: key=token, db=passwords
        $this->store->set('token@passwords', 'hunter2');
        $this->assertSame('hunter2', $this->store->get('token@passwords'));
    }

    // ─── Step 15 (a): setFile throws on unreadable source ────────────────────

    public function testSetFileThrowsOnNonexistentSource(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot read file');
        $this->store->setFile('key', '/nonexistent/path/to/file.txt');
    }

    // ─── Step 15 (b): getFile returns false for missing key ──────────────────

    public function testGetFileReturnsFalseForMissingKey(): void
    {
        $dst = $this->tmpDir . '/dst.txt';
        $ok = $this->store->getFile('nonexistent-key', $dst);
        $this->assertFalse($ok);
        $this->assertFileDoesNotExist($dst);
    }

    // ─── Step 15 (c): getFile returns false when destination unwritable ───────

    public function testGetFileReturnsFalseWhenDestinationUnwritable(): void
    {
        // Skip if running as root (root bypasses permission checks).
        if (\function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test permission failure as root.');
        }

        $this->store->set('writable-key', 'content');
        $readonlyDir = $this->tmpDir . '/readonly_dir';
        \mkdir($readonlyDir, 0o500);
        $dst = $readonlyDir . '/out.txt';

        $ok = $this->store->getFile('writable-key', $dst);
        $this->assertFalse($ok);
    }

    // ─── Step 15 (d): fuzzyFilter ordering is score-descending ───────────────

    public function testFuzzyFilterReturnsScoreDescendingOrder(): void
    {
        // Create a set of keys with deliberately varied similarity to "api"
        // so we can verify descending score order.
        $this->store->set('api-gateway', 'v1');       // strong match
        $this->store->set('api-handler', 'v2');       // strong match
        $this->store->set('apikey', 'v3');            // moderate match
        $this->store->set('rest-api', 'v4');          // moderate match
        $this->store->set('完全不匹配', 'v5');          // no match

        $results = $this->store->fuzzyFilter('api');

        $this->assertNotEmpty($results);
        // All results should have score > 0
        foreach ($results as $entry) {
            $this->assertInstanceOf(\SugarCraft\Kv\Entry::class, $entry);
        }
        // Keys containing "api" as a substring should appear before partial matches.
        $keys = \array_map(fn($e) => $e->key, $results);
        // "api-gateway" and "api-handler" start with "api" — check they come
        // before "apikey" which only contains "api" but isn't a prefix.
        $apiGatewayIdx = \array_search('api-gateway', $keys, true);
        $apikeyIdx = \array_search('apikey', $keys, true);
        if ($apiGatewayIdx !== false && $apikeyIdx !== false) {
            $this->assertLessThan($apikeyIdx, $apiGatewayIdx);
        }
    }

    public function testFuzzyFilterScoreDescendingWithNearTie(): void
    {
        // Two keys equally close to "abc" — order between equals is
        // undefined but must still be stable (same relative order each run).
        $this->store->set('abc-def', 'v1');
        $this->store->set('abc-xyz', 'v2');

        $results = $this->store->fuzzyFilter('abc');

        $this->assertCount(2, $results);
        $keys = \array_map(fn($e) => $e->key, $results);
        $this->assertContains('abc-def', $keys);
        $this->assertContains('abc-xyz', $keys);
    }

    // ─── Step 15 (e): Store::transaction on named db commits multi-set ───────

    public function testStoreTransactionCommitsMultiSetOnNamedDb(): void
    {
        $this->store->set('k1@mydb', 'v1');
        $result = $this->store->transaction('mydb', function (): string {
            $this->store->set('k2@mydb', 'v2');
            $this->store->set('k3@mydb', 'v3');
            return 'committed';
        });

        $this->assertSame('committed', $result);
        $this->assertSame('v1', $this->store->get('k1@mydb'));
        $this->assertSame('v2', $this->store->get('k2@mydb'));
        $this->assertSame('v3', $this->store->get('k3@mydb'));
    }

    public function testStoreTransactionRollsBackOnException(): void
    {
        $this->store->set('before@mydb', 'still');

        try {
            $this->store->transaction('mydb', function (): void {
                $this->store->set('during@mydb', 'should-be-gone');
                throw new \RuntimeException('intentional');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('intentional', $e->getMessage());
        }

        $this->assertSame('still', $this->store->get('before@mydb'));
        $this->assertSame('', $this->store->get('during@mydb'));
    }

    // ─── sanitizeForTty: terminal-safe rendering of stored values ────────────

    public function testSanitizeForTtyPassesPlainTextThrough(): void
    {
        $this->assertSame('hello world', Store::sanitizeForTty('hello world'));
    }

    public function testSanitizeForTtyStripsEscSequences(): void
    {
        // A poisoned value must not be able to emit an SGR/OSC escape. Since
        // kv-005 the scrubber is Sanitize::untrusted(), which deletes whole
        // ESC-introduced sequences instead of leaving their printable residue.
        $out = Store::sanitizeForTty("\x1b[31mRED\x1b[0m");
        $this->assertStringNotContainsString("\x1b", $out);
        $this->assertSame('RED', $out);
    }

    public function testSanitizeForTtyRemovesTheEncodedC1Introducer(): void
    {
        // kv-005 snapshot pin: the exact byte pair `list` handed the terminal
        // from a poisoned/synced store under the old C0-only scrub. A terminal
        // decodes \xC2\x9B (U+009B) and EXECUTES it as 8-bit CSI, so it must
        // not survive a control-free preview line.
        $this->assertSame('q"p', Store::sanitizeForTty("\xC2\x9B" . 'q"p'));
        // The whole encoded-C1 band, not just CSI.
        $this->assertSame('ab', Store::sanitizeForTty("a\xC2\x80\xC2\x9Fb"));
    }

    public function testSanitizeForTtyStripsC0AndDelControls(): void
    {
        // \x07 (BEL) and \x7f (DEL) are removed outright.
        $this->assertSame('ab', Store::sanitizeForTty("a\x07\x7fb"));
    }

    public function testSanitizeForTtyFoldsNewlinesToSpaces(): void
    {
        // Single-line preview: \n / \r / \t collapse to spaces.
        $this->assertSame('a b c', Store::sanitizeForTty("a\nb\tc"));
    }

    public function testSanitizeForTtyBinaryBecomesPlaceholder(): void
    {
        // Invalid UTF-8 bytes are rendered as a placeholder, never dumped raw.
        $this->assertSame('<binary 3 bytes>', Store::sanitizeForTty("\xff\xfe\xfd"));
    }

    public function testSanitizeForTtyEmptyStringStaysEmpty(): void
    {
        $this->assertSame('', Store::sanitizeForTty(''));
    }

    // ─── defaultDatabase: getter for the configured default db name ─────────

    public function testDefaultDatabaseReturnsConfiguredDefault(): void
    {
        $this->assertSame('testdb', $this->store->defaultDatabase());
    }

    public function testDefaultDatabaseWithCustomName(): void
    {
        $customStore = new Store($this->tmpDir, 'myDb');
        $this->assertSame('myDb', $customStore->defaultDatabase());
    }

    // ─── dbPath validation: path traversal prevention ─────────────────────────

    public function testDbPathThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database name');

        $reflection = new \ReflectionClass($this->store);
        $method = $reflection->getMethod('dbPath');
        $method->setAccessible(true);
        $method->invoke($this->store, '');
    }

    public function testDbPathThrowsOnInvalidChars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only letters, digits');

        $reflection = new \ReflectionClass($this->store);
        $method = $reflection->getMethod('dbPath');
        $method->setAccessible(true);
        $method->invoke($this->store, 'foo@bar');
    }

    // ─── isPattern: glob character detection ──────────────────────────────────

    public function testIsPatternReturnsTrueForStar(): void
    {
        $reflection = new \ReflectionClass($this->store);
        $method = $reflection->getMethod('isPattern');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->store, 'temp-*'));
    }

    public function testIsPatternReturnsTrueForQuestionMark(): void
    {
        $reflection = new \ReflectionClass($this->store);
        $method = $reflection->getMethod('isPattern');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->store, 'item?'));
    }

    public function testIsPatternReturnsFalseForPlainKey(): void
    {
        $reflection = new \ReflectionClass($this->store);
        $method = $reflection->getMethod('isPattern');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->store, 'plainkey'));
    }

    // ─── kv-043: escaped-glob get helpers ────────────────────────────────

    public function testHasRawGlobFlagsUnescapedGlobBytesOnly(): void
    {
        $this->assertTrue(Store::hasRawGlob('user-*'));
        $this->assertTrue(Store::hasRawGlob('q?mark'));
        $this->assertTrue(Store::hasRawGlob('*leading'), 'offset 0 has no lookback — raw');
        $this->assertFalse(Store::hasRawGlob('ra\\*wt'), 'the \\* spelling is exact-key intent');
        $this->assertFalse(Store::hasRawGlob('plain'));
    }

    public function testUnescapeGlobKeyDropsOnlyGlobBackslashes(): void
    {
        $this->assertSame('ra*wt', Store::unescapeGlobKey('ra\\*wt'));
        $this->assertSame('a?b', Store::unescapeGlobKey('a\\?b'));
        // The grammar has no \\ self-escape (kv-007): a backslash before
        // anything else stays literal text, mirroring globToLike.
        $this->assertSame('keep\other', Store::unescapeGlobKey('keep\other'));
        $this->assertSame('plain', Store::unescapeGlobKey('plain'));
    }

    public function testGlobCharKeysRoundTripThroughTheLibraryDoor(): void
    {
        // The Store API never gated glob bytes (only the CLI did); pin that
        // a raw-glob key stores and reads back exactly, so the bin law sits
        // on top of a faithful library door.
        $this->store->set('a*b', 'one');
        $this->store->set('q?mark', 'two');
        $this->assertSame('one', $this->store->get('a*b'));
        $this->assertSame('two', $this->store->get('q?mark'));
    }

    // ─── Data dir resolution, mkdir fail-loud, setWithTtl ───────────────

    public function testResolveDataDirPrefersXdgThenHome(): void
    {
        $savedXdg = \getenv('XDG_CONFIG_HOME');
        $savedHome = \getenv('HOME');
        try {
            \putenv('XDG_CONFIG_HOME=' . $this->tmpDir . '/xdg');
            $this->assertSame($this->tmpDir . '/xdg/skate', Store::resolveDataDir());

            \putenv('XDG_CONFIG_HOME');
            \putenv('HOME=' . $this->tmpDir . '/home');
            $this->assertSame($this->tmpDir . '/home/.config/skate', Store::resolveDataDir());
        } finally {
            $this->restoreEnv('XDG_CONFIG_HOME', $savedXdg);
            $this->restoreEnv('HOME', $savedHome);
        }
    }

    public function testResolveDataDirNeverFallsBackIntoTheInstallTree(): void
    {
        // Regression: with HOME unset the old code wrote .config/skate
        // under dirname(__DIR__,2) — inside the library's own checkout.
        $savedXdg = \getenv('XDG_CONFIG_HOME');
        $savedHome = \getenv('HOME');
        try {
            \putenv('XDG_CONFIG_HOME');
            \putenv('HOME');

            try {
                $dir = Store::resolveDataDir();
            } catch (\RuntimeException $e) {
                // passwd/posix unavailable and no env: refusal is the
                // correct loud outcome.
                $this->assertStringContainsString('data directory', $e->getMessage());
                return;
            }

            $installRoot = \dirname((new \ReflectionClass(Store::class))->getFileName(), 2);
            $this->assertStringNotContainsString($installRoot, $dir, 'resolution must not land in the install tree');
            $this->assertStringEndsWith('/.config/skate', $dir, 'passwd fallback must end at the conventional subdir');
            // Cross-check against the passwd entry when ext-posix is present.
            if (\function_exists('posix_getpwuid') && \function_exists('posix_geteuid')) {
                $entry = @\posix_getpwuid(\posix_geteuid());
                if (\is_array($entry) && isset($entry['dir']) && $entry['dir'] !== '' && $entry['dir'] !== '/') {
                    $this->assertSame($entry['dir'] . '/.config/skate', $dir);
                }
            }
        } finally {
            $this->restoreEnv('XDG_CONFIG_HOME', $savedXdg);
            $this->restoreEnv('HOME', $savedHome);
        }
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        \putenv($value === false ? $name : $name . '=' . $value);
    }

    public function testConstructorRefusesUncreatableDataDir(): void
    {
        $blocker = $this->tmpDir . '/blocker';
        \file_put_contents($blocker, 'not-a-dir');

        // Construction touches nothing; the refusal happens on the first
        // WRITE (reads against an unopenable store just see emptiness).
        $store = new Store($blocker . '/sub/dir');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create the key-value data directory');
        $store->set('k', 'v');
    }

    public function testSetWithTtlRefusesArrayValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scalar value');
        $this->store->setWithTtl('arr', ['a'], 60);
    }

    public function testSetWithTtlRefusesNullWithoutStoring(): void
    {
        // BEHAVIOR CHANGE (Step 45): null once passed the guard and stored an
        // empty string while the throw text said "expects a scalar". It is
        // now refused like every other non-scalar, and nothing is written.
        try {
            $this->store->setWithTtl('nil', null, 60);
            $this->fail('null must be refused, not stringified to "".');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('got null', $e->getMessage());
        }
        $this->assertNull($this->store->entry('nil'));
    }

    public function testSetWithTtlRefusesNonStringableObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->setWithTtl('obj', new \stdClass(), 60);
    }

    public function testSetWithTtlAcceptsScalarsAndStringables(): void
    {
        $this->store->setWithTtl('num', 42, 3600);
        $this->assertSame('42', $this->store->get('num'));

        $stringable = new class {
            public function __toString(): string
            {
                return 'str-form';
            }
        };
        $this->store->setWithTtl('str-obj', $stringable, 3600);
        $this->assertSame('str-form', $this->store->get('str-obj'));
    }

    public function testSetWithTtlZeroTtlIsNoOpNotForever(): void
    {
        // Documented polarity: ttl<=0 writes NOTHING (it must not store the
        // key un-expiring, which the old silent return could be mistaken for).
        $this->store->setWithTtl('zero', 'v', 0);
        $this->assertNull($this->store->entry('zero'));
    }

    public function testDeleteDatabaseClosesHandleBeforeUnlink(): void
    {
        $this->store->set('keeper@sidedb', 'v');
        $path = $this->tmpDir . '/sidedb.db';
        $this->assertFileExists($path);

        $this->assertTrue($this->store->deleteDatabase('sidedb'));
        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path . '-wal', 'WAL sidecar must not survive the delete');
        $this->assertFileDoesNotExist($path . '-shm', 'SHM sidecar must not survive the delete');
    }

    // ─── Write-path TTL law (candy-kv-001/014) ──────────────────────────────

    public function testAZeroTtlRaisesInsteadOfStoringSilentForever(): void
    {
        // kv-014: the writer used to DROP non-positive TTLs, silently
        // inverting an expiry request into a forever entry. A forever entry
        // is made by omitting the TTL, never by a clever value.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be between 1 and 999999999 seconds, got 0');
        $this->store->set('zero', 'v', false, 0);
    }

    public function testANegativeTtlRaisesInsteadOfStoringSilentForever(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be between 1 and 999999999 seconds, got -100');
        $this->store->set('neg', 'v', false, -100);
    }

    public function testAnOversizedTtlIsRefusedAndTheLiveRowSurvivesIntact(): void
    {
        // kv-001: `time() + PHP_INT_MAX` overflowed to a negative epoch —
        // the poison row committed permanently invisible while the upsert
        // had ALREADY destroyed the previous live value. The guard fires
        // pre-write, so the old row must survive untouched.
        $this->store->set('spine', 'old');
        try {
            $this->store->set('spine', 'new', false, \PHP_INT_MAX);
            self::fail('Expected InvalidArgumentException for a PHP_INT_MAX TTL.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('between 1 and 999999999 seconds', $e->getMessage());
        }
        $this->assertSame('old', $this->store->get('spine'), 'The refusal must not touch the live row.');
        $this->assertNull($this->store->entry('spine')?->expiresAt, 'No expiry may leak onto the surviving row.');
    }

    public function testJustPastTheHorizonIsRefusedToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->set('edge-plus', 'v', false, Ttl::MAX_SECONDS + 1);
    }

    public function testTheHorizonEdgeItselfStillStores(): void
    {
        // The bound is INCLUSIVE: ~31.7 years is storable, one second past
        // it is not — pin the boundary so a future refactor does not
        // silently shrink or lift it.
        $entry = $this->store->set('edge', 'v', false, Ttl::MAX_SECONDS);
        $this->assertNotNull($entry->expiresAt);
        $this->assertSame('v', $this->store->get('edge'));
    }
}

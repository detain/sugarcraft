<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Kv\Database;
use SugarCraft\Kv\Store;

/**
 * Pins the storage-layer contract established alongside the strict CLI
 * contract: globs match case-sensitively and character-wise like exact
 * reads, deletes only see live
 * rows, reads never create files, "\@" spells a literal at-sign in keys,
 * exported files land at 0600, and suggestions measure codepoints.
 */
final class StorageContractTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/candy-kv-storage-test-' . \uniqid();
        \mkdir($this->tmpDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . '/' . $f;
            \is_dir($path) ? $this->removeDir($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }

    private function store(string $db = 'default'): Store
    {
        return new Store($this->tmpDir . '/data', $db);
    }

    /** Collect the keys produced by a Store::list() run in 'keys' mode. */
    private function keysMatching(Store $store, string $pattern): array
    {
        $out = [];
        foreach ($store->list($pattern, null, false, 'keys') as $key) {
            $out[] = $key;
        }
        return $out;
    }

    public function testGlobMatchingIsCaseSensitiveLikeExactReads(): void
    {
        $store = $this->store();
        $store->set('Abc-1', 'v');

        $this->assertSame([], $this->keysMatching($store, 'abc-*'));
        $this->assertSame(['Abc-1'], $this->keysMatching($store, 'Abc-*'));

        $this->assertSame(0, $store->delete('abc-1'));
        $this->assertSame(1, $store->delete('Abc-1'));
    }

    /**
     * M4: the pattern half of the contract is SQLite LIKE over the stored
     * text — width is counted in characters, not bytes. 'k?y' fills its
     * single '_' with the multi-byte 'ä' (three UTF-8 bytes, one codepoint).
     * The case half stays byte-faithful via case_sensitive_like = ON.
     */
    public function testGlobWildcardSpansOneCharacterNotOneByte(): void
    {
        $store = $this->store();
        $store->set('kay', 'v');
        $store->set("käy", 'v');
        $store->set('kaay', 'v');

        $this->assertSame(['kay', "käy"], $this->keysMatching($store, 'k?y'));
        $this->assertSame([], $this->keysMatching($store, 'K?Y'));
    }

    public function testLiteralEscapesInGlobs(): void
    {
        $store = $this->store();
        $store->set('a*b', '1');
        $store->set('aXb', '2');
        $store->set('a?b', '3');
        $store->set('aYb', '4');

        $this->assertSame(['a*b'], $this->keysMatching($store, 'a\\*b'));
        $this->assertSame(['a?b'], $this->keysMatching($store, 'a\\?b'));
        $this->assertSame(['a*b', 'a?b', 'aXb', 'aYb'], $this->keysMatching($store, 'a*b'));
        // '?' is any one byte — the literal-? key matches too.
        $this->assertSame(['a*b', 'a?b', 'aXb', 'aYb'], $this->keysMatching($store, 'a?b'));
    }

    public function testPatternDeletesAndExactDeletesOnlySeeLiveRows(): void
    {
        $file = $this->tmpDir . '/prune.db';
        $db = new Database($file, 'prune');

        $db->set('p-b', 'gone', false, 1);
        $db->set('p-c', 'gone', false, 1);
        \sleep(2);
        $db->set('p-a', 'live');

        // Under the pattern only the live row is deletable; expired residue
        // survives both delete arms but stays invisible to readers.
        $this->assertSame(1, $db->deleteMany('p-*'));
        $this->assertFalse($db->delete('p-b'));
        $this->assertNull($db->get('p-b'));
        $this->assertNotNull($db->raw('p-b'), 'expired residue stays until pruneExpired()');

        $this->assertSame(2, $db->pruneExpired());
        $this->assertNull($db->raw('p-b'));
        $this->assertNull($db->raw('p-c'));
    }

    public function testReadsNeverCreateFilesAndWritesDo(): void
    {
        $dir = $this->tmpDir . '/lazy';
        $store = new Store($dir, 'default');

        $this->assertSame('', $store->get('nope'));
        $this->assertNull($store->entry('nope'));
        $this->assertSame(0, \iterator_count($store->list()));
        $this->assertSame(0, $store->delete('x'));
        $this->assertSame(0, $store->pruneExpired());
        $this->assertNull($store->suggestionFor('anything'));
        $this->assertSame([], $store->listDatabases());

        $this->assertFalse(\is_dir($dir), 'pure reads must not touch the filesystem');

        $store->set('k', 'v');
        $this->assertTrue(\is_dir($dir));
        $this->assertFileExists($dir . '/default.db');
        $this->assertSame('v', $store->get('k'));

        $mode = \fileperms($dir) & 0o777;
        $this->assertSame(0, $mode & 0o077, 'data directory is created 0700');
    }

    public function testReadOnlyDatabaseOpenRefusesMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        new Database($this->tmpDir . '/never-existed.db', 'never-existed', false);
    }

    public function testRelativeEnvironmentHomesAreRefused(): void
    {
        $previous = \getenv('XDG_CONFIG_HOME');
        \putenv('XDG_CONFIG_HOME=relative/dir');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('must be an absolute path');
            new Store();
        } finally {
            \putenv($previous === false ? 'XDG_CONFIG_HOME' : 'XDG_CONFIG_HOME=' . $previous);
        }
    }

    public function testEmptyEntryKeysAreRefused(): void
    {
        $store = $this->store();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');
        $store->set('', 'v');
    }

    public function testEmptyDatabaseNameAfterSeparatorIsRefused(): void
    {
        $store = $this->store();
        $this->expectException(\InvalidArgumentException::class);
        $store->set('k@', 'v');
    }

    public function testEscapedAtSignSpellsLiteralKeys(): void
    {
        $store = $this->store();
        $store->set('a\\@b', 'v');

        $this->assertSame('v', $store->get('a\\@b'));
        $entry = $store->entry('a\\@b');
        $this->assertNotNull($entry);
        $this->assertSame('a@b', $entry->key, 'the escape is decoded before storage');

        // Without an escape, a second unescaped @ is a refusal, not a guess.
        $this->expectException(\InvalidArgumentException::class);
        $store->set('a@b@c', 'v');
    }

    public function testLeadingAtSignIsALiteralKey(): void
    {
        $store = $this->store();
        $store->set('@x', 'v');
        $this->assertSame('v', $store->get('@x'));
        $this->assertSame(['@x'], $this->keysMatching($store, '@*'));
    }

    public function testSplitKeyAnswersTheReadmeLawExample(): void
    {
        // README 'Key naming, @, and escapes': Store::splitKey('a\@b@db',
        // 'default') returns ['db', 'a@b']. The old raw-strpos guard saw
        // the escaped \@ inside the entry part and refused the exact
        // documented spelling.
        $this->assertSame(['db', 'a@b'], Store::splitKey('a\\@b@db', 'default'));
        $this->assertSame(['default', 'a@b'], Store::splitKey('a\\@b', 'default'), 'no-separator path decodes too');
        $this->assertSame(['db', '@roo'], Store::splitKey('@roo@db', 'default'), 'leading @ stays literal, mirroring the no-separator law');
    }

    public function testSplitKeyStillRefusesASecondUnescapedAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Entry key 'a@b' cannot contain @");
        Store::splitKey('a@b@c', 'default');
    }

    public function testEscapedAtRoutesThroughTheSuffixEndToEnd(): void
    {
        $store = $this->store();
        $store->set('a\\@b@legacy', 'v');

        $this->assertSame('v', $store->get('a\\@b@legacy'));
        $entry = $store->entry('a\\@b@legacy');
        $this->assertNotNull($entry);
        $this->assertSame('a@b', $entry->key, 'entry part decoded, suffix stripped');
        $this->assertNull($store->entry('a@b'), 'the unescaped spelling routes to a different database instead');
    }

    public function testSuggestionsMeasureCodepointsNotBytes(): void
    {
        $store = $this->store();
        $store->set("äbc", 'v');
        $store->set('color', 'v');

        // Codepoint distance 1 within threshold 1; byte levenshtein scored 2.
        $this->assertSame("äbc", $store->suggestionFor('abc'));
        $this->assertSame('color', $store->suggestionFor('colar'));

        $this->assertNull($store->suggestionFor('z'), 'no live key within one edit of a one-codepoint query');
        $this->assertNull($store->suggestionFor('qqqqqq'));
    }

    public function testShortQueriesStillSuggestAtTheFlooredAllowance(): void
    {
        $store = $this->store();
        $store->set('at', 'v');
        $store->set('color', 'v');

        // A plain half-the-length rule gave 1-2 codepoint queries a zero
        // threshold, silencing did-you-mean exactly where a hint helps most.
        // The allowance is floored at one edit instead.
        $this->assertSame('at', $store->suggestionFor('a'), 'one-codepoint query clears the floored allowance');
        $this->assertSame('at', $store->suggestionFor('ct'), 'two-codepoint one-edit typo suggests');

        // The floor is not a free-for-all: distance must still clear it.
        $this->assertNull($store->suggestionFor('co'));
    }

    public function testSaveToWritesPrivateFilesAndReportsMissingKeys(): void
    {
        $store = $this->store();
        $store->set('secret', 'hunter2');

        $target = $this->tmpDir . '/dump.txt';
        $this->assertTrue($store->saveTo('secret', $target));
        \clearstatcache();
        $this->assertSame('hunter2', \file_get_contents($target));
        $this->assertSame(0, \fileperms($target) & 0o077, 'exports never publish at umask');

        $this->assertFalse($store->saveTo('absent', $this->tmpDir . '/other.txt'));
    }

    public function testDeleteDatabaseRemovesMainFileAndAnySidecars(): void
    {
        $store = $this->store();
        $store->set('x@dc', 'v');

        // A read-write open leaves -wal/-shm siblings until checkpoint;
        // whichever of the three exist on disk must all be gone after.
        $dir = $this->tmpDir . '/data';
        $this->assertFileExists($dir . '/dc.db');

        $this->assertTrue($store->deleteDatabase('dc'));
        $this->assertFileDoesNotExist($dir . '/dc.db');
        $this->assertFileDoesNotExist($dir . '/dc.db-wal');
        $this->assertFileDoesNotExist($dir . '/dc.db-shm');
    }

    public function testDeleteDatabaseOfUnknownDatabaseIsFalse(): void
    {
        $store = $this->store();
        $store->set('x@dc', 'v');
        $store->transaction('dc', function () use ($store): void {
            $store->set('inside@dc', 'v');
        });

        // Deleting a name that has no file is a no-op reported as false,
        // so a typo'd database name never looks like a successful wipe.
        $this->assertFalse($store->deleteDatabase('no-such-db'));
    }

    public function testTransactionCreatesOnWrite(): void
    {
        $store = $this->store();
        $store->transaction('txndb', function () use ($store): void {
            $store->set('a@txndb', 'v');
        });

        $this->assertFileExists($this->tmpDir . '/data/txndb.db');
        $this->assertSame('v', $store->get('a@txndb'));
    }

    public function testListDatabasesIgnoresGlobMagicNames(): void
    {
        $dir = $this->tmpDir . '/data';
        \mkdir($dir, 0o700, true);
        // A filename containing glob metacharacters must not be interpreted.
        $odd = $dir . '/we[ir]d.db';
        \file_put_contents($odd, '');
        $store = new Store($dir, 'default');

        $this->assertContains('we[ir]d', $store->listDatabases());
    }

    public function testWalSidecarsAreTightenedAroundWritesAndCleanedOnClose(): void
    {
        $umask = \umask(0o022);
        try {
            $path = $this->tmpDir . '/wal.db';
            $db = new Database($path, 'wal');
            \clearstatcache(true, $path);
            $this->assertSame(0, \fileperms($path) & 0o077, 'the database must be owner-only at ctor return');

            $db->set('secret', 'token');
            foreach ([$path . '-wal', $path . '-shm'] as $sidecar) {
                \clearstatcache(true, $sidecar);
                if (\is_file($sidecar)) {
                    $this->assertSame(0, \fileperms($sidecar) & 0o077, "sidecar {$sidecar} must never sit at the umask");
                }
            }

            $db->close();
            \clearstatcache(true, $path . '-wal');
            $this->assertFileDoesNotExist($path . '-wal', 'a clean close checkpoints and removes the WAL sidecar');

            // A legacy/crash residue at the umask must be tightened on the next open.
            \chmod($path, 0o644);
            \file_put_contents($path . '-wal', 'dirty residue');
            \chmod($path . '-wal', 0o644);
            $again = new Database($path, 'wal');
            \clearstatcache(true, $path);
            $this->assertSame(0, \fileperms($path) & 0o077);
            $this->assertSame(0, \fileperms($path . '-wal') & 0o077);
            $again->close();
        } finally {
            \umask($umask);
        }
    }
}

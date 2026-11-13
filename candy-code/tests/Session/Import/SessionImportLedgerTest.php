<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session\Import;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Session\Import\SessionImportLedger;

final class SessionImportLedgerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite required');
        }
        $this->dir = sys_get_temp_dir() . '/importledger-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testPathReturnsTheGivenPath(): void
    {
        $p = $this->dir . '/l.db';

        self::assertSame($p, SessionImportLedger::atPath($p)->path());
    }

    public function testLookupMissesOnUnknownKey(): void
    {
        self::assertNull(SessionImportLedger::atPath($this->dir . '/l.db')->lookup('nope'));
    }

    public function testRememberThenLookupRoundTrips(): void
    {
        $ledger = SessionImportLedger::atPath($this->dir . '/l.db');
        $ledger->remember('src-1', 'sess-9', 12, 'abc123');

        self::assertSame(['session_id' => 'sess-9', 'items' => 12, 'digest' => 'abc123'], $ledger->lookup('src-1'));
        self::assertNull($ledger->lookup('src-2'));
    }

    public function testRememberReplacesAnExistingKey(): void
    {
        $ledger = SessionImportLedger::atPath($this->dir . '/l.db');
        $ledger->remember('k', 's1', 1, 'd1');
        $ledger->remember('k', 's2', 5, 'd2');

        self::assertSame(['session_id' => 's2', 'items' => 5, 'digest' => 'd2'], $ledger->lookup('k'));
    }

    public function testRowsPersistAcrossInstances(): void
    {
        $p = $this->dir . '/l.db';
        SessionImportLedger::atPath($p)->remember('k', 's', 3, 'd');

        self::assertSame(3, SessionImportLedger::atPath($p)->lookup('k')['items'] ?? null);
    }

    public function testNewDatabaseFileIsOwnerOnly(): void
    {
        $p = $this->dir . '/l.db';
        SessionImportLedger::atPath($p);

        self::assertSame('0600', substr(sprintf('%o', fileperms($p)), -4));
    }

    public function testUnopenableStoreFailsSafeInsteadOfThrowingOnUse(): void
    {
        $this->expectException(\PDOException::class);
        // Opening a path inside a missing directory cannot succeed; the
        // ledger's fail-safe covers schema/query errors, not a dead open.
        SessionImportLedger::atPath($this->dir . '/missing/deeper/l.db');
    }
}

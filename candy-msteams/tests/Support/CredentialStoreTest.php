<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Support;

use CandyCode\Bundles\MsTeams\Support\CredentialStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The store holds the Azure Bot credential triples, pairing ledgers and
 * allowlists in one 0600 JSON file shared (by design) with candy-code's own
 * channels ledger and the discord bundle's rows. The read discipline and
 * the whole-record mutate guards are the security boundary — they must
 * refuse loudly, never quietly.
 */
final class CredentialStoreTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/p3-store-' . getmypid() . '-' . uniqid('', true);
        mkdir($this->dir, 0o700, true);
        $this->path = $this->dir . '/channels.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '.partial');
        @rmdir($this->dir);
    }

    public function testAnAbsentStoreReadsAsEmptyAndStaysDark(): void
    {
        self::assertSame([], (new CredentialStore($this->path))->read());
        self::assertNull((new CredentialStore($this->path))->msteamsCredentials('default'));
        self::assertSame([], (new CredentialStore($this->path))->msteamsAccounts());
    }

    public function testTheWriteLandsZeroSixZeroZeroAndCarriesTheShapeBack(): void
    {
        $store = new CredentialStore($this->path);
        $store->writeMsteamsPairing('default', [], [], [
            'appId' => ' app-1 ', 'appPassword' => ' shh-secret ', 'tenantId' => ' ten-9 ',
        ]);

        clearstatcache();
        self::assertSame(0o600, fileperms($this->path) & 0o777);

        $again = new CredentialStore($this->path);
        self::assertSame(
            ['appId' => 'app-1', 'appPassword' => 'shh-secret', 'tenantId' => 'ten-9'],
            $again->msteamsCredentials('default'),
            'surrounding spaces are trimmed on read',
        );
        self::assertSame(['default'], $again->msteamsAccounts());
    }

    public function testAnIncompleteTripleReadsAsNull(): void
    {
        $store = new CredentialStore($this->path);
        // appId + tenant but no secret: cannot mint a token, must not half-load.
        $store->writeMsteamsPairing('partial', [], [], ['appId' => 'a', 'tenantId' => 't']);
        $store->writeMsteamsPairing('empty', [], [], ['appId' => 'a', 'appPassword' => '  ', 'tenantId' => 't']);

        self::assertNull($store->msteamsCredentials('partial'), 'a missing field is a missing credential');
        self::assertNull($store->msteamsCredentials('empty'), 'a whitespace-only secret is a missing credential');
        self::assertNull($store->msteamsCredentials('nobody'));
    }

    public function testAGroupReadableFileIsRefusedWithTheChmodInstruction(): void
    {
        $store = new CredentialStore($this->path);
        $store->writeMsteamsPairing('default', [], [], ['appId' => 'a']);
        chmod($this->path, 0o640);
        clearstatcache();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('chmod 600');
        (new CredentialStore($this->path))->read();
    }

    public function testAForeignVersionNumberIsRefused(): void
    {
        file_put_contents($this->path, json_encode(['version' => 2, 'platforms' => []]));
        chmod($this->path, 0o600);
        clearstatcache();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported version');
        (new CredentialStore($this->path))->read();
    }

    public function testAPathThatIsNotARegularFileIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a regular file');
        (new CredentialStore($this->dir))->read();
    }

    public function testTheMutateGuardsRefuseHalfRecords(): void
    {
        $store = new CredentialStore($this->path);
        $thrown = null;
        try {
            $store->mutate(static fn (): string => 'nope');
        } catch (RuntimeException $error) {
            $thrown = $error->getMessage();
        }
        self::assertStringContainsString('must return the whole record', (string) $thrown);

        $thrown = null;
        try {
            $store->mutate(static fn (array $contents): array => ['version' => 9]);
        } catch (RuntimeException $error) {
            $thrown = $error->getMessage();
        }
        self::assertStringContainsString('must keep version 1', (string) $thrown);
    }

    public function testThePairingWriteReplacesTheLedgerAndKeepsForeignKeys(): void
    {
        $store = new CredentialStore($this->path);
        // Simulate P1 having already written its ledger — and the discord
        // bundle having written its rows: one store, several platforms, and
        // neither writer may shave the other.
        $store->write([
            'seenEntrypoints' => ['x' => true],
            'platforms' => ['discord' => ['accounts' => ['default' => ['token' => 'dt']]]],
        ]);
        $store->writeMsteamsPairing(
            'work',
            ['CODE1001' => ['userId' => 'p1', 'createdAt' => 1, 'expiresAt' => 999, 'lastReminderAt' => 1]],
            ['p0', 'p0', 42],
            ['appId' => 'wa', 'appPassword' => 'wp', 'tenantId' => 'wt'],
        );

        $contents = $store->read();
        self::assertSame(1, $contents['version']);
        self::assertArrayHasKey('seenEntrypoints', $contents, 'unknown top-level keys survive the pairing write');
        self::assertSame('dt', $contents['platforms']['discord']['accounts']['default']['token'], 'the sibling platform row rides on untouched');
        self::assertSame('wp', $contents['platforms']['msteams']['accounts']['work']['appPassword']);
        self::assertSame(
            ['CODE1001'],
            array_keys($contents['platforms']['msteams']['accounts']['work']['pairingPending']),
        );
        self::assertSame(['p0', '42'], $store->msteamsAllowUsers('work'), 'deduped, string-cast');

        $store->writeMsteamsPairing('work', [], ['p0']);
        self::assertSame([], (new CredentialStore($this->path))->read()['platforms']['msteams']['accounts']['work']['pairingPending']);
        self::assertSame(
            ['appId' => 'wa', 'appPassword' => 'wp', 'tenantId' => 'wt'],
            (new CredentialStore($this->path))->msteamsCredentials('work'),
            'untouched credentials ride on',
        );
        self::assertSame(['work'], (new CredentialStore($this->path))->msteamsAccounts());
    }

    public function testAccountsAreSortedAndPairingOnlyRowsStillCount(): void
    {
        $store = new CredentialStore($this->path);
        $store->writeMsteamsPairing('zeta', [], []);
        $store->writeMsteamsPairing('alpha', [], [], ['appId' => '']);
        $store->writeMsteamsPairing('mid', [], [], ['appId' => 'm', 'appPassword' => 'm', 'tenantId' => 'm']);

        self::assertSame(['alpha', 'mid', 'zeta'], $store->msteamsAccounts(), 'a row with only pairing data still counts');
        self::assertNull($store->msteamsCredentials('alpha'));
        self::assertNotNull($store->msteamsCredentials('mid'));
    }

    public function testTheEnvFallbackNamesAreConstantsForTheDefaultAccountOnly(): void
    {
        self::assertSame('MSTEAMS_APP_ID', CredentialStore::APP_ID_ENV);
        self::assertSame('MSTEAMS_APP_PASSWORD', CredentialStore::APP_PASSWORD_ENV);
        self::assertSame('MSTEAMS_TENANT_ID', CredentialStore::TENANT_ID_ENV);
    }
}

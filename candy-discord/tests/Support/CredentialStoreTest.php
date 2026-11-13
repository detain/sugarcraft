<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Support;

use CandyCode\Bundles\Discord\Support\CredentialStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The store holds bot tokens, pairing ledgers and allowlists in one 0600
 * JSON file shared (by design) with candy-code's own channels ledger. The
 * read discipline and the whole-record mutate guards are the security
 * boundary — they must refuse loudly, never quietly.
 */
final class CredentialStoreTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/p2-store-' . getmypid() . '-' . uniqid('', true);
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
        self::assertNull((new CredentialStore($this->path))->discordToken('default'));
        self::assertSame([], (new CredentialStore($this->path))->discordAccounts());
    }

    public function testAnUnsearchableParentDirectoryRefusesInsteadOfReportingFresh(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX search permissions do not exist on Windows');
        }
        if (\function_exists('posix_geteuid') && 0 === \posix_geteuid()) {
            self::markTestSkipped('root bypasses directory search permissions');
        }

        $locked = $this->dir . '/locked';
        mkdir($locked, 0o700);
        $store = new CredentialStore($locked . '/channels.json');
        chmod($locked, 0o000);
        $error = null;
        try {
            $store->read();
        } catch (RuntimeException $e) {
            $error = $e;
        } finally {
            chmod($locked, 0o700);
            @rmdir($locked);
        }

        self::assertNotNull($error, 'a locked config dir must refuse loudly, not masquerade as unconfigured');
        self::assertStringContainsString('cannot be searched', $error->getMessage());
        self::assertStringContainsString($locked, $error->getMessage());
    }

    public function testTheWriteLandsZeroSixZeroZeroAndCarriesTheShapeBack(): void
    {
        $store = new CredentialStore($this->path);
        $store->writeDiscordPairing('default', [], [], ['token' => ' bot-t ']);

        clearstatcache();
        self::assertSame(0o600, fileperms($this->path) & 0o777);

        $again = new CredentialStore($this->path);
        self::assertSame('bot-t', $again->discordToken('default'), 'surrounding spaces are trimmed on read');
        self::assertSame(['default'], $again->discordAccounts());
    }

    public function testAGroupReadableFileIsRefusedWithTheChmodInstruction(): void
    {
        $store = new CredentialStore($this->path);
        $store->writeDiscordPairing('default', [], [], ['token' => 't']);
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
        // Simulate P1 having already written its ledger: the pairing write
        // must leave that unknown key standing.
        $store->write(['seenEntrypoints' => ['x' => true], 'platforms' => []]);
        $store->writeDiscordPairing(
            'work',
            ['CODE1001' => ['userId' => 'p1', 'createdAt' => 1, 'expiresAt' => 999, 'lastReminderAt' => 1]],
            ['p0', 'p0', 42],
            ['token' => 'wt'],
        );

        $contents = $store->read();
        self::assertSame(1, $contents['version']);
        self::assertArrayHasKey('seenEntrypoints', $contents, 'unknown top-level keys survive the pairing write');
        self::assertSame('wt', $contents['platforms']['discord']['accounts']['work']['token']);
        self::assertSame(
            ['CODE1001'],
            array_keys($contents['platforms']['discord']['accounts']['work']['pairingPending']),
        );
        self::assertSame(['p0', '42'], $store->discordAllowUsers('work'), 'deduped, string-cast');

        $store->writeDiscordPairing('work', [], ['p0']);
        self::assertSame([], (new CredentialStore($this->path))->read()['platforms']['discord']['accounts']['work']['pairingPending']);
        self::assertSame('wt', (new CredentialStore($this->path))->discordToken('work'), 'untouched token rides on');
        self::assertSame(['work'], (new CredentialStore($this->path))->discordAccounts());
    }

    public function testAccountsAreSortedAndEmptyTokensReadAsNull(): void
    {
        $store = new CredentialStore($this->path);
        $store->writeDiscordPairing('zeta', [], []);
        $store->writeDiscordPairing('alpha', [], [], ['token' => '']);
        $store->writeDiscordPairing('mid', [], [], ['token' => 'm']);

        self::assertSame(['alpha', 'mid', 'zeta'], $store->discordAccounts());
        self::assertNull($store->discordToken('alpha'));
        self::assertSame('m', $store->discordToken('mid'));
        self::assertNull($store->discordToken('nobody'));
    }
}

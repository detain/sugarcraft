<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Channels;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Channels\ChannelToolPolicyService;
use SugarCraft\Code\Channels\ChannelsStore;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Code\Tests\Support\DiscardsErrorLogTrait;

/**
 * Ledger pins for the channels store (code-chat P1 step 3/8): the quarantine
 * memory is per-file and survives store instances, its bytes answer only the
 * shapes the loader's law honors — and a broken ledger NEVER degrades to a
 * silent empty one. Corruption is reported, not hidden: the policy read
 * throws loudly, the diagnostic names the file, the boot write stays
 * suppressed so the operator's bytes survive to be repaired (M-1: the
 * old degrade-to-empty pin encoded the wrong policy — ChannelToolScope's
 * written law is fail-CLOSED, and garbage read as `[]` silently unrestricted
 * every channel).
 */
final class ChannelsStoreTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use DiscardsErrorLogTrait;

    private string $root = '';
    private string $configDir = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/candy-code-store-test-' . uniqid((string) getmypid(), true);
        mkdir($this->root, 0777, true);
        $this->configDir = $this->root . '/cfg';
        mkdir($this->configDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testARecordingSurvivesIntoAFreshStoreOverTheSameDirectory(): void
    {
        $first = $this->ledger();
        $first->recordEntrypoint('/tmp/marks/never-loaded.php');
        self::assertTrue($this->ledger()->seenEntrypoint('/tmp/marks/never-loaded.php'), 'the ledger is disk truth, not instance memory');
    }

    public function testTheLedgerBytesStayExclusiveToTheOwnerAccount(): void
    {
        $this->ledger()->recordEntrypoint('/tmp/marks/private.php');
        $file = $this->configDir . '/' . ChannelsStore::FILE_NAME;
        self::assertSame(0600, fileperms($file) & 0777, 'quarantine state is security state — no group and no world bits');
    }

    public function testGarbageBytesOnTheLedgerPathFailLoudlyOnEveryPolicyRead(): void
    {
        $this->seedRawLedger('{not json at all');
        $store = $this->ledger();
        self::assertNotNull($store->readFailure(), 'the launch survives a broken record only because the failure is NAMED — constructor-tolerated, never silently empty');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(ChannelsStore::FILE_NAME);
        $store->all();
    }

    public function testACorruptLedgerIsAnnouncedOnTheOperatorLogAtLaunch(): void
    {
        $this->seedRawLedger('{not json at all');
        $store = $this->ledger();
        $captured = self::withErrorLogDiscarded(static function () use ($store): void {
            $store->announceReadFailure();
        });
        self::assertStringContainsString(ChannelsStore::FILE_NAME . ' unusable', $captured);
        self::assertStringContainsString('fails closed', $captured);
    }

    public function testAHealthyOrAbsentLedgerAnnouncesNothing(): void
    {
        $store = $this->ledger();
        $captured = self::withErrorLogDiscarded(static function () use ($store): void {
            $store->announceReadFailure();
        });
        self::assertSame('', trim($captured));
    }

    public function testALedgerThatDecodesToSomethingOtherThanAnArrayFailsLoudlyToo(): void
    {
        $this->seedRawLedger('true');
        $store = $this->ledger();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(ChannelsStore::FILE_NAME);
        $store->all();
    }

    public function testAFailedReadIsNeverWrittenBackOverTheOperatorsBytes(): void
    {
        $this->seedRawLedger('{torn on purpose');
        $store = $this->ledger();
        $store->recordEntrypoint('/tmp/marks/boot-only.php');
        self::assertTrue($store->seenEntrypoint('/tmp/marks/boot-only.php'), 'the mark lives in memory so this launch still dedups its quarantine');
        self::assertSame(
            '{torn on purpose',
            (string) file_get_contents($this->configDir . '/' . ChannelsStore::FILE_NAME),
            'a store that was never successfully read is NEVER written — corrupt bytes wait for the operator, not for a boot clobber',
        );
        self::assertFalse(
            $this->ledger()->seenEntrypoint('/tmp/marks/boot-only.php'),
            'disk truth is still the broken record: the suppressed mark never reached it',
        );
    }

    public function testTheDiagnosticNamesTheFileAndClearsWhenTheRecordIsRepaired(): void
    {
        $this->seedRawLedger('{not json at all');
        $store = $this->ledger();
        $failure = $store->readFailure();
        self::assertIsString($failure);
        self::assertStringContainsString(ChannelsStore::FILE_NAME, $failure, 'the diagnostic an operator sees must name the file');
        self::assertStringContainsString('Syntax', $failure, "the decoder's own reason rides along");
        $this->seedRawLedger(json_encode(['seenEntrypoints' => ['/tmp/marks/kept.php' => true]], JSON_THROW_ON_ERROR));
        self::assertSame(['seenEntrypoints' => ['/tmp/marks/kept.php' => true]], $store->all(), 'a repaired record lands without a restart — the fresh-read law holds');
        self::assertNull($store->readFailure(), 'the diagnostic clears with the repair');
        $store->recordEntrypoint('/tmp/marks/persisted.php');
        self::assertTrue($this->ledger()->seenEntrypoint('/tmp/marks/persisted.php'), 'once a read has succeeded, boot writes are armed again');
    }

    public function testAValidRecordWithoutAChannelsMapIsPolicySilenceNotCorruption(): void
    {
        $this->seedRawLedger(json_encode(['seenEntrypoints' => []], JSON_THROW_ON_ERROR));
        $store = $this->ledger();
        self::assertNull($store->readFailure(), 'a well-formed record that grants no policy is the day-one state — nothing is refused');
        self::assertSame(['seenEntrypoints' => []], $store->all());
    }

    public function testACorruptRecordScopesEveryChannelToRefusingAllThroughTheService(): void
    {
        $this->seedRawLedger('{not json at all');
        $service = ChannelToolPolicyService::forStore($this->ledger(), ['candy-discord']);
        $scope = $service->scopeFor('agent:main:candy-discord:group:42');
        self::assertNotNull($scope, 'a broken record must never answer "no policy" — that is the fail-OPEN shape M-1 cures');
        self::assertTrue($scope->blocksEverything(), 'the ChannelToolScope law end to end: corrupt file, refusingAll scope');
        $this->seedRawLedger(json_encode(['channels' => ['candy-discord|discord' => ['tools' => ['deny' => ['Bash']]]]], JSON_THROW_ON_ERROR));
        $healed = ChannelToolPolicyService::forStore($this->ledger(), ['candy-discord']);
        $real = $healed->scopeFor('agent:main:discord:group:42');
        self::assertNotNull($real, 'a repaired record flows the real policy again');
        self::assertFalse($real->blocksEverything(), 'refusingAll rides the corruption only — the live deny list is the scope, not the poison');
    }

    public function testAStoreWithoutAnyFileSeesNothingAndListsNothing(): void
    {
        $store = $this->ledger();
        self::assertFalse($store->seenEntrypoint('/tmp/marks/unseen.php'));
        self::assertSame([], $store->all());
    }

    public function testTheQuarantineMarkIsStrictlyBooleanTrueAndNotMerelyPresent(): void
    {
        $path = '/tmp/marks/truthy.php';
        $this->seedRawLedger(json_encode(['seenEntrypoints' => [$path => 1, '/tmp/marks/other.php' => true]], JSON_THROW_ON_ERROR));
        $store = $this->ledger();
        self::assertFalse($store->seenEntrypoint($path), 'an integer 1 is not a recorded quarantine — the mark must be true itself');
        self::assertTrue($store->seenEntrypoint('/tmp/marks/other.php'));
    }

    public function testTheWholeLedgerShapeIsTheRecordedKeyMap(): void
    {
        $store = $this->ledger();
        $store->recordEntrypoint('/tmp/marks/one.php');
        $store->recordEntrypoint('/tmp/marks/two.php');
        self::assertSame(
            ['seenEntrypoints' => ['/tmp/marks/one.php' => true, '/tmp/marks/two.php' => true]],
            $this->ledger()->all(),
        );
    }

    public function testAWriteLandingBetweenConstructionAndTheBootLedgerWriteSurvivesTheMerge(): void
    {
        // M-6: one file, two writers. The Discord plugin's CredentialStore
        // may land a pairing write at any moment between this store's
        // construction and the loader's first quarantine write — the boot
        // write must merge against THOSE bytes, never against the snapshot
        // taken in the constructor, or operator credentials vanish silently.
        $this->seedRawLedger(json_encode(['seenEntrypoints' => ['/tmp/marks/old.php' => true]], JSON_THROW_ON_ERROR));
        $store = $this->ledger();
        $this->seedRawLedger(json_encode([
            'seenEntrypoints' => ['/tmp/marks/old.php' => true],
            'platforms' => ['discord' => ['accounts' => ['main' => ['token' => 'fresh-pair']]]],
        ], JSON_THROW_ON_ERROR));
        $store->recordEntrypoint('/tmp/marks/boot.php');
        $disk = $this->ledger()->all();
        self::assertSame(
            'fresh-pair',
            $disk['platforms']['discord']['accounts']['main']['token'] ?? null,
            'a credential write landing after construction is merged around, not clobbered by, the boot ledger write',
        );
        self::assertTrue($this->ledger()->seenEntrypoint('/tmp/marks/old.php'), 'the fresh read keeps marks that are only on disk');
        self::assertTrue($this->ledger()->seenEntrypoint('/tmp/marks/boot.php'), 'the quarantine mark itself lands');
    }

    public function testTheLedgerWriteRefusesWhenTheRecordRotsAfterConstruction(): void
    {
        // M-6: the refuse-to-write door is re-checked against disk at write
        // time. Bytes that rotted AFTER a clean construction are operator
        // bytes too — the constructor's success must not license a clobber.
        $this->seedRawLedger(json_encode(['seenEntrypoints' => []], JSON_THROW_ON_ERROR));
        $store = $this->ledger();
        self::assertNull($store->readFailure(), 'construction read the good record cleanly');
        $this->seedRawLedger('{torn mid boot');
        $store->recordEntrypoint('/tmp/marks/late.php');
        self::assertTrue($store->seenEntrypoint('/tmp/marks/late.php'), 'the in-memory mark keeps this launch deduping its quarantine');
        self::assertSame(
            '{torn mid boot',
            (string) file_get_contents($this->configDir . '/' . ChannelsStore::FILE_NAME),
            'the write-time read check refuses to overwrite bytes that became unreadable after construction',
        );
        self::assertNotNull($store->readFailure(), 'the fresh failure is named on the store, not swallowed');
        self::assertFalse(
            $this->ledger()->seenEntrypoint('/tmp/marks/late.php'),
            'disk truth is still the broken record: the refused mark never reached it',
        );
    }

    private function ledger(): ChannelsStore
    {
        return ChannelsStore::new(fn (): string => $this->configDir);
    }

    private function seedRawLedger(string $bytes): void
    {
        file_put_contents($this->configDir . '/' . ChannelsStore::FILE_NAME, $bytes);
    }
}

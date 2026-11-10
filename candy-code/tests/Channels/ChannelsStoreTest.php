<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Channels;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Channels\ChannelsStore;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;

/**
 * Ledger pins for the channels store (code-chat P1 step 3/8): the quarantine
 * memory is per-file and survives store instances, its bytes answer only the
 * shapes the loader's law honors, and a broken ledger never takes the reader
 * down — it degrades to an empty ledger so the next launch quarantines again.
 */
final class ChannelsStoreTest extends TestCase
{
    use TemporaryDirectoryTrait;

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

    public function testGarbageBytesOnTheLedgerPathDegradeToAnEmptyLedger(): void
    {
        $this->seedRawLedger('{not json at all');
        $store = $this->ledger();
        self::assertSame([], $store->all(), 'a torn file reads empty rather than crashing the launch');
        self::assertFalse($store->seenEntrypoint('/tmp/marks/any.php'));
    }

    public function testALedgerThatDecodesToSomethingOtherThanAnArrayReadsEmpty(): void
    {
        $this->seedRawLedger('true');
        $store = $this->ledger();
        self::assertSame([], $store->all(), 'only array shape is a ledger — scalars are garbage wearing json');
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

    private function ledger(): ChannelsStore
    {
        return ChannelsStore::create(fn (): string => $this->configDir);
    }

    private function seedRawLedger(string $bytes): void
    {
        file_put_contents($this->configDir . '/' . ChannelsStore::FILE_NAME, $bytes);
    }
}

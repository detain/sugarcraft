<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Tests\Support\Scratch;
use SugarCraft\Plugins\Trust\JsonFileQuarantineLedger;

final class JsonFileQuarantineLedgerTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        $this->dir = Scratch::dir('ledger');
        $this->path = $this->dir . '/channels.json';
    }

    protected function tearDown(): void
    {
        Scratch::remove($this->dir);
    }

    /** @return array<string, mixed> */
    private function onDisk(): array
    {
        return json_decode((string) file_get_contents($this->path), true) ?? [];
    }

    public function testAMarkIsSeenByALaterLedgerOverTheSameFile(): void
    {
        $ledger = JsonFileQuarantineLedger::new($this->path);
        $this->assertFalse($ledger->seen('/plugin/Impl.php'));

        $ledger->record('/plugin/Impl.php');

        $this->assertTrue($ledger->seen('/plugin/Impl.php'));
        $this->assertTrue(JsonFileQuarantineLedger::new($this->path)->seen('/plugin/Impl.php'));
        $this->assertNull($ledger->readFailure());
    }

    public function testTheRecordIsWrittenOwnerOnly(): void
    {
        JsonFileQuarantineLedger::new($this->path)->record('/plugin/Impl.php');

        $this->assertSame(0600, fileperms($this->path) & 0777);
    }

    public function testAWriteMergesIntoTheFreshRecordKeepingOtherKeys(): void
    {
        file_put_contents($this->path, (string) json_encode([
            'credentials' => ['discord' => 'kept'],
            'seenEntrypoints' => ['/plugin/A.php' => true],
        ]));
        $ledger = JsonFileQuarantineLedger::new($this->path);

        $ledger->record('/plugin/B.php');

        $disk = $this->onDisk();
        $this->assertSame(['discord' => 'kept'], $disk['credentials']);
        $this->assertSame(['/plugin/A.php' => true, '/plugin/B.php' => true], $disk['seenEntrypoints']);
    }

    public function testAnUnreadableRecordIsNeverOverwritten(): void
    {
        $broken = '{not json at all';
        file_put_contents($this->path, $broken);
        $ledger = JsonFileQuarantineLedger::new($this->path);

        $this->assertNotNull($ledger->readFailure());
        $ledger->record('/plugin/Impl.php');

        $this->assertSame($broken, file_get_contents($this->path));
        $this->assertTrue($ledger->seen('/plugin/Impl.php'), 'the mark still holds for this launch');
        $this->assertNotNull($ledger->readFailure());
    }

    public function testTheKeyIsConfigurable(): void
    {
        $ledger = JsonFileQuarantineLedger::new($this->path, 'quarantine');

        $ledger->record('/plugin/Impl.php');

        $disk = $this->onDisk();
        $this->assertArrayHasKey('quarantine', $disk);
        $this->assertArrayNotHasKey('seenEntrypoints', $disk);
        $this->assertTrue(JsonFileQuarantineLedger::new($this->path, 'quarantine')->seen('/plugin/Impl.php'));
        $this->assertFalse(JsonFileQuarantineLedger::new($this->path)->seen('/plugin/Impl.php'));
    }

    public function testAMissingFileIsTheDayOneEmptyState(): void
    {
        $ledger = JsonFileQuarantineLedger::new($this->path);

        $this->assertNull($ledger->readFailure());
        $this->assertFalse($ledger->seen('/plugin/Impl.php'));
    }
}

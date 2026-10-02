<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\SiblingSpendLedger;
use SugarCraft\Crush\Support\ToolIpcFiles;
use SugarCraft\Crush\Usage;

/**
 * The spend file one concurrent tool group shares (audit B4-rem): what a
 * member records, what its siblings read back, and what the parent recovers
 * for a member that died — plus the file-lifecycle rules that keep it private
 * and keep a late writer from resurrecting it.
 */
final class SiblingSpendLedgerTest extends TestCase
{
    /** @var list<SiblingSpendLedger> */
    private array $ledgers = [];

    protected function tearDown(): void
    {
        foreach ($this->ledgers as $ledger) {
            $ledger->discard();
        }
    }

    public function testEachMemberSeesOnlyTheOthersAndTheParentSeesEachMember(): void
    {
        $ledger = $this->ledger();
        $a = $ledger->forMember('0');
        $b = $ledger->forMember('1');

        $a->record(Usage::new(100, 0.60));
        $a->record(Usage::new(50, 0.25));
        $b->record(Usage::new(10, 0.10));

        $this->assertEqualsWithDelta(0.10, $a->spentByOthers()?->costUsd ?? 0.0, 1e-9, 'a sees b only');
        $this->assertEqualsWithDelta(0.85, $b->spentByOthers()?->costUsd ?? 0.0, 1e-9, 'b sees a only');
        $this->assertSame(150, $ledger->spentBy('0')?->totalTokens, 'the crash arm\'s figure for member 0');
        $this->assertEqualsWithDelta(0.85, $ledger->spentBy('0')?->costUsd ?? 0.0, 1e-9);
        $this->assertNull($ledger->spentBy('2'), 'a member that recorded nothing reports nothing, not zero');
    }

    public function testTheBucketsSurviveTheFileByValue(): void
    {
        $ledger = $this->ledger()->forMember('0');
        $usage = Usage::new(4321, 0.1234, 1000, 2000, 30000, 400, 777, 'mystery-1');

        $ledger->record($usage);

        $this->assertEquals($usage, $this->ledgers[0]->spentBy('0'));
    }

    public function testNothingReportedWritesNothing(): void
    {
        $ledger = $this->ledger();
        $ledger->forMember('0')->record(null);

        $this->assertSame('', file_get_contents($ledger->path()));
    }

    public function testTheFileIsCreatedPrivateAndUnderTheSweptPrefix(): void
    {
        $ledger = $this->ledger();

        $this->assertFileExists($ledger->path());
        $this->assertSame(0o600, fileperms($ledger->path()) & 0o777);
        $this->assertStringStartsWith(ToolIpcFiles::RUNTIME_PREFIX, basename($ledger->path()), 'a killed group\'s file is left to the payload sweep');
    }

    public function testAMemberNeverRecreatesADiscardedLedger(): void
    {
        $ledger = $this->ledger();
        $ledger->discard();

        $ledger->forMember('0')->record(Usage::new(100, 1.0));

        $this->assertFileDoesNotExist($ledger->path(), 'a late writer must not leave a file nobody will collect');
        $this->assertNull($ledger->forMember('1')->spentByOthers());
    }

    public function testATornLineCostsOnlyItsOwnStep(): void
    {
        $ledger = $this->ledger();
        $ledger->forMember('0')->record(Usage::new(100, 1.0));
        // The shape a SIGKILL mid-write leaves: half a line, no newline.
        file_put_contents($ledger->path(), '{"m":"0","u":{"totalTok', FILE_APPEND);
        $ledger->forMember('0')->record(Usage::new(10, 0.25));

        $this->assertEqualsWithDelta(1.25, $ledger->spentBy('0')?->costUsd ?? 0.0, 1e-9, 'the record after the torn one still counts');
    }

    private function ledger(): SiblingSpendLedger
    {
        $ledger = SiblingSpendLedger::create();
        $this->assertNotNull($ledger);
        $this->ledgers[] = $ledger;

        return $ledger;
    }
}

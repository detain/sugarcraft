<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\BuiltIn\ApplyPatch;
use SugarCraft\Code\Tools\BuiltIn\Edit;
use SugarCraft\Code\Tools\BuiltIn\Read;
use SugarCraft\Code\Tools\BuiltIn\Write;
use SugarCraft\Code\Tools\ReadLedger;
use SugarCraft\Code\Tools\ToolLimits;

/**
 * Roadmap C-TOOL-5 (GAP-EDT-02): the opt-in read-before-write rule. With
 * `requireReadBeforeWrite` on, Edit, a Write that overwrites, and every
 * ApplyPatch section that touches an EXISTING file refuse a file the session
 * never read -- "Read it first" -- before anything else is tried. Creating
 * new files is never blocked, and off (the shipped default) leaves every
 * byte of today's behaviour exactly as it was.
 */
final class ReadBeforeWriteTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-read-before-write-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        $this->dir = (string) realpath($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    // ── the refusals ────────────────────────────────────────────────────

    public function testEditRefusesAnExistingFileTheSessionNeverRead(): void
    {
        $path = $this->placed('a.txt', "alpha\n");
        $edit = $this->enforcing(new Edit($this->dir, readLedger: ReadLedger::new()));

        $refused = $edit->execute(['file_path' => 'a.txt', 'old_string' => 'alpha', 'new_string' => 'ALPHA']);

        $this->assertTrue($refused->isError());
        $this->assertStringContainsString('has not been read in this session', $refused->content());
        $this->assertStringContainsString('Read it first', $refused->content());
        $this->assertSame('alpha' . "\n", file_get_contents($path), 'refused before the bytes are even opened');
    }

    public function testWriteRefusesAnOverwriteOfAnUnreadFileButNeverACreate(): void
    {
        $path = $this->placed('a.txt', "alpha\n");
        $write = $this->enforcing(new Write($this->dir, readLedger: ReadLedger::new()));

        $refused = $write->execute(['file_path' => 'a.txt', 'content' => "mine\n", 'overwrite' => true]);
        $this->assertTrue($refused->isError());
        $this->assertStringContainsString('Read it first', $refused->content());
        $this->assertSame("alpha\n", file_get_contents($path));

        $this->assertFalse($write->execute(['file_path' => 'new.txt', 'content' => "fresh\n"])->isError(), 'a new file has nothing to have read');
        $this->assertFalse($write->execute(['file_path' => 'new.txt', 'content' => "again\n", 'overwrite' => true])->isError(), 'its own write is a read');
    }

    public function testApplyPatchRefusesUpdateMoveAndDeleteSourcesTheSessionNeverRead(): void
    {
        $this->placed('u.txt', "one\n");
        $this->placed('m.txt', "keep\nchange\n");
        $this->placed('d.txt', "gone\n");
        $patch = $this->enforcing(new ApplyPatch($this->dir, readLedger: ReadLedger::new()));

        foreach ([
            '*** Begin Patch' . "\n*** Update File: u.txt\n-one\n+ONE\n*** End Patch",
            '*** Begin Patch' . "\n*** Update File: m.txt\n*** Move to: m2.txt\n-change\n+CHANGED\n*** End Patch",
            '*** Begin Patch' . "\n*** Delete File: d.txt\n*** End Patch",
        ] as $case) {
            $refused = $patch->execute(['patch' => $case]);
            $this->assertTrue($refused->isError(), 'refused: ' . $case);
            $this->assertStringContainsString('has not been read in this session', $refused->content());
            $this->assertStringContainsString('Read it first', $refused->content());
            $this->assertStringContainsString('no file changed', $refused->content());
        }
        $this->assertSame("one\n", $this->bytesOf('u.txt'));
        $this->assertFileDoesNotExist($this->dir . '/m2.txt');
        $this->assertFileExists($this->dir . '/d.txt');
    }

    public function testApplyPatchStillAddsNewFilesWhileEnforcing(): void
    {
        $patch = $this->enforcing(new ApplyPatch($this->dir, readLedger: ReadLedger::new()));

        $result = $patch->execute(['patch' => "*** Begin Patch\n*** Add File: brand-new.txt\n+hello\n*** End Patch"]);

        $this->assertFalse($result->isError());
        $this->assertSame("hello\n", $this->bytesOf('brand-new.txt'));
    }

    // ── a Read lifts every one of them ──────────────────────────────────

    public function testAReadOfTheFileLiftsTheRefusalForAllThreeTools(): void
    {
        $this->placed('a.txt', "alpha\nbeta\ngamma\n");
        $ledger = ReadLedger::new();
        (new Read($this->dir, readLedger: $ledger))->execute(['file_path' => 'a.txt']);
        $this->assertTrue($ledger->has($this->dir . '/a.txt'));

        $this->assertFalse($this->enforcing(new Edit($this->dir, readLedger: $ledger))
            ->execute(['file_path' => 'a.txt', 'old_string' => 'alpha', 'new_string' => 'ALPHA'])->isError());
        file_put_contents($this->dir . '/b.txt', "one\n");
        (new Read($this->dir, readLedger: $ledger))->execute(['file_path' => 'b.txt']);
        $this->assertFalse($this->enforcing(new Write($this->dir, readLedger: $ledger))
            ->execute(['file_path' => 'b.txt', 'content' => "ONE\n", 'overwrite' => true])->isError());
        (new Read($this->dir, readLedger: $ledger))->execute(['file_path' => 'a.txt']);
        $this->assertFalse($this->enforcing(new ApplyPatch($this->dir, readLedger: $ledger))
            ->execute(['patch' => "*** Begin Patch\n*** Delete File: a.txt\n*** End Patch"])->isError());
    }

    public function testTheForkedReadsLedgerArrivalLiftsTheParentsRefusal(): void
    {
        $path = $this->placed('a.txt', "alpha\n");
        // Exactly how a parallel Read child's marks come home (roadmap 3.I-2):
        // the scalar envelope through serialize, merged into the parent ledger.
        $child = new Read($this->dir, readLedger: ReadLedger::new());
        $child->execute(['file_path' => 'a.txt']);
        $parent = ReadLedger::new();
        (new Read($this->dir, readLedger: $parent))->mergeSessionState(
            unserialize(serialize($child->exportSessionState()), ['allowed_classes' => false]),
        );

        $result = $this->enforcing(new Edit($this->dir, readLedger: $parent))
            ->execute(['file_path' => 'a.txt', 'old_string' => 'alpha', 'new_string' => 'ALPHA']);

        $this->assertFalse($result->isError(), 'the rule asks only whether the session saw the file, and the forked Read saw it');
        $this->assertStringNotContainsString('Read it first', $result->content());
    }

    // ── off is today, byte for byte ─────────────────────────────────────

    public function testTheDefaultInstancesBehaveAndReadExactlyAsBefore(): void
    {
        $this->placed('a.txt', "alpha\n");
        $plain = new Edit($this->dir, readLedger: ReadLedger::new());

        $this->assertFalse($plain->execute(['file_path' => 'a.txt', 'old_string' => 'alpha', 'new_string' => 'beta'])->isError(), 'unread edits ride on with the switch off');
        $this->assertStringNotContainsString('never read', $plain->description());
        $this->assertSame($plain->description(), $plain->withRequireReadBeforeWrite(false)->description(), 'an explicit off rebind says nothing new either');

        $unread = $this->placed('u.txt', "one\n");
        $this->assertFalse((new ApplyPatch($this->dir, readLedger: ReadLedger::new()))
            ->execute(['patch' => "*** Begin Patch\n*** Update File: u.txt\n-one\n+ONE\n*** End Patch"])->isError());
        $this->assertSame("ONE\n", file_get_contents($unread));
    }

    public function testOnlyAnInstanceThatEnforcesClaimsTheRuleInItsDescription(): void
    {
        // Ledger and flag are both doors: a flag with no ledger to consult
        // cannot refuse, so it must not claim the rule to the model.
        $this->assertStringNotContainsString('never read', (new Edit())->description());
        $this->assertStringNotContainsString('never read', (new Write())->description());
        $this->assertStringNotContainsString('never read', (new ApplyPatch())->description());

        $on = $this->enforcing(new Edit($this->dir, readLedger: ReadLedger::new()));
        $this->assertStringContainsString('Read it first', $on->description());
        $this->assertStringContainsString('Read it first', $this->enforcing(new Write($this->dir, readLedger: ReadLedger::new()))->description());
        $this->assertStringContainsString('Read it first', $this->enforcing(new ApplyPatch($this->dir, readLedger: ReadLedger::new()))->description());

        $flaglessLedger = new Edit($this->dir, readLedger: ReadLedger::new());
        $this->assertStringNotContainsString('Read it first', $flaglessLedger->description());
        $this->assertStringNotContainsString('Read it first', (new Edit($this->dir, requireReadBeforeWrite: true))->description(), 'without a ledger nothing is enforced, so nothing is claimed');
    }

    // ── the setting ─────────────────────────────────────────────────────

    public function testTheSwitchRidesTheTurnTimeRebind(): void
    {
        $limits = ToolLimits::fromConfig([ToolLimits::REQUIRE_READ_BEFORE_WRITE_KEY => true]);
        foreach ([new Edit($this->dir, readLedger: ReadLedger::new()), new Write($this->dir, readLedger: ReadLedger::new()), new ApplyPatch($this->dir, readLedger: ReadLedger::new())] as $tool) {
            $bound = $limits->applyTo($tool);
            $this->assertStringContainsString('Read it first', $bound->description(), $tool->name());
        }

        $this->assertSame((new Edit($this->dir, readLedger: ReadLedger::new()))->description(), ToolLimits::fromConfig([])->applyTo(new Edit($this->dir, readLedger: ReadLedger::new()))->description(), 'unset leaves the tool as it was built');
        $untouched = new Edit($this->dir);
        $this->assertSame($untouched, ToolLimits::fromConfig([])->applyTo($untouched), 'unset rebinds nothing at all');
    }

    public function testOnlyARealBoolIsHonoured(): void
    {
        foreach (['true', '1', 1, 0, null] as $spelling) {
            $this->assertNull(ToolLimits::fromConfig([ToolLimits::REQUIRE_READ_BEFORE_WRITE_KEY => $spelling])->bool(ToolLimits::REQUIRE_READ_BEFORE_WRITE_KEY), var_export($spelling, true) . ' is not a bool');
        }
        $this->assertTrue(ToolLimits::fromConfig([ToolLimits::REQUIRE_READ_BEFORE_WRITE_KEY => true])->bool(ToolLimits::REQUIRE_READ_BEFORE_WRITE_KEY));
        $this->assertFalse(ToolLimits::fromConfig([ToolLimits::REQUIRE_READ_BEFORE_WRITE_KEY => false])->bool(ToolLimits::REQUIRE_READ_BEFORE_WRITE_KEY));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function enforcing(Edit|Write|ApplyPatch $tool): Edit|Write|ApplyPatch
    {
        return $tool->withRequireReadBeforeWrite(true);
    }

    private function placed(string $name, string $bytes): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $bytes);

        return $path;
    }

    private function bytesOf(string $name): string
    {
        return (string) file_get_contents($this->dir . '/' . $name);
    }
}

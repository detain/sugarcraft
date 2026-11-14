<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Files\UndoAction;
use SugarCraft\Core\Undo\UndoActionType;
use PHPUnit\Framework\TestCase;

final class UndoActionTest extends TestCase
{
    /**
     * CF-41 (audit 053): every delete fixture here used to carry the
     * pre-trash schema {path,isDir,content,stat} — keys production has not
     * written since the CF-04 trash move made undo a byte-rename, not a
     * content replay. The record is a LOCATION ({'trash'} = where the
     * bytes live now, '' = permanently gone), so that is what these
     * fixtures, and the engine round-trip at the bottom, pin.
     */
    public function testDeleteCreatesDeleteAction(): void
    {
        $items = [
            ['path' => '/tmp/test.txt', 'isDir' => false, 'trash' => '/tmp/trash/test.txt'],
        ];
        $action = UndoAction::delete($items);

        $this->assertSame('items deleted: 1', $action->description);
        $this->assertSame($items, $action->items);
        $this->assertSame(UndoActionType::Delete, $action->type);
    }

    public function testDeleteMultipleItems(): void
    {
        $items = [
            ['path' => '/tmp/a.txt', 'isDir' => false, 'trash' => '/tmp/trash/a.txt'],
            ['path' => '/tmp/b.txt', 'isDir' => false, 'trash' => '/tmp/trash/b.txt'],
            ['path' => '/tmp/c.txt', 'isDir' => false, 'trash' => ''],   // EXDEV-permanent leg
        ];
        $action = UndoAction::delete($items);

        $this->assertSame('items deleted: 3', $action->description);
        $this->assertCount(3, $action->items);
        $this->assertSame(UndoActionType::Delete, $action->type);
    }

    public function testDeleteWithDirectories(): void
    {
        $items = [
            ['path' => '/tmp/mydir', 'isDir' => true, 'trash' => '/tmp/trash/mydir'],
        ];
        $action = UndoAction::delete($items);

        $this->assertSame('items deleted: 1', $action->description);
        $this->assertTrue($action->items[0]['isDir']);
        $this->assertSame(UndoActionType::Delete, $action->type);
    }

    public function testRenameCreatesRenameAction(): void
    {
        $renames = [
            '/tmp/old.txt' => '/tmp/new.txt',
        ];
        $action = UndoAction::rename($renames);

        $this->assertSame('items renamed: 1', $action->description);
        $this->assertSame($renames, $action->items);
        $this->assertSame(UndoActionType::Rename, $action->type);
    }

    public function testRenameMultipleItems(): void
    {
        $renames = [
            '/tmp/a.txt' => '/tmp/b.txt',
            '/tmp/c.txt' => '/tmp/d.txt',
        ];
        $action = UndoAction::rename($renames);

        $this->assertSame('items renamed: 2', $action->description);
        $this->assertCount(2, $action->items);
        $this->assertSame(UndoActionType::Rename, $action->type);
    }

    public function testMoveCreatesMoveAction(): void
    {
        // CF-40 (audit 064): the record is the compact leg tuple, not a
        // pair map — the map is a re-derived on-demand view.
        $action = UndoAction::move('/tmp', '/home', ['file.txt']);

        $this->assertSame('items moved: 1', $action->description);
        $this->assertSame(
            ['srcDir' => '/tmp', 'dstDir' => '/home', 'names' => ['file.txt'], 'dstNames' => null],
            $action->items,
        );
        $this->assertSame(['/tmp/file.txt' => '/home/file.txt'], $action->absolutePairs());
        $this->assertSame(UndoActionType::Move, $action->type);
    }

    public function testMoveMultipleItems(): void
    {
        $action = UndoAction::move('/tmp', '/home', ['a.txt', 'b.txt']);

        $this->assertSame('items moved: 2', $action->description);
        $this->assertSame(
            ['/tmp/a.txt' => '/home/a.txt', '/tmp/b.txt' => '/home/b.txt'],
            $action->absolutePairs(),
            're-derivation is byte-identical and schedule-order preserving'
        );
        $this->assertSame(UndoActionType::Move, $action->type);
    }

    public function testCopyCreatesCopyAction(): void
    {
        $action = UndoAction::copy('/tmp/source', '/tmp/dest', ['source.txt']);

        $this->assertSame('items copied: 1', $action->description);
        $this->assertSame(
            ['srcDir' => '/tmp/source', 'dstDir' => '/tmp/dest', 'names' => ['source.txt'], 'dstNames' => null],
            $action->items,
        );
        $this->assertSame(['/tmp/source/source.txt' => '/tmp/dest/source.txt'], $action->absolutePairs());
        $this->assertSame(UndoActionType::Copy, $action->type);
    }

    public function testCopyMultipleItems(): void
    {
        $action = UndoAction::copy('/tmp', '/tmp', ['a.txt', 'b.txt'], ['a_copy.txt', 'b_copy.txt']);

        $this->assertSame('items copied: 2', $action->description);
        $this->assertSame(
            ['/tmp/a.txt' => '/tmp/a_copy.txt', '/tmp/b.txt' => '/tmp/b_copy.txt'],
            $action->absolutePairs(),
            'the extract circuit lands under flat names — dstNames carries the split spelling'
        );
        $this->assertSame(UndoActionType::Copy, $action->type);
    }

    public function testEmptyDeleteAction(): void
    {
        $action = UndoAction::delete([]);

        $this->assertSame('items deleted: 0', $action->description);
        $this->assertSame([], $action->items);
        $this->assertSame(UndoActionType::Delete, $action->type);
    }

    public function testEmptyRenameAction(): void
    {
        $action = UndoAction::rename([]);

        $this->assertSame('items renamed: 0', $action->description);
        $this->assertSame([], $action->items);
        $this->assertSame(UndoActionType::Rename, $action->type);
    }

    public function testEmptyMoveAction(): void
    {
        $action = UndoAction::move('/x', '/y', []);

        $this->assertSame('items moved: 0', $action->description);
        $this->assertSame(['srcDir' => '/x', 'dstDir' => '/y', 'names' => [], 'dstNames' => null], $action->items);
        $this->assertSame([], $action->absolutePairs());
        $this->assertSame(UndoActionType::Move, $action->type);
    }

    public function testEmptyCopyAction(): void
    {
        $action = UndoAction::copy('/x', '/y', []);

        $this->assertSame('items copied: 0', $action->description);
        $this->assertSame(['srcDir' => '/x', 'dstDir' => '/y', 'names' => [], 'dstNames' => null], $action->items);
        $this->assertSame(UndoActionType::Copy, $action->type);
    }

    public function testDescriptionIsReadonly(): void
    {
        $action = UndoAction::delete([['path' => '/tmp/x', 'isDir' => false, 'trash' => '/tmp/trash/x']]);

        $this->assertSame('items deleted: 1', $action->description);
        $this->assertSame('items deleted: 1', $action->description); // Ensure it's consistent
        $this->assertSame(UndoActionType::Delete, $action->type);
    }

    public function testItemsPreservesOriginalStructure(): void
    {
        $originalItems = [
            ['path' => '/tmp/test.txt', 'isDir' => false, 'trash' => '/tmp/trash/test.txt'],
        ];
        $action = UndoAction::delete($originalItems);

        $this->assertSame($originalItems, $action->items);
        $this->assertSame('/tmp/test.txt', $action->items[0]['path']);
        $this->assertSame('/tmp/trash/test.txt', $action->items[0]['trash']);
    }

    /**
     * CF-41 (audit 053): the fixtures above describe the record; this one
     * PROVES production writes it. Delete a real file through the real
     * dispatch (j, d, y) and read the action back off the stack: the keys
     * must be exactly the live {path,isDir,trash} schema — the record's
     * trash leg must point at bytes that actually exist — and undo must
     * resurrect them by LOCATION rename, not by replaying stored content
     * (a stored content string would be the dead schema sneaking back).
     */
    public function testProductionDeleteWritesTheLiveSchemaAndRestoresByLocation(): void
    {
        $dir = sys_get_temp_dir() . '/sugarcraft-uaschema-' . uniqid('', true);
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/victim.txt', 'LOCATION-RESTORED');

        $m = \SugarCraft\Files\Manager::start($dir, $dir, \SugarCraft\Files\FsLister::lister());
        [$m] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'j'));
        [$armed] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'd'));
        [$deleted] = $armed->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'y'));

        $this->assertCount(1, $deleted->undoStack);
        $record = $deleted->undoStack[0];
        $this->assertSame(UndoActionType::Delete, $record->type);
        $this->assertSame(['path', 'isDir', 'trash'], array_keys($record->items[0]), 'the production schema is path/isDir/trash — nothing else');
        $this->assertSame($dir . '/victim.txt', $record->items[0]['path']);
        $this->assertFalse($record->items[0]['isDir']);
        $trash = $record->items[0]['trash'];
        // No conditional escape (the pattern CF-41/055 just executed): a
        // same-device tmp root trash-renames, always — ManagerDeleteTest's
        // CF-04 guard pins that premise from the other side.
        $this->assertNotSame('', $trash, 'a temp-dir delete trashes; an empty location here means the trash engine regressed');
        $this->assertFileExists($trash, 'the record names where the bytes actually live');
        $this->assertStringContainsString('LOCATION-RESTORED', (string) file_get_contents($trash));

        [$undone] = $deleted->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'u'));
        $this->assertFileExists($dir . '/victim.txt');
        $this->assertSame('LOCATION-RESTORED', file_get_contents($dir . '/victim.txt'));
        $this->assertStringContainsString('undone', $undone->status);

        // Sweep the trash root this test created (own-PID prefix only, CF-41/074 law).
        foreach (glob(sys_get_temp_dir() . '/candyfiles-trash-' . getmypid() . '-*') ?: [] as $root) {
            foreach (@scandir($root) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    @unlink($root . '/' . $name);
                }
            }
            @rmdir($root);
        }
        @unlink($dir . '/victim.txt');
        @rmdir($dir);
    }

    /**
     * CF-40 (audit 064): trimTo is the weight cap's eviction primitive.
     * A trimmed record must (a) keep the NEWEST legs, (b) carry a
     * description whose count matches what a reversal will really do,
     * with the shortfall disclosed, and (c) never mutate the applied
     * count the disclosure speaks of. Non-compressible records pass
     * through untouched.
     */
    public function testTrimToKeepsNewestLegsWithHonestDescription(): void
    {
        $names = [];
        for ($i = 0; $i < 10; $i++) {
            $names[] = 'leg' . $i . '.txt';
        }
        $action = UndoAction::move('/src', '/dst', $names);

        $trimmed = $action->trimTo(4);

        $this->assertSame(['leg6.txt', 'leg7.txt', 'leg8.txt', 'leg9.txt'], $trimmed->items['names']);
        $this->assertSame(
            'items moved: 4 — undo covers the newest 4 of 10 legs',
            $trimmed->description,
        );
        $this->assertSame(10, $trimmed->appliedLegs, 'the applied count is immutable under trim');
        $this->assertSame(4, $trimmed->legCount());
        $this->assertSame('/src/leg9.txt', array_key_last($trimmed->absolutePairs()));

        // No-ops on the wrong side of every edge.
        $this->assertSame($action, $action->trimTo(10));
        $this->assertSame($action, $action->trimTo(11));
        $rename = UndoAction::rename(['/a' => '/b']);
        $this->assertSame($rename, $rename->trimTo(1));
    }

    /**
     * CF-40: the extract circuit's copy record carries a per-leg dstNames
     * list (staged rel-name -> flat dst name). A trim that sliced only
     * `names` would leave the lists misaligned and reverseMove would
     * replay wrong pairs — the two lists must evict in lockstep.
     */
    public function testTrimToKeepsSourceAndDestinationLegsAligned(): void
    {
        $action = UndoAction::copy(
            '/staging',
            '/dst',
            ['sub/leg0.txt', 'sub/leg1.txt', 'sub/leg2.txt', 'sub/leg3.txt'],
            ['leg0.txt', 'leg1.txt', 'leg2.txt', 'leg3.txt'],
        );

        $trimmed = $action->trimTo(2);

        $this->assertSame(['sub/leg2.txt', 'sub/leg3.txt'], $trimmed->items['names']);
        $this->assertSame(['leg2.txt', 'leg3.txt'], $trimmed->items['dstNames']);
        $this->assertSame(
            ['/staging/sub/leg2.txt' => '/dst/leg2.txt', '/staging/sub/leg3.txt' => '/dst/leg3.txt'],
            $trimmed->absolutePairs(),
            'each surviving leg keeps ITS OWN destination name'
        );
    }

    /**
     * CF-40: enforceLegCap walks undo-then-redo oldest action first,
     * evicts oldest LEGS within each compressible record, floors at one
     * leg per action, never touches delete/insert/rename territory, and
     * leaves a cap-honest stack completely alone.
     */
    public function testEnforceLegCapEvictsOldestLegsOfOldestCompressibleAction(): void
    {
        $many = static fn(int $n): array => array_map(static fn(int $i): string => 'n' . $i, range(0, $n - 1));
        $undo = [
            UndoAction::move('/a', '/b', $many(6)),
            UndoAction::move('/c', '/d', $many(3)),
        ];
        $redo = [UndoAction::delete([
            ['path' => '/p', 'isDir' => false, 'trash' => '/t/p'],
        ]), UndoAction::move('/e', '/f', $many(3))];

        [$undo, $redo] = UndoAction::enforceLegCap($undo, $redo, 9);

        // Total was 13 (6+3 in undo, 1+3 in redo), cap 9 → deficit 4:
        // the oldest action sheds its 4 oldest legs; nothing else moves.
        $this->assertSame(2, $undo[0]->legCount(), '6 dropped by 4 keeps the newest 2');
        $this->assertSame(3, $undo[1]->legCount());
        $this->assertSame(1, $redo[0]->legCount(), 'the delete locator is untouchable');
        $this->assertSame(3, $redo[1]->legCount());
        $this->assertStringContainsString('newest 2 of 6', $undo[0]->description);

        // Within-cap stacks are returned untouched (identity per action).
        [$u2, $r2] = UndoAction::enforceLegCap($undo, $redo, 1000);
        $this->assertSame($undo, $u2);
        $this->assertSame($redo, $r2);

        // The one-leg floor is observable: cap 2 against 9 legs spread over
        // two records must leave EXACTLY one leg in each (oldest-first walk,
        // never emptied), not shave the deficit off a single victim.
        [$u3, $r3] = UndoAction::enforceLegCap(
            [UndoAction::move('/a', '/b', $many(6))],
            [UndoAction::move('/e', '/f', $many(3))],
            2,
        );
        $this->assertSame(1, $u3[0]->legCount(), 'oldest compressible floors at one leg');
        $this->assertSame(1, $r3[0]->legCount(), 'redo-side record floors at one leg too');

        $this->expectException(\InvalidArgumentException::class);
        UndoAction::enforceLegCap([], [], 0);
    }

    /**
     * CF-40 acceptance measure: the compact record must be dramatically
     * lighter than the pair map it replaced. A 5k-leg synthetic action
     * under the pair-map baseline is the finding's own unit of worst
     * case; 4x margin is asserted well above allocator jitter.
     */
    public function testCompactRecordIsFarLighterThanThePairMapBaseline(): void
    {
        $srcDir = '/very/long/source/root/that/winds/on/for/many/cells';
        $dstDir = '/a/destination/root/that/is/also/quite/long/deep/down';
        $names = [];
        for ($i = 0; $i < 5000; $i++) {
            $names[] = 'file-' . $i . '-with-a-reasonably-long-base-name.tsv';
        }

        gc_collect_cycles();
        $m0 = memory_get_usage();
        $map = [];
        foreach ($names as $n) {
            $map[$srcDir . '/' . $n] = $dstDir . '/' . $n;
        }
        $m1 = memory_get_usage();
        unset($map);
        gc_collect_cycles();
        $m2 = memory_get_usage();
        $record = UndoAction::move($srcDir, $dstDir, $names);
        $m3 = memory_get_usage();

        $this->assertGreaterThan(400_000, $m1 - $m0, 'sanity: the pair baseline really does cost real bytes here');
        $this->assertLessThan(
            (int) (($m1 - $m0) * 0.25),
            $m3 - $m2,
            'the compact leg record must be a fraction of the pair map it replaces'
        );
        $this->assertSame(5000, $record->legCount());
    }

    /**
     * CF-40 through the live circuit: a real three-file move driven
     * through real dispatch, undone, and redone — byte-identically on
     * disk at every transition — while the stack-resident record stays
     * the compact leg tuple (no absolute pair survives on the stack;
     * the reversers re-derive their pairs on demand).
     */
    public function testLiveMoveUndoRedoRoundTripsThroughTheCompactRecord(): void
    {
        $base = sys_get_temp_dir() . '/sugarcraft-cf40-' . uniqid('', true);
        $left = $base . '/left';
        $right = $base . '/right';
        mkdir($left, 0755, true);
        mkdir($right, 0755, true);
        foreach (['a.txt', 'b.txt', 'c.txt'] as $n) {
            file_put_contents($left . '/' . $n, 'BYTES:' . $n);
        }

        $key = static fn(string $c): \SugarCraft\Core\Msg\KeyMsg => new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, $c);
        $m = \SugarCraft\Files\Manager::start($left, $right, \SugarCraft\Files\FsLister::lister());
        // Walk onto the first file, then select all three entries.
        [$m] = $m->update($key('j'));
        foreach (['v', 'j', 'v', 'j', 'v'] as $k) {
            [$m] = $m->update($key($k));
        }
        $this->assertCount(3, $m->activePane()->selected);

        [$armed] = $m->update($key('m'));
        [$moved] = $armed->update($key('y'));
        foreach (['a.txt', 'b.txt', 'c.txt'] as $n) {
            $this->assertFileExists($right . '/' . $n);
            $this->assertFileDoesNotExist($left . '/' . $n);
        }

        $record = $moved->undoStack[count($moved->undoStack) - 1];
        $this->assertSame(UndoActionType::Move, $record->type);
        $this->assertSame(['srcDir', 'dstDir', 'names', 'dstNames'], array_keys($record->items), 'the stack-resident record is the compact tuple — no pair map survives here');
        $this->assertSame(['a.txt', 'b.txt', 'c.txt'], $record->items['names']);

        [$undone] = $moved->update($key('u'));
        foreach (['a.txt', 'b.txt', 'c.txt'] as $n) {
            $this->assertSame('BYTES:' . $n, file_get_contents($left . '/' . $n), "undo restored $n byte-identically");
            $this->assertFileDoesNotExist($right . '/' . $n);
        }

        // Ctrl+Y through the real parser — the ManagerMoveTest idiom.
        $ctrlY = null;
        foreach ((new \SugarCraft\Core\InputReader())->parse("\x19") as $msg) {
            if ($msg instanceof \SugarCraft\Core\Msg\KeyMsg) {
                $ctrlY = $msg;
                break;
            }
        }
        $this->assertNotNull($ctrlY, 'the real parser yields a KeyMsg for Ctrl+Y');
        [$redone] = $undone->update($ctrlY);
        foreach (['a.txt', 'b.txt', 'c.txt'] as $n) {
            $this->assertSame('BYTES:' . $n, file_get_contents($right . '/' . $n), 'redo replays the FORWARD move byte-identically');
            $this->assertFileDoesNotExist($left . '/' . $n);
        }

        foreach (['a.txt', 'b.txt', 'c.txt'] as $n) {
            @unlink($right . '/' . $n);
        }
        @rmdir($right);
        @rmdir($left);
        @rmdir($base);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Edit;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\BuiltIn\ApplyPatch;
use SugarCraft\Code\Tools\BuiltIn\Edit;
use SugarCraft\Code\Tools\BuiltIn\Read;
use SugarCraft\Code\Tools\BuiltIn\Write;
use SugarCraft\Code\Tools\Edit\IndentShift;
use SugarCraft\Code\Tools\ReadLedger;

/**
 * Roadmap C-TOOL-5 (GAP-EDT-10): the UTF-8 byte-order mark is on-disk bytes
 * like any other -- an editor that drops it silently re-signs the file (Excel
 * CSV, sniffer tooling, and the model's own byte contract from Read, whose
 * first line carries the mark). Matching therefore sees the body WITHOUT the
 * mark, and every write path puts the file's own mark back exactly once: a
 * BOM survives Edit, an overwriting Write and ApplyPatch -- and a file that
 * never had one never gains it from a tool.
 */
final class BomPreservationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-bom-' . bin2hex(random_bytes(6));
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

    // ── Edit ────────────────────────────────────────────────────────────

    public function testEditKeepsTheMarkAndMatchesTheBodyWithoutIt(): void
    {
        $path = $this->placed('bom.txt', IndentShift::BOM . "one\ntwo\n");

        $result = (new Edit($this->dir))->execute(['file_path' => 'bom.txt', 'old_string' => 'two', 'new_string' => 'TWO']);

        $this->assertFalse($result->isError(), 'an inner-line edit needs no mark bytes in old_string');
        $this->assertSame(IndentShift::BOM . "one\nTWO\n", file_get_contents($path), 'exactly one mark, first, then the body');
    }

    public function testAnEditOfTheFirstLineKeepsExactlyOneMark(): void
    {
        $path = $this->placed('bom.txt', IndentShift::BOM . "one\ntwo\n");

        $result = (new Edit($this->dir))->execute(['file_path' => 'bom.txt', 'old_string' => 'one', 'new_string' => 'ONE']);

        $this->assertFalse($result->isError());
        $this->assertSame(IndentShift::BOM . "ONE\ntwo\n", file_get_contents($path));
        $this->assertSame(1, substr_count((string) file_get_contents($path), IndentShift::BOM), 'the first line never ends up double-marked');
    }

    public function testAModelThatEchoedTheMarkStillMatches(): void
    {
        $path = $this->placed('bom.txt', IndentShift::BOM . "one\ntwo\n");

        // Read shows the mark as part of line one; a model that faithfully
        // copies what it saw must not be punished for it.
        $result = (new Edit($this->dir))->execute(['file_path' => 'bom.txt', 'old_string' => IndentShift::BOM . 'one', 'new_string' => 'ONE']);

        $this->assertFalse($result->isError());
        $this->assertSame(IndentShift::BOM . "ONE\ntwo\n", file_get_contents($path));
    }

    public function testALooseMatchedEditKeepsTheMark(): void
    {
        $path = $this->placed('bom.php', IndentShift::BOM . "if (\$a) {\n        call();\n        done();\n}\n");

        // Different indentation than on disk: the fuzzy chain runs, and its
        // offset is into the mark-free body the caller handed match().
        $result = (new Edit($this->dir))->execute(['file_path' => 'bom.php', 'old_string' => "    call();\n    done();", 'new_string' => "    call();\n    finish();"]);

        $this->assertFalse($result->isError());
        $this->assertStringStartsWith(IndentShift::BOM, (string) file_get_contents($path), 'a re-indented body still starts with the mark');
        $this->assertStringContainsString('finish()', (string) file_get_contents($path));
    }

    public function testTheWriterRecordsTheFullBytesSoTheNextEditStands(): void
    {
        $this->placed('bom.txt', IndentShift::BOM . "alpha\n");
        $ledger = ReadLedger::new();
        (new Read($this->dir, readLedger: $ledger))->execute(['file_path' => 'bom.txt']);
        $edit = new Edit($this->dir, readLedger: $ledger);

        $this->assertFalse($edit->execute(['file_path' => 'bom.txt', 'old_string' => 'alpha', 'new_string' => 'ALPHA'])->isError());
        $this->assertFalse($edit->execute(['file_path' => 'bom.txt', 'old_string' => 'ALPHA', 'new_string' => 'Alpha'])->isError(), 'a marked file edited from its own Read must not go stale on its mark');
        $this->assertSame([], $ledger->changedSinceRead());
    }

    // ── Write ───────────────────────────────────────────────────────────

    public function testWriteOverwritingAMarkedFileKeepsItsMark(): void
    {
        $path = $this->placed('bom.txt', IndentShift::BOM . "old\n");

        $result = (new Write($this->dir))->execute(['file_path' => 'bom.txt', 'content' => "new\n", 'overwrite' => true]);

        $this->assertFalse($result->isError());
        $this->assertSame(IndentShift::BOM . "new\n", file_get_contents($path));
    }

    public function testWriteContentCarryingItsOwnMarkIsNotDoubledAndANewFileNeverGainsOne(): void
    {
        $path = $this->placed('bom.txt', IndentShift::BOM . "old\n");
        $result = (new Write($this->dir))->execute(['file_path' => 'bom.txt', 'content' => IndentShift::BOM . "new\n", 'overwrite' => true]);
        $this->assertFalse($result->isError());
        $this->assertSame(IndentShift::BOM . "new\n", file_get_contents($path), 'the mark the content carries is the mark');
        $this->assertSame(1, substr_count((string) file_get_contents($path), IndentShift::BOM));

        $plain = $this->placed('plain.txt', "old\n");
        (new Write($this->dir))->execute(['file_path' => 'plain.txt', 'content' => "new\n", 'overwrite' => true]);
        $this->assertSame("new\n", file_get_contents($plain));
        (new Write($this->dir))->execute(['file_path' => 'born.txt', 'content' => "fresh\n"]);
        $this->assertStringStartsWith('fr', (string) file_get_contents($this->dir . '/born.txt'), 'a created file is exactly what was asked for');
    }

    // ── ApplyPatch ──────────────────────────────────────────────────────

    public function testApplyPatchMoveKeepsTheMark(): void
    {
        $path = $this->placed('bom.txt', IndentShift::BOM . "one\ntwo\n");
        $patch = new ApplyPatch($this->dir);

        $result = $patch->execute(['patch' => "*** Begin Patch\n*** Update File: bom.txt\n*** Move to: kept.txt\n-one\n+ONE\n*** End Patch"]);

        $this->assertFalse($result->isError());
        $this->assertFileDoesNotExist($path);
        $this->assertSame(IndentShift::BOM . "ONE\ntwo\n", file_get_contents($this->dir . '/kept.txt'), 'a moved marked file arrives still marked');
    }

    public function testApplyPatchDeleteIsUnaffectedAndPlainFilesStayPlain(): void
    {
        $this->placed('bom.txt', IndentShift::BOM . "one\n");
        $this->placed('plain.txt', "one\n");
        $patch = new ApplyPatch($this->dir);

        $this->assertFalse($patch->execute(['patch' => "*** Begin Patch\n*** Update File: plain.txt\n-one\n+ONE\n*** End Patch"])->isError());
        $this->assertSame("ONE\n", file_get_contents($this->dir . '/plain.txt'), 'a mark-free file gains nothing from the machinery');

        $this->assertFalse($patch->execute(['patch' => "*** Begin Patch\n*** Delete File: bom.txt\n*** End Patch"])->isError());
        $this->assertFileDoesNotExist($this->dir . '/bom.txt');
    }

    // ── the primitive ───────────────────────────────────────────────────

    public function testInheritBomMirrorsTheFileAndNeverAddsOne(): void
    {
        $this->assertSame(IndentShift::BOM . 'x', IndentShift::inheritBom(IndentShift::BOM . 'f', 'x'));
        $this->assertSame('x', IndentShift::inheritBom('f', 'x'), 'a plain file never lends a mark');
        $this->assertSame(IndentShift::BOM . 'x', IndentShift::inheritBom(IndentShift::BOM . 'f', IndentShift::BOM . 'x'), 'an owned mark is kept, not doubled');
        $this->assertSame('x', IndentShift::inheritBom('', 'x'), 'no bytes, no mark');
        $this->assertTrue(IndentShift::hasBom(IndentShift::BOM));
        $this->assertSame('', IndentShift::stripBom(IndentShift::BOM));
        $this->assertSame('x', IndentShift::stripBom('x'));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function placed(string $name, string $bytes): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $bytes);

        return $path;
    }
}

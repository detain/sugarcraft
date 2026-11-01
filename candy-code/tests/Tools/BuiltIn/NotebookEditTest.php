<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Notebook\Notebook;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\BuiltIn\NotebookEdit;
use SugarCraft\Code\Tools\Catalog\ToolCatalog;
use SugarCraft\Code\Tools\ReadLedger;

/**
 * C-TOOL-6.b: the NotebookEdit tool face. The model round-trip is pinned in
 * {@see \SugarCraft\Code\Tests\Notebook\NotebookTest}; this file pins what
 * the TOOL promises: every refusal lands BEFORE a byte is written (a torn
 * notebook is worse than a refused one), the two ledger refusals copy
 * Edit's message family verbatim, and a successful edit records its own
 * bytes as the new baseline so chaining edits in one session works.
 *
 * Temp notebooks are copies — the fixtures themselves are inputs and must
 * stay untouched on disk.
 */
final class NotebookEditTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cc-notebookedit_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        $this->dir = (string) realpath($this->dir);
    }

    protected function tearDown(): void
    {
        // Explicit known names only — no directory walk (TreeWideGuardRoster).
        foreach (['nb.ipynb', 'plain.ipynb', 'v3.ipynb', 'broken.ipynb', 'new.ipynb', 'outside.ipynb'] as $leftover) {
            @unlink($this->dir . '/' . $leftover);
        }
        @rmdir($this->dir);
    }

    /** Lay a v4 fixture at a temp path and hand back both bytes and path. */
    private function plant(string $name, string $fixture = 'v45-outputs.ipynb'): string
    {
        copy(\dirname(__DIR__, 2) . '/fixtures/notebooks/' . $fixture, $this->dir . '/' . $name);

        return $this->dir . '/' . $name;
    }

    public function testTheCatalogCarriesItAtTheMeasuredFreePosition(): void
    {
        // Design said 42; 42-47 were live-taken at the cut (SkillsSearch,
        // SkillWorkshop, AdvisorTool, CanvasTool, ScriptTool, ShellSend),
        // so the attribute holds the next free number — see the commit
        // message. This pin is what the deviation record points at.
        $attribute = (new \ReflectionClass(NotebookEdit::class))->getAttributes(ToolPermissionClass::class);
        self::assertCount(0, $attribute, 'the marker attribute is BuiltInTool, not a permission class');

        $toolAttribute = (new \ReflectionClass(NotebookEdit::class))->getAttributes(BuiltInTool::class);
        self::assertCount(1, $toolAttribute);
        $meta = $toolAttribute[0]->newInstance();
        self::assertSame('NotebookEdit', $meta->name);
        self::assertSame(ToolPermissionClass::Write, $meta->permission);
        self::assertSame(48, $meta->position);
        self::assertSame(ToolPermissionClass::Write, ToolCatalog::permissionOf('NotebookEdit'));
    }

    public function testReplaceRewritesOneCellAndRecordsItsOwnBytes(): void
    {
        $path = $this->plant('nb.ipynb');
        $originalBytes = (string) file_get_contents($path);
        $ledger = ReadLedger::new();
        $tool = new NotebookEdit($this->dir, readLedger: $ledger);

        $done = $tool->execute(['file_path' => 'nb.ipynb', 'edit_mode' => 'replace', 'cell_id' => 'a1b2c3d4', 'new_source' => "print('new')\n"]);

        self::assertFalse($done->isError(), $done->content());
        self::assertStringStartsWith('Notebook updated: ', $done->content());
        self::assertStringNotContainsString("\n", trim(str_replace('Notebook updated: ' . $path, '', $done->content())), 'the success line is one summary, not a file dump');

        $after = json_decode((string) file_get_contents($path));
        self::assertSame("print('new')\n", implode('', (array) $after->cells[1]->source));
        self::assertSame([], $after->cells[1]->outputs, 'a replaced code cell must not keep results of the old source');
        self::assertNull($after->cells[1]->execution_count);
        self::assertSame(json_encode(json_decode($originalBytes)->cells[0]), json_encode($after->cells[0]), 'untouched cells ride byte-identical');

        self::assertTrue($ledger->has($path));
        self::assertNull($ledger->staleness($path, (string) file_get_contents($path)), 'the tool records its own bytes as the new baseline');
    }

    public function testInsertAtHeadMintsTheEightHexIdInFileBytes(): void
    {
        $path = $this->plant('nb.ipynb');
        $done = (new NotebookEdit($this->dir))->execute(
            ['file_path' => 'nb.ipynb', 'edit_mode' => 'insert', 'new_source' => "# head\n", 'cell_type' => 'markdown'],
        );

        self::assertFalse($done->isError(), $done->content());
        $after = json_decode((string) file_get_contents($path));
        self::assertSame('markdown', $after->cells[0]->cell_type);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}\z/', $after->cells[0]->id);
        self::assertStringContainsString($after->cells[0]->id, $done->content(), 'the answer names the minted id so the model can address it next');
        self::assertCount(5, $after->cells);
    }

    public function testDeleteTakesExactlyTheNamedCellOutOfTheFile(): void
    {
        $path = $this->plant('nb.ipynb');
        $done = (new NotebookEdit($this->dir))->execute(
            ['file_path' => 'nb.ipynb', 'edit_mode' => 'delete', 'cell_id' => 'deadbeef'],
        );

        self::assertFalse($done->isError(), $done->content());
        $keys = array_map(static fn (object $c): string => (string) ($c->id ?? '?'), json_decode((string) file_get_contents($path))->cells);
        self::assertSame(['4f1c2b8a', 'a1b2c3d4', '01234567'], $keys);
    }

    public function testAWriteThatDiesHalfwayLeavesTheNotebookBytesIntact(): void
    {
        $path = $this->plant('nb.ipynb');
        $original = (string) file_get_contents($path);
        $tool = new NotebookEdit(
            $this->dir,
            writeSeam: static function (string $target, string $payload): int {
                // Write half the payload into the temp file, then refuse —
                // the atomic rename must never have happened.
                $handle = fopen($target, 'ab');
                self::assertIsResource($handle);
                fwrite($handle, substr($payload, 0, (int) (strlen($payload) / 2)));
                fclose($handle);

                throw new \RuntimeException('simulated ENOSPC mid-write');
            },
        );

        $refused = $tool->execute(['file_path' => 'nb.ipynb', 'edit_mode' => 'replace', 'cell_id' => '01234567', 'new_source' => 'x']);

        self::assertTrue($refused->isError());
        self::assertStringContainsString('Error writing file: ' . $path, $refused->content());
        self::assertSame($original, file_get_contents($path), 'a failed publish must leave the ORIGINAL notebook, not a torn temp');
    }

    public function testThePathJailAppliesToNotebooksLikeEveryWriteTool(): void
    {
        $escape = (new NotebookEdit($this->dir))->execute(
            ['file_path' => '../outside.ipynb', 'edit_mode' => 'delete', 'cell_id' => 'x'],
        );
        self::assertTrue($escape->isError());
        self::assertStringContainsString('path outside workspace root', $escape->content());

        $nul = (new NotebookEdit($this->dir))->execute(
            ['file_path' => "a\0b.ipynb", 'edit_mode' => 'delete', 'cell_id' => 'x'],
        );
        self::assertTrue($nul->isError());
        self::assertStringContainsString('NUL byte', $nul->content());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function argumentRefusals(): iterable
    {
        yield 'no mode' => [['file_path' => 'nb.ipynb'], 'edit_mode must be one of'];
        yield 'bad mode' => [['file_path' => 'nb.ipynb', 'edit_mode' => 'append', 'cell_id' => 'a', 'new_source' => 'x'], 'edit_mode must be one of'];
        yield 'replace without id' => [['file_path' => 'nb.ipynb', 'edit_mode' => 'replace', 'new_source' => 'x'], 'cell_id is required'];
        yield 'insert without type' => [['file_path' => 'nb.ipynb', 'edit_mode' => 'insert', 'new_source' => 'x'], 'requires cell_type'];
        // A REPLACE may carry a type to flip to — and it must still be one
        // of the two the wire format knows (the insert arm above catches
        // missing/invalid for inserts first, so this pins the replace leg).
        yield 'bad cell type' => [['file_path' => 'nb.ipynb', 'edit_mode' => 'replace', 'cell_id' => 'a1b2c3d4', 'new_source' => 'x', 'cell_type' => 'raw'], 'cell_type must be one of'];
        yield 'replace without source' => [['file_path' => 'nb.ipynb', 'edit_mode' => 'replace', 'cell_id' => 'a1b2c3d4'], 'new_source is required'];
        yield 'missing file' => [['file_path' => 'plain.ipynb', 'edit_mode' => 'delete', 'cell_id' => 'a'], 'file not found'];
    }

    #[DataProvider('argumentRefusals')]
    public function testMalformedCallsRefuseWithoutTouchingDisk(array $args, string $needle): void
    {
        if ($needle === 'file not found') {
            // The one leg whose refusal comes after the file guard — plant
            // NOTHING: plain.ipynb stays absent.
        } else {
            $this->plant('nb.ipynb');
        }

        $refused = (new NotebookEdit($this->dir))->execute($args);

        self::assertTrue($refused->isError());
        self::assertStringContainsString($needle, $refused->content());
    }

    #[DataProvider('nonV4Flavours')]
    public function testNonV4AndBrokenNotebooksAreRefusedAndLeftAlone(string $fixture, string $contents): void
    {
        $path = $this->dir . '/' . $fixture;
        file_put_contents($path, $contents);

        $refused = (new NotebookEdit($this->dir))->execute(
            ['file_path' => $fixture, 'edit_mode' => 'replace', 'cell_id' => 'cell-0', 'new_source' => 'x'],
        );

        self::assertTrue($refused->isError());
        self::assertStringContainsString('not a parseable nbformat v4 notebook; nothing was written', $refused->content());
        self::assertSame($contents, file_get_contents($path), 'the refusal is only honest if the bytes did not move');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonV4Flavours(): iterable
    {
        yield 'nbformat 3' => ['v3.ipynb', (string) file_get_contents(\dirname(__DIR__, 2) . '/fixtures/notebooks/v3-legacy.ipynb')];
        yield 'half a JSON document' => ['broken.ipynb', '{"nbformat": 4, "cells": ['];
    }

    public function testAnUnknownCellNamesTheAddressableKeys(): void
    {
        $this->plant('nb.ipynb');
        $refused = (new NotebookEdit($this->dir))->execute(
            ['file_path' => 'nb.ipynb', 'edit_mode' => 'delete', 'cell_id' => 'ff00ff00'],
        );

        self::assertTrue($refused->isError());
        self::assertStringContainsString("cell 'ff00ff00' was not found", $refused->content());
        foreach (['4f1c2b8a', 'a1b2c3d4', 'deadbeef', '01234567'] as $key) {
            self::assertStringContainsString($key, $refused->content());
        }
    }

    public function testTheLedgerRefusalsCarryEditsFamilyWordingVerbatim(): void
    {
        $path = $this->plant('nb.ipynb');
        $bytes = (string) file_get_contents($path);

        // Stale: recorded, then the file moved under the session.
        $ledger2 = ReadLedger::new();
        $ledger2->record($path, $bytes);
        file_put_contents($path, $bytes . "\n");
        $staleTool = new NotebookEdit($this->dir, readLedger: $ledger2);
        $refused = $staleTool->execute(['file_path' => 'nb.ipynb', 'edit_mode' => 'delete', 'cell_id' => '01234567']);

        self::assertTrue($refused->isError());
        self::assertStringContainsString('changed on disk since you last read it', $refused->content());
        self::assertStringContainsString('Read it again before editing it; file left unchanged', $refused->content());

        // Unread: fresh ledger, opt-in on, a file that exists.
        $enforcing = (new NotebookEdit($this->dir, readLedger: ReadLedger::new()))->withRequireReadBeforeWrite(true);
        $unreadRefusal = $enforcing->execute(['file_path' => 'nb.ipynb', 'edit_mode' => 'delete', 'cell_id' => '01234567']);
        self::assertTrue($unreadRefusal->isError());
        self::assertStringContainsString('has not been read in this session; Read it first; file left unchanged', $unreadRefusal->content());

        // Off (the shipped default) writes without any read.
        $defaultTool = new NotebookEdit($this->dir, readLedger: ReadLedger::new());
        self::assertFalse($defaultTool->execute(['file_path' => 'nb.ipynb', 'edit_mode' => 'delete', 'cell_id' => '01234567'])->isError());
    }

    public function testAnOversizedNotebookIsRefusedByNameAndLeftWhole(): void
    {
        $path = $this->plant('nb.ipynb');
        // Grow it past a hand-set cap.
        $notebook = Notebook::parse((string) file_get_contents($path));
        self::assertNotNull($notebook);
        for ($i = 0; $i < 200; $i++) {
            $inserted = $notebook->insert(null, str_repeat('x', 100), 'code');
            self::assertNotNull($inserted);
            $notebook = $inserted[0];
        }
        file_put_contents($path, $notebook->serialize());
        $size = strlen((string) file_get_contents($path));

        $refused = (new NotebookEdit($this->dir, maxBytes: 1024))->execute(
            ['file_path' => 'nb.ipynb', 'edit_mode' => 'delete', 'cell_id' => 'cell-0'],
        );

        self::assertTrue($refused->isError());
        self::assertStringContainsString('notebook too large to edit', $refused->content());
        self::assertStringContainsString(number_format($size) . ' bytes', $refused->content());
        self::assertStringContainsString('file left unchanged', $refused->content());
    }

    public function testAnIdlessNotebookEditsThroughItsPositionalKeys(): void
    {
        $path = $this->plant('new.ipynb', 'v44-idless.ipynb');
        $done = (new NotebookEdit($this->dir))->execute(
            ['file_path' => 'new.ipynb', 'edit_mode' => 'replace', 'cell_id' => 'cell-1', 'new_source' => "y = 2\n"],
        );

        self::assertFalse($done->isError(), $done->content());
        $after = json_decode((string) file_get_contents($path));
        self::assertSame(["y = 2\n"], $after->cells[1]->source);
        self::assertStringNotContainsString('"id"', (string) file_get_contents($path), 'a 4.4 file stays id-free through an edit');
    }
}

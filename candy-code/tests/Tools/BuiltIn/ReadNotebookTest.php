<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\BuiltIn\Read;
use SugarCraft\Code\Tools\ReadLedger;

/**
 * C-TOOL-6.b: Read answers a parseable nbformat v4 notebook as numbered
 * CELL PAGES — a header per cell, the source verbatim, and for outputs a
 * digest only. The promise that makes the pair work: the ledger is handed
 * the RAW file bytes (same stat, same hash), so NotebookEdit's staleness
 * check sees exactly what this answer was built from, and the success
 * pattern of the pair (Read the notebook, edit one cell) has to cross no
 * extra call.
 */
final class ReadNotebookTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cc-readnotebook_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        $this->dir = (string) realpath($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (['nb.ipynb', 'v3.ipynb', 'broken.ipynb', 'big.ipynb', 'notes.md'] as $leftover) {
            @unlink($this->dir . '/' . $leftover);
        }
        @rmdir($this->dir);
    }

    private function plant(string $name, string $fixture): string
    {
        copy(\dirname(__DIR__, 2) . '/fixtures/notebooks/' . $fixture, $this->dir . '/' . $name);

        return $this->dir . '/' . $name;
    }

    /** @param array<string, mixed> $args */
    private function answer(string $file, array $args = [], ?ReadLedger $ledger = null): \SugarCraft\Code\Tools\ToolResult
    {
        return (new Read($this->dir, readLedger: $ledger))->execute(['file_path' => $file] + $args);
    }

    public function testAFourFiveNotebookAnswersWithHeadersSourcesAndDigests(): void
    {
        $this->plant('nb.ipynb', 'v45-outputs.ipynb');
        $page = $this->answer('nb.ipynb')->content();

        self::assertStringContainsString('[cell 1 · markdown · id 4f1c2b8a]', $page);
        self::assertStringContainsString('[cell 2 · code · id a1b2c3d4 · ran 7]', $page);
        self::assertStringContainsString('[cell 3 · code · id deadbeef · ran 8]', $page);
        self::assertStringContainsString('  - stream 220 chars', $page);
        self::assertStringContainsString('  - image/png 75 B', $page);
        self::assertStringContainsString('  - error: ZeroDivisionError', $page);
        self::assertStringNotContainsString('iVBOR', $page, 'output BYTES must never ride a cell page — the base64 is the bulk and none of it is content');
        self::assertStringNotContainsString('Traceback', $page, 'error tracebacks are output bodies too');
        self::assertStringContainsString('# Title', $page, 'source lines ride verbatim');
        self::assertStringNotContainsString('call Read with offset=', $page, 'a whole-notebook answer carries no continuation footer');
    }

    public function testOffsetAndLimitCountCellsNotLines(): void
    {
        $this->plant('nb.ipynb', 'v45-outputs.ipynb');

        $second = $this->answer('nb.ipynb', ['offset' => 2, 'limit' => 1])->content();
        self::assertStringStartsWith('[cell 2 · code', $second);
        self::assertStringNotContainsString('[cell 1', $second);
        self::assertStringNotContainsString('[cell 3', $second);
        self::assertStringContainsString('[cells 2-2 of 4 — call Read with offset=3 to continue]', $second);

        $tail = $this->answer('nb.ipynb', ['offset' => 3])->content();
        self::assertStringContainsString('[cell 3 · code · id deadbeef', $tail);
        self::assertStringContainsString('[cell 4 · code · id 01234567', $tail);
        self::assertStringNotContainsString('to continue]', $tail, 'the window that reaches the last cell carries no footer');
    }

    public function testAnOffsetPastTheLastCellRefusesAndCountsCells(): void
    {
        $this->plant('nb.ipynb', 'v45-outputs.ipynb');
        $refused = $this->answer('nb.ipynb', ['offset' => 9]);

        self::assertTrue($refused->isError());
        self::assertStringContainsString('past the end', $refused->content());
        self::assertStringContainsString('4 cells', $refused->content());
    }

    public function testNotebooksThatDoNotParseFallThroughToTextPaging(): void
    {
        $v3 = $this->plant('v3.ipynb', 'v3-legacy.ipynb');
        $page = $this->answer('v3.ipynb')->content();
        self::assertStringStartsWith('1: {', $page, 'the old text paging shape, line numbers and all');
        self::assertStringContainsString('worksheet', $page);

        file_put_contents($this->dir . '/broken.ipynb', "{\"nbformat\": 4, \"cells\": [oops\n");
        $broken = $this->answer('broken.ipynb')->content();
        self::assertStringStartsWith('1: {', $broken);

        // The RAW file still answers; a notebook that refuses to parse
        // costs the caller nothing but the old behaviour.
        self::assertStringNotContainsString('[cell 1', $page);
        self::assertStringNotContainsString('[cell 1', $broken);
        unset($v3);
    }

    public function testTheLedgerSeesTheRawBytesThePageWasBuiltFrom(): void
    {
        $path = $this->plant('nb.ipynb', 'v45-outputs.ipynb');
        $ledger = ReadLedger::new();

        self::assertStringContainsString('[cell 1 · markdown', $this->answer('nb.ipynb', [], $ledger)->content());
        self::assertTrue($ledger->has($path), 'a cell-page answer must unlock edits the same way a text answer does');
        self::assertNull($ledger->staleness($path, (string) file_get_contents($path)), 'the observed hash must be the RAW file hash');

        // And the pair's full loop through the real tool: the Read unlocks
        // a requireReadBeforeWrite NotebookEdit with nothing but its own
        // answer in the ledger.
        $edit = (new \SugarCraft\Code\Tools\BuiltIn\NotebookEdit($this->dir, readLedger: $ledger))
            ->withRequireReadBeforeWrite(true)
            ->execute(['file_path' => 'nb.ipynb', 'edit_mode' => 'replace', 'cell_id' => '01234567', 'new_source' => "print('moved on')\n"]);
        self::assertFalse($edit->isError(), $edit->content());

        file_put_contents($path, (string) file_get_contents($path) . "\n");
        $afterTtl = (new \SugarCraft\Code\Tools\BuiltIn\NotebookEdit($this->dir, readLedger: $ledger))
            ->execute(['file_path' => 'nb.ipynb', 'edit_mode' => 'replace', 'cell_id' => '01234567', 'new_source' => 'x']);
        self::assertTrue($afterTtl->isError(), 'the tool records its own bytes, but an out-of-band touch after them is staleness');
        self::assertStringContainsString('changed on disk since you last read it', $afterTtl->content());
    }

    public function testAnOversizedNotebookIsTextPageNotCellPage(): void
    {
        // maxBytes on Read is its own cap; over it the parse never runs and
        // today's (capped, flagged) text answer is what ships.
        $path = $this->dir . '/big.ipynb';
        $notebook = \SugarCraft\Code\Notebook\Notebook::parse((string) file_get_contents($this->plant('nb.ipynb', 'v45-outputs.ipynb')));
        self::assertNotNull($notebook);
        for ($i = 0; $i < 60; $i++) {
            $inserted = $notebook->insert(null, str_repeat('x', 2000), 'code');
            self::assertNotNull($inserted);
            $notebook = $inserted[0];
        }
        file_put_contents($path, $notebook->serialize());

        $page = (new Read($this->dir, maxBytes: 4096))->execute(['file_path' => 'big.ipynb'])->content();
        self::assertStringNotContainsString('[cell 1 ·', $page, 'the byte cap is a parse gate too — a notebook over cap pages as text');
        self::assertStringStartsWith('1:', $page);
    }

    public function testPlainTextFilesNeverEnterTheNotebookArm(): void
    {
        // Negative control with the same shape as the answer path: a .md
        // with cell-ish text is still a .md.
        $name = 'notes.md';
        file_put_contents($this->dir . '/' . $name, "[cell 1 · fake]\nx = 1\n");
        $page = $this->answer($name)->content();
        self::assertStringStartsWith('1: [cell 1', $page);
    }
}

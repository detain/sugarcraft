<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Stash\Git;
use SugarCraft\Stash\Tests\Concerns\RecursiveDirCleanup;

/**
 * Real-`git` coverage for unicode / quote-bearing filenames (audit #3).
 *
 * Without `-c core.quotepath=off` git emits `café.txt` as
 * `"caf\303\251.txt"`; even with it, a name containing `"` stays quoted as
 * `"we\"ird.txt"`. Both shapes used to flow verbatim into the status rows,
 * so every stage/discard/diff on such a file passed a literal that exists
 * nowhere on disk and failed.
 */
final class GitUnicodePathTest extends TestCase
{
    use RecursiveDirCleanup;

    private string $cwd;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/gituni_' . uniqid();
        mkdir($this->cwd);
        $q = escapeshellarg($this->cwd);
        exec("git -c core.quotepath=off init $q 2>/dev/null");
        exec("git -C $q config user.email 'test@test.com' 2>/dev/null");
        exec("git -C $q config user.name 'Test' 2>/dev/null");
        file_put_contents($this->cwd . '/base.txt', "seed\n");
        exec("git -C $q add base.txt 2>/dev/null && git -C $q commit -m seed 2>/dev/null");
    }

    protected function tearDown(): void
    {
        if (isset($this->cwd)) {
            $this->removeDir($this->cwd);
        }
    }

    public function testStatusReportsUnicodePathUnquoted(): void
    {
        file_put_contents($this->cwd . '/café.txt', "latte\n");

        $rows = (new Git($this->cwd))->status();

        $paths = array_column($rows, 'path');
        $this->assertContains('café.txt', $paths, 'unicode path must arrive raw, never C-quoted');
        foreach ($paths as $path) {
            $this->assertStringNotContainsString('\\303', (string) $path);
        }
    }

    public function testStatusDecodesQuoteEscapedPathAndStageSucceeds(): void
    {
        $weird = 'we"ird.txt';
        file_put_contents($this->cwd . '/' . $weird, "content\n");

        $git = new Git($this->cwd);
        $rows = $git->status();

        $paths = array_column($rows, 'path');
        $this->assertContains($weird, $paths, 'a double-quote in a name must be decoded, not left as \\"');

        // The pre-fix literal `"we\"ird.txt"` does not exist on disk, so this
        // add died with "pathspec did not match any files".
        $git->stage($weird);

        $relaunched = null;
        foreach ($git->status() as $row) {
            if (($row['path'] ?? null) === $weird) {
                $relaunched = $row;
            }
        }
        $this->assertNotNull($relaunched);
        $this->assertSame('A', $relaunched['index_status'], 'quoting round-trip must land the file in the index');
    }

    public function testDiffOnUnicodePathReturnsContent(): void
    {
        file_put_contents($this->cwd . '/café.txt', "one\n");
        $git = new Git($this->cwd);
        $git->stage('café.txt');
        file_put_contents($this->cwd . '/café.txt', "two\n");

        $lines = $git->diff('café.txt');

        $joined = implode("\n", $lines);
        $this->assertStringContainsString('café.txt', $joined);
        $this->assertStringContainsString('-one', $joined);
    }

    public function testRenameWithQuotedBothSidesSplitsAndDecodes(): void
    {
        $old = 'we"ird.txt';
        $new = 'odd"name.txt';
        file_put_contents($this->cwd . '/' . $old, "x\n");
        $git = new Git($this->cwd);
        $git->stage($old);
        $git->commit('add weird');
        exec('git -C ' . escapeshellarg($this->cwd) . ' mv ' . escapeshellarg($old) . ' ' . escapeshellarg($new) . ' 2>/dev/null');

        $rename = null;
        foreach ($git->status() as $row) {
            if (($row['index_status'] ?? null) === 'R') {
                $rename = $row;
            }
        }

        $this->assertNotNull($rename, 'git mv of a tracked file must surface an R row');
        $this->assertSame($new, $rename['path']);
        $this->assertSame($old, $rename['orig_path'] ?? null);
    }
}

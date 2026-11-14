<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\DiffViewer;
use SugarCraft\Git\Git;
use SugarCraft\Git\Process;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * The 4 MiB capture ceiling against real git (candy-git-040).
 *
 * The bug was a silent handoff: run() head-trims, appends its notice, and
 * fromRawDiff used to parse the beheaded tail hunk as if it were complete -
 * staging it fed git apply a partial patch that failed confusingly. The lane
 * now carries an explicit truncated flag, the viewer strips the notice into
 * the partialTail fact, and the App refuses to stage the last hunk of such
 * a diff.
 */
final class TruncationIntegrationTest extends TestCase
{
    use RecursiveDirCleanup;

    private string $cwd;
    private Git $git;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/gittrunc_' . uniqid();
        mkdir($this->cwd, 0o777, true);
        exec('git init -q ' . escapeshellarg($this->cwd) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.name 'Test' 2>/dev/null");

        file_put_contents($this->cwd . '/big.txt', "first line\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' add big.txt 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . ' commit -q -m seed 2>/dev/null');
        $this->git = new Git($this->cwd);
    }

    public function testAnOversizedDiffArrivesFlaggedWithTheNoticeAsLastLine(): void
    {
        $line    = str_repeat('x', 60);
        $content = '';
        for ($i = 0; $i < 120_000; $i++) {
            $content .= $line . "\n";
        }
        file_put_contents($this->cwd . '/big.txt', $content);

        $lines = $this->git->diff('big.txt');

        $this->assertSame(Process::CAPTURE_NOTICE, $lines[count($lines) - 1], 'the notice is the last physical line');

        $dv = DiffViewer::fromRawDiff('big.txt', $lines);
        $this->assertTrue($dv->partialTail, 'the viewer knows the capture lost its tail');
        $this->assertNotSame(Process::CAPTURE_NOTICE, $dv->lines[count($dv->lines) - 1]);
        $this->assertGreaterThanOrEqual(1, $dv->hunkCount());
    }

    public function testAComfortablySmallDiffIsNotPartial(): void
    {
        file_put_contents($this->cwd . '/big.txt', "first line\nsecond line\n");

        $dv = DiffViewer::fromRawDiff('big.txt', $this->git->diff('big.txt'));

        $this->assertFalse($dv->partialTail);
        $this->assertSame(1, $dv->hunkCount());
    }
}

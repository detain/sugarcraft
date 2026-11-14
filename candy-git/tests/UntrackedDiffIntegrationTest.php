<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\DiffViewer;
use SugarCraft\Git\Git;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Real-`git` coverage for the untracked row lanes (candy-git-035).
 *
 * A ?? row used to be a dead end in both directions: `git diff` answers
 * nothing for it (blank overlay on the routine review action) and
 * `git restore` refuses it (guaranteed pathspec error on the routine
 * cleanup action). The cures are a synthesized new-file patch and a true
 * unlink - so the patch shape must agree with git's own byte for byte,
 * and `git apply --cached` must accept it without the index-line context.
 */
final class UntrackedDiffIntegrationTest extends TestCase
{
    use RecursiveDirCleanup;

    private string $cwd;
    private Git $git;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/gituntracked_' . uniqid();
        mkdir($this->cwd, 0o777, true);
        exec('git init -q ' . escapeshellarg($this->cwd) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.name 'Test' 2>/dev/null");

        file_put_contents($this->cwd . '/seed.txt', "seed\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' add -A 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . ' commit -qm "seed" 2>/dev/null');

        $this->git = new Git($this->cwd);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cwd);
    }

    private function raw(string ...$args): string
    {
        return rtrim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null'), "\n");
    }

    /**
     * git's own --no-index output minus the 'index' line (whose blob hash
     * we do not compute) must equal the synthesized diff exactly.
     */
    public function testTheSyntheticDiffMatchesGitsOwnShape(): void
    {
        file_put_contents($this->cwd . '/junk.txt', "aaa\nbbb");

        $mine = $this->git->untrackedDiff('junk.txt');

        $reference = array_values(array_filter(
            explode("\n", trim($this->raw('diff', '--no-index', '--', '/dev/null', 'junk.txt'))),
            static fn (string $l): bool => !str_starts_with($l, 'index ') && $l !== '',
        ));

        $this->assertSame($reference, $mine);
    }

    public function testStagingTheSyntheticHunkAddsTheFileToTheIndex(): void
    {
        file_put_contents($this->cwd . '/junk.txt', "aaa\nbbb");
        $patch = implode("\n", $this->git->untrackedDiff('junk.txt')) . "\n";

        $this->git->stagePatch('junk.txt', $patch);

        // --cached-only listing proves a staged addition of the full file.
        $this->assertSame('junk.txt', $this->raw('diff', '--cached', '--name-only'));
        $this->assertSame(
            $this->raw('hash-object', 'junk.txt'),
            $this->raw('rev-parse', ':junk.txt'),
            'the staged blob must equal the file bytes',
        );
    }

    public function testDeleteUntrackedRemovesTheBytesAndRefusesTheSecondPress(): void
    {
        $file = $this->cwd . '/doomed.txt';
        file_put_contents($file, "x\n");

        $this->git->deleteUntracked('doomed.txt');
        $this->assertFalse(file_exists($file));

        $this->expectException(\RuntimeException::class);
        $this->git->deleteUntracked('doomed.txt');
    }

    public function testABinaryUntrackedFileOffersNoStageableHunk(): void
    {
        file_put_contents($this->cwd . '/blob.dat', "x\0y");

        $lines = $this->git->untrackedDiff('blob.dat');
        $this->assertContains('Binary files /dev/null and b/blob.dat differ', $lines);

        $viewer = DiffViewer::fromRawDiff('blob.dat', $lines);
        $this->assertSame(0, $viewer->hunkCount(), 'the viewer stages nothing it cannot show');
    }

    public function testAnEmptyUntrackedFileDiffCarriesNoHunk(): void
    {
        file_put_contents($this->cwd . '.keep', '');
        touch($this->cwd . '/empty.txt');

        $lines  = $this->git->untrackedDiff('empty.txt');
        $viewer = DiffViewer::fromRawDiff('empty.txt', $lines);

        foreach ($lines as $line) {
            $this->assertStringStartsNotWith('@@', $line);
            if (str_starts_with($line, '+')) {
                // The only + opener an empty creation may carry is the
                // file header itself (+++ b/...), never added content.
                $this->assertSame('+++ b/empty.txt', $line);
            }
        }

        $this->assertSame(0, $viewer->hunkCount());
    }
}

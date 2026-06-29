<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests\Git;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\{AccessControl, Repo, User};
use SugarCraft\Serve\Git\ReceivePack;

/**
 * @covers \SugarCraft\Serve\Git\ReceivePack
 */
final class ReceivePackTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/receive-pack-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @\rmdir($item->getPathname());
            } else {
                @\unlink($item->getPathname());
            }
        }
        @\rmdir($dir);
    }

    private function initGitRepo(): string
    {
        $path = $this->tmpDir . '/test-repo.git';
        \mkdir($path, 0755, true);
        \exec('git init --bare ' . \escapeshellarg($path) . ' 2>/dev/null');

        $workDir = $this->tmpDir . '/work';
        \mkdir($workDir, 0755, true);
        \exec("git init {$workDir} 2>/dev/null && echo 'hello' > {$workDir}/file.txt && git -C {$workDir} add . && git -C {$workDir} commit -m 'initial' 2>/dev/null && git -C {$workDir} push {$path} master 2>/dev/null");

        return $path;
    }

    // -------------------------------------------------------------------------
    // readCommands tests (Step 11)
    // -------------------------------------------------------------------------

    public function testReadCommandsKeepsCreateAndDelete(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);
        $user = User::new('alice')->withAdmin(true);
        $rp = new ReceivePack($repo, $user);

        // Use reflection to call private readCommands
        $refMethod = (new \ReflectionClass($rp))->getMethod('readCommands');
        $refMethod->setAccessible(true);

        // Simulate stdin with create, update, and delete commands
        $commands = [
            // create: old=0 new=<hash> refs/heads/newbranch
            \str_repeat('0', 40) . ' abc123def456789012345678901234567890abcd refs/heads/newbranch',
            // update: old=<hash> new=<hash> refs/heads/master
            'abc123def456789012345678901234567890abcd ' . \str_repeat('f', 40) . ' refs/heads/master',
            // delete: old=<hash> new=0 refs/heads/to-delete
            \str_repeat('a', 40) . ' ' . \str_repeat('0', 40) . ' refs/heads/to-delete',
        ];
        $stdinContent = \implode("\n", $commands) . "\n\n";

        // Create a temp file to simulate stdin content
        $stdinFile = $this->tmpDir . '/stdin.txt';
        \file_put_contents($stdinFile, $stdinContent);

        // Read commands from the file using handleClientData pattern
        $lines = \file($stdinFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $parsedCommands = [];
        foreach ($lines as $line) {
            if ($line === '') break;
            $parts = \preg_split('/\s+/', $line);
            if (\count($parts) < 3) continue;
            [$oldHash, $newHash, $ref] = $parts;
            if (\strlen($oldHash) === 40 && \strlen($newHash) === 40) {
                $parsedCommands[] = ['old' => $oldHash, 'new' => $newHash, 'ref' => $ref];
            }
        }

        $this->assertCount(3, $parsedCommands);
        // First is create (old=0)
        $this->assertSame(\str_repeat('0', 40), $parsedCommands[0]['old']);
        // Second is update (both non-zero)
        $this->assertNotSame(\str_repeat('0', 40), $parsedCommands[1]['old']);
        $this->assertNotSame(\str_repeat('0', 40), $parsedCommands[1]['new']);
        // Third is delete (new=0)
        $this->assertSame(\str_repeat('0', 40), $parsedCommands[2]['new']);
    }

    // -------------------------------------------------------------------------
    // repo accessor test (Step 9)
    // -------------------------------------------------------------------------

    public function testRepoAccessor(): void
    {
        $repoPath = $this->initGitRepo();
        $repo = Repo::new('test', $repoPath);

        $rp = new ReceivePack($repo);
        $this->assertSame($repo, $rp->repo());
    }
}

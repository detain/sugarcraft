<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\PathJail;
use SugarCraft\Crush\Agents\PathJailConfig;

/**
 * Tests for PathJail - path isolation layer for agent worktrees.
 */
final class PathJailTest extends TestCase
{
    private string $worktreePath;

    protected function setUp(): void
    {
        $this->worktreePath = sys_get_temp_dir() . '/pathjail_test_' . uniqid('', true);
        mkdir($this->worktreePath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->worktreePath);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = array_diff(@scandir($path) ?: [], ['.', '..']);
        foreach ($entries as $entry) {
            $entryPath = $path . '/' . $entry;
            if (is_dir($entryPath)) {
                $this->removeDirectory($entryPath);
            } else {
                unlink($entryPath);
            }
        }
        rmdir($path);
    }

    // -------------------------------------------------------------------------
    // jailPath() - prepends worktree for relative paths
    // -------------------------------------------------------------------------

    public function testJailPathRelativePath(): void
    {
        $jail = new PathJail($this->worktreePath, new PathJailConfig());

        $result = $jail->jailPath('src/Agents/Thing.php');
        $this->assertSame($this->worktreePath . '/src/Agents/Thing.php', $result);
    }

    public function testJailPathRelativePathWithDots(): void
    {
        $jail = new PathJail($this->worktreePath, new PathJailConfig());

        $result = $jail->jailPath('../foo.txt');
        $this->assertSame($this->worktreePath . '/../foo.txt', $result);
    }

    public function testJailPathAbsolutePathUnchanged(): void
    {
        $jail = new PathJail($this->worktreePath, new PathJailConfig());

        $result = $jail->jailPath('/etc/passwd');
        $this->assertSame('/etc/passwd', $result);
    }

    public function testJailPathEmptyPath(): void
    {
        $jail = new PathJail($this->worktreePath, new PathJailConfig());

        $result = $jail->jailPath('');
        $this->assertSame($this->worktreePath, $result);
    }

    // -------------------------------------------------------------------------
    // isAllowed() - checks containment within worktree
    // -------------------------------------------------------------------------

    public function testIsAllowedPathInsideWorktree(): void
    {
        $subdir = $this->worktreePath . '/src/Agents';
        mkdir($subdir, 0755, true);
        file_put_contents($subdir . '/Thing.php', '<?php');

        $jail = new PathJail($this->worktreePath, new PathJailConfig());

        $this->assertTrue($jail->isAllowed($subdir . '/Thing.php'));
        $this->assertTrue($jail->isAllowed($this->worktreePath . '/src'));
        $this->assertTrue($jail->isAllowed($this->worktreePath));
    }

    public function testIsAllowedPathOutsideWorktree(): void
    {
        $jail = new PathJail($this->worktreePath, new PathJailConfig());

        $this->assertFalse($jail->isAllowed('/etc/passwd'));
        $this->assertFalse($jail->isAllowed('/tmp'));
        $this->assertFalse($jail->isAllowed('/usr'));
    }

    public function testIsAllowedNonexistentPath(): void
    {
        $jail = new PathJail($this->worktreePath, new PathJailConfig());

        $this->assertFalse($jail->isAllowed($this->worktreePath . '/nonexistent/file.txt'));
    }

    public function testIsAllowedPathWithParentTraversal(): void
    {
        $subdir = $this->worktreePath . '/src';
        mkdir($subdir, 0755, true);

        $jail = new PathJail($this->worktreePath, new PathJailConfig());

        // A path that uses .. to escape the worktree
        $this->assertFalse($jail->isAllowed($subdir . '/../etc/passwd'));
        $this->assertFalse($jail->isAllowed($this->worktreePath . '/../sibling_dir'));
    }

    public function testIsAllowedSymlinkPointsToOutside(): void
    {
        // Create a file outside the worktree
        $outsideDir = sys_get_temp_dir() . '/pathjail_outside_' . uniqid('', true);
        mkdir($outsideDir, 0755, true);
        $outsideFile = $outsideDir . '/real.txt';
        file_put_contents($outsideFile, 'content');

        // Create a symlink inside the worktree that points to the outside file
        $symlinkPath = $this->worktreePath . '/link.txt';
        symlink($outsideFile, $symlinkPath);

        $jail = new PathJail($this->worktreePath, new PathJailConfig());

        // The symlink path is inside the worktree, but realpath resolves through
        // the symlink to the actual target which is outside — so access is denied.
        $this->assertFalse($jail->isAllowed($symlinkPath));

        // Clean up
        unlink($symlinkPath);
        unlink($outsideFile);
        rmdir($outsideDir);
    }

    public function testIsAllowedSymlinkPointsInside(): void
    {
        // Create a directory outside the worktree
        $outsideDir = sys_get_temp_dir() . '/pathjail_outside_' . uniqid('', true);
        mkdir($outsideDir, 0755, true);
        $outsideFile = $outsideDir . '/real.txt';
        file_put_contents($outsideFile, 'content');

        // Create a symlink inside the worktree pointing to outside
        $symlinkPath = $this->worktreePath . '/link.txt';
        symlink($outsideFile, $symlinkPath);

        $jail = new PathJail($this->worktreePath, new PathJailConfig());

        // The symlink path is inside the worktree, so it would pass the check
        // However, realpath() resolves it to the actual file outside
        $this->assertFalse($jail->isAllowed($symlinkPath));

        // Clean up
        unlink($symlinkPath);
        unlink($outsideFile);
        rmdir($outsideDir);
    }

    // -------------------------------------------------------------------------
    // Integration: jailPath + isAllowed
    // -------------------------------------------------------------------------

    public function testJailPathThenIsAllowedRoundTrip(): void
    {
        $subdir = $this->worktreePath . '/src/Agents';
        mkdir($subdir, 0755, true);
        file_put_contents($subdir . '/Thing.php', '<?php');

        $jail = new PathJail($this->worktreePath, new PathJailConfig());

        $jailed = $jail->jailPath('src/Agents/Thing.php');
        $this->assertTrue($jail->isAllowed($jailed));
    }
}

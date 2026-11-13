<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Files\Support\LaunchDir;

/**
 * CF-22 (closes audit 031): the launcher's door check must refuse a
 * directory the UI could only show as a lie (missing → fake-empty) or
 * strand the user in (0500 → unreadable). The prose runs before locale
 * detection, so these sentences are raw English by design.
 */
final class LaunchDirTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/candy_files_launchdir_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->tmpDir . '/secret', 0700);
        @rmdir($this->tmpDir . '/secret');
        @rmdir($this->tmpDir . '/vacant');
        @unlink($this->tmpDir . '/f.txt');
        @rmdir($this->tmpDir);
    }

    public function testReadableDirectoryPassesTheDoor(): void
    {
        self::assertNull(LaunchDir::refusal($this->tmpDir));
    }

    public function testMissingDirectoryRefuses(): void
    {
        self::assertSame('does not exist', LaunchDir::refusal($this->tmpDir . '/nope'));
    }

    public function testPlainFileRefusesAsMissingDirectory(): void
    {
        file_put_contents($this->tmpDir . '/f.txt', 'x');
        self::assertSame('does not exist', LaunchDir::refusal($this->tmpDir . '/f.txt'));
    }

    public function testUnreadableDirectoryRefuses(): void
    {
        $secret = $this->tmpDir . '/secret';
        mkdir($secret, 0000);
        if (!is_dir($secret) || is_readable($secret)) {
            @chmod($secret, 0700);
            self::markTestSkipped('this environment defeats directory read permissions (running as root?)');
        }

        try {
            self::assertSame(
                'is not readable',
                LaunchDir::refusal($secret),
                'CF-22: a 0500 dir used to pass the door and render as a lying empty pane',
            );
        } finally {
            chmod($secret, 0700);
        }
    }
}

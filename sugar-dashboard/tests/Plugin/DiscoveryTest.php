<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Plugin\Discovery;

final class DiscoveryTest extends TestCase
{
    public function testScanNonExistentDirectoryReturnsEmpty(): void
    {
        $result = Discovery::scan('/non/existent/directory');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testScanEmptyDirectoryReturnsEmpty(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sugar-dashboard-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        try {
            $result = Discovery::scan($tmpDir);
            $this->assertIsArray($result);
            $this->assertEmpty($result);
        } finally {
            rmdir($tmpDir);
        }
    }

    public function testScanFindsExecutableFiles(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sugar-dashboard-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        $pluginFile = $tmpDir . '/my-plugin';
        file_put_contents($pluginFile, '#!/bin/bash\necho "test"');
        chmod($pluginFile, 0755);

        try {
            $result = Discovery::scan($tmpDir);
            $this->assertCount(1, $result);
            $this->assertSame($pluginFile, $result[0]);
        } finally {
            unlink($pluginFile);
            rmdir($tmpDir);
        }
    }

    public function testScanSkipsNonExecutableFiles(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sugar-dashboard-test-' . uniqid();
        mkdir($tmpDir, 0755, true);

        $nonExecFile = $tmpDir . '/not-executable';
        file_put_contents($nonExecFile, '#!/bin/bash\necho "test"');
        chmod($nonExecFile, 0644);

        try {
            $result = Discovery::scan($tmpDir);
            $this->assertEmpty($result);
        } finally {
            unlink($nonExecFile);
            rmdir($tmpDir);
        }
    }

    public function testScanSkipsSubdirectories(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sugar-dashboard-test-' . uniqid();
        mkdir($tmpDir, 0755, true);
        mkdir($tmpDir . '/subdir', 0755, true);

        $pluginFile = $tmpDir . '/my-plugin';
        file_put_contents($pluginFile, '#!/bin/bash\necho "test"');
        chmod($pluginFile, 0755);

        try {
            $result = Discovery::scan($tmpDir);
            $this->assertCount(1, $result);
        } finally {
            unlink($pluginFile);
            rmdir($tmpDir . '/subdir');
            rmdir($tmpDir);
        }
    }

    public function testDefaultDirectoryReturnsPath(): void
    {
        $defaultDir = Discovery::defaultDirectory();

        $this->assertIsString($defaultDir);
        $this->assertStringContainsString('sugar-dashboard', $defaultDir);
        $this->assertStringContainsString('plugins', $defaultDir);
    }

    /**
     * The fallback used to compute dirname($HOME) . '/.config' — collapsing
     * /home/user to /home and pointing plugin discovery at /home/.config,
     * a path no user owns. HOME itself is the anchor.
     */
    public function testDefaultDirectoryAnchorsOnHomeNotItsParent(): void
    {
        $savedHome = getenv('HOME');
        $savedXdg = getenv('XDG_CONFIG_HOME');
        putenv('HOME=/home/tester');
        putenv('XDG_CONFIG_HOME');

        try {
            $this->assertSame(
                '/home/tester/.config/sugar-dashboard/plugins',
                Discovery::defaultDirectory(),
            );
        } finally {
            putenv($savedHome === false ? 'HOME' : "HOME=$savedHome");
            putenv($savedXdg === false ? 'XDG_CONFIG_HOME' : "XDG_CONFIG_HOME=$savedXdg");
        }
    }

    public function testXdgConfigHomeWinsOverHome(): void
    {
        $savedHome = getenv('HOME');
        $savedXdg = getenv('XDG_CONFIG_HOME');
        putenv('HOME=/home/tester');
        putenv('XDG_CONFIG_HOME=/xdg/root');

        try {
            $this->assertSame(
                '/xdg/root/sugar-dashboard/plugins',
                Discovery::defaultDirectory(),
            );
        } finally {
            putenv($savedHome === false ? 'HOME' : "HOME=$savedHome");
            putenv($savedXdg === false ? 'XDG_CONFIG_HOME' : "XDG_CONFIG_HOME=$savedXdg");
        }
    }

    /**
     * FilesystemIterator hands back directory order — plugin load order
     * used to vary between machines and runs. scan() sorts.
     */
    public function testScanReturnsDeterministicOrder(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sugar-dashboard-order-' . uniqid();
        mkdir($tmpDir, 0755, true);

        foreach (['zulu.php', 'alpha.php', 'mike.php'] as $name) {
            file_put_contents($tmpDir . '/' . $name, "#!/usr/bin/env php\n");
            chmod($tmpDir . '/' . $name, 0755);
        }

        try {
            $result = Discovery::scan($tmpDir);
            $this->assertSame([
                $tmpDir . '/alpha.php',
                $tmpDir . '/mike.php',
                $tmpDir . '/zulu.php',
            ], $result);
        } finally {
            foreach (['zulu.php', 'alpha.php', 'mike.php'] as $name) {
                unlink($tmpDir . '/' . $name);
            }
            rmdir($tmpDir);
        }
    }

    public function testScanDefaultReturnsArray(): void
    {
        $result = Discovery::scanDefault();

        $this->assertIsArray($result);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plugin\Discovery;

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
        $tmpDir = sys_get_temp_dir() . '/sugar-dash-test-' . uniqid();
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
        $tmpDir = sys_get_temp_dir() . '/sugar-dash-test-' . uniqid();
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
        $tmpDir = sys_get_temp_dir() . '/sugar-dash-test-' . uniqid();
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
        $tmpDir = sys_get_temp_dir() . '/sugar-dash-test-' . uniqid();
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
        $this->assertStringContainsString('sugar-dash', $defaultDir);
        $this->assertStringContainsString('plugins', $defaultDir);
    }

    public function testScanDefaultReturnsArray(): void
    {
        $result = Discovery::scanDefault();

        $this->assertIsArray($result);
    }
}

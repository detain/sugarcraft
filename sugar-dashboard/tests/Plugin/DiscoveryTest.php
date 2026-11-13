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

    /**
     * Run a closure with error_log redirected to a capture file so
     * fail-closed refusal reasons are observable, not folklore.
     */
    private function withErrorLogCaptured(callable $with): string
    {
        $file = tempnam(sys_get_temp_dir(), 'dashboard-discovery-log-');
        $previous = ini_get('error_log');
        ini_set('error_log', $file);
        try {
            $with();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $log = (string) file_get_contents($file);
        unlink($file);
        return $log;
    }

    /**
     * Finding sugar-dashboard-090: with HOME unset the old code answered
     * '/tmp' — a world-writable plugin source is arbitrary code execution
     * as the dashboard user. Now: null plus a logged reason.
     */
    public function testHomeUnsetRefusesTmpFallback(): void
    {
        $vars = ['HOME', 'XDG_CONFIG_HOME', 'CANDY_DASHBOARD_PLUGINS_DIR'];
        $saved = [];
        foreach ($vars as $name) {
            $saved[$name] = getenv($name);
            putenv($name);
        }

        try {
            $directory = null;
            $log = $this->withErrorLogCaptured(function () use (&$directory) {
                $directory = Discovery::defaultDirectory();
            });
            $this->assertNull($directory);
            $this->assertStringContainsString('refusing the world-writable /tmp fallback', $log);

            $plugins = [];
            $log2 = $this->withErrorLogCaptured(function () use (&$plugins) {
                $plugins = Discovery::scanDefault();
            });
            $this->assertSame([], $plugins);
            $this->assertStringContainsString('discovery skipped', $log2);
        } finally {
            foreach ($vars as $name) {
                $value = $saved[$name];
                if ($value === false) {
                    putenv($name);
                } else {
                    putenv("{$name}={$value}");
                }
            }
        }
    }

    public function testExplicitPluginsDirOverridesHome(): void
    {
        $saved = ['CANDY_DASHBOARD_PLUGINS_DIR' => getenv('CANDY_DASHBOARD_PLUGINS_DIR')];
        putenv('CANDY_DASHBOARD_PLUGINS_DIR=/opt/custom/plugins');
        try {
            $this->assertSame('/opt/custom/plugins', Discovery::defaultDirectory());
        } finally {
            $value = $saved['CANDY_DASHBOARD_PLUGINS_DIR'];
            $value === false ? putenv('CANDY_DASHBOARD_PLUGINS_DIR') : putenv("CANDY_DASHBOARD_PLUGINS_DIR={$value}");
        }
    }

    /**
     * A world-writable directory is refused even when it is full of
     * legitimate-looking executables (the /tmp attack shape, owned or not).
     */
    public function testWorldWritableDirectoryRefused(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sugar-dashboard-0777-' . uniqid();
        mkdir($tmpDir, 0700, true);
        file_put_contents($tmpDir . '/evil.php', "#!/usr/bin/env php\n");
        chmod($tmpDir . '/evil.php', 0755);
        chmod($tmpDir, 0777);

        try {
            if ((fileperms($tmpDir) & 0o022) === 0) {
                $this->markTestSkipped('umask strips the group/world write bits on this host');
            }

            $log = '';
            $plugins = [];
            $log = $this->withErrorLogCaptured(function () use ($tmpDir, &$plugins) {
                $plugins = Discovery::scan($tmpDir);
            });
            $this->assertSame([], $plugins);
            $this->assertStringContainsString('group/world-writable', $log);
        } finally {
            @unlink($tmpDir . '/evil.php');
            @chmod($tmpDir, 0700);
            @rmdir($tmpDir);
        }
    }

    /**
     * Group-writable is refused with the same reasoning: a shared group is
     * another author for the dropped-executable attack.
     */
    public function testGroupWritableDirectoryRefused(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sugar-dashboard-0775-' . uniqid();
        mkdir($tmpDir, 0700, true);
        file_put_contents($tmpDir . '/maybe.php', "#!/usr/bin/env php\n");
        chmod($tmpDir . '/maybe.php', 0755);
        chmod($tmpDir, 0775);

        try {
            if ((fileperms($tmpDir) & 0o020) === 0) {
                $this->markTestSkipped('umask strips the group write bit on this host');
            }

            $plugins = [];
            $log = $this->withErrorLogCaptured(function () use ($tmpDir, &$plugins) {
                $plugins = Discovery::scan($tmpDir);
            });
            $this->assertSame([], $plugins);
            $this->assertStringContainsString('group/world-writable', $log);
        } finally {
            @unlink($tmpDir . '/maybe.php');
            @chmod($tmpDir, 0700);
            @rmdir($tmpDir);
        }
    }

    /**
     * The trustworthy shape still works: own dir, 0755, exec files found.
     */
    public function testOwnTrustedDirectoryStillScans(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sugar-dashboard-trusted-' . uniqid();
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir . '/ok.php', "#!/usr/bin/env php\n");
        chmod($tmpDir . '/ok.php', 0755);

        try {
            $plugins = Discovery::scan($tmpDir);
            $this->assertSame([$tmpDir . '/ok.php'], $plugins);
        } finally {
            unlink($tmpDir . '/ok.php');
            rmdir($tmpDir);
        }
    }
}

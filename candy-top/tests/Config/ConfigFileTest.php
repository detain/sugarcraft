<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\ConfigFile;
use SugarCraft\Top\Config\ConfigWriter;

final class ConfigFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/candy-top-config-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
    }

    public function testDefaultPathPrefersAbsoluteXdgConfigHome(): void
    {
        $this->assertSame(
            '/x/cfg/candy-top/config.conf',
            ConfigFile::defaultPath(['XDG_CONFIG_HOME' => '/x/cfg/', 'HOME' => '/home/u']),
        );
    }

    public function testDefaultPathFallsBackToHome(): void
    {
        $this->assertSame('/home/u/.config/candy-top/config.conf', ConfigFile::defaultPath(['HOME' => '/home/u']));
        $this->assertSame(
            '/home/u/.config/candy-top/config.conf',
            ConfigFile::defaultPath(['XDG_CONFIG_HOME' => 'relative/cfg', 'HOME' => '/home/u']),
            'relative XDG_CONFIG_HOME is invalid per the XDG spec',
        );
        $this->assertSame(
            '/home/u/.config/candy-top/config.conf',
            ConfigFile::defaultPath(['XDG_CONFIG_HOME' => '', 'HOME' => '/home/u']),
        );
    }

    public function testDefaultPathNullWithoutEnvironment(): void
    {
        $this->assertNull(ConfigFile::defaultPath([]));
    }

    public function testDefaultPathReadsProcessEnvWhenNoOverride(): void
    {
        $xdg = getenv('XDG_CONFIG_HOME');
        $home = getenv('HOME');
        putenv('XDG_CONFIG_HOME=/proc-env/cfg');
        try {
            $this->assertSame('/proc-env/cfg/candy-top/config.conf', ConfigFile::defaultPath());
        } finally {
            putenv($xdg === false ? 'XDG_CONFIG_HOME' : 'XDG_CONFIG_HOME=' . $xdg);
            putenv($home === false ? 'HOME' : 'HOME=' . $home);
        }
    }

    public function testNewWithoutAnyPathThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not determine config path');
        ConfigFile::new(null, []);
    }

    public function testNewResolvesFromEnv(): void
    {
        $file = ConfigFile::new(null, ['XDG_CONFIG_HOME' => $this->dir]);
        $this->assertSame($this->dir . '/candy-top/config.conf', $file->path());
        $this->assertFalse($file->exists());
    }

    public function testLoadMissingFileGivesDefaultsAndRequestsWrite(): void
    {
        $result = ConfigFile::new($this->dir . '/none.conf')->load();
        $this->assertSame(Config::new()->toArray(), $result->config->toArray());
        $this->assertSame([], $result->warnings);
        $this->assertTrue($result->needsRewrite);
    }

    public function testLoadMissingFileKeepsBase(): void
    {
        $base = Config::new()->with('vim_keys', true);
        $this->assertTrue(ConfigFile::new($this->dir . '/none.conf')->load($base)->config->bool('vim_keys'));
    }

    public function testLoadUnreadableFileWarnsAndKeepsDefaults(): void
    {
        $file = $this->dir . '/locked.conf';
        file_put_contents($file, "update_ms = 500\n");
        chmod($file, 0000);
        if (is_readable($file)) {
            $this->markTestSkipped('running as root — permission bits are not enforced');
        }
        $result = ConfigFile::new($file)->load();
        $this->assertSame(['Could not read config file ' . $file], $result->warnings);
        $this->assertSame(2000, $result->config->updateMs());
    }

    public function testWriteCreatesDirectoryAndRoundTrips(): void
    {
        $file = ConfigFile::new(null, ['XDG_CONFIG_HOME' => $this->dir]);
        $config = Config::new()->with('update_ms', 900)->with('color_theme', 'nord')->with('proc_tree', true);

        $file->write($config);

        $this->assertTrue($file->exists());
        $this->assertSame(ConfigWriter::render($config), file_get_contents($file->path()));
        $result = $file->load();
        $this->assertSame($config->toArray(), $result->config->toArray());
        $this->assertSame([], $result->warnings);
        $this->assertFalse($result->needsRewrite);
    }

    public function testWriteIsAtomicAndLeavesNoTempFiles(): void
    {
        $path = $this->dir . '/config.conf';
        file_put_contents($path, "old\n");
        $inodeBefore = fileinode($path);

        ConfigFile::new($path)->write(Config::new());

        $this->assertSame(['config.conf'], array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])));
        $this->assertNotSame($inodeBefore, fileinode($path), 'replaced by rename, not rewritten in place');
        $this->assertStringStartsWith('#? ', (string) file_get_contents($path));
    }

    public function testWritePreservesExistingPermissions(): void
    {
        $path = $this->dir . '/config.conf';
        file_put_contents($path, "old\n");
        chmod($path, 0600);

        ConfigFile::new($path)->write(Config::new());
        clearstatcache();

        $this->assertSame(0600, fileperms($path) & 0777);
    }

    public function testWriteFailureLeavesOldFileIntact(): void
    {
        $path = $this->dir . '/config.conf';
        file_put_contents($path, "update_ms = 500\n");
        chmod($this->dir, 0500);
        clearstatcache();
        if (is_writable($this->dir)) {
            chmod($this->dir, 0700);
            $this->markTestSkipped('running as root — permission bits are not enforced');
        }

        try {
            ConfigFile::new($path)->write(Config::new());
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Could not write config file ' . $path, $e->getMessage());
        } finally {
            chmod($this->dir, 0700);
        }

        $this->assertSame("update_ms = 500\n", file_get_contents($path));
    }

    public function testWriteCreatesNewDirectoryPrivate(): void
    {
        $path = $this->dir . '/fresh/candy-top/config.conf';
        ConfigFile::new($path)->write(Config::new());
        clearstatcache();
        $this->assertSame(0700, fileperms(\dirname($path)) & 0777);
    }

    public function testWriteFollowsSymlinkAndKeepsIt(): void
    {
        mkdir($this->dir . '/dotfiles');
        $target = $this->dir . '/dotfiles/top.conf';
        file_put_contents($target, "old\n");
        $link = $this->dir . '/config.conf';
        symlink('dotfiles/top.conf', $link);

        ConfigFile::new($link)->write(Config::new()->with('update_ms', 1234));
        clearstatcache();

        $this->assertTrue(is_link($link), 'link survives');
        $this->assertSame('dotfiles/top.conf', readlink($link));
        $this->assertStringContainsString("\nupdate_ms = 1234\n", (string) file_get_contents($target));
        $this->assertSame(['top.conf'], array_values(array_diff(scandir($this->dir . '/dotfiles') ?: [], ['.', '..'])));
        $this->assertSame(1234, ConfigFile::new($link)->load()->config->updateMs());
    }

    public function testWriteFollowsChainedAndDanglingSymlinks(): void
    {
        mkdir($this->dir . '/real');
        $target = $this->dir . '/real/config.conf';
        symlink($target, $this->dir . '/hop');          // dangling: target not created yet
        symlink('hop', $this->dir . '/config.conf');

        ConfigFile::new($this->dir . '/config.conf')->write(Config::new());
        clearstatcache();

        $this->assertTrue(is_link($this->dir . '/config.conf'));
        $this->assertTrue(is_link($this->dir . '/hop'));
        $this->assertFileExists($target);
    }

    public function testWriteRejectsSymlinkLoop(): void
    {
        symlink('b', $this->dir . '/a');
        symlink('a', $this->dir . '/b');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too many levels of symbolic links');
        ConfigFile::new($this->dir . '/a')->write(Config::new());
    }

    public function testWriteRefusesReadOnlyFile(): void
    {
        $path = $this->dir . '/config.conf';
        file_put_contents($path, "update_ms = 500\n");
        chmod($path, 0444);
        clearstatcache();
        if (is_writable($path)) {
            $this->markTestSkipped('running as root — permission bits are not enforced');
        }

        try {
            ConfigFile::new($path)->write(Config::new());
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Config file ' . $path . ' is read-only; settings were not saved.', $e->getMessage());
        }

        $this->assertSame("update_ms = 500\n", file_get_contents($path));
        clearstatcache();
        $this->assertSame(0444, fileperms($path) & 0777);
    }

    public function testWriteFailsWhenDirectoryCannotBeCreated(): void
    {
        $blocker = $this->dir . '/blocker';
        file_put_contents($blocker, 'x');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot create directory');
        ConfigFile::new($blocker . '/sub/config.conf')->write(Config::new());
    }

    private function rmrf(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            @chmod($path, 0700);
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                $this->rmrf($path . '/' . $entry);
            }
            @rmdir($path);

            return;
        }
        @chmod($path, 0600);
        @unlink($path);
    }
}

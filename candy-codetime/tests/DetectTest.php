<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeTime\Support\Detect;

/**
 * CT-S32: the detection lane `push <file>` runs on. Units over a private
 * tmp tree — the CLI-level probes live in CliTest.
 */
final class DetectTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/ct-detect-' . uniqid('', true);
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = array_merge(glob($this->tmp . '/*') ?: [], glob($this->tmp . '/*/*') ?: []);
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        foreach ([$this->tmp . '/repo', $this->tmp . '/loose'] as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        @rmdir($this->tmp);
    }

    public function testTheExtensionMapNamesLanguagesAndFallsBackToUnknown(): void
    {
        $this->assertSame('php', Detect::language('src/Main.php'));
        // Case does not matter, and the last dot wins.
        $this->assertSame('php', Detect::language('MAIN.PHP'));
        $this->assertSame('js', Detect::language('app.test.js'));
        $this->assertSame('js', Detect::language('mod.mjs'));
        $this->assertSame('ts', Detect::language('view.tsx'));
        $this->assertSame('python', Detect::language('tools/deploy.py'));
        $this->assertSame('c++', Detect::language('widget.hpp'));
        $this->assertSame('unknown', Detect::language('notes'));
        $this->assertSame('unknown', Detect::language('weird.qkv'));
        $this->assertSame('unknown', Detect::language(''));
    }

    public function testTheNearestGitAncestorNamesTheProject(): void
    {
        mkdir($this->tmp . '/repo', 0700, true);
        mkdir($this->tmp . '/repo/.git', 0700, true);
        mkdir($this->tmp . '/repo/src/deep', 0700, true);
        $file = $this->tmp . '/repo/src/deep/main.php';
        file_put_contents($file, "<?php\n");

        $this->assertSame('repo', Detect::project($file, $this->tmp . '/repo/src'));
    }

    public function testAWorktreeStyleGitFileCountsToo(): void
    {
        mkdir($this->tmp . '/wt-proj', 0700, true);
        file_put_contents($this->tmp . '/wt-proj/.git', "gitdir: elsewhere\n");

        $this->assertSame('wt-proj', Detect::project($this->tmp . '/wt-proj/a.php', null));
    }

    public function testWithoutAGitRootTheRelativeFirstSegmentWins(): void
    {
        mkdir($this->tmp . '/loose/sub', 0700, true);

        // A file in a subdirectory of the cwd is filed under that directory.
        $this->assertSame('sub', Detect::project('sub/tool.js', $this->tmp . '/loose'));
        // A bare file in the cwd names no directory — the cwd itself is the
        // nearest thing to a project label there is.
        $this->assertSame('loose', Detect::project('tool.js', $this->tmp . '/loose'));
    }

    public function testAnUnrelatedAbsolutePathIsNoProject(): void
    {
        // Filesystem geography ('home') must never masquerade as a project.
        $this->assertSame('unknown', Detect::project('/home/someone/misc/x.js', '/etc/php'));
        $this->assertSame('unknown', Detect::project('/x/a.php', false));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;

/**
 * Regression: `render-batch --recursive` walks subdirectories.
 *
 * Bug fixed in d070e742: the old implementation only globbed
 * `<dir>/*.tape` and dropped any nested `<dir>/sub/foo.tape`. The
 * fix swaps the glob for a `RecursiveDirectoryIterator` when -r is set.
 */
final class RenderBatchRecursiveTest extends TestCase
{
    public function testRecursiveDiscoveryFindsNestedTapeFiles(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }

        $root = sys_get_temp_dir() . '/candy-vcr-batch-r-' . bin2hex(random_bytes(4));
        $sub = $root . '/sub';
        if (!mkdir($sub, 0700, true) && !is_dir($sub)) {
            $this->fail("Failed to create temp dir tree: {$sub}");
        }

        $aTape = $root . '/a.tape';
        $bTape = $sub . '/b.tape';
        $tapeBody = "Set Theme \"TokyoNight\"\nSet Width 20\nSet Height 5\nType \"x\"\nEnter\nSleep 50ms\n";
        file_put_contents($aTape, $tapeBody);
        file_put_contents($bTape, $tapeBody);

        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $this->assertNotFalse($stdout);
            $this->assertNotFalse($stderr);

            $app = new Application();
            $exit = $app->run(
                ['candy-vcr', 'render-batch', $root, '-r', '--encoder', 'php', '--backend', 'gd'],
                $stdout,
                $stderr,
            );

            rewind($stdout);
            rewind($stderr);
            $out = (string) stream_get_contents($stdout);
            $err = (string) stream_get_contents($stderr);

            $this->assertSame(0, $exit, "render-batch -r should exit 0; got {$exit}; stderr: {$err}; stdout: {$out}");

            $this->assertFileExists($root . '/a.gif', 'top-level tape should be rendered');
            $this->assertFileExists($sub . '/b.gif', 'nested tape should also be rendered via -r');
        } finally {
            @unlink($root . '/a.gif');
            @unlink($sub . '/b.gif');
            @unlink($aTape);
            @unlink($bTape);
            @rmdir($sub);
            @rmdir($root);
        }
    }
}

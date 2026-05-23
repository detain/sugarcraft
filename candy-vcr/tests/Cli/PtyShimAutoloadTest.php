<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Regression: pty-shim.php walks parent vendor directories to find
 * Composer autoload when installed inside a host project's vendor tree.
 *
 * Bug fixed in d070e742: the shim used to assume autoload at
 * `dirname(__DIR__) . '/vendor/autoload.php'` only — which works when
 * candy-pty is checked out top-level but fails when installed as
 * `vendor/sugarcraft/candy-pty/bin/pty-shim.php` inside a parent
 * project. The fix walks 3 candidate paths.
 *
 * This test mirrors the nested install: copies pty-shim.php into a
 * fake `vendor/sugarcraft/candy-pty/bin/` layout, stubs an autoload at
 * the parent's `vendor/autoload.php`, then invokes the shim with no
 * args. We expect exit code 2 (the shim's "usage" exit code, hit AFTER
 * autoload is found) — NOT exit code 3 (autoload not found).
 */
final class PtyShimAutoloadTest extends TestCase
{
    public function testShimFindsAutoloadInParentVendorLayout(): void
    {
        $shimSrc = realpath(__DIR__ . '/../../../candy-pty/bin/pty-shim.php');
        $this->assertNotFalse($shimSrc, 'pty-shim.php must exist in the repo');

        $root = sys_get_temp_dir() . '/candy-vcr-shim-' . bin2hex(random_bytes(4));
        $shimDir = $root . '/vendor/sugarcraft/candy-pty/bin';
        if (!mkdir($shimDir, 0700, true) && !is_dir($shimDir)) {
            $this->fail("Failed to create nested vendor layout: {$shimDir}");
        }

        $shimDst = $shimDir . '/pty-shim.php';
        $autoloadDst = $root . '/vendor/autoload.php';

        copy($shimSrc, $shimDst);
        // Stub autoload — empty file is enough; the shim only needs
        // `is_file()` + `require` to succeed.
        file_put_contents($autoloadDst, "<?php\n");

        try {
            $process = new Process(['php', $shimDst]);
            $process->setTimeout(10);
            $process->run();
            $exit = (int) $process->getExitCode();
            $stderr = $process->getErrorOutput();

            $this->assertNotSame(
                3,
                $exit,
                "Shim exited 3 (autoload-not-found); discovery loop regressed. stderr: {$stderr}",
            );
            // Even if exit happens to be a non-3 value that still surfaces
            // the autoload-missing message (defensive belt-and-braces),
            // refuse to pass — the discovery loop is the contract.
            $this->assertStringNotContainsString(
                'vendor/autoload.php not found',
                $stderr,
                'Autoload discovery loop failed to walk to the parent vendor layout',
            );
        } finally {
            @unlink($shimDst);
            @unlink($autoloadDst);
            @rmdir($shimDir);
            @rmdir(dirname($shimDir));
            @rmdir(dirname($shimDir, 2));
            @rmdir(dirname($shimDir, 3));
            @rmdir($root);
        }
    }
}

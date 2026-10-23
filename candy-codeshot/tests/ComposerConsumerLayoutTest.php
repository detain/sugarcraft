<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Real composer-consumer layout proof for bin/candy-codeshot.
 *
 * BinStructureTest pins the candidate list; this test drives the middle
 * candidate for real: it places a copy of the bin at the exact path
 * composer uses for an installed dependency
 * (<project>/vendor/sugarcraft/candy-codeshot/bin/candy-codeshot) with a
 * vendor/autoload.php one level above it, and checks the CLI actually
 * boots and renders there.
 *
 * The autoloader maps the three needed namespaces onto the monorepo src/
 * directories instead of copying them — the probe order under test is
 * decided by the bin's __DIR__, not by where the classes live.
 */
final class ComposerConsumerLayoutTest extends TestCase
{
    public function testTheBinBootsFromTheInstalledDependencyPath(): void
    {
        $repoRoot   = dirname(__DIR__, 2);
        $packageSrc = $repoRoot . '/candy-codeshot';
        $scratch    = sys_get_temp_dir() . '/codeshot_consumer_' . uniqid('', true);

        $binDir = $scratch . '/proj/vendor/sugarcraft/candy-codeshot/bin';
        if (!mkdir($binDir, 0700, true) && !is_dir($binDir)) {
            throw new \RuntimeException('could not create scratch consumer tree');
        }

        try {
            copy($packageSrc . '/bin/candy-codeshot', $binDir . '/candy-codeshot');

            $loader = <<<'PHP'
            <?php
            $map = [
                'SugarCraft\\CodeShot\\' => '%PACKAGE%/src/',
                'SugarCraft\\Core\\' => '%ROOT%/sugar-core/src/',
                'SugarCraft\\AnsiParser\\' => '%ROOT%/sugar-ansi-parser/src/',
            ];
            spl_autoload_register(function (string $class) use ($map): void {
                foreach ($map as $prefix => $dir) {
                    if (str_starts_with($class, $prefix)) {
                        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                        if (is_file($file)) {
                            require $file;
                            return;
                        }
                    }
                }
            });
            PHP;
            $loader = str_replace(
                ['%PACKAGE%', '%ROOT%'],
                [$packageSrc, $repoRoot],
                $loader,
            );
            file_put_contents($scratch . '/proj/vendor/autoload.php', $loader);

            file_put_contents($scratch . '/proj/a.php', "<?php echo 1;\n");

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $handle = proc_open(
                [PHP_BINARY, $binDir . '/candy-codeshot', '--no-window', '--no-shadow', 'a.php'],
                $descriptors,
                $pipes,
                $scratch . '/proj',
            );
            $this->assertIsResource($handle);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($handle);

            $this->assertSame(0, $exitCode, 'installed-path bin must not fail: ' . $stderr);
            $this->assertStringStartsWith('<?xml', $stdout, 'the SVG must come back from the installed path');
        } finally {
            $this->rmTree($scratch);
        }
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

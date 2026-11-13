<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\ParentProcessGuard;

final class ParentProcessGuardTest extends TestCase
{
    public function testCaptureReturnsAClosureThatIsSilentWhileTheParentLives(): void
    {
        $check = ParentProcessGuard::capture('agent');

        self::assertInstanceOf(\Closure::class, $check);
        $check();
        $check();
        $this->addToAssertionCount(1);
    }

    public function testAnOrphanedChildRaisesNamingTheWho(): void
    {
        if (!function_exists('posix_getppid') || PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('needs posix');
        }

        $dir = sys_get_temp_dir() . '/ppg-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = $dir . '/child.php';
        file_put_contents($script, '<?php require ' . var_export($autoload, true) . ';
$check = SugarCraft\\Code\\Support\\ParentProcessGuard::capture("worker");
touch(' . var_export($dir . '/ready', true) . ');
$deadline = microtime(true) + 10;
$parent = posix_getppid();
while (posix_getppid() === $parent && microtime(true) < $deadline) { usleep(5000); }
try { $check(); $r = "no-throw"; } catch (\\RuntimeException $e) { $r = $e->getMessage(); }
file_put_contents(' . var_export($dir . '/out', true) . ', $r);
');
        // The wrapper shell exits only once the child has captured its parent, orphaning it.
        $sh = sprintf(
            '%s %s </dev/null >/dev/null 2>&1 & i=0; while [ ! -e %s ] && [ $i -lt 400 ]; do sleep 0.02; i=$((i+1)); done',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($dir . '/ready'),
        );
        exec('sh -c ' . escapeshellarg($sh) . ' 2>&1');

        $deadline = microtime(true) + 12;
        while (!is_file($dir . '/out') && microtime(true) < $deadline) {
            usleep(20000);
        }
        $out = is_file($dir . '/out') ? (string) file_get_contents($dir . '/out') : '';
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);

        self::assertStringContainsString('the worker is gone', $out);
    }
}

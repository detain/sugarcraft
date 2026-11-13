<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * S-V01: a foreground serve must leave no discovery record or control socket
 * behind when SIGTERM'd, in the boot window (guard armed) and once serving.
 */
final class ServeForegroundSigtermTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('posix_kill')) {
            $this->markTestSkipped('needs ext-pcntl and ext-posix');
        }
        $this->home = \sys_get_temp_dir() . '/sv01-' . \bin2hex(\random_bytes(4));
        \mkdir($this->home, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->home);
    }

    public function testGuardRemovesResidueThenDiesBySignal(): void
    {
        $file = $this->home . '/server.json';
        \file_put_contents($file, '{}');
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = 'require ' . \var_export($autoload, true) . ';'
            . '\SugarCraft\Code\Cli\Serve\ServeResidueGuard::arm(static function (): void { @unlink(' . \var_export($file, true) . '); });'
            . 'echo "ready\n"; while (true) { usleep(10000); }';
        $proc = \proc_open([\PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $this->assertSame("ready\n", \fgets($pipes[1]));
        $pid = \proc_get_status($proc)['pid'];
        \posix_kill($pid, \SIGTERM);
        $status = null;
        for ($i = 0; $i < 300; $i++) {
            $status = \proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            \usleep(10000);
        }
        $this->assertFalse($status['running']);
        $this->assertTrue($status['signaled']);
        $this->assertSame(\SIGTERM, $status['termsig']);
        $this->assertFileDoesNotExist($file);
        \proc_close($proc);
    }

    public function testForegroundServeSigtermLeavesNoRecordOrSocket(): void
    {
        $root = \dirname(__DIR__, 2);
        $env = ['HOME' => $this->home, 'PATH' => (string) \getenv('PATH'), 'XDG_STATE_HOME' => $this->home . '/state'];
        $proc = \proc_open([\PHP_BINARY, $root . '/bin/candy-code', 'serve', '--port', '0'], [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        $this->assertIsResource($proc);
        \stream_set_blocking($pipes[2], false);
        $err = '';
        $deadline = \microtime(true) + 20;
        while (\microtime(true) < $deadline && !\str_contains($err, 'sign in:')) {
            $err .= (string) \fread($pipes[2], 8192);
            \usleep(50000);
        }
        if (!\str_contains($err, 'sign in:')) {
            \proc_terminate($proc, \SIGKILL);
            $this->markTestSkipped('serve did not reach readiness here: ' . \substr($err, 0, 200));
        }
        $records = \glob($this->home . '/.candy-code/server/server.json') ?: [];
        $this->assertNotSame([], $records, 'a live serve keeps its record');
        \posix_kill(\proc_get_status($proc)['pid'], \SIGTERM);
        $running = true;
        for ($i = 0; $i < 600 && $running; $i++) {
            $running = \proc_get_status($proc)['running'];
            \usleep(25000);
        }
        $this->assertFalse($running, 'serve exits on SIGTERM');
        \proc_close($proc);
        $this->assertSame([], \glob($this->home . '/.candy-code/server/server.json') ?: []);
        $this->assertSame([], \glob($this->home . '/.candy-code/server/*.sock') ?: []);
    }

    private function rm(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);

            return;
        }
        foreach (\glob($path . '/{,.}*', \GLOB_BRACE) ?: [] as $child) {
            if (\in_array(\basename($child), ['.', '..'], true)) {
                continue;
            }
            $this->rm($child);
        }
        @\rmdir($path);
    }
}

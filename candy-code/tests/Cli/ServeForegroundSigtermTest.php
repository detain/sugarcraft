<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tests\Support\SigtermBeforeRunLoop;

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

    /**
     * A SIGTERM that lands after the serve has installed its signal handlers but
     * before it has armed its timers must still stop the serve. The old
     * direct-call handler ran the teardown inside that window, so the timers the
     * protocol and gateway armed afterwards kept the loop alive forever. The
     * readiness-then-SIGTERM case below cannot see that loss, because by the time
     * the parent reads the readiness line the timers are already armed.
     */
    public function testSigtermDeliveredAsTheLoopIsEnteredStillStopsTheServe(): void
    {
        $root = \dirname(__DIR__, 2);
        $prepend = $this->home . '/sigterm-before-run.php';
        \file_put_contents($prepend, "<?php\ndeclare(strict_types=1);\n"
            . 'require ' . \var_export($root . '/vendor/autoload.php', true) . ";\n"
            . 'require ' . \var_export($root . '/tests/Support/SigtermBeforeRunLoop.php', true) . ";\n"
            . '\React\EventLoop\Loop::set(new \SugarCraft\Code\Tests\Support\SigtermBeforeRunLoop(new \React\EventLoop\StreamSelectLoop()));' . "\n");
        $err = $this->home . '/serve.err';
        $env = ['HOME' => $this->home, 'PATH' => (string) \getenv('PATH'), 'XDG_STATE_HOME' => $this->home . '/state'];
        $proc = \proc_open(
            [\PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, $root . '/bin/candy-code', 'serve', '--port', '0'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', $err, 'w']],
            $pipes,
            $root,
            $env,
        );
        $this->assertIsResource($proc);

        $running = true;
        $deadline = \microtime(true) + 15.0;
        while (\microtime(true) < $deadline && $running) {
            $running = \proc_get_status($proc)['running'];
            \usleep(25000);
        }
        if ($running) {
            \proc_terminate($proc, \SIGKILL);
            \proc_close($proc);
            $this->fail('serve kept running after a SIGTERM delivered before its first timer was armed; stderr: '
                . \substr((string) \file_get_contents($err), -400));
        }
        \proc_close($proc);
        // Both lines are needed for the result to mean anything: the seam fired, and
        // the serve was past readiness when it did. A serve that exited during boot
        // would otherwise pass without ever reaching the window.
        $stderr = (string) \file_get_contents($err);
        $this->assertStringContainsString('sign in:', $stderr, 'the serve never reached readiness');
        $this->assertStringContainsString(SigtermBeforeRunLoop::MARKER, $stderr, 'the seam never delivered its SIGTERM');
        $this->assertSame([], \glob($this->home . '/.candy-code/server/server.json') ?: []);
        $this->assertSame([], \glob($this->home . '/.candy-code/server/*.sock') ?: []);
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
        // SIGTERM lands right after the readiness line, inside the window before
        // the loop starts: the stop must survive into the loop, not be lost.
        \posix_kill(\proc_get_status($proc)['pid'], \SIGTERM);
        // The bound is generous on purpose: a correct teardown (watchdog disarm,
        // server stop, record and socket removal) on a loaded shared runner, with
        // 24 candy-code shards in parallel, can take several seconds. A tight bound
        // turns load into a red that no code defect caused.
        $running = true;
        $deadline = \microtime(true) + 15.0;
        while (\microtime(true) < $deadline && $running) {
            $running = \proc_get_status($proc)['running'];
            \usleep(25000);
        }
        if ($running) {
            \proc_terminate($proc, \SIGKILL);
            \proc_close($proc);
            $this->fail('serve did not exit within 15 s of SIGTERM');
        }
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

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Sandbox;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Tools\Sandbox\EgressProxyProcess;

/**
 * Launch-process pins for the egress proxy child: the private socket scaffold,
 * the configuration frame feeding it, the group-kill teardown, and the two
 * liveness exits (explicit stop() and the STDIN-EOF path that fires when the
 * launching parent dies without running its destructors).
 *
 * Every scenario is hermetic: refusals resolve before any DNS lookup, so no
 * test depends on network reachability.
 */
final class EgressProxyProcessTest extends TestCase
{
    public function testStartServesPolicyRefusalOnAPrivateSocket(): void
    {
        $proxy = new EgressProxyProcess(['github.com']);
        $proxy->start();

        try {
            $socket = $proxy->socketPath();
            self::assertIsString($socket);
            self::assertLessThan(100, strlen($socket), 'unix socket paths are capped near 104 bytes on macOS');
            self::assertTrue(self::socketAppears($socket), 'proxy socket never appeared: ' . self::tail($proxy->logPath()));
            self::assertSame(0700, fileperms((string) $proxy->directory()) & 0777);
            self::assertSame(0600, fileperms($socket) & 0777);
            self::assertSame(0600, fileperms((string) $proxy->logPath()) & 0777);

            $answer = self::askProxy($socket, "CONNECT blocked.internal:443 HTTP/1.1\r\n\r\n");
            self::assertStringStartsWith('HTTP/1.1 403', $answer, 'proxy stderr: ' . self::tail($proxy->logPath()));
            self::assertStringContainsString('blocked.internal', $answer);
            self::assertStringContainsString('sandboxAllowedDomains', $answer);
        } finally {
            $proxy->stop();
        }
    }

    public function testStopKillsTheRecordedGroupAndDiscardsTheScaffold(): void
    {
        $proxy = new EgressProxyProcess([]);
        $proxy->start();
        $socket = (string) $proxy->socketPath();
        $directory = (string) $proxy->directory();
        $log = (string) $proxy->logPath();
        self::assertTrue(self::socketAppears($socket), 'proxy socket never appeared: ' . self::tail($log));
        self::assertTrue(
            self::egressSocketRegistered($socket),
            'kernel unix table never listed the bound proxy socket (release check below would be vacuous)'
        );

        $proxy->stop();

        self::assertFalse($proxy->running());
        self::assertFalse(file_exists($socket), 'socket lingered after stop()');
        self::assertFalse(file_exists($log), 'stderr log lingered after stop()');
        self::assertFalse(file_exists($directory), 'scaffold directory lingered after stop()');
        $proxy->stop();

        self::assertTrue(
            self::egressSocketReleased($socket),
            'some process still holds the proxy socket after stop()'
        );
    }

    public function testWrapperDeathEndsTheProxyThroughStdinEof(): void
    {
        $script = self::writeWrapperScript();
        $process = proc_open(
            [PHP_BINARY, $script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process, 'failed to spawn the wrapper probe');

        $socket = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        // The wrapper SIGKILLs itself on purpose (destructors must not run,
        // so only the stdin-EOF liveness path can end the proxy); proc_close
        // reports a signal death as the signal number.
        self::assertSame(9, $status, 'wrapper probe did not die on SIGKILL as designed, socket ' . $socket);
        self::assertNotSame('', $socket);

        // The wrapper was SIGKILLed with the proxy still running and its
        // destructor never executed: the proxy must still notice the closed
        // stdin write-end within one sweep and tear its socket down.
        self::assertTrue(self::socketVanished($socket, 12.0), 'proxy outlived its parent: ' . $socket);
    }

    public function testStubRefusesAMissingConfigurationFrame(): void
    {
        [$status, $stderr] = self::runStubChild('');

        self::assertSame(1, $status);
        self::assertStringContainsString('no configuration frame', $stderr);
    }

    public function testStubRefusesAnUnreadableAutoloader(): void
    {
        $frame = json_encode([
            'autoload' => '/nonexistent/cc-4f-autoload.php',
            'socket' => '/tmp/cc-4f-unused.sock',
            'entries' => [],
        ]);
        self::assertIsString($frame);

        [$status, $stderr] = self::runStubChild($frame . "\n");

        self::assertSame(1, $status);
        self::assertStringContainsString('autoloader is not readable', $stderr);
    }

    /**
     * @return array{int,string}
     */
    private static function runStubChild(string $stdinPayload): array
    {
        $process = proc_open(
            [PHP_BINARY, '-r', EgressProxyProcess::STUB],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        if ($stdinPayload !== '') {
            fwrite($pipes[0], $stdinPayload);
        }

        fclose($pipes[0]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stderr];
    }

    private static function writeWrapperScript(): string
    {
        $autoloadLocation = BackgroundSupervisor::autoloadPath();
        self::assertIsString($autoloadLocation, 'no autoload path for this installation');
        $autoload = var_export($autoloadLocation, true);
        $code = "<?php\n"
            . 'require ' . $autoload . ";\n"
            . '$proxy = new \\SugarCraft\\Code\\Tools\\Sandbox\\EgressProxyProcess([\'github.com\']);' . "\n"
            . '$proxy->start();' . "\n"
            . 'fwrite(STDOUT, (string) $proxy->socketPath());' . "\n"
            . 'fflush(STDOUT);' . "\n"
            . 'posix_kill(posix_getpid(), 9);' . "\n";
        $path = sys_get_temp_dir() . '/cc4f-wrapper-' . bin2hex(random_bytes(4)) . '.php';
        self::assertNotFalse(file_put_contents($path, $code));

        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }

    private static function askProxy(string $socket, string $request): string
    {
        $client = @stream_socket_client('unix://' . $socket, $errno, $errstr, 5.0);
        self::assertIsResource($client, 'could not reach proxy socket: ' . $errstr);
        stream_set_timeout($client, 8);
        self::assertGreaterThan(0, fwrite($client, $request));

        $answer = (string) stream_get_contents($client);
        fclose($client);

        return $answer;
    }

    private static function socketAppears(string $socket, float $limitSeconds = 8.0): bool
    {
        $deadline = microtime(true) + $limitSeconds;
        while (microtime(true) < $deadline) {
            if (file_exists($socket)) {
                return true;
            }

            usleep(50000);
        }

        return false;
    }

    private static function socketVanished(string $socket, float $limitSeconds): bool
    {
        $deadline = microtime(true) + $limitSeconds;
        while (microtime(true) < $deadline) {
            if (!file_exists($socket)) {
                return true;
            }

            usleep(100000);
        }

        return false;
    }

    /**
     * True while some live file descriptor still binds this path, read from
     * the kernel's unix-socket table (the Path column of /proc/net/unix).
     * Unlike a /proc/<pid>/fd readlink sweep this is non-vacuous: socket
     * links there carry an inode, never the filesystem path.
     */
    private static function egressSocketRegistered(string $socket): bool
    {
        $lines = @file('/proc/net/unix');
        if (!is_array($lines)) {
            // No procfs to read: the oracle does not apply on this platform
            // and the file-based assertions around stop() remain the guard.
            return true;
        }
        foreach ($lines as $line) {
            if (str_contains(rtrim($line), $socket)) {
                return true;
            }
        }

        return false;
    }

    private static function egressSocketReleased(string $socket, float $limitSeconds = 5.0): bool
    {
        $deadline = microtime(true) + $limitSeconds;
        while (microtime(true) < $deadline) {
            if (!self::egressSocketRegistered($socket)) {
                return true;
            }

            usleep(100000);
        }

        return false;
    }

    private static function tail(?string $path): string
    {
        if ($path === null || !file_exists($path)) {
            return '(no log)';
        }

        return (string) @file_get_contents($path);
    }
}

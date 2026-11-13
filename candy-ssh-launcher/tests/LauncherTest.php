<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use SugarCraft\SshLauncher\Endpoint;
use SugarCraft\SshLauncher\Launcher;
use PHPUnit\Framework\TestCase;

final class LauncherTest extends TestCase
{
    public function testDispatchPassesCorrectArgvToExecutor(): void
    {
        $captured = null;
        $launcher = new Launcher(function (string $bin, array $args) use (&$captured): void {
            $captured = ['bin' => $bin, 'args' => $args];
        });
        $e = new Endpoint(name: 'prod', host: 'prod.test', port: 2222, user: 'deploy');
        $launcher->dispatch($e, '/usr/bin/ssh');

        $this->assertSame('/usr/bin/ssh', $captured['bin']);
        $this->assertSame(
            ['-p', '2222', '--', 'deploy@prod.test'],
            $captured['args'],
        );
    }

    public function testDispatchUsesProvidedSshBinary(): void
    {
        $captured = null;
        $launcher = new Launcher(function (string $bin, array $args) use (&$captured): void {
            $captured = $bin;
        });
        $e = new Endpoint(name: 'a', host: 'a.test');
        $launcher->dispatch($e, '/usr/local/bin/ssh');
        $this->assertSame('/usr/local/bin/ssh', $captured);
    }

    public function testConstructorWithNullExecutorUsesDefault(): void
    {
        $launcher = new Launcher(null);
        $this->assertInstanceOf(Launcher::class, $launcher);
    }

    public function testDispatchWithDefaultPort22OmitsPortFlag(): void
    {
        $captured = null;
        $launcher = new Launcher(function (string $bin, array $args) use (&$captured): void {
            $captured = $args;
        });
        $e = new Endpoint(name: 'prod', host: 'prod.test', port: 22, user: 'admin');
        $launcher->dispatch($e);

        // Port 22 is default, so -p should not be in args
        $this->assertSame(['--', 'admin@prod.test'], $captured);
    }

    public function testDispatchWithoutUserDefaultsToHostOnly(): void
    {
        $captured = null;
        $launcher = new Launcher(function (string $bin, array $args) use (&$captured): void {
            $captured = $args;
        });
        $e = new Endpoint(name: 'bare', host: 'just-a-host.example.com');
        $launcher->dispatch($e);

        $this->assertSame(['--', 'just-a-host.example.com'], $captured);
    }

    /**
     * -080: the default executor must splice the recorded OS reason
     * into the exec_failed throw instead of losing it to a vanished
     * E_WARNING. A nonexistent binary is deterministic ENOENT.
     */
    public function testDefaultExecutorFailureSplicesTheOsReason(): void
    {
        $launcher = new Launcher();
        $e = new Endpoint(name: 'a', host: 'a.test');

        $this->expectException(\RuntimeException::class);
        try {
            $launcher->dispatch($e, '/definitely-not-here/no-ssh');
        } catch (\RuntimeException $ex) {
            $message = $ex->getMessage();
            $this->assertStringContainsString('failed to exec /definitely-not-here/no-ssh', $message);
            $this->assertStringContainsString('No such file or directory', $message, 'errno reason must ride the throw');
            throw $ex;
        }
    }

    /**
     * -080 staleness guard: an unrelated suppressed error recorded
     * BEFORE the exec must not be mislabeled as the exec reason —
     * the fresh pcntl_exec warning still wins when it lands.
     */
    public function testStaleErrorDoesNotReplaceTheRealExecReason(): void
    {
        @\file_get_contents('/definitely-not-here/stale-probe');
        $stale = error_get_last()['message'] ?? null;
        $this->assertIsString($stale, 'probe premise: a suppressed error is on record');

        $launcher = new Launcher();
        $e = new Endpoint(name: 'a', host: 'a.test');

        try {
            $launcher->dispatch($e, '/definitely-not-here/no-ssh');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $ex) {
            // Either the fresh pcntl reason (correct) or no reason at
            // all (guarded stale) is acceptable; replaying the STALE
            // text as if it were the exec reason is not.
            $this->assertStringNotContainsString('stale-probe', $ex->getMessage());
            $this->assertStringContainsString('No such file or directory', $ex->getMessage());
        }
    }
}

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
     * -027: `command` replaces the ssh binary for one row and the argv
     * shrinks to the transport-safe subset — no `--` boundary either
     * (mosh-class binaries do not speak it), identity/proxyJump/options
     * and the remote command dropped, port and destination kept.
     */
    public function testCustomTransportBuildsTheRestrictedArgv(): void
    {
        $captured = null;
        $launcher = new Launcher(function (string $bin, array $args) use (&$captured): void {
            $captured = ['bin' => $bin, 'args' => $args];
        });
        $e = new Endpoint(
            name: 'mob', host: 'm.test', port: 6000, user: 'ops',
            identityFiles: ['/tmp/id'], options: ['ServerAliveInterval=30'],
            remoteCommand: 'uptime', command: '/bin/echo',
        );
        $launcher->dispatch($e, '/bin/false'); // global ssh must be ignored

        $this->assertSame('/bin/echo', $captured['bin']);
        $this->assertSame(['-p', '6000', 'ops@m.test'], $captured['args']);
    }

    public function testCustomTransportAnnouncesEveryDroppedLeg(): void
    {
        $capture = fopen('php://memory', 'w+');
        $this->assertIsResource($capture);
        $launcher = new Launcher(function (string $bin, array $args): void {
        });
        $launcher->setWarningStream($capture);
        $e = new Endpoint(
            name: 'mob', host: 'm.test', proxyJump: 'j.test',
            command: '/bin/echo',
        );
        $launcher->dispatch($e, '/usr/bin/ssh');

        rewind($capture);
        $notice = (string) stream_get_contents($capture);
        $this->assertStringContainsString('the custom transport ignores proxyJump', $notice);
        $this->assertStringContainsString('mob', $notice, 'the notice names the endpoint');
    }

    public function testPlainSshEndpointCarriesNoTransportNotice(): void
    {
        $capture = fopen('php://memory', 'w+');
        $this->assertIsResource($capture);
        $launcher = new Launcher(function (string $bin, array $args): void {
        });
        $launcher->setWarningStream($capture);
        $launcher->dispatch(new Endpoint(name: 'a', host: 'a.test'), '/bin/echo');

        rewind($capture);
        $this->assertSame('', (string) stream_get_contents($capture), 'nothing was trimmed, nothing may be announced');
    }

    /**
     * -027: the transport binary passes the same exists-and-executable
     * gate as --ssh — an existing but non-executable file is refused by
     * name (nothing is exec'd), instead of surfacing as an ENOEXEC-style
     * failure from pcntl_exec.
     */
    public function testCustomTransportNonExecutableThrowsByName(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sshl27-') . '.bin';
        file_put_contents($path, "data\n");
        chmod($path, 0644);
        try {
            $launcher = new Launcher(function (string $bin, array $args): void {
                $this->fail('the executor must not run for an unvetted transport: ' . $bin);
            });
            $e = new Endpoint(name: 'mob', host: 'm.test', command: $path);
            $thrown = null;
            try {
                $launcher->dispatch($e, '/bin/echo');
            } catch (\RuntimeException $ex) {
                $thrown = $ex;
            }
            $this->assertNotNull($thrown, 'a non-executable transport must throw, never exec');
            $this->assertStringContainsString($path, $thrown->getMessage(), 'the refusal names the path');
            $this->assertStringContainsString('not executable', $thrown->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function testEmptyCommandStringBehavesAsUnset(): void
    {
        $captured = null;
        $launcher = new Launcher(function (string $bin, array $args) use (&$captured): void {
            $captured = $bin;
        });
        $e = new Endpoint(name: 'a', host: 'a.test', command: '');

        $launcher->dispatch($e, '/bin/echo');

        $this->assertSame('/bin/echo', $captured, 'an empty command is config noise, not a transport');
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

    public function testFirstExecutableSshWalksTheCandidateList(): void
    {
        // -075: first existing EXECUTABLE candidate wins; non-files and
        // non-executables are passed over.
        $this->assertSame(
            PHP_BINARY,
            Launcher::firstExecutableSsh(['/definitely-not-here/ssh', '/etc/shadow', PHP_BINARY]),
            'a non-executable data file must not count as the binary'
        );
    }

    public function testFirstExecutableSshReturnsNullWhenNothingResolves(): void
    {
        $this->assertNull(Launcher::firstExecutableSsh(['/definitely-not-here/ssh', '/etc/shadow']));
    }

    public function testSshCandidateListCoversTheDocumentedHosts(): void
    {
        $this->assertSame(
            ['/usr/bin/ssh', '/run/current-system/sw/bin/ssh', '/opt/homebrew/bin/ssh'],
            Launcher::SSH_CANDIDATES,
            'the README --ssh row names exactly this list in this order'
        );
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use SugarCraft\SshLauncher\Endpoint;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for SSH argv injection protection.
 *
 * Verifies that leading-dash values in proxyJump, options, or host/user
 * are either rejected with a clear error or neutralized by the `--`
 * separator so they cannot be interpreted as ssh flags.
 */
final class EndpointArgvSafetyTest extends TestCase
{
    public function testDestinationIsAfterDoubleDash(): void
    {
        // The `--` separator is placed immediately before the destination,
        // so ssh treats everything after it as an operand, not an option.
        $e = new Endpoint(name: 'prod', host: 'prod.example.com', user: 'admin');
        $argv = $e->toSshArgv();
        // Find the '--' index and confirm destination follows it
        $dashIdx = array_search('--', $argv, true);
        $this->assertNotFalse($dashIdx, '-- separator must be present');
        $destIdx = $dashIdx + 1;
        $this->assertArrayHasKey($destIdx, $argv, 'destination must follow --');
        $this->assertSame('admin@prod.example.com', $argv[$destIdx]);
    }

    public function testProxyJumpLeadingDashThrows(): void
    {
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            proxyJump: '-oProxyCommand=calc',
        );
        $this->expectException(\InvalidArgumentException::class);        $this->expectExceptionMessage('unsafe leading-dash');
        $e->toSshArgv();
    }

    public function testOptionLeadingDashThrows(): void
    {
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            options: ['-oProxyCommand=calc'],
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe leading-dash');
        $e->toSshArgv();
    }

    public function testUserLeadingDashThrows(): void
    {
        // A leading-dash user becomes part of user@host and would be
        // an injected flag without the assertNotOption guard.
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            user: '-x',
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe leading-dash');
        $e->toSshArgv();
    }

    public function testNormalEndpointArgvUnchangedExceptForDoubleDash(): void
    {
        // A normal endpoint without any dash-leading fields should have
        // the same argv as before, except for the new trailing -- before
        // the destination.
        $e = new Endpoint(
            name: 'prod',
            host: 'prod.example.com',
            port: 2222,
            user: 'deploy',
            identityFiles: ['/path/to/key'],
        );
        $argv = $e->toSshArgv();
        $expected = [
            'ssh',
            '-p', '2222',
            '-i', '/path/to/key',
            '--',
            'deploy@prod.example.com',
        ];
        $this->assertSame($expected, $argv);
    }

    public function testEmptyHostRefusedAtConstruction(): void
    {
        // F-22 (Step 18): an empty host minted a selectable picker row that
        // dispatched a blank destination; construction now refuses by name.
        // -099(b): the old expectExceptionMessage('x') substring matched
        // ANY message carrying an x — it could not fail. Assert the exact
        // Lang refusal substance instead, like the adjacent guards.
        try {
            new Endpoint(name: 'x', host: '');
            $this->fail('expected a named refusal');
        } catch (\InvalidArgumentException $ex) {
            $this->assertSame(
                \SugarCraft\SshLauncher\Lang::t('endpoint.host_empty', ['name' => 'x']),
                $ex->getMessage(),
            );
        }
    }

    public function testEmptyOptionRefusedAtDispatch(): void
    {
        // F-22 (Step 18): options [''] shipped a bare '-o' argument — the
        // empty entry is refused by name before the leading-dash guard.
        $e = new Endpoint(name: 'opt', host: 'o.test', options: ['']);
        try {
            $e->toSshArgv('ssh');
            $this->fail('expected a named refusal');
        } catch (\InvalidArgumentException $ex) {
            $this->assertStringContainsString('opt', $ex->getMessage());
        }
    }

    public function testRemoteCommandRidesAfterTheDestination(): void
    {
        // -007: ssh(1) synopsis is `ssh [options] destination [command]` —
        // the command is the token AFTER the destination, never behind `--`
        // (that slot would demote the destination itself to command text).
        $e = new Endpoint(
            name: 'kprod',
            host: 'k.test',
            user: 'admin',
            remoteCommand: 'kubectl exec -it bash',
        );
        $this->assertSame(
            ['ssh', '--', 'admin@k.test', 'kubectl exec -it bash'],
            $e->toSshArgv(),
        );
    }

    public function testRemoteCommandLeadingDashThrows(): void
    {
        // A leading-dash command would land in an option-shaped slot of the
        // remote argv-0; the shared guard refuses it by field name.
        $e = new Endpoint(name: 'bad', host: 'b.test', remoteCommand: '-oProxyCommand=calc');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('field [remoteCommand]');
        $e->toSshArgv();
    }

    public function testFlagsLaneEmitsDashPrefixedLetters(): void
    {
        // -033: `-t`/`-N` are not `-o`-able; the lane carries letters and
        // adds the dash at argv build, before the destination boundary.
        $e = new Endpoint(name: 'tty', host: 'p.test', flags: ['t', 'N']);
        $this->assertSame(['ssh', '-t', '-N', '--', 'p.test'], $e->toSshArgv());
    }

    public function testFlagWithLeadingDashThrows(): void
    {
        // The lane takes letters: an entry that already carries a dash is a
        // smuggling attempt (e.g. '-oProxyCommand=calc') and is refused by
        // field name — the very misuse the letters-only rule closes.
        $e = new Endpoint(name: 'bad', host: 'b.test', flags: ['-N']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('field [flag]');
        $e->toSshArgv();
    }

    public function testEmptyFlagItemSkipped(): void
    {
        // An empty flag is config noise, never a bare '-' token on argv.
        $e = new Endpoint(name: 'tty', host: 'p.test', flags: ['t', '', 'N']);
        $this->assertSame(['ssh', '-t', '-N', '--', 'p.test'], $e->toSshArgv());
    }

    public function testIdentityLeadingDashNowThrows(): void
    {
        // -074: the identity slot's OpenSSH-only exemption is gone — on
        // dropbear/busybox a leading-dash `-i` value re-parses as an option,
        // so identity files pass the same guard as every other argv value.
        $e = new Endpoint(name: 'id', host: 'i.test', identityFiles: ['-oProxyCommand=calc']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('field [identityFile]');
        $e->toSshArgv();
    }

    public function testEmptyIdentityItemStillSkips(): void
    {
        // The guard added to the lane must not regress the empty-skip:
        // '' has nothing to inject and used to (correctly) pass silently.
        $e = new Endpoint(name: 'id', host: 'i.test', identityFiles: ['', '/k.pem']);
        $this->assertSame(['ssh', '-i', '/k.pem', '--', 'i.test'], $e->toSshArgv());
    }

    // ------------------------------------------------------------------
    // sshl-22 / finding -029: the host-key-trust-destruction family,
    // named per-hit for the stderr notice.
    // ------------------------------------------------------------------

    public function testUnsafeOptionsNameTheDegradingForms(): void
    {
        // The textbook MITM trio plus the delegation pair: every entry
        // the bin must name before the exec, in order, as written.
        $e = new Endpoint(name: 'mitm', host: 'm.test', options: [
            'StrictHostKeyChecking=no',
            'UserKnownHostsFile=/dev/null',
            'GlobalKnownHostsFile=/dev/null',
            'LogLevel=QUIET',
            'IdentityAgent=/tmp/evil.sock',
            'CertificateFile=/tmp/evil-cert.pub',
        ]);
        $this->assertSame([
            'StrictHostKeyChecking=no',
            'UserKnownHostsFile=/dev/null',
            'GlobalKnownHostsFile=/dev/null',
            'LogLevel=QUIET',
            'IdentityAgent=/tmp/evil.sock',
            'CertificateFile=/tmp/evil-cert.pub',
        ], $e->unsafeOptions());
    }

    public function testUnsafeOptionsJudgeEnumValuedKeysByValue(): void
    {
        // StrictHostKeyChecking=no/never/accept-new (case-insensitive) and
        // LogLevel=QUIET/ERROR/FATAL are the degrading settings; the
        // strengthening and neutral values must never nag. accept-new
        // counts: auto-trusting first contact is exactly the MITM window.
        $e = new Endpoint(name: 'x', host: 'x.test', options: [
            'StrictHostKeyChecking=no',
            'StrictHostKeyChecking=NEVER',
            'StrictHostKeyChecking=accept-new',
            'StrictHostKeyChecking=yes',
            'StrictHostKeyChecking=ask',
            'LogLevel=error',
            'LogLevel=FATAL',
            'LogLevel=INFO',
            'LogLevel=VERBOSE',
        ]);
        $this->assertSame([
            'StrictHostKeyChecking=no',
            'StrictHostKeyChecking=NEVER',
            'StrictHostKeyChecking=accept-new',
            'LogLevel=error',
            'LogLevel=FATAL',
        ], $e->unsafeOptions());
    }

    public function testTrustDatabaseOverridesAreNamedWhateverTheValue(): void
    {
        // An explicit known-hosts/agent/certificate override IS the payload
        // shape (the default locations need no spelling out); re-pointing to
        // the canonical path is still noise worth one line, stated in the
        // Endpoint docblock policy.
        $e = new Endpoint(name: 'x', host: 'x.test', options: [
            'UserKnownHostsFile=~/.ssh/known_hosts',
            'IdentityAgent=none',
        ]);
        $this->assertSame([
            'UserKnownHostsFile=~/.ssh/known_hosts',
            'IdentityAgent=none',
        ], $e->unsafeOptions());
    }

    public function testUnsafeOptionsIgnoreShapelessAndUnrelatedEntries(): void
    {
        // No '=' or an unrelated key is not a family member; the scan
        // never fires on the option lanes the launcher uses legitimately.
        $e = new Endpoint(name: 'x', host: 'x.test', options: [
            'Compression=yes',
            'ServerAliveInterval=30',
            'ControlMaster',
            'ConnectTimeout=10',
        ]);
        $this->assertSame([], $e->unsafeOptions());
    }
}

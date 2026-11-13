<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\UserStore;

/**
 * S21 least-privilege accounts (finding 036): the control plane cannot
 * mint an admin by accident. Every child-process leg matters here — the
 * mutation this family kills is `withAdmin($admin)` reverting to
 * `withAdmin(true)` (or the flag parse swallowing `--admin` as a data
 * path), and both shapes only show through the persisted store that the
 * WHOLE CLI writes.
 */
final class UserAddLeastPrivilegeTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/gitserver-leastpriv-' . \uniqid();
        \mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tmpDir . '/*') ?: [] as $f) {
            if (\is_file($f)) {
                @\unlink($f);
            } else {
                @\rmdir($f);
            }
        }
        @\rmdir($this->tmpDir);
        parent::tearDown();
    }

    /** @return array{rc: int, out: string, err: string} */
    private function runBin(array $args): array
    {
        $bin = (string) \realpath(__DIR__ . '/../bin/candy-git-server');
        $proc = \proc_open(
            ['timeout', '-s', 'KILL', '15', \PHP_BINARY, $bin, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            [
                'CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir,
                'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
            ]
        );
        $this->assertIsResource($proc);
        $out = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        return ['rc' => \proc_close($proc), 'out' => $out, 'err' => $err];
    }

    private function store(): UserStore
    {
        return new UserStore($this->tmpDir . '/users.json');
    }

    public function testBareUserAddMintsLeastPrivilegeAndSaysSo(): void
    {
        $r = $this->runBin(['user', 'add', 'viewer']);
        $this->assertSame(0, $r['rc'], $r['err']);
        $this->assertStringContainsString('admin: false', $r['out'], 'the confirmation must report the real posture');

        $viewer = $this->store()->find('viewer');
        $this->assertNotNull($viewer);
        $this->assertFalse($viewer->isAdmin, 'the CLI default is NOT a global admin (finding 036)');
    }

    public function testAdminFlagRaisesGlobalAdmin(): void
    {
        $r = $this->runBin(['user', 'add', 'root', '--admin']);
        $this->assertSame(0, $r['rc'], $r['err']);
        $this->assertStringContainsString('admin: true', $r['out']);

        $root = $this->store()->find('root');
        $this->assertNotNull($root);
        $this->assertTrue($root->isAdmin, '--admin is the only door to the elevated posture');
    }

    public function testReadFlagIsTheDefaultStatedLoudly(): void
    {
        $r = $this->runBin(['user', 'add', 'reader', '--read']);
        $this->assertSame(0, $r['rc'], $r['err']);
        $this->assertStringContainsString('admin: false', $r['out']);
        $this->assertFalse($this->store()->find('reader')->isAdmin);
    }

    public function testAdminAndReadConflictRefusesWithoutPersisting(): void
    {
        $r = $this->runBin(['user', 'add', 'squatter', '--admin', '--read']);
        $this->assertSame(1, $r['rc']);
        $this->assertStringContainsString('mutually exclusive', $r['err']);
        $this->assertFileDoesNotExist($this->tmpDir . '/users.json', 'a refused add writes nothing');
    }

    public function testFlagsAreNeverSwallowedAsTheDataPathPositional(): void
    {
        // Pre-fix shape: unknown flags fell into the positional list, so
        // `user add frank --read` read --read as the DATA PATH and created
        // the account somewhere else entirely.
        $r = $this->runBin(['user', 'add', 'frank', '--read']);
        $this->assertSame(0, $r['rc'], $r['err']);
        $this->assertNotNull(
            $this->store()->find('frank'),
            'the account must land in the env data path, not a --read directory'
        );
        $this->assertFileDoesNotExist($this->tmpDir . '/--read');
    }

    // -------------------------------------------------------------------------
    // S36 (findings 019, 096): password intake and the factor-less account
    // -------------------------------------------------------------------------

    /**
     * Same bounded-child discipline as runBin(), plus a real STDIN pipe:
     * the whole point of 096 is that the secret enters this way.
     *
     * @param list<string> $args
     *
     * @return array{rc: int, out: string, err: string}
     */
    private function runBinWithStdin(array $args, string $stdin): array
    {
        $bin = (string) \realpath(__DIR__ . '/../bin/candy-git-server');
        $proc = \proc_open(
            ['timeout', '-s', 'KILL', '15', \PHP_BINARY, $bin, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            [
                'CANDY_GIT_SERVER_DATA_PATH' => $this->tmpDir,
                'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
            ]
        );
        $this->assertIsResource($proc);
        \fwrite($pipes[0], $stdin);
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        return ['rc' => \proc_close($proc), 'out' => $out, 'err' => $err];
    }

    public function testValuelessPasswordReadsStdinAndHashesIt(): void
    {
        $r = $this->runBinWithStdin(['user', 'add', 'steven', '--password'], "topsecret\n");
        $this->assertSame(0, $r['rc'], $r['err']);

        $user = $this->store()->find('steven');
        $this->assertNotNull($user);
        $this->assertNotNull($user->passwordHash, 'the STDIN secret must reach the digest');
        $this->assertTrue(\password_verify('topsecret', (string) $user->passwordHash), 'the digest must verify the typed secret');
        $this->assertStringNotContainsString(
            'topsecret',
            (string) @\file_get_contents($this->tmpDir . '/users.json'),
            'the plaintext never lands in the store'
        );
        $this->assertStringNotContainsString(
            'visible in process listings',
            $r['err'],
            'the STDIN path must not warn about argv exposure'
        );
    }

    public function testDashSentinelStillReadsStdin(): void
    {
        $r = $this->runBinWithStdin(['user', 'add', 'harbor', '--password', '-'], 'sentinelpw');
        $this->assertSame(0, $r['rc'], $r['err']);

        $user = $this->store()->find('harbor');
        $this->assertNotNull($user);
        $this->assertTrue(\password_verify('sentinelpw', (string) $user->passwordHash));
    }

    public function testArgvPasswordWarnsAboutProcessVisibility(): void
    {
        // 096: the legacy greedy form keeps working — but it is cleartext
        // in /proc/pid/cmdline for the whole bcrypt window and must say so.
        $r = $this->runBin(['user', 'add', 'gossip', '--password', 'cleartext-value']);
        $this->assertSame(0, $r['rc'], $r['err']);
        $this->assertStringContainsString('visible in process listings', $r['err']);
        $this->assertTrue(
            \password_verify('cleartext-value', (string) $this->store()->find('gossip')->passwordHash),
            'the warning must not change the outcome'
        );
    }

    public function testEqualFormArgvPasswordWarnsToo(): void
    {
        $r = $this->runBin(['user', 'add', 'equalg', '--password=cleartext-two']);
        $this->assertSame(0, $r['rc'], $r['err']);
        $this->assertStringContainsString('visible in process listings', $r['err']);
    }

    public function testPasswordlessAddWarnsAboutTheFactorlessAccount(): void
    {
        // 019: the silent dead account was the live-verified bug — the
        // documented flow minted password_hash: null and push refused
        // forever without one word of warning.
        $r = $this->runBin(['user', 'add', 'ghostface']);
        $this->assertSame(0, $r['rc'], $r['err']);
        $this->assertStringContainsString('no password and no SSH key', $r['err']);
        $this->assertStringContainsString('ghostface', $r['err'], 'the warning must name the account it warns about');
        $this->assertNull($this->store()->find('ghostface')->passwordHash);
    }

    public function testTrailingFlagAfterValuelessPasswordIsStillParsed(): void
    {
        // '--password' followed by another flag reads STDIN for the secret
        // and still honors the flag — the old greedy-consume shape would
        // have made '--admin' the password (and, worse, the value-less
        // refusal shape rejected this invocation entirely).
        $r = $this->runBinWithStdin(['user', 'add', 'flaggy', '--password', '--admin'], 'pw123');
        $this->assertSame(0, $r['rc'], $r['err']);

        $user = $this->store()->find('flaggy');
        $this->assertNotNull($user);
        $this->assertTrue($user->isAdmin, '--admin after a value-less --password is a flag, not the secret');
        $this->assertTrue(\password_verify('pw123', (string) $user->passwordHash));
    }
}

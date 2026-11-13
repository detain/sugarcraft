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
}

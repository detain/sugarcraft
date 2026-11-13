<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\UserStore;

/**
 * S21 first-admin bootstrap + S36 value-less --password: child-process legs
 * asserting against the store the whole CLI persists.
 */
final class InitAdminBootstrapTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/gitserver-initadmin-' . \uniqid();
    }

    protected function tearDown(): void
    {
        $this->rm($this->dir);
    }

    private function rm(string $p): void
    {
        if (\is_dir($p) && !\is_link($p)) {
            foreach (\scandir($p) ?: [] as $e) {
                if ($e !== '.' && $e !== '..') {
                    $this->rm("$p/$e");
                }
            }
            @\rmdir($p);
        } elseif (\file_exists($p)) {
            @\unlink($p);
        }
    }

    /** @return array{rc: int, out: string, err: string} */
    private function runBin(array $args, string $stdin = '', array $env = []): array
    {
        $bin  = (string) \realpath(__DIR__ . '/../bin/candy-git-server');
        $proc = \proc_open(
            ['timeout', '-s', 'KILL', '30', \PHP_BINARY, $bin, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            $env + ['PATH' => \getenv('PATH') ?: '/usr/bin:/bin'],
        );
        $this->assertIsResource($proc);
        \fwrite($pipes[0], $stdin);
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return ['rc' => \proc_close($proc), 'out' => $out, 'err' => $err];
    }

    public function testInitAdminCreatesAdminWithStdinPassword(): void
    {
        $r = $this->runBin(['init', $this->dir, '--admin', 'root', '--admin-password-stdin'], "s3cret\n");
        $this->assertSame(0, $r['rc'], $r['err']);
        $this->assertStringContainsString("Admin account 'root' created", $r['out']);
        $user = (new UserStore($this->dir . '/users.json'))->find('root');
        $this->assertNotNull($user);
        $this->assertTrue($user->isAdmin);
        $this->assertTrue(\password_verify('s3cret', (string) $user->passwordHash));
    }

    public function testSecondBootstrapRefusedUnlessForced(): void
    {
        $this->assertSame(0, $this->runBin(['init', $this->dir, '--admin', 'root'])['rc']);
        $r = $this->runBin(['init', $this->dir, '--admin', 'other']);
        $this->assertSame(1, $r['rc']);
        $this->assertStringContainsString('already exists', $r['err']);
        $this->assertNull((new UserStore($this->dir . '/users.json'))->find('other'));

        $forced = $this->runBin(['init', $this->dir, '--admin', 'other', '--force']);
        $this->assertSame(0, $forced['rc'], $forced['err']);
        $this->assertNotNull((new UserStore($this->dir . '/users.json'))->find('other'));
    }

    public function testEnvBootstrapAndPasswordlessWarning(): void
    {
        $r = $this->runBin(['init', $this->dir], '', ['CANDY_GIT_SERVER_BOOTSTRAP_ADMIN' => 'envroot']);
        $this->assertSame(0, $r['rc'], $r['err']);
        $this->assertStringContainsString('warning', $r['err'], 'a factor-less admin is warned about loudly');
        $this->assertTrue((new UserStore($this->dir . '/users.json'))->find('envroot')?->isAdmin ?? false);
    }

    public function testAdminFlagNeedsAName(): void
    {
        $r = $this->runBin(['init', $this->dir, '--admin']);
        $this->assertSame(1, $r['rc']);
        $this->assertStringContainsString('--admin requires', $r['err']);
    }

    public function testValuelessPasswordReadsStdin(): void
    {
        $this->assertSame(0, $this->runBin(['init', $this->dir])['rc']);
        $r = $this->runBin(['user', 'add', 'bob', $this->dir, '--password'], "pw1\n");
        $this->assertSame(0, $r['rc'], $r['err']);
        $bob = (new UserStore($this->dir . '/users.json'))->find('bob');
        $this->assertTrue(\password_verify('pw1', (string) $bob?->passwordHash));
        $this->assertStringNotContainsString('visible in process listings', $r['err']);
    }

    public function testServeCarriesTheZeroAdminHint(): void
    {
        // Behavioural serve needs a bound port; pin the wiring instead.
        $bin = (string) \file_get_contents(__DIR__ . '/../bin/candy-git-server');
        $this->assertStringContainsString("Lang::t('cli.serve_no_admin_hint'", $bin);
        $en = include __DIR__ . '/../lang/en.php';
        $this->assertStringContainsString('--admin <name>', $en['cli.serve_no_admin_hint']);
    }
}

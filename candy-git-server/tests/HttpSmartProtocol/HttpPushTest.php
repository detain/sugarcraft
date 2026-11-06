<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\AccessControl;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\Git\PktLine;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\User;

/**
 * End-to-end push over the smart-HTTP receive endpoint: the request body
 * relays verbatim to a real `git receive-pack --stateless-rpc` child, so
 * report-status (unpack ok / ok <ref>) rides back in-band with a 200.
 *
 * The forged-push arm is the corruption-killer pin: a command that claims
 * a sha without shipping the objects behind it must NOT move any ref —
 * the pre-delegation hand-rolled path used to update refs to whatever the
 * client asserted.
 *
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\Server
 */
final class HttpPushTest extends TestCase
{
    private string $tmpDir;
    private Server $server;
    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = \sys_get_temp_dir() . '/http-push-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);

        \file_put_contents($this->tmpDir . '/config.yaml', "name: HttpPush\n");
        $this->server = new Server(Config::load($this->tmpDir . '/config.yaml'));

        $target = $this->tmpDir . '/push-target.git';
        \mkdir($target, 0755, true);
        \exec('git init --bare -q ' . \escapeshellarg($target) . ' 2>&1');
        $this->server->registerRepo(Repo::new('push-target.git', $target));

        $this->server->registerUser(User::new('admin')->withAdmin(true)
            ->withPasswordHash(\password_hash('pushpass', \PASSWORD_DEFAULT)));
    }

    protected function tearDown(): void
    {
        AccessControl::instance()->setAllowAnonymousRead(false);
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @\rmdir($item->getPathname());
            } else {
                @\unlink($item->getPathname());
            }
        }
        @\rmdir($dir);
    }

    /** @return array{0: string, 1: string} work repo path + commit sha */
    private function workRepoWithCommit(): array
    {
        $work = $this->tmpDir . '/work';
        \mkdir($work, 0755, true);
        \file_put_contents($work . '/hello.txt', "pushed over http\n");
        $git = static fn (string $args, string $cwd): string => (string) \shell_exec(
            'git -C ' . \escapeshellarg($cwd) . ' -c user.email=p@p -c user.name=p ' . $args . ' 2>/dev/null'
        );
        $git('init -q', $work);
        $git('add hello.txt', $work);
        $git('commit -q -m one', $work);
        return [$work, \trim($git('rev-parse HEAD', $work))];
    }

    private function packBytes(string $work, string $sha): string
    {
        $proc = \proc_open(
            ['git', '-C', $work, 'pack-objects', '--stdout', '--revs'],
            // Descriptor modes are child-relative: index 0 gets ['pipe','r']
            // (the child READS its stdin; the parent holds the write end).
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc);
        \fwrite($pipes[0], $sha . "\n");
        \fclose($pipes[0]);
        $pack = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $this->assertSame(0, \proc_close($proc), 'pack-objects must succeed');

        return $pack;
    }

    /** @return array<string, string> */
    private function adminHeaders(): array
    {
        return ['Authorization' => 'Basic ' . \base64_encode('admin:pushpass')];
    }

    public function testARealPushMovesTheRefThroughTheHttpEndpoint(): void
    {
        [$work, $sha] = $this->workRepoWithCommit();
        $pack = $this->packBytes($work, $sha);
        $zero = \str_repeat('0', 40);

        $body = PktLine::encode("# service=git-receive-pack\n")
            . PktLine::FLUSH
            . PktLine::encode("{$zero} {$sha} refs/heads/pushed\0report-status\n")
            . PktLine::FLUSH
            . $pack;

        $result = $this->server->handleRequest(
            'POST',
            '/push-target.git/git-receive-pack',
            '',
            $this->adminHeaders(),
            $body
        );

        $this->assertSame(200, $result['status'], $result['body']);
        $this->assertStringContainsString('unpack ok', $result['body']);
        $this->assertStringContainsString('ok refs/heads/pushed', $result['body']);
        $this->assertSame(
            'application/x-git-receive-pack-result',
            $result['headers']['Content-Type'] ?? null
        );

        $target = $this->tmpDir . '/push-target.git';
        $moved = \trim((string) \shell_exec(
            'git -C ' . \escapeshellarg($target) . ' rev-parse --verify refs/heads/pushed 2>/dev/null'
        ));
        $this->assertSame($sha, $moved, 'the ref must point at the pushed commit');
        \exec(
            'git -C ' . \escapeshellarg($target) . ' cat-file -e ' . \escapeshellarg($sha) . ' 2>/dev/null',
            $out,
            $objectRc
        );
        $this->assertSame(0, $objectRc, 'the pushed objects must exist in the repository');
    }

    public function testAForgedPushThatCarriesNoObjectsCreatesNoRef(): void
    {
        [$work, $sha] = $this->workRepoWithCommit();
        $zero = \str_repeat('0', 40);

        // Same command the honest push sent — but the pack is missing.
        $body = PktLine::encode("# service=git-receive-pack\n")
            . PktLine::FLUSH
            . PktLine::encode("{$zero} {$sha} refs/heads/forged\0report-status\n")
            . PktLine::FLUSH;

        $result = $this->server->handleRequest(
            'POST',
            '/push-target.git/git-receive-pack',
            '',
            $this->adminHeaders(),
            $body
        );

        $this->assertStringNotContainsString('ok refs/heads/forged', $result['body']);

        $rc = 0;
        \exec(
            'git -C ' . \escapeshellarg($this->tmpDir . '/push-target.git')
            . ' rev-parse --verify refs/heads/forged 2>/dev/null',
            $out,
            $rc
        );
        $this->assertNotSame(0, $rc, 'a claimed sha without objects must not create a ref');
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Fresh-process pins for the `repo list` / `repo info` CLI slice: visibility
 * persists only via the on-disk marker (never public-by-presence), bare repos
 * are listed exactly as the serve scan accepts them, and a jail-unsafe name
 * is refused before it ever touches the filesystem. Children run with a PATH
 * that includes the system git (Repo::init() shells out) and a cwd inside the
 * fixture sandbox, so a misparsed path argument can never plant junk in the
 * repository tree.
 */
final class BinRepoCommandsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/gitserver-binrepos-' . \uniqid('', true);
        \mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tmpDir . '/repositories/*/.git') ?: [] as $git) {
            foreach (\array_reverse(\glob($git . '/../*') ?: []) as $inner) {
                if (\is_dir($inner)) {
                    @\rmdir($inner);
                }
            }
        }
        $this->removeTree($this->tmpDir);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (\is_dir($path)) {
                $this->removeTree($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }

    /**
     * @param list<string>             $args
     * @param array<string,string>     $env
     *
     * @return array{rc:int,out:string,err:string}
     */
    private function runBin(array $args, array $env = []): array
    {
        $bin = (string) \realpath(__DIR__ . '/../bin/candy-git-server');
        $proc = \proc_open(
            ['timeout', '-s', 'KILL', '15', \PHP_BINARY, $bin, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmpDir,
            $env + [
                'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
                'CANDY_GIT_SERVER_LANG' => 'en',
            ]
        );
        $this->assertIsResource($proc);
        $out = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        return ['rc' => \proc_close($proc), 'out' => $out, 'err' => $err];
    }

    /** Make a fake working repo directory (non-bare: .git/ present, no marker). */
    private function makePlainRepo(string $name): string
    {
        $path = $this->tmpDir . '/repositories/' . $name;
        \mkdir($path . '/.git/refs', 0700, true);
        \mkdir($path . '/.git/objects', 0700, true);
        \file_put_contents($path . '/.git/HEAD', "ref: refs/heads/main\n");

        return $path;
    }

    /** Make a fake bare repo directory (HEAD + objects at top level). */
    private function makeBareRepo(string $name): string
    {
        $path = $this->tmpDir . '/repositories/' . $name;
        \mkdir($path . '/refs', 0700, true);
        \mkdir($path . '/objects', 0700, true);
        \file_put_contents($path . '/HEAD', "ref: refs/heads/main\n");

        return $path;
    }

    public function testRepoInfoRefusesJailUnsafeName(): void
    {
        $result = $this->runBin(['repo', 'info', '../evil', $this->tmpDir]);

        $this->assertSame(1, $result['rc'], $result['out'] . $result['err']);
        $this->assertStringContainsString('Invalid repo name', $result['err']);
    }

    public function testRepoListIncludesBareReposLikeTheServeScan(): void
    {
        $this->makeBareRepo('bare.git');
        $this->makePlainRepo('plain');

        $result = $this->runBin(['repo', 'list', $this->tmpDir]);

        $this->assertSame(0, $result['rc'], $result['out'] . $result['err']);
        $this->assertStringContainsString('bare.git', $result['out']);
        $this->assertStringContainsString('plain', $result['out']);
    }

    public function testMarkerlessReposAreNeverListedAsPublic(): void
    {
        $this->makePlainRepo('quiet');

        $result = $this->runBin(['repo', 'list', $this->tmpDir]);

        $this->assertSame(0, $result['rc'], $result['out'] . $result['err']);
        $this->assertStringContainsString('quiet', $result['out']);
        $this->assertStringNotContainsString('(public)', $result['out']);
    }

    public function testPublicMarkerSurfacesInTheListing(): void
    {
        $gitDir = $this->makePlainRepo('loud');
        \file_put_contents($gitDir . '/.git/candy-git-server-visibility', "public\n");

        $result = $this->runBin(['repo', 'list', $this->tmpDir]);

        $this->assertSame(0, $result['rc'], $result['out'] . $result['err']);
        $this->assertMatchesRegularExpression('/loud\s*\(public\)/', $result['out']);
    }

    public function testRepoInfoReportsMarkerVisibilityNotPublicByPresence(): void
    {
        $this->makePlainRepo('honest');

        $result = $this->runBin(['repo', 'info', 'honest', $this->tmpDir]);

        $this->assertSame(0, $result['rc'], $result['out'] . $result['err']);
        $this->assertMatchesRegularExpression('/Public:\s+no/', $result['out']);
        $this->assertMatchesRegularExpression('/Private:\s+yes/', $result['out']);
    }

    public function testRepoCreatePublicPersistsMarkerVisibleToInfo(): void
    {
        $created = $this->runBin(['repo', 'create', 'showcase', $this->tmpDir, '--public']);
        $this->assertSame(0, $created['rc'], $created['out'] . $created['err']);

        $info = $this->runBin(['repo', 'info', 'showcase', $this->tmpDir]);
        $this->assertSame(0, $info['rc'], $info['out'] . $info['err']);
        $this->assertMatchesRegularExpression('/Public:\s+yes/', $info['out']);

        $list = $this->runBin(['repo', 'list', $this->tmpDir]);
        $this->assertMatchesRegularExpression('/showcase\s*\(public\)/', $list['out']);

        // The create note must be state-aware: the operator already passed
        // --public, so repeating "private until --public" names an action
        // they have taken and hides the knob that actually gates them.
        $this->assertStringContainsString('published via its marker', $created['out']);
        $this->assertStringNotContainsString('private until --public', $created['out']);
    }

    public function testRepoCreatePrivateNoteStillNamesThePendingPublishAction(): void
    {
        $created = $this->runBin(['repo', 'create', 'quiet', $this->tmpDir]);
        $this->assertSame(0, $created['rc'], $created['out'] . $created['err']);

        $this->assertStringContainsString('private until --public', $created['out']);
        $this->assertStringContainsString('git.allow_anonymous_read', $created['out']);
        $this->assertStringNotContainsString('published via its marker', $created['out']);
    }


    public function testServeHttpRefusesNonNumericPort(): void
    {
        $r = $this->runBin(['serve', '--http', '--repo-root', $this->tmpDir, '--http-port', 'abc']);
        self::assertSame(1, $r['rc'], $r['err']);
        self::assertStringContainsString('abc', $r['err'], 'garbage port must be refused by name, not cast to 0');
    }

    public function testServeHttpRefusesOversizedNumericPort(): void
    {
        $r = $this->runBin(['serve', '--http', '--repo-root', $this->tmpDir, '--http-port', '70000']);
        self::assertSame(1, $r['rc'], $r['err']);
        self::assertStringContainsString('70000', $r['err']);
    }

    public function testServeHttpPortAliasWarnsAndRoutesToHttpPort(): void
    {
        $r = $this->runBin(['serve', '--http', '--repo-root', $this->tmpDir, '--port', '70000']);
        self::assertSame(1, $r['rc'], $r['err']);
        self::assertStringContainsString('deprecated', $r['err'], 'the legacy flag must name itself');
        self::assertStringContainsString('70000', $r['err'], 'the value must reach the same range check');
    }

    public function testServeHttpPortAliasEqFormRefusesGarbage(): void
    {
        $r = $this->runBin(['serve', '--http', '--repo-root', $this->tmpDir, '--port=abc']);
        self::assertSame(1, $r['rc'], $r['err']);
        self::assertStringContainsString('abc', $r['err']);
    }

    public function testServeHttpRefusesDaemonFlag(): void
    {
        $r = $this->runBin(['serve', '--http', '--repo-root', $this->tmpDir, '--daemon']);
        self::assertSame(1, $r['rc'], $r['err']);
        self::assertStringContainsString('--daemon', $r['err']);
    }

    public function testServeHttpRefusesPidFileEqForm(): void
    {
        $r = $this->runBin(['serve', '--http', '--repo-root', $this->tmpDir, '--pid-file=/tmp/some.pid']);
        self::assertSame(1, $r['rc'], $r['err']);
        self::assertStringContainsString('--pid-file', $r['err']);
    }

    public function testServeHttpRefusesUnknownFlagAndEqForm(): void
    {
        $r = $this->runBin(['serve', '--http', '--repo-root', $this->tmpDir, '--nope']);
        self::assertSame(1, $r['rc'], $r['err']);
        self::assertStringContainsString('--nope', $r['err']);
        $r = $this->runBin(['serve', '--http', '--repo-root', $this->tmpDir, '--publc=1']);
        self::assertSame(1, $r['rc'], $r['err']);
        self::assertStringContainsString('--publc', $r['err']);
    }

    public function testServeHttpRefusesVerboseFlag(): void
    {
        $r = $this->runBin(['serve', '--http', '--repo-root', $this->tmpDir, '--verbose']);
        self::assertSame(1, $r['rc'], "the listener has no verbosity switch yet — silence would lie");
        self::assertStringContainsString('--verbose', $r['err']);
    }

    public function testRepoCreateRefusesTypoFlagBeforePath(): void
    {
        $r = $this->runBin(['repo', 'create', 'oops', '--publc', $this->tmpDir]);
        self::assertSame(1, $r['rc'], $r['err']);
        self::assertStringContainsString('Usage:', $r['err']);
        self::assertDirectoryDoesNotExist($this->tmpDir . '/repositories/oops');
        self::assertDirectoryDoesNotExist($this->tmpDir . '/--publc/repositories/oops');
    }

    public function testRepoCreateRefusesTypoFlagAfterPath(): void
    {
        $r = $this->runBin(['repo', 'create', 'oops2', $this->tmpDir, '--publc']);
        self::assertSame(1, $r['rc'], $r['err']);
        self::assertStringContainsString('Usage:', $r['err']);
        self::assertDirectoryDoesNotExist($this->tmpDir . '/repositories/oops2');
    }
}

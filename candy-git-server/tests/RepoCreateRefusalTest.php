<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;

/**
 * S18/013: `repo create` must refuse to run over an existing git
 * directory. Before the guard, init() happily adopted whatever lived at
 * $path/.git, so a mistyped name printed "created" on top of a live
 * repository — and adding --public REWROTE that repository's visibility
 * marker. Creation intent must never touch bytes it does not own.
 */
final class RepoCreateRefusalTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/gitserver-create-' . \uniqid();
        \mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
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

    public function testCreateMakesAFreshPrivateRepoWithoutAnyMarker(): void
    {
        $r = $this->runBin(['repo', 'create', 'fresh']);
        $this->assertSame(0, $r['rc'], $r['err']);

        $gitDir = $this->tmpDir . '/repositories/fresh/.git';
        $this->assertDirectoryExists($gitDir);
        $this->assertFileDoesNotExist($gitDir . '/candy-git-server-visibility', 'a private-by-default create must not mint a visibility marker');
    }

    public function testCreateRefusesAnExistingDirectoryAndLeavesItWhole(): void
    {
        $first = $this->runBin(['repo', 'create', 'precious', '--public']);
        $this->assertSame(0, $first['rc'], $first['err']);

        $marker = $this->tmpDir . '/repositories/precious/.git/candy-git-server-visibility';
        $this->assertFileExists($marker);
        \file_put_contents($this->tmpDir . '/repositories/precious/.git/candy-git-desc-note', 'do-not-touch');

        $again = $this->runBin(['repo', 'create', 'precious']);
        $this->assertSame(1, $again['rc'], 'the second create must exit non-zero: ' . $again['out']);
        $this->assertStringContainsString('already exists', $again['err']);

        // The refusal happens BEFORE init(): nothing was rewritten, and
        // the published marker survived an unqualified second create.
        $this->assertFileExists($this->tmpDir . '/repositories/precious/.git/candy-git-desc-note');
        $this->assertSame("public\n", \file_get_contents($marker), 'the refuse path must not touch the marker');
    }

    public function testCreateRefusesEvenWhenTheSecondRunCarriesPublic(): void
    {
        $this->assertSame(0, $this->runBin(['repo', 'create', 'twice'])['rc']);

        $r = $this->runBin(['repo', 'create', 'twice', '--public']);
        $this->assertSame(1, $r['rc'], 'an explicit --public does not license clobbering an existing repo');
        $this->assertFileDoesNotExist($this->tmpDir . '/repositories/twice/.git/candy-git-server-visibility', 'the refused create must not mint the marker it wanted');
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workspace;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Workspace\GitRunner;
use SugarCraft\Crush\Workspace\ShadowRepo;
use SugarCraft\Crush\Workspace\WorkspaceCheckpointer;

/**
 * Item 3.A-1 for a project that is not a git work tree: the snapshot goes
 * into a private git directory beside the session store, and the project
 * directory itself is never written to.
 *
 * @see ShadowRepo
 */
final class ShadowRepoTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmp;

    private string $project;

    private string $store;

    protected function setUp(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('git is not installed');
        }
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_shadow_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o700, true);
        $this->useHomeSandbox($this->tmp . '/home');
        WorkspaceCheckpointer::forgetDisabled();

        $this->project = $this->tmp . '/plain';
        mkdir($this->project . '/src', 0o700, true);
        file_put_contents($this->project . '/src/app.txt', "v1\n");
        file_put_contents($this->project . '/README', "readme\n");
        $this->store = $this->tmp . '/store/checkpoints';
    }

    protected function tearDown(): void
    {
        WorkspaceCheckpointer::forgetDisabled();
        $this->restoreHomeSandbox();
        self::rmrf($this->tmp);
    }

    public function testTheShadowLivesUnderTheBaseKeyedByTheProjectsRealPath(): void
    {
        $shadow = ShadowRepo::forRoot($this->project . '/src/..', $this->store);

        self::assertNotNull($shadow);
        self::assertSame($this->store . '/' . sha1($this->project), $shadow->gitDir());
        self::assertSame($this->project, $shadow->workTree());
        self::assertNull(ShadowRepo::forRoot($this->tmp . '/missing', $this->store));
    }

    public function testANonRepositoryIsSnapshottedWithoutWritingIntoIt(): void
    {
        $before = self::listing($this->project);

        $workspace = WorkspaceCheckpointer::new($this->project)->withShadowBase($this->store)->capture('sess-s', 4);

        self::assertSame('captured', $workspace['status'], (string) ($workspace['reason'] ?? ''));
        self::assertSame('shadow', $workspace['kind']);
        self::assertSame('snapshot', $workspace['layout']);
        self::assertNull($workspace['base']);
        self::assertSame(2, $workspace['untracked']);
        self::assertSame($this->store . '/' . sha1($this->project), $workspace['gitDir']);
        self::assertSame($before, self::listing($this->project), 'no .git, no index, nothing in the project');
        self::assertSame($this->project . "\n", file_get_contents($workspace['gitDir'] . '/' . ShadowRepo::ROOT_MARKER));
        self::assertStringContainsString('node_modules/', (string) file_get_contents($workspace['gitDir'] . '/info/exclude'));
        self::assertSame(
            $workspace['sha'],
            $this->gitDirRun($workspace['gitDir'], 'rev-parse', 'refs/sugar-crush/checkpoints/sess-s/4'),
        );
        self::assertSame('', $this->gitDirRun($workspace['gitDir'], 'show', '-s', '--format=%P', $workspace['sha']), 'parentless, so a dropped ref frees it');
    }

    public function testRestoreRewindsTheProjectFiles(): void
    {
        $checkpointer = WorkspaceCheckpointer::new($this->project)->withShadowBase($this->store);
        $first = $checkpointer->capture('sess-s', 0);

        file_put_contents($this->project . '/src/app.txt', "v2\n");
        file_put_contents($this->project . '/src/extra.txt', "extra\n");
        unlink($this->project . '/README');
        $second = $checkpointer->capture('sess-s', 1);
        self::assertSame('captured', $second['status']);

        $result = $checkpointer->restore($first);

        self::assertSame('restored', $result['status'], $result['reason']);
        self::assertSame("v1\n", file_get_contents($this->project . '/src/app.txt'));
        self::assertSame("readme\n", file_get_contents($this->project . '/README'));
        self::assertFileDoesNotExist($this->project . '/src/extra.txt');
        self::assertSame([], $checkpointer->changes($first));

        // And forward again: the later snapshot is still pinned.
        self::assertSame('restored', $checkpointer->restore($second)['status']);
        self::assertSame("v2\n", file_get_contents($this->project . '/src/app.txt'));
        self::assertFileDoesNotExist($this->project . '/README');
    }

    public function testWithoutAShadowBaseANonRepositoryIsRefused(): void
    {
        $workspace = WorkspaceCheckpointer::new($this->project)->capture('sess-s', 0);

        self::assertSame('refused', $workspace['status']);
        self::assertStringContainsString('not a git work tree', $workspace['reason']);
    }

    public function testAShadowBaseInsideTheProjectIsRefused(): void
    {
        $inside = $this->project . '/.cache/checkpoints';

        $shadow = ShadowRepo::forRoot($this->project, $inside);
        self::assertNotNull($shadow);
        self::assertStringContainsString('inside the project', (string) $shadow->refusal());

        $workspace = WorkspaceCheckpointer::new($this->project)->withShadowBase($inside)->capture('sess-s', 0);
        self::assertSame('refused', $workspace['status']);
        self::assertDirectoryDoesNotExist($inside);
    }

    public function testDroppingTheRefUnpinsTheSnapshot(): void
    {
        $workspace = WorkspaceCheckpointer::new($this->project)->withShadowBase($this->store)->capture('sess-s', 0);

        WorkspaceCheckpointer::dropRefs([$workspace]);

        self::assertSame('', $this->gitDirRun($workspace['gitDir'], 'for-each-ref'));
    }

    private function gitDirRun(string $gitDir, string ...$args): string
    {
        $command = 'git --git-dir=' . escapeshellarg($gitDir);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $out, $code);
        self::assertSame(0, $code, $command . "\n" . implode("\n", $out));

        return implode("\n", $out);
    }

    /** @return list<string> */
    private static function listing(string $dir): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $paths[] = substr((string) $entry, \strlen($dir));
        }
        sort($paths);

        return $paths;
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}

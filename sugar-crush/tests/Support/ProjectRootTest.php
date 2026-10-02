<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ProjectRoot;

/**
 * Audit 15d-13 (b): the root `.sugar-crush/*` lookups resolve against walks up
 * from a launch subdirectory to the repository, and nowhere else.
 */
final class ProjectRootTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_project_root_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o700, true);
        $this->useHomeSandbox($this->tmp . '/home');
        ProjectRoot::forget();
    }

    protected function tearDown(): void
    {
        ProjectRoot::forget();
        $this->restoreHomeSandbox();
        self::rmrf($this->tmp);
    }

    public function testASubdirectoryOfARepositoryResolvesToTheRepositoryRoot(): void
    {
        $repo = $this->repo('repo');
        mkdir($repo . '/src/deep', 0o700, true);

        self::assertSame($repo, ProjectRoot::resolve($repo . '/src/deep'));
    }

    public function testTheLaunchDirectoryWinsWhenItHoldsAMarker(): void
    {
        $repo = $this->repo('repo');
        foreach (['.sugar-crush' => 'dir', '.mcp.json' => 'file'] as $marker => $kind) {
            $dir = $repo . '/pkg-' . ltrim($marker, '.');
            mkdir($dir, 0o700, true);
            $kind === 'dir' ? mkdir($dir . '/' . $marker) : file_put_contents($dir . '/' . $marker, '{}');

            self::assertSame($dir, ProjectRoot::resolve($dir), $marker);
        }
    }

    public function testTheNearestMarkedAncestorInsideTheRepositoryWins(): void
    {
        $repo = $this->repo('mono');
        mkdir($repo . '/.sugar-crush');
        mkdir($repo . '/packages/app/.sugar-crush', 0o700, true);
        mkdir($repo . '/packages/app/src/lib', 0o700, true);

        self::assertSame($repo . '/packages/app', ProjectRoot::resolve($repo . '/packages/app/src/lib'));
    }

    public function testADirectoryOutsideAnyRepositoryIsItsOwnRoot(): void
    {
        $outer = $this->tmp . '/plain';
        mkdir($outer . '/.sugar-crush', 0o700, true);
        mkdir($outer . '/sub', 0o700, true);

        // No work tree bounds the walk, so it does not happen: the parent's
        // `.sugar-crush` is not this launch's project.
        self::assertSame($outer . '/sub', ProjectRoot::resolve($outer . '/sub'));
    }

    public function testARepositoryRootedAtTheHomeDirectoryIsNotWalked(): void
    {
        $home = $this->tmp . '/home';
        $this->git($home, 'init', '-q');
        mkdir($home . '/.sugar-crush', 0o700, true);
        mkdir($home . '/work/proj', 0o700, true);

        // A dotfiles repository at ~ would otherwise turn the USER config
        // directory into the project tier of everything under it.
        self::assertSame($home . '/work/proj', ProjectRoot::resolve($home . '/work/proj'));
    }

    public function testResolvingIsIdempotentAndLeavesOddInputsAlone(): void
    {
        $repo = $this->repo('idem');
        mkdir($repo . '/a/b', 0o700, true);

        $once = ProjectRoot::resolve($repo . '/a/b');
        self::assertSame($once, ProjectRoot::resolve($once));
        self::assertSame('', ProjectRoot::resolve(''));
        self::assertSame($this->tmp . '/missing', ProjectRoot::resolve($this->tmp . '/missing'));
    }

    private function repo(string $name): string
    {
        $repo = $this->tmp . '/' . $name;
        mkdir($repo, 0o700, true);
        $this->git($repo, 'init', '-q');

        return $repo;
    }

    private function git(string $dir, string ...$args): void
    {
        $command = 'git -C ' . escapeshellarg($dir);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
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

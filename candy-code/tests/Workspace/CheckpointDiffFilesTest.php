<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workspace;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Workspace\CheckpointDiff;
use SugarCraft\Code\Workspace\GitRunner;
use SugarCraft\Code\Workspace\WorkspaceCheckpointer;

/**
 * C-TUI-2.d: the per-file view the diff review is built on — files() joins
 * git's name-list with the bounded, screen-safe patch, and between() answers
 * checkpoint-against-checkpoint so a turn's diff cannot drift while it is
 * being read.
 *
 * @see CheckpointDiff::files()
 * @see CheckpointDiff::between()
 */
final class CheckpointDiffFilesTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmp;

    private string $repo;

    protected function setUp(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('git is not installed');
        }
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_cpfiles_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o700, true);
        $this->useHomeSandbox($this->tmp . '/home');
        WorkspaceCheckpointer::forgetDisabled();

        $this->repo = $this->tmp . '/repo';
        mkdir($this->repo, 0o700, true);
        $this->gitIn($this->repo, 'init', '-q');
        file_put_contents($this->repo . '/tracked.txt', "original\n");
        $this->gitIn($this->repo, 'add', '.');
        $this->gitIn($this->repo, 'commit', '-q', '-m', 'base');
    }

    protected function tearDown(): void
    {
        WorkspaceCheckpointer::forgetDisabled();
        $this->restoreHomeSandbox();
        self::rmrf($this->tmp);
    }

    public function testFilesCarriesPerFileCountsAndPatchSlices(): void
    {
        $checkpointer = WorkspaceCheckpointer::new($this->repo);
        $workspace = $checkpointer->capture('s', 0);
        file_put_contents($this->repo . '/tracked.txt', "edited\n");
        file_put_contents($this->repo . '/fresh.txt', "new\n");

        $diff = CheckpointDiff::of($checkpointer, $workspace);
        self::assertInstanceOf(CheckpointDiff::class, $diff);
        $files = $diff->files();

        self::assertSame(['fresh.txt', 'tracked.txt'], array_column($files, 'path'));
        self::assertSame(['A', 'M'], array_column($files, 'status'));
        self::assertSame([1, 1], array_column($files, 'added'));
        self::assertSame([0, 1], array_column($files, 'removed'));
        self::assertStringContainsString('+new', $files[0]['patch'], 'the slice holds its own added row (rtrimmed at the tail)');
        self::assertStringContainsString('fresh.txt', $files[0]['patch']);
        self::assertStringNotContainsString('fresh.txt', $files[1]['patch'], 'a slice holds only its own file');
        self::assertStringContainsString("-original\n+edited", $files[1]['patch']);
        self::assertStringStartsWith('diff --git a/tracked.txt b/tracked.txt', $files[1]['patch']);
    }

    public function testAnEmptyDiffHasNoFileRows(): void
    {
        $checkpointer = WorkspaceCheckpointer::new($this->repo);
        $diff = CheckpointDiff::of($checkpointer, $checkpointer->capture('s', 1));

        self::assertInstanceOf(CheckpointDiff::class, $diff);
        self::assertSame([], $diff->files());
    }

    public function testABinaryFileKeepsItsRowButNoCountedLines(): void
    {
        $checkpointer = WorkspaceCheckpointer::new($this->repo);
        $workspace = $checkpointer->capture('s', 0);
        file_put_contents($this->repo . '/blob.dat', "\x00\x01x");

        $diff = CheckpointDiff::of($checkpointer, $workspace);
        self::assertInstanceOf(CheckpointDiff::class, $diff);
        $files = $diff->files();

        self::assertCount(1, $files);
        self::assertSame('blob.dat', $files[0]['path']);
        self::assertSame('A', $files[0]['status']);
        self::assertSame(0, $files[0]['added']);
        self::assertSame(0, $files[0]['removed']);
        self::assertStringContainsString('Binary files', $files[0]['patch']);
        self::assertStringNotContainsString("\x00", $files[0]['patch'], 'the screen-safe pass runs before the slice');
    }

    public function testBetweenShowsTheTurnOnlyTheWorktreeStaysOutOfIt(): void
    {
        $checkpointer = WorkspaceCheckpointer::new($this->repo);
        $older = $checkpointer->capture('s', 0);
        file_put_contents($this->repo . '/a.txt', "turn-one\n");
        $newer = $checkpointer->capture('s', 1);
        file_put_contents($this->repo . '/b.txt', "turn-two\n");

        $diff = CheckpointDiff::between($checkpointer, $older, $newer);
        self::assertInstanceOf(CheckpointDiff::class, $diff);

        self::assertSame(['a.txt'], array_column($diff->files(), 'path'), 'b.txt is later work, not this turn');
        self::assertStringNotContainsString('b.txt', $diff->patch);
        self::assertStringContainsString('+turn-one', $diff->patch);
    }

    public function testBetweenCarriesTheSameScreenSafeAndBoundedTreatment(): void
    {
        $checkpointer = WorkspaceCheckpointer::new($this->repo);
        $older = $checkpointer->capture('s', 0);
        file_put_contents($this->repo . '/ctl.txt', "\x01run\x07\n");
        $newer = $checkpointer->capture('s', 1);

        $diff = CheckpointDiff::between($checkpointer, $older, $newer);
        self::assertInstanceOf(CheckpointDiff::class, $diff);

        self::assertStringContainsString("\u{FFFD}run\u{FFFD}", $diff->patch);
        self::assertStringNotContainsString(chr(1), $diff->patch);
        self::assertStringNotContainsString(chr(7), $diff->patch);
        foreach ($diff->files() as $file) {
            self::assertStringNotContainsString(chr(1), $file['patch']);
        }
    }

    public function testBetweenRefusesAWildsideSnapshotAndACrossRepositoryPair(): void
    {
        $checkpointer = WorkspaceCheckpointer::new($this->repo);
        $captured = $checkpointer->capture('s', 0);

        self::assertSame(
            'this checkpoint has no workspace snapshot: checkpoints are not taken in the home directory',
            CheckpointDiff::between($checkpointer, ['status' => 'refused', 'reason' => 'checkpoints are not taken in the home directory'], $captured),
        );

        $repo2 = $this->tmp . '/repo2';
        mkdir($repo2, 0o700, true);
        $this->gitIn($repo2, 'init', '-q');
        file_put_contents($repo2 . '/other.txt', "there\n");
        $other = WorkspaceCheckpointer::new($repo2)->capture('s', 0);

        self::assertSame(
            'the two checkpoints do not share a repository',
            CheckpointDiff::between($checkpointer, $captured, $other),
        );
    }

    public function testATruncatedTailKeepsItsGitRowWithZeroCounts(): void
    {
        $old = '';
        for ($i = 1; $i <= 2100; $i++) {
            $old .= 'line ' . $i . "\n";
        }
        file_put_contents($this->repo . '/huge.txt', $old);
        file_put_contents($this->repo . '/tail.txt', "first\n");
        $this->gitIn($this->repo, 'add', '.');
        $this->gitIn($this->repo, 'commit', '-q', '-m', 'long');

        $checkpointer = WorkspaceCheckpointer::new($this->repo);
        $workspace = $checkpointer->capture('s', 0);

        $new = '';
        for ($i = 1; $i <= 2100; $i++) {
            $new .= 'moved ' . $i . "\n";
        }
        file_put_contents($this->repo . '/huge.txt', $new);
        file_put_contents($this->repo . '/tail.txt', "second\n");

        $diff = CheckpointDiff::of($checkpointer, $workspace);
        self::assertInstanceOf(CheckpointDiff::class, $diff);
        self::assertGreaterThan(0, $diff->omittedLines);

        $files = $diff->files();
        self::assertSame(['huge.txt', 'tail.txt'], array_column($files, 'path'), 'the cap cuts bodies, never the git name-list');
        self::assertGreaterThan(0, $files[0]['removed']);
        self::assertLessThan(2100, $files[0]['removed'], 'the cut tail loses the lines the cap dropped');
        self::assertLessThanOrEqual(CheckpointDiff::MAX_LINES, $files[0]['added'] + $files[0]['removed']);
        self::assertSame(['status' => 'M', 'path' => 'tail.txt', 'added' => 0, 'removed' => 0, 'patch' => ''], $files[1], 'a fully cut section keeps its row and no counts');
    }

    private function gitIn(string $dir, string ...$args): string
    {
        $command = 'git -c user.name=t -c user.email=t@example.invalid -c commit.gpgSign=false -C ' . escapeshellarg($dir);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $out, $code);
        self::assertSame(0, $code, $command . "\n" . implode("\n", $out));

        return implode("\n", $out);
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

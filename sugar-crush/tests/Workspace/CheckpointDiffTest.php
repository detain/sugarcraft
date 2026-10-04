<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workspace;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Workspace\CheckpointDiff;
use SugarCraft\Crush\Workspace\GitRunner;
use SugarCraft\Crush\Workspace\WorkspaceCheckpointer;

/**
 * Item 3.A-2: the `/diff` primitive — exactly what a restore of the
 * checkpoint would undo, and still answerable after HEAD has moved, which
 * refuses a restore but changes nothing a diff does.
 *
 * @see CheckpointDiff
 */
final class CheckpointDiffTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmp;

    private string $repo;

    protected function setUp(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('git is not installed');
        }
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_cpdiff_' . bin2hex(random_bytes(6));
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

    public function testItListsAndPatchesWhatARestoreWouldUndoAndNothingIgnored(): void
    {
        $checkpointer = WorkspaceCheckpointer::new($this->repo);
        $workspace = $checkpointer->capture('s', 0);
        file_put_contents($this->repo . '/tracked.txt', "edited\n");
        file_put_contents($this->repo . '/fresh.txt', "new\n");
        file_put_contents($this->repo . '/debug.log', "excluded by the snapshot limits\n");

        $diff = CheckpointDiff::of($checkpointer, $workspace);

        self::assertInstanceOf(CheckpointDiff::class, $diff);
        self::assertSame(['A fresh.txt', 'M tracked.txt'], $diff->summaryLines());
        self::assertStringContainsString("-original\n+edited", $diff->patch);
        self::assertStringNotContainsString('debug.log', $diff->patch);
        self::assertSame(0, $diff->omittedLines);
    }

    public function testAMovedHeadStillDiffsWhileARestoreIsRefused(): void
    {
        $checkpointer = WorkspaceCheckpointer::new($this->repo);
        $workspace = $checkpointer->capture('s', 0);
        file_put_contents($this->repo . '/tracked.txt', "committed\n");
        $this->gitIn($this->repo, 'commit', '-q', '-am', 'moved');

        self::assertIsString($checkpointer->changes($workspace));
        $diff = CheckpointDiff::of($checkpointer, $workspace);

        self::assertInstanceOf(CheckpointDiff::class, $diff);
        self::assertSame(['M tracked.txt'], $diff->summaryLines());
    }

    public function testNoSnapshotIsAReasonAndAMatchIsEmpty(): void
    {
        $checkpointer = WorkspaceCheckpointer::new($this->repo);

        self::assertSame(
            'this checkpoint has no workspace snapshot: checkpoints are not taken in the home directory',
            CheckpointDiff::of($checkpointer, ['status' => 'refused', 'reason' => 'checkpoints are not taken in the home directory']),
        );
        $diff = CheckpointDiff::of($checkpointer, $checkpointer->capture('s', 1));
        self::assertInstanceOf(CheckpointDiff::class, $diff);
        self::assertTrue($diff->isEmpty());
        self::assertSame('', $diff->patch);
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

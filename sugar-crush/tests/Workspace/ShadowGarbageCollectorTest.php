<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workspace;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Workspace\GitRunner;
use SugarCraft\Crush\Workspace\ShadowGarbageCollector;
use SugarCraft\Crush\Workspace\WorkspaceCheckpointer;

/**
 * Item 3.A-2 (the 3.A-1 hand-off): a shadow repository's dropped snapshots
 * are collected — at most once a day, only past a loose-object threshold, and
 * never in a repository that is not a shadow.
 *
 * @see ShadowGarbageCollector
 */
final class ShadowGarbageCollectorTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmp;

    private string $project;

    protected function setUp(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('git is not installed');
        }
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_shgc_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o700, true);
        $this->useHomeSandbox($this->tmp . '/home');
        WorkspaceCheckpointer::forgetDisabled();

        $this->project = $this->tmp . '/plain';
        mkdir($this->project, 0o700, true);
        file_put_contents($this->project . '/app.txt', "v1\n");
    }

    protected function tearDown(): void
    {
        WorkspaceCheckpointer::forgetDisabled();
        $this->restoreHomeSandbox();
        self::rmrf($this->tmp);
    }

    public function testADroppedSnapshotIsPrunedAndTheNextRunWaitsADay(): void
    {
        $workspace = WorkspaceCheckpointer::new($this->project)->withShadowBase($this->tmp . '/store')->capture('s', 0);
        self::assertSame('captured', $workspace['status'], (string) ($workspace['reason'] ?? ''));
        $gitDir = (string) $workspace['gitDir'];
        WorkspaceCheckpointer::dropRefs([$workspace]);
        self::assertTrue($this->objectExists($gitDir, $workspace['sha']), 'fixture: dropping the ref leaves the commit behind');

        $result = ShadowGarbageCollector::collectIfDue($gitDir, 0, 'now');

        self::assertSame(ShadowGarbageCollector::STATUS_COLLECTED, $result['status'], $result['reason']);
        self::assertFalse($this->objectExists($gitDir, $workspace['sha']), 'the unreachable snapshot is gone');
        self::assertFileExists($gitDir . '/' . ShadowGarbageCollector::STAMP_FILE);

        self::assertSame(ShadowGarbageCollector::STATUS_NOT_DUE, ShadowGarbageCollector::collectIfDue($gitDir, 0, 'now')['status']);
        self::assertSame(
            ShadowGarbageCollector::STATUS_COLLECTED,
            ShadowGarbageCollector::collectIfDue($gitDir, 0, 'now', time() + ShadowGarbageCollector::INTERVAL_SECONDS + 1)['status'],
            'a day later it runs again',
        );
    }

    public function testAPinnedSnapshotSurvivesAndAFewLooseObjectsAreNotWorthARun(): void
    {
        $workspace = WorkspaceCheckpointer::new($this->project)->withShadowBase($this->tmp . '/store')->capture('s', 0);
        $gitDir = (string) $workspace['gitDir'];

        self::assertSame(ShadowGarbageCollector::STATUS_NOT_DUE, ShadowGarbageCollector::collectIfDue($gitDir)['status']);
        self::assertSame(
            ShadowGarbageCollector::STATUS_COLLECTED,
            ShadowGarbageCollector::collectIfDue($gitDir, 0, 'now', time() + ShadowGarbageCollector::INTERVAL_SECONDS + 1)['status'],
        );
        self::assertTrue($this->objectExists($gitDir, $workspace['sha']), 'a snapshot its ref still pins is kept');
    }

    public function testARepositoryThatIsNotAShadowIsNeverCollected(): void
    {
        $this->gitIn($this->project, 'init', '-q');

        $result = ShadowGarbageCollector::collectIfDue($this->project . '/.git', 0, 'now');

        self::assertSame(ShadowGarbageCollector::STATUS_SKIPPED, $result['status']);
        self::assertFileDoesNotExist($this->project . '/.git/' . ShadowGarbageCollector::STAMP_FILE);
    }

    private function objectExists(string $gitDir, string $sha): bool
    {
        // fd 2 joins fd 1, which is captured: "missing object" is the answer
        // being asked for, not output for the runner.
        exec('git --git-dir=' . escapeshellarg($gitDir) . ' cat-file -e ' . escapeshellarg($sha) . ' 2>&1', $out, $code);

        return $code === 0;
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

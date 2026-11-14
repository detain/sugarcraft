<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Git;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Real-`git` integration coverage for Git::branches() and Git::log() — the
 * two read lanes whose contract was previously pinned only by fake drivers
 * (candy-git-017/026/072). The fakes replayed whatever argv the code sends,
 * so the literal-backslash-t for-each-ref format (CG-01) and the tab-unsafe
 * log field order survived every unit suite; only talking to actual git
 * catches this class.
 */
final class GitBranchesLogIntegrationTest extends TestCase
{
    use RecursiveDirCleanup;

    private string $cwd;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/gitbrlog_' . uniqid();
        mkdir($this->cwd);
        exec('git init ' . escapeshellarg($this->cwd) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.name 'Test' 2>/dev/null");
    }

    protected function tearDown(): void
    {
        if (isset($this->cwd)) {
            $this->removeDir($this->cwd);
        }
    }

    private function commit(string $message): void
    {
        $file = $this->cwd . '/' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($file, 'x');
        exec('git -C ' . escapeshellarg($this->cwd) . ' add -A 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . ' commit -m ' . escapeshellarg($message) . ' 2>/dev/null');
    }

    /**
     * @return array<int, array{name: string, sha: string, current: bool}>
     */
    private function branches(): array
    {
        return (new Git($this->cwd))->branches();
    }

    public function testBranchesSplitsNameAndShaOnARealTab(): void
    {
        $this->commit('first');
        exec('git -C ' . escapeshellarg($this->cwd) . ' branch feat-a 2>/dev/null');

        $rows = $this->branches();
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        // Pre-CG-01 the '\t' reached git literally, so the key was
        // "feat-a\t<sha>" and sha was ''. An exact-name lookup is the red
        // signal for that shape.
        self::assertArrayHasKey('feat-a', $byName);
        self::assertArrayHasKey('master', $byName);
        self::assertMatchesRegularExpression('/^[0-9a-f]{7,40}$/', $byName['feat-a']['sha']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{7,40}$/', $byName['master']['sha']);
        self::assertTrue($byName['master']['current']);
        self::assertFalse($byName['feat-a']['current']);
    }

    public function testBranchesOnDetachedHeadAnswersWithoutCurrentFlag(): void
    {
        $this->commit('first');
        exec('git -C ' . escapeshellarg($this->cwd) . ' branch feat-a 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . ' checkout --detach 2>/dev/null');

        $rows = $this->branches();
        self::assertNotEmpty($rows, 'branches still lists locals while HEAD is detached');
        foreach ($rows as $row) {
            self::assertFalse($row['current'], 'no local branch carries the * marker under a detached HEAD');
        }
    }

    public function testLogNewestRowCarriesShaAuthorAgoAndSubject(): void
    {
        $this->commit('older subject');
        $this->commit('newest subject');

        $rows = (new Git($this->cwd))->log(5);
        self::assertNotEmpty($rows);

        $newest = $rows[0];
        self::assertSame('newest subject', $newest['subject']);
        self::assertSame('Test', $newest['author']);
        self::assertNotSame('', $newest['ago'], 'relative-age column must be populated');
        $expectedSha = trim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' rev-parse --short HEAD'));
        self::assertSame($expectedSha, $newest['sha']);
    }

    public function testLogWithTabInsideSubjectKeepsItWhole(): void
    {
        $this->commit("sub\tject with a real tab");

        $rows = (new Git($this->cwd))->log(5);
        self::assertNotEmpty($rows);
        // The reorder (subject LAST) plus explode's limit-4 is what makes
        // this whole; with %s mid-format the tail leaked into author.
        self::assertSame("sub\tject with a real tab", $rows[0]['subject']);
        self::assertSame('Test', $rows[0]['author']);
    }

    public function testLogOnUnbornHeadReturnsEmptyWithoutThrowing(): void
    {
        // candy-git-026: `git log` fatal-exits on a repo with zero commits;
        // the history pane's honest answer is an empty list, not an error.
        $rows = (new Git($this->cwd))->log(5);
        self::assertSame([], $rows);
    }

    public function testBranchesOnUnbornHeadIsEmpty(): void
    {
        self::assertSame([], $this->branches());
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Git\App;
use SugarCraft\Git\Git;
use SugarCraft\Git\Tests\Concerns\GitSpawn;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Real-git witness for the conflict-marker sweep guard (candy-git-090):
 * a divergent merge produces a genuine UU row whose bytes carry the
 * <<<<<<< markers, and only real git proves that `git add -A` would
 * stage those markers silently. The arm and its answer are driven through
 * the dispatch exactly as the user drives them.
 */
final class ConflictMarkerIntegrationTest extends TestCase
{
    use GitSpawn;
    use RecursiveDirCleanup;

    private string $cwd;

    protected function setUp(): void
    {
        if (self::gitSpawn(['--version'])['exit'] !== 0) {
            $this->markTestSkipped('git not spawnable');
        }
        $this->cwd = sys_get_temp_dir() . '/convmark_' . uniqid();
        mkdir($this->cwd, 0o777, true);
        $this->gitMust(['init', '-q', '-b', 'main', $this->cwd]);
        $this->gitMust(['-C', $this->cwd, 'config', 'user.email', 'test@test.com']);
        $this->gitMust(['-C', $this->cwd, 'config', 'user.name', 'Test']);
        $this->commitLine('shared base line');
        $this->gitMust(['-C', $this->cwd, 'checkout', '-q', '-b', 'other']);
        $this->commitLine('other branch line');
        $this->gitMust(['-C', $this->cwd, 'checkout', '-q', 'main']);
        $this->commitLine('main branch line');
        // The merge conflicts; git exits nonzero by design here, so this is
        // a raw gitSpawn, never gitMust.
        self::gitSpawn(['-C', $this->cwd, 'merge', '-q', 'other']);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cwd);
    }

    private function commitLine(string $line): void
    {
        file_put_contents($this->cwd . '/f.txt', $line . "\n");
        $this->gitMust(['-C', $this->cwd, 'add', '--', 'f.txt']);
        $this->gitMust(['-C', $this->cwd, 'commit', '-q', '-m', $line]);
    }

    public function test_the_sweep_arms_on_real_markers_and_the_answer_stages_through(): void
    {
        $app = App::start(new Git($this->cwd));

        // Confirm the scenario the guard answers: git itself reports the
        // file unmerged AND the bytes carry the markers.
        $rows = (new Git($this->cwd))->status();
        $unmerged = false;
        foreach ($rows as $row) {
            if (($row['path'] ?? null) === 'f.txt' && ($row['index_status'] ?? '') === 'U') {
                $unmerged = true;
            }
        }
        $this->assertTrue($unmerged, 'the divergent merge must leave a real UU row');
        $this->assertStringContainsString('<<<<<<<', (string) file_get_contents($this->cwd . '/f.txt'));

        [$armed,] = $app->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertNotNull($armed->pendingConfirm, 'the marker-bearing sweep must arm instead of staging');
        $this->assertStringContainsString('stageall-markers', (string) $armed->pendingConfirm);
        $this->assertStringContainsString('f.txt', (string) $armed->notice);

        [$swept,] = $armed->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertNull($swept->pendingConfirm, 'the answering press clears the arm');

        $after = (new Git($this->cwd))->status();
        foreach ($after as $row) {
            if (($row['path'] ?? null) === 'f.txt') {
                $this->assertNotSame('U', $row['index_status'], 'the answered sweep stages the file - the same git add -A parity the warning exists to disclose');
            }
        }
    }
}

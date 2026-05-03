<?php

declare(strict_types=1);

namespace CandyCore\Stash\Tests;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Stash\App;
use CandyCore\Stash\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function git(): FixtureGit
    {
        return new FixtureGit(
            statusRows: [
                ['branch_summary' => 'main...origin/main [ahead 1]'],
                ['index_status' => 'M', 'work_status' => ' ', 'path' => 'src/A.php'],
                ['index_status' => ' ', 'work_status' => 'M', 'path' => 'src/B.php'],
                ['index_status' => ' ', 'work_status' => ' ', 'path' => 'src/C.php'],
            ],
            branchRows: [
                ['name' => 'main', 'sha' => 'abc1', 'current' => true],
                ['name' => 'feature', 'sha' => 'def2', 'current' => false],
            ],
            logRows: [
                ['sha' => 'abc1', 'subject' => 'A short subject', 'author' => 'Joe', 'ago' => '5m'],
                ['sha' => 'def2', 'subject' => str_repeat('long-subject-line ', 5), 'author' => 'Joe', 'ago' => '1h'],
            ],
        );
    }

    public function testRenderIncludesHeaderAndBranchSummary(): void
    {
        $a = App::start($this->git());
        $out = Renderer::render($a);
        $this->assertStringContainsString('SugarStash', $out);
        $this->assertStringContainsString('main...origin/main', $out);
    }

    public function testRenderShowsHelpFooter(): void
    {
        $out = Renderer::render(App::start($this->git()));
        $this->assertStringContainsString('switch pane', $out);
        $this->assertStringContainsString('quit', $out);
    }

    public function testRenderShowsAllPanesContent(): void
    {
        $out = Renderer::render(App::start($this->git()));
        $this->assertStringContainsString('src/A.php', $out);
        $this->assertStringContainsString('main', $out);
        $this->assertStringContainsString('abc1', $out);
    }

    public function testRenderHandlesEmptyState(): void
    {
        $g = new FixtureGit([], [], []);
        $a = App::start($g);
        $out = Renderer::render($a);
        $this->assertStringContainsString('clean working tree', $out);
        $this->assertStringContainsString('no branches', $out);
        $this->assertStringContainsString('empty log', $out);
    }

    public function testRenderShowsErrorBanner(): void
    {
        $g = new class implements \CandyCore\Stash\GitDriver {
            public function status(): array   { throw new \RuntimeException('fatal: not a git repository'); }
            public function branches(): array { return []; }
            public function log(int $limit = 25): array { return []; }
            public function stage(string $path): void {}
            public function unstage(string $path): void {}
        };
        $a = App::start($g);
        $out = Renderer::render($a);
        $this->assertStringContainsString('error', $out);
        $this->assertStringContainsString('not a git repository', $out);
    }

    public function testRenderTruncatesLongSubjects(): void
    {
        $out = Renderer::render(App::start($this->git()));
        // The 'long-subject-line' subject is 90 chars; should be cut to 25 + '…'.
        $this->assertStringContainsString('…', $out);
    }
}

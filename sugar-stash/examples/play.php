<?php

declare(strict_types=1);

/**
 * Run sugar-stash against an in-memory fixture so the demo doesn't
 * require a real working tree:
 *   php examples/play.php
 */
require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Stash\App;
use CandyCore\Stash\GitDriver;

$fixture = new class implements GitDriver {
    public function status(): array
    {
        return [
            ['branch_summary' => 'main...origin/main [ahead 2, behind 1]'],
            ['index_status' => 'M', 'work_status' => ' ', 'path' => 'src/App.php'],
            ['index_status' => ' ', 'work_status' => 'M', 'path' => 'src/Renderer.php'],
            ['index_status' => '?', 'work_status' => '?', 'path' => 'docs/notes.md'],
        ];
    }
    public function branches(): array
    {
        return [
            ['name' => 'main',           'sha' => 'a1b2c3d', 'current' => true],
            ['name' => 'feature/charts', 'sha' => 'd4e5f6a', 'current' => false],
            ['name' => 'fix/regression', 'sha' => '7890abc', 'current' => false],
        ];
    }
    public function log(int $limit = 25): array
    {
        return [
            ['sha' => 'a1b2c3d', 'subject' => 'add three-pane layout',     'author' => 'Joe', 'ago' => '4m ago'],
            ['sha' => 'b2c3d4e', 'subject' => 'wire up GitDriver fixture', 'author' => 'Joe', 'ago' => '6m ago'],
            ['sha' => 'c3d4e5f', 'subject' => 'parse porcelain v1 output', 'author' => 'Joe', 'ago' => '8m ago'],
            ['sha' => 'd4e5f6a', 'subject' => 'wave 1 — status pane only', 'author' => 'Joe', 'ago' => '12m ago'],
        ];
    }
    public function stage(string $path): void   {}
    public function unstage(string $path): void {}
};

(new Program(App::start($fixture), new ProgramOptions(useAltScreen: true)))->run();

<?php

declare(strict_types=1);

/**
 * Hermetic staging demo for the .vhs/stage.tape render: the s key stages and
 * unstages rows that visibly change, without depending on a populated git
 * repository at render time (AGENTS VHS law: tapes drive `php examples/<demo>.php`,
 * never a live `./bin/candy-git` against the CI checkout — the old stage tape
 * produced environment-dependent output, candy-git-087).
 *
 * Run directly:
 *   php examples/stage.php
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Git\App;
use SugarCraft\Git\GitDriver;

/**
 * Self-contained on purpose: ExamplesSmokeTest extracts this declaration and
 * compiles it against the live GitDriver interface in a subprocess, so the
 * body may only reference globals (no imports beyond GitDriver itself).
 */
$fixture = new class implements GitDriver {
    /** @var list<array{index_status: string, work_status: string, path: string}> */
    private array $rows = [
        ['index_status' => ' ', 'work_status' => 'M', 'path' => 'src/Renderer.php'],
        ['index_status' => ' ', 'work_status' => 'M', 'path' => 'src/DiffViewer.php'],
        ['index_status' => '?', 'work_status' => '?', 'path' => 'docs/notes.md'],
    ];

    public function status(): array
    {
        return array_merge(
            [['branch_summary' => 'main...origin/main']],
            $this->rows,
        );
    }

    public function branches(): array
    {
        return [
            ['name' => 'main', 'sha' => 'a1b2c3d', 'current' => true],
        ];
    }

    public function log(int $limit = 25): array
    {
        return [
            ['sha' => 'a1b2c3d', 'subject' => 'fixture for the staging tape', 'author' => 'Joe', 'ago' => '1m ago'],
        ];
    }

    public function stage(string $path): void
    {
        foreach ($this->rows as $i => $row) {
            if ($row['path'] === $path) {
                $this->rows[$i] = $row['work_status'] === '?'
                    ? ['index_status' => 'A', 'work_status' => ' ', 'path' => $path]
                    : ['index_status' => 'M', 'work_status' => ' ', 'path' => $path];
            }
        }
    }

    public function unstage(string $path): void
    {
        foreach ($this->rows as $i => $row) {
            if ($row['path'] === $path) {
                $this->rows[$i] = $row['index_status'] === 'A'
                    ? ['index_status' => '?', 'work_status' => '?', 'path' => $path]
                    : ['index_status' => ' ', 'work_status' => 'M', 'path' => $path];
            }
        }
    }

    public function checkout(string $branch): void {}
    public function checkoutRemote(string $remoteBranch): void {}
    public function commit(string $message): void {}
    public function stageAll(): void {}
    public function diff(string $path, bool $cached = false): array { return []; }
    public function discard(string $path): void {}
    public function amend(): void {}
    public function stagePatch(string $path, string $hunk): void {}
    public function unstageAll(): void {}
    public function unstagePatch(string $path, string $hunk): void {}
    public function createBranch(string $name): void {}
    public function deleteBranch(string $name): void {}
    public function restoreBranch(string $name, string $sha): void {}
    public function merge(string $branch): void {}
    public function fetch(): void {}
    public function pull(): void {}
    public function push(): void {}
    public function rebaseContinue(): void {}
    public function rebaseAbort(): void {}
    public function rebaseSkip(): void {}
    public function reset(): void {}
    public function stashList(): array { return []; }
    public function stashApply(string $stashRef): void {}
    public function stashDrop(string $stashRef): void {}
    public function cherryPick(string $commit): void {}
    public function cherryPickContinue(): void {}
    public function cherryPickAbort(): void {}
    public function worktreeList(): array { return []; }
    public function worktreeAdd(string $path, string $branch): void {}
    public function worktreeRemove(string $path): void {}
    public function rebaseInProgress(): bool { return false; }
    public function sequenceState(): string { return ''; }
};

(new Program(App::start($fixture), new ProgramOptions(useAltScreen: true)))->run();

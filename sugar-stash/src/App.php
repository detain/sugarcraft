<?php

declare(strict_types=1);

namespace CandyCore\Stash;

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

/**
 * Three-pane git TUI: status (left), branches (top right), log (bottom
 * right). Tab cycles focus; up/down moves the cursor in the active
 * pane; `s` stages / unstages the highlighted status entry; `R`
 * refreshes from disk.
 */
final class App implements Model
{
    /**
     * @param list<array<string,mixed>> $status
     * @param list<array{name:string,sha:string,current:bool}> $branches
     * @param list<array{sha:string,subject:string,author:string,ago:string}> $log
     */
    public function __construct(
        public readonly GitDriver $git,
        public readonly array $status   = [],
        public readonly array $branches = [],
        public readonly array $log      = [],
        public readonly string $branchSummary = '',
        public readonly Pane $pane = Pane::Status,
        public readonly int $statusCursor   = 0,
        public readonly int $branchesCursor = 0,
        public readonly int $logCursor      = 0,
        public readonly ?string $error = null,
    ) {}

    public static function start(GitDriver $git): self
    {
        return (new self($git))->refresh();
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q')
            || ($msg->ctrl && $msg->rune === 'c')) {
            return [$this, Cmd::quit()];
        }
        if ($msg->type === KeyType::Tab) {
            return [$this->withPane($this->pane->next()), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'R') {
            return [$this->refresh(), null];
        }
        if ($msg->type === KeyType::Up
            || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return [$this->moveCursor(-1), null];
        }
        if ($msg->type === KeyType::Down
            || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return [$this->moveCursor(+1), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 's') {
            return [$this->toggleStage(), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    public function refresh(): self
    {
        try {
            $status   = $this->git->status();
            $branches = $this->git->branches();
            $log      = $this->git->log(20);
            $summary  = '';
            $rows = [];
            foreach ($status as $row) {
                if (isset($row['branch_summary'])) {
                    $summary = $row['branch_summary'];
                    continue;
                }
                $rows[] = $row;
            }
            return new self(
                git: $this->git,
                status: $rows,
                branches: $branches,
                log: $log,
                branchSummary: $summary,
                pane: $this->pane,
                statusCursor:   min($this->statusCursor,   max(0, count($rows) - 1)),
                branchesCursor: min($this->branchesCursor, max(0, count($branches) - 1)),
                logCursor:      min($this->logCursor,      max(0, count($log) - 1)),
                error: null,
            );
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }

    private function withPane(Pane $p): self
    {
        return new self(
            $this->git, $this->status, $this->branches, $this->log,
            $this->branchSummary, $p,
            $this->statusCursor, $this->branchesCursor, $this->logCursor,
            $this->error,
        );
    }

    private function withError(string $msg): self
    {
        return new self(
            $this->git, $this->status, $this->branches, $this->log,
            $this->branchSummary, $this->pane,
            $this->statusCursor, $this->branchesCursor, $this->logCursor,
            $msg,
        );
    }

    private function moveCursor(int $dir): self
    {
        return match ($this->pane) {
            Pane::Status => new self(
                $this->git, $this->status, $this->branches, $this->log,
                $this->branchSummary, $this->pane,
                $this->clamp($this->statusCursor + $dir, count($this->status)),
                $this->branchesCursor, $this->logCursor, $this->error,
            ),
            Pane::Branches => new self(
                $this->git, $this->status, $this->branches, $this->log,
                $this->branchSummary, $this->pane,
                $this->statusCursor,
                $this->clamp($this->branchesCursor + $dir, count($this->branches)),
                $this->logCursor, $this->error,
            ),
            Pane::Log => new self(
                $this->git, $this->status, $this->branches, $this->log,
                $this->branchSummary, $this->pane,
                $this->statusCursor, $this->branchesCursor,
                $this->clamp($this->logCursor + $dir, count($this->log)),
                $this->error,
            ),
        };
    }

    private function clamp(int $i, int $size): int
    {
        if ($size <= 0) return 0;
        return max(0, min($size - 1, $i));
    }

    private function toggleStage(): self
    {
        if ($this->pane !== Pane::Status) {
            return $this;
        }
        $row = $this->status[$this->statusCursor] ?? null;
        if (!is_array($row) || !isset($row['path'])) {
            return $this;
        }
        try {
            // If already staged (index_status != ' '), unstage; else stage.
            $isStaged = ($row['index_status'] ?? ' ') !== ' ';
            if ($isStaged) {
                $this->git->unstage($row['path']);
            } else {
                $this->git->stage($row['path']);
            }
            return $this->refresh();
        } catch (\RuntimeException $e) {
            return $this->withError($e->getMessage());
        }
    }
}

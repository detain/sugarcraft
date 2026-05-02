<?php

declare(strict_types=1);

namespace CandyCore\SuperCandy;

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

/**
 * The dual-pane file manager `Model`.
 *
 * Holds two {@see Pane}s plus an active-pane index (Tab swaps),
 * a status line, and a confirmation-state enum so destructive
 * operations route through an explicit "press y to confirm,
 * anything else to cancel" gate.
 *
 * Mutations (delete) are gated by ConfirmState. The first press
 * of `d` arms the confirmation; the next `y` actually deletes.
 * Any other key cancels. This is one TUI pattern that's worth
 * the extra state — accidental deletes are too annoying to ship.
 *
 * Filesystem reads happen through an injected lister closure.
 * `FsLister::lister()` is the prod default; tests pass a fake.
 */
final class Manager implements Model
{
    /** @var \Closure(string): list<Entry> */
    private readonly \Closure $lister;

    /**
     * @param \Closure(string): list<Entry> $lister
     */
    public function __construct(
        public readonly Pane $left,
        public readonly Pane $right,
        public readonly int $activeIdx = 0,
        public readonly string $status = '',
        public readonly ConfirmState $confirm = ConfirmState::None,
        \Closure $lister = null,
    ) {
        $this->lister = $lister ?? FsLister::lister();
    }

    /**
     * @param \Closure(string): list<Entry>|null $lister
     */
    public static function start(string $leftCwd, string $rightCwd, ?\Closure $lister = null): self
    {
        $lister ??= FsLister::lister();
        return new self(
            Pane::open($leftCwd, $lister),
            Pane::open($rightCwd, $lister),
            lister: $lister,
        );
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

        // Confirmation gate consumes the next keystroke entirely.
        if ($this->confirm !== ConfirmState::None) {
            return [$this->resolveConfirm($msg), null];
        }

        if ($msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }

        return [$this->dispatch($msg), null];
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    public function activePane(): Pane
    {
        return $this->activeIdx === 0 ? $this->left : $this->right;
    }

    public function inactivePane(): Pane
    {
        return $this->activeIdx === 0 ? $this->right : $this->left;
    }

    private function dispatch(KeyMsg $msg): self
    {
        return match (true) {
            $msg->type === KeyType::Tab
                => $this->withActive(1 - $this->activeIdx),
            $msg->type === KeyType::Up,
            $msg->type === KeyType::Char && $msg->rune === 'k'
                => $this->withActivePane(fn(Pane $p) => $p->moveCursor(-1)),
            $msg->type === KeyType::Down,
            $msg->type === KeyType::Char && $msg->rune === 'j'
                => $this->withActivePane(fn(Pane $p) => $p->moveCursor(1)),
            $msg->type === KeyType::Home,
            $msg->type === KeyType::Char && $msg->rune === 'g'
                => $this->withActivePane(fn(Pane $p) => $p->gotoTop()),
            $msg->type === KeyType::End,
            $msg->type === KeyType::Char && $msg->rune === 'G'
                => $this->withActivePane(fn(Pane $p) => $p->gotoBottom()),
            $msg->type === KeyType::Enter,
            $msg->type === KeyType::Right
                => $this->navigate(),
            $msg->type === KeyType::Left,
            $msg->type === KeyType::Char && $msg->rune === 'h'
                => $this->goUp(),
            $msg->type === KeyType::Char && $msg->rune === ' '
                => $this->withActivePane(fn(Pane $p) => $p->toggleSelection())
                       ->withActivePane(fn(Pane $p) => $p->moveCursor(1)),
            $msg->type === KeyType::Char && $msg->rune === 's'
                => $this->cycleSort(),
            $msg->type === KeyType::Char && $msg->rune === '.'
                => $this->withActivePane(fn(Pane $p) => $p->toggleHidden($this->lister)),
            $msg->type === KeyType::Char && $msg->rune === 'd'
                => $this->armDelete(),
            $msg->type === KeyType::Char && $msg->rune === 'r'
                => $this->refresh(),
            default => $this,
        };
    }

    private function withActive(int $idx): self
    {
        return new self($this->left, $this->right, $idx, $this->status, $this->confirm, $this->lister);
    }

    /**
     * @param \Closure(Pane): Pane $fn
     */
    private function withActivePane(\Closure $fn): self
    {
        if ($this->activeIdx === 0) {
            return new self($fn($this->left), $this->right, 0, $this->status, $this->confirm, $this->lister);
        }
        return new self($this->left, $fn($this->right), 1, $this->status, $this->confirm, $this->lister);
    }

    private function navigate(): self
    {
        return $this->withActivePane(fn(Pane $p) => $p->navigate($this->lister));
    }

    private function goUp(): self
    {
        return $this->withActivePane(fn(Pane $p) =>
            Pane::open(Pane::parentPath($p->cwd), $this->lister, $p->sort, $p->showHidden));
    }

    private function cycleSort(): self
    {
        $active = $this->activePane();
        return $this->withActivePane(fn(Pane $p) => $p->setSort($active->sort->cycle(), $this->lister));
    }

    private function refresh(): self
    {
        return $this->withActivePane(fn(Pane $p) =>
            Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
            ->withStatus('refreshed');
    }

    private function armDelete(): self
    {
        $selectedCount = count($this->activePane()->selected);
        $current = $this->activePane()->currentEntry();
        if ($selectedCount === 0 && ($current === null || $current->isParentSentinel())) {
            return $this->withStatus('nothing to delete');
        }
        $names = $selectedCount > 0
            ? "{$selectedCount} selected entries"
            : "'{$current->name}'";
        return $this->withConfirm(ConfirmState::DeleteSelected, "delete {$names}? (y/n)");
    }

    private function resolveConfirm(KeyMsg $msg): self
    {
        $confirmed = $msg->type === KeyType::Char && $msg->rune === 'y';
        if (!$confirmed) {
            return $this->withConfirm(ConfirmState::None, 'cancelled');
        }
        return $this->performDelete();
    }

    private function performDelete(): self
    {
        $pane = $this->activePane();
        $names = $pane->selected !== []
            ? array_keys($pane->selected)
            : [$pane->currentEntry()?->name];
        $errors = 0;
        foreach ($names as $name) {
            if ($name === null || $name === '..' || $name === '') {
                continue;
            }
            $full = Pane::join($pane->cwd, $name);
            if (!self::removePath($full)) {
                $errors++;
            }
        }
        $msg = $errors === 0
            ? 'deleted ' . count($names) . ' entries'
            : "deleted with {$errors} errors";
        return $this->withActivePane(fn(Pane $p) =>
            Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
            ->withConfirm(ConfirmState::None, $msg);
    }

    private function withConfirm(ConfirmState $state, string $status): self
    {
        return new self($this->left, $this->right, $this->activeIdx, $status, $state, $this->lister);
    }

    private function withStatus(string $status): self
    {
        return new self($this->left, $this->right, $this->activeIdx, $status, $this->confirm, $this->lister);
    }

    /** Recursive delete. Empty dirs use rmdir; files use unlink. */
    private static function removePath(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return false;
        }
        $items = @scandir($path) ?: [];
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!self::removePath(rtrim($path, '/') . '/' . $name)) {
                return false;
            }
        }
        return @rmdir($path);
    }
}

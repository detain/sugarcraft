<?php

declare(strict_types=1);

namespace SugarCraft\Files\Manager;

use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Entry;
use SugarCraft\Files\FsLister;
use SugarCraft\Files\Pane;
use SugarCraft\Files\UndoAction;

/**
 * Fluent builder for Manager.
 *
 * Mirrors yorukot/superfile.Manager.builder
 *
 * Every `with*()` below is a real declared method returning a clone; there is
 * no magic __call, so no @method annotations are needed (a previous stale
 * block advertised signatures that drifted from the declared ones).
 */
final class ManagerBuilder
{
    /** @var \Closure(string): list<Entry> */
    private \Closure $lister;

    private ?Pane $left = null;
    private ?Pane $right = null;
    private int $activeIdx = 0;
    private string $status = '';
    private ConfirmState $confirm = ConfirmState::None;
    private ?string $searchQuery = null;
    private array $searchResults = [];
    private int $searchCursor = 0;
    private array $tabs = [];
    private int $tabIndex = 0;
    /** @var list<UndoAction> */
    private array $undoStack = [];
    /** @var list<UndoAction> */
    private array $redoStack = [];
    private ?string $pendingOpDest = null;
    private ?string $pendingOpType = null;
    private ?string $inputBuffer = null;

    public function __construct()
    {
        $this->lister = FsLister::lister();
    }

    /**
     * @param \Closure(string): list<Entry>|null $lister
     */
    public function withLister(?\Closure $lister): self
    {
        $clone = clone $this;
        $clone->lister = $lister ?? FsLister::lister();
        return $clone;
    }

    public function withLeft(Pane $left): self
    {
        $clone = clone $this;
        $clone->left = $left;
        return $clone;
    }

    public function withRight(Pane $right): self
    {
        $clone = clone $this;
        $clone->right = $right;
        return $clone;
    }

    public function withActiveIdx(int $activeIdx): self
    {
        $clone = clone $this;
        $clone->activeIdx = $activeIdx;
        return $clone;
    }

    public function withStatus(string $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function withConfirm(ConfirmState $confirm): self
    {
        $clone = clone $this;
        $clone->confirm = $confirm;
        return $clone;
    }

    public function withSearchQuery(?string $searchQuery): self
    {
        $clone = clone $this;
        $clone->searchQuery = $searchQuery;
        return $clone;
    }

    public function withSearchResults(array $searchResults): self
    {
        $clone = clone $this;
        $clone->searchResults = $searchResults;
        return $clone;
    }

    public function withSearchCursor(int $searchCursor): self
    {
        $clone = clone $this;
        $clone->searchCursor = $searchCursor;
        return $clone;
    }

    /**
     * @param array<int,array{left:Pane,right:Pane,activeIdx:int}> $tabs
     */
    public function withTabs(array $tabs): self
    {
        $clone = clone $this;
        $clone->tabs = $tabs;
        return $clone;
    }

    public function withTabIndex(int $tabIndex): self
    {
        $clone = clone $this;
        $clone->tabIndex = $tabIndex;
        return $clone;
    }

    /**
     * @param list<UndoAction> $undoStack
     */
    public function withUndoStack(array $undoStack): self
    {
        $clone = clone $this;
        $clone->undoStack = $undoStack;
        return $clone;
    }

    /**
     * @param list<UndoAction> $redoStack
     */
    public function withRedoStack(array $redoStack): self
    {
        $clone = clone $this;
        $clone->redoStack = $redoStack;
        return $clone;
    }

    public function withPendingOpDest(?string $pendingOpDest): self
    {
        $clone = clone $this;
        $clone->pendingOpDest = $pendingOpDest;
        return $clone;
    }

    public function withPendingOpType(?string $pendingOpType): self
    {
        $clone = clone $this;
        $clone->pendingOpType = $pendingOpType;
        return $clone;
    }

    public function withInputBuffer(?string $inputBuffer): self
    {
        $clone = clone $this;
        $clone->inputBuffer = $inputBuffer;
        return $clone;
    }

    public function build(): \SugarCraft\Files\Manager
    {
        if ($this->left === null || $this->right === null) {
            throw new \LogicException('left and right panes are required');
        }

        return new \SugarCraft\Files\Manager(
            $this->left,
            $this->right,
            $this->activeIdx,
            $this->status,
            $this->confirm,
            $this->lister,
            $this->searchQuery,
            $this->searchResults,
            $this->searchCursor,
            $this->tabs,
            $this->tabIndex,
            $this->undoStack,
            $this->redoStack,
            $this->pendingOpDest,
            $this->pendingOpType,
            $this->inputBuffer,
        );
    }
}

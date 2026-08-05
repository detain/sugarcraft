<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use DateTimeImmutable;

/**
 * Immutable collection of session tabs with focus management and keybinding support.
 *
 * Manages a list of open sessions as tabs, each with its own identity,
 * scrollback, agent-view state, and input focus. Tab switching is driven
 * by CTRL+TAB / CTRL+SHIFT+TAB key events.
 *
 * Mirrors charmbracelet/crush SessionTabs.
 */
final class SessionTabs
{
    /** Key code for Ctrl+Tab (forward tab cycle). */
    public const CTRL_TAB = "\x1b[1;5I";

    /** Key code for Ctrl+Shift+Tab (backward tab cycle). */
    public const CTRL_SHIFT_TAB = "\x1b[1;6I";

    /** @var array<string, SessionTab> Tab lookup by id */
    private readonly array $tabsById;

    /** @var list<string> Ordered list of tab ids */
    private readonly array $tabIds;

    /**
     * Initialize with one default "main" tab.
     */
    public function __construct(
        array $tabsById = [],
        array $tabIds = [],
    ) {
        if ($tabsById === [] && $tabIds === []) {
            $mainTab = new SessionTab(
                id: $this->generateId(),
                sessionName: 'main',
                isActive: true,
                lastActivityAt: new DateTimeImmutable(),
                agentSummary: null,
                isDetached: false,
            );

            $this->tabsById = [$mainTab->id => $mainTab];
            $this->tabIds = [$mainTab->id];
        } else {
            $this->tabsById = $tabsById;
            $this->tabIds = $tabIds;
        }
    }

    /**
     * Private constructor for building new instances from existing state.
     *
     * @param array<string, SessionTab> $tabsById
     * @param list<string>               $tabIds
     */
    private static function fromState(
        array $tabsById,
        array $tabIds,
    ): self {
        return new self($tabsById, $tabIds);
    }

    /**
     * Return all tabs in creation order.
     *
     * @return array<SessionTab>
     */
    public function tabs(): array
    {
        $result = [];
        foreach ($this->tabIds as $id) {
            $result[] = $this->tabsById[$id];
        }

        return $result;
    }

    /**
     * Return the currently focused tab or null if none.
     */
    public function activeTab(): ?SessionTab
    {
        foreach ($this->tabsById as $tab) {
            if ($tab->isActive) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * Open a new tab and return a new SessionTabs instance.
     *
     * The new tab becomes active; the previously active tab loses focus.
     */
    public function openTab(string $sessionId, string $sessionName): self
    {
        // Deactivate current active tab
        $updatedTabs = [];
        foreach ($this->tabsById as $id => $tab) {
            $updatedTabs[$id] = $tab->isActive
                ? $tab->withActive(false)
                : $tab;
        }

        $newTab = new SessionTab(
            id: $sessionId,
            sessionName: $sessionName,
            isActive: true,
            lastActivityAt: new DateTimeImmutable(),
            agentSummary: null,
            isDetached: false,
        );

        $updatedTabs[$sessionId] = $newTab;

        return self::fromState($updatedTabs, [...$this->tabIds, $sessionId]);
    }

    /**
     * Close a tab by id and return a new SessionTabs instance.
     *
     * If the closing tab is active, the next available tab becomes active.
     * At least one tab is always kept — closing the last tab is a no-op.
     */
    public function closeTab(string $id): self
    {
        // Cannot close the last tab
        if (count($this->tabIds) <= 1) {
            return $this;
        }

        if (!isset($this->tabsById[$id])) {
            return $this;
        }

        $wasActive = $this->tabsById[$id]->isActive;

        // Build new tabsById without the closed tab
        $newTabsById = array_filter(
            $this->tabsById,
            static fn(SessionTab $tab): bool => $tab->id !== $id,
        );

        $newTabIds = array_values(array_filter(
            $this->tabIds,
            static fn(string $tabId): bool => $tabId !== $id,
        ));

        // If we closed the active tab, activate the next one in order
        if ($wasActive && count($newTabIds) > 0) {
            $closedIndex = array_search($id, $this->tabIds, true);
            // Middle tab: successor is at $closedIndex in new array (shifted left)
            // Last tab: no successor, so activate predecessor at $closedIndex - 1
            $nextIndex = $closedIndex < count($newTabIds)
                ? $closedIndex
                : $closedIndex - 1;
            $nextActiveId = $newTabIds[$nextIndex];
            $newTabsById[$nextActiveId] = $newTabsById[$nextActiveId]->withActive(true);
        }

        return self::fromState($newTabsById, $newTabIds);
    }

    /**
     * Switch input focus to the specified tab and return a new instance.
     */
    public function setActiveTab(string $id): self
    {
        if (!isset($this->tabsById[$id])) {
            return $this;
        }

        $updatedTabs = [];
        foreach ($this->tabsById as $tabId => $tab) {
            $updatedTabs[$tabId] = $tab->withActive($tabId === $id);
        }

        return self::fromState($updatedTabs, $this->tabIds);
    }

    /**
     * Mark a tab as detached (background session) and return a new instance.
     */
    public function detachTab(string $id): self
    {
        if (!isset($this->tabsById[$id])) {
            return $this;
        }

        $tab = $this->tabsById[$id];

        return self::fromState(
            [...$this->tabsById, $id => $tab->withDetached(true)],
            $this->tabIds,
        );
    }

    /**
     * Mark a tab as re-attached and return a new instance.
     */
    public function reattachTab(string $id): self
    {
        if (!isset($this->tabsById[$id])) {
            return $this;
        }

        $tab = $this->tabsById[$id];

        return self::fromState(
            [...$this->tabsById, $id => $tab->withDetached(false)],
            $this->tabIds,
        );
    }

    /**
     * Update the agent summary text for a tab and return a new instance.
     */
    public function updateTabSummary(string $id, string $summary): self
    {
        if (!isset($this->tabsById[$id])) {
            return $this;
        }

        $tab = $this->tabsById[$id];

        return self::fromState(
            [...$this->tabsById, $id => $tab->withSummary($summary)],
            $this->tabIds,
        );
    }

    /**
     * Return the number of open tabs.
     */
    public function count(): int
    {
        return count($this->tabIds);
    }

    /**
     * Process a key event for tab switching.
     *
     * Returns a new SessionTabs instance if the key triggered a tab switch,
     * or null if the key was not recognized as a tab navigation key.
     */
    public function handleKey(string $key): ?self
    {
        if ($key === self::CTRL_TAB) {
            return $this->cycleForward();
        }

        if ($key === self::CTRL_SHIFT_TAB) {
            return $this->cycleBackward();
        }

        return null;
    }

    /**
     * Cycle focus to the next tab (wraps around).
     */
    private function cycleForward(): self
    {
        $activeId = $this->activeTab()?->id;

        if ($activeId === null || count($this->tabIds) <= 1) {
            return $this;
        }

        $currentIndex = array_search($activeId, $this->tabIds, true);
        $nextIndex = ($currentIndex + 1) % count($this->tabIds);
        $nextId = $this->tabIds[$nextIndex];

        return $this->setActiveTab($nextId);
    }

    /**
     * Cycle focus to the previous tab (wraps around).
     */
    private function cycleBackward(): self
    {
        $activeId = $this->activeTab()?->id;

        if ($activeId === null || count($this->tabIds) <= 1) {
            return $this;
        }

        $currentIndex = array_search($activeId, $this->tabIds, true);
        $prevIndex = ($currentIndex - 1 + count($this->tabIds)) % count($this->tabIds);
        $prevId = $this->tabIds[$prevIndex];

        return $this->setActiveTab($prevId);
    }

    /**
     * Generate a unique id for a new tab.
     */
    private function generateId(): string
    {
        return 'tab_' . bin2hex(random_bytes(8));
    }
}

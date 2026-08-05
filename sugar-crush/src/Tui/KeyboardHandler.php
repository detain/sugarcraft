<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Tui\Commands\CancelAgentCmd;
use SugarCraft\Crush\Tui\Commands\CancelCmd;
use SugarCraft\Crush\Tui\Commands\CommandPaletteCmd;
use SugarCraft\Crush\Tui\Commands\GroupInputCmd;
use SugarCraft\Crush\Tui\Commands\KeyCmd;
use SugarCraft\Crush\Tui\Commands\NewSessionCmd;
use SugarCraft\Crush\Tui\Commands\ProviderSelectCmd;
use SugarCraft\Crush\Tui\Commands\QuitAgentViewCmd;
use SugarCraft\Crush\Tui\Commands\ResumeAgentCmd;
use SugarCraft\Crush\Tui\Commands\SourceSkillCmd;
use SugarCraft\Crush\Tui\Commands\StopAllAgentsCmd;
use SugarCraft\Crush\Tui\Components\MenuBar;

/**
 * Handles keyboard input and routes to appropriate handlers.
 *
 * Mirrors charmbracelet/crush key handling logic.
 */
final class KeyboardHandler
{
    /**
     * Process a keypress and return updated App and optional command.
     *
     * @return array{0: App, 1: ?KeyCmd} [newApp, command]
     */
    public function handle(string $key, App $app): array
    {
        // Handle Tab - cycle panes
        if ($key === 'tab') {
            return [$app->withPane($app->pane->next()), null];
        }

        // Agent view keyboard handling - before generic navigation
        if ($app->pane === Pane::Agents) {
            $result = $this->handleAgentViewKey($key, $app);
            if ($result !== null) {
                return $result;
            }
        }

        // Handle arrow keys / vim keys for navigation
        if (in_array($key, ['up', 'k', 'down', 'j', 'left', 'h', 'right', 'l'], true)) {
            return $this->handleNavigation($key, $app);
        }

        // Handle menu shortcuts via menu bar
        $currentMenu = MenuBar::getActiveMenu();
        if ($currentMenu > 0) {
            $result = MenuBar::handleKey($key, $currentMenu);
            if ($result[1] !== null) {
                return [$app, $result[1]];
            }
        }

        // Handle Escape - close menu and return to Chat pane
        if ($key === 'escape') {
            MenuBar::closeMenu();
            return [$app->withPane(Pane::Chat), null];
        }

        // Handle Ctrl+key combinations
        if (str_starts_with($key, 'ctrl+')) {
            return $this->handleCtrl(substr($key, 5), $app);
        }

        return [$app, null];
    }

    /**
     * Handle keyboard input when in the Agent View pane.
     *
     * @return array{0: App, 1: ?KeyCmd}|null [newApp, command] or null if key not handled
     */
    private function handleAgentViewKey(string $key, App $app): ?array
    {
        // Quick action keys (c, r, s, q) work in any agent view mode
        if ($key === 'c' && $app->selectedAgentIndex >= 0) {
            return [$app, new CancelAgentCmd($app->selectedAgentIndex)];
        }

        if ($key === 'r' && $app->selectedAgentIndex >= 0) {
            return [$app, new ResumeAgentCmd($app->selectedAgentIndex)];
        }

        if ($key === 's') {
            return [$app, new StopAllAgentsCmd()];
        }

        if ($key === 'q') {
            return [
                $app
                    ->withPane(Pane::Chat)
                    ->withSelectedAgentIndex(-1)
                    ->withAgentViewMode(AgentViewMode::List),
                new QuitAgentViewCmd(),
            ];
        }

        // Mode-specific handling
        return match ($app->agentViewMode) {
            AgentViewMode::List => $this->handleAgentListKey($key, $app),
            AgentViewMode::Peek => $this->handleAgentPeekKey($key, $app),
            AgentViewMode::Attach => $this->handleAgentAttachKey($key, $app),
        };
    }

    /**
     * Handle keys in agent list view mode.
     *
     * @return array{0: App, 1: ?KeyCmd}|null
     */
    private function handleAgentListKey(string $key, App $app): ?array
    {
        // Enter - switch to peek view (if an agent is selected)
        if ($key === 'enter' && $app->selectedAgentIndex >= 0) {
            return [$app->withAgentViewMode(AgentViewMode::Peek), null];
        }

        // Escape - return to Chat pane (same as Q when no selection)
        if ($key === 'escape') {
            if ($app->selectedAgentIndex >= 0) {
                // Clear selection but stay in agent view
                return [$app->withSelectedAgentIndex(-1), null];
            }

            // No selection - return to Chat pane
            MenuBar::closeMenu();
            return [$app->withPane(Pane::Chat), null];
        }

        return null;
    }

    /**
     * Handle keys in agent peek view mode.
     *
     * @return array{0: App, 1: ?KeyCmd}|null
     */
    private function handleAgentPeekKey(string $key, App $app): ?array
    {
        // Enter - switch to attach mode
        if ($key === 'enter') {
            return [$app->withAgentViewMode(AgentViewMode::Attach), null];
        }

        // Escape - return to list view
        if ($key === 'escape') {
            return [$app->withAgentViewMode(AgentViewMode::List), null];
        }

        return null;
    }

    /**
     * Handle keys in agent attach view mode.
     *
     * @return array{0: App, 1: ?KeyCmd}|null
     */
    private function handleAgentAttachKey(string $key, App $app): ?array
    {
        // Escape - detach and return to list view
        if ($key === 'escape') {
            return [$app->withAgentViewMode(AgentViewMode::List), null];
        }

        // In attach mode, most keys are passed directly to the agent.
        // The runtime handles routing them to the attached agent.
        // Only escape is intercepted here for detaching.
        return null;
    }

    /**
     * Handle navigation keys within panes.
     *
     * @return array{0: App, 1: ?KeyCmd} [newApp, command]
     */
    private function handleNavigation(string $key, App $app): array
    {
        // Agent list navigation when in Agents pane and list view
        if ($app->pane === Pane::Agents && $app->agentViewMode === AgentViewMode::List) {
            return $this->handleAgentListNavigation($key, $app);
        }

        // Navigation is delegated to specific pane handlers
        // The appropriate pane will handle scroll/movement based on current focus
        return [$app, null];
    }

    /**
     * Handle arrow key navigation within the agent list.
     *
     * @return array{0: App, 1: ?KeyCmd} [newApp, command]
     */
    private function handleAgentListNavigation(string $key, App $app): array
    {
        $isUp = in_array($key, ['up', 'k'], true);
        $isDown = in_array($key, ['down', 'j'], true);

        if (!$isUp && !$isDown) {
            return [$app, null];
        }

        // If nothing selected yet, down/j selects first agent (index 0)
        // up/k does nothing (can't go above 0)
        if ($app->selectedAgentIndex < 0) {
            if ($isDown) {
                return [$app->withSelectedAgentIndex(0), null];
            }

            return [$app, null];
        }

        // Calculate new index (up decreases, down increases)
        $delta = $isUp ? -1 : 1;
        $newIndex = $app->selectedAgentIndex + $delta;

        // Clamp to valid range (0 or greater)
        $newIndex = max(0, $newIndex);

        // If index changed, return new app with updated selection
        if ($newIndex !== $app->selectedAgentIndex) {
            return [$app->withSelectedAgentIndex($newIndex), null];
        }

        return [$app, null];
    }

    /**
     * Handle Ctrl+key combinations.
     *
     * @return array{0: App, 1: ?KeyCmd} [newApp, command]
     */
    private function handleCtrl(string $key, App $app): array
    {
        return match ($key) {
            'n' => [$app, new NewSessionCmd()],
            'c' => [$app, new CancelCmd()],
            'g' => [$app, new GroupInputCmd()],
            'k' => [$app, new CommandPaletteCmd()],
            's' => [$app, new SourceSkillCmd()],
            'a' => [$app->withPane(Pane::Agents), null],
            'p' => [$app, new ProviderSelectCmd()],
            ',' => [$app->withPane(Pane::Settings), null],
            default => [$app, null],
        };
    }
}

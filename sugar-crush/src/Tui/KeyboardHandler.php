<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Tui\Commands\CancelCmd;
use SugarCraft\Crush\Tui\Commands\CommandPaletteCmd;
use SugarCraft\Crush\Tui\Commands\GroupInputCmd;
use SugarCraft\Crush\Tui\Commands\KeyCmd;
use SugarCraft\Crush\Tui\Commands\NewSessionCmd;
use SugarCraft\Crush\Tui\Commands\ProviderSelectCmd;
use SugarCraft\Crush\Tui\Commands\SourceSkillCmd;
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
     * Handle navigation keys within panes.
     *
     * @return array{0: App, 1: ?KeyCmd} [newApp, command]
     */
    private function handleNavigation(string $key, App $app): array
    {
        // Navigation is delegated to specific pane handlers
        // The appropriate pane will handle scroll/movement based on current focus
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

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
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
     * Ctrl+<rune> chords the SHELL answers. Deliberately excludes every chord
     * the live {@see \SugarCraft\Crush\Chat} already binds — see
     * {@see chatOwns()}.
     */
    private const SHELL_CTRL_RUNES = ['n', 'g', 'k', 's', ','];

    /**
     * Ctrl+<rune> chords the CONTENT model owns outright. The shell never
     * claims these, in any pane, so merging the two layers cannot silently
     * steal a binding a `bin/sugarcrush` user already has:
     *
     * - `p` — Chat's command palette. KeyboardHandler's own Ctrl+P
     *   (`ProviderSelectCmd`) loses: the palette is the live, user-facing,
     *   tested binding and `ProviderSelectCmd` is inert (see
     *   {@see handleKeyMsg()}), so claiming it would trade a working feature
     *   for a no-op.
     * - `o` — Chat's collapse/expand of the latest tool output (§1 E5); the
     *   only way to read a hidden tool body. The shell binds nothing to it.
     * - `a` — Chat's `/agents` dispatch. KeyboardHandler's Ctrl+A (focus
     *   `Pane::Agents`) loses for the same reason as Ctrl+P: the Chat arm
     *   was built (R20) precisely as the reachable equivalent of this
     *   shortcut, and the Agents pane is still one plain Tab away.
     * - `w` — Chat's word-delete while typing.
     * - `c` — Chat quits on it. Note most terminals deliver Ctrl+C as the
     *   raw `\x03` rune with no ctrl flag, which {@see chatOwns()} also
     *   covers explicitly.
     */
    private const CHAT_CTRL_RUNES = ['p', 'o', 'a', 'w', 'c'];

    /**
     * Decide whether the pane SHELL claims this keypress, and if so apply it.
     *
     * This is the {@see KeyMsg} bridge that lets {@see App::update()} host the
     * live {@see \SugarCraft\Crush\Chat} (crush_feat.md §5 E7, the merge
     * branch): the shell answers its own keys — menu, pane switch, the agent
     * view's c/r/s/q and arrows — and returns **null** for everything else so
     * the App can hand the untouched `KeyMsg` to `Chat::update()`. Returning
     * `[$app, null]` for an unclaimed key (which {@see handle()} does, having
     * no way to say "not mine") would swallow the palette, the "/" menu,
     * Ctrl+O, history recall and every other live binding.
     *
     * Claim rules, in order:
     * 1. Never claim a {@see chatOwns()} binding — content wins outright.
     * 2. An open menu owns the keyboard.
     * 3. `Pane::Agents` owns the keyboard: it is a full-pane agent view where
     *    c/r/s/q are commands, not text, and `q` is how the user leaves.
     * 4. Unmodified Tab cycles panes. Ctrl+Tab is excluded by rule 1 — that
     *    is Chat's session cycling (§5 E2).
     * 5. Escape returns to the chat pane, but only when the shell has
     *    somewhere to return FROM. In `Pane::Chat` it belongs to Chat, whose
     *    Escape cancels an in-flight turn and closes the palette.
     * 6. The {@see SHELL_CTRL_RUNES} chords.
     *
     * Disclosure: the {@see KeyCmd} in element 1 is still inert. No main loop
     * consumes any `Cmd` object in `src/` yet — the pre-existing, repo-wide
     * gap documented on {@see App::userInvocableSkills()} — so a claimed
     * Ctrl+K produces a `CommandPaletteCmd` nobody runs. Claiming is still
     * strictly better than falling through, which would type a literal "k"
     * into the chat input; wiring a Cmd driver is a later step.
     *
     * @return array{0: App, 1: ?object}|null [newApp, command], or null when
     *         the key is not the shell's and must fall through to Chat.
     *         Element 1 is a {@see KeyCmd} except on the menu path, which
     *         surfaces {@see \SugarCraft\Crush\Tui\Components\MenuSelectedMsg}.
     */
    public function handleKeyMsg(KeyMsg $msg, App $app): ?array
    {
        if (!$this->claims($msg, $app)) {
            return null;
        }

        if ($msg->type === KeyType::F10) {
            MenuBar::openMenu();

            return [$app, null];
        }

        return $this->handle($msg->string(), $app);
    }

    /**
     * @see handleKeyMsg() for the rule list this implements.
     */
    private function claims(KeyMsg $msg, App $app): bool
    {
        if (self::chatOwns($msg)) {
            return false;
        }

        if (MenuBar::getActiveMenu() > 0) {
            return true;
        }

        if ($app->pane === Pane::Agents) {
            return true;
        }

        // F10 opens the menu bar -- the conventional TUI menu key, and the
        // piece that was missing: MenuBar's navigation existed but nothing
        // could ever open it, so the bar was unreachable by keyboard AND
        // mouse. Claimed unconditionally so it works from any pane.
        if ($msg->type === KeyType::F10) {
            return true;
        }

        if ($msg->type === KeyType::Tab && !$msg->ctrl && !$msg->alt && !$msg->shift) {
            return true;
        }

        if ($msg->type === KeyType::Escape && $app->pane !== Pane::Chat) {
            return true;
        }

        return $msg->type === KeyType::Char
            && $msg->ctrl
            && in_array($msg->rune, self::SHELL_CTRL_RUNES, true);
    }

    /**
     * Bindings the live {@see \SugarCraft\Crush\Chat} owns in every pane.
     *
     * @see CHAT_CTRL_RUNES for the per-chord rationale.
     */
    private static function chatOwns(KeyMsg $msg): bool
    {
        // Session cycling (crush_feat.md §5 E2). Shift only picks the
        // direction, so both Ctrl+Tab and Ctrl+Shift+Tab belong to Chat.
        if ($msg->type === KeyType::Tab && $msg->ctrl) {
            return true;
        }

        if ($msg->type !== KeyType::Char) {
            return false;
        }

        if ($msg->rune === "\x03") {
            return true;
        }

        return $msg->ctrl && in_array($msg->rune, self::CHAT_CTRL_RUNES, true);
    }

    /**
     * Process a keypress and return updated App and optional command.
     *
     * Takes the string key label the pane layer has always used. The live
     * path speaks {@see KeyMsg}; {@see handleKeyMsg()} is the bridge, and is
     * what decides whether a key reaches here at all.
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

        // An OPEN menu takes the arrow/vim keys first. Ordered before the
        // generic pane navigation below because that block claimed
        // left/right/h/l unconditionally, so a menu could never be navigated
        // even once something opened it.
        $currentMenu = MenuBar::getActiveMenu();
        if ($currentMenu > 0) {
            $result = MenuBar::handleKey($key, $currentMenu);
            // Persist the index handleKey() navigated to -- it is a pure
            // function and this return value used to be dropped, so every
            // move was computed and discarded.
            MenuBar::activateMenu($result[0]);
            if ($result[1] !== null) {
                return [$app, $result[1]];
            }
            if ($result[0] !== $currentMenu) {
                return [$app, null];
            }
        }

        // Handle arrow keys / vim keys for navigation
        if (in_array($key, ['up', 'k', 'down', 'j', 'left', 'h', 'right', 'l'], true)) {
            return $this->handleNavigation($key, $app);
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
     * P5.S4 fix: mode dispatch for {@see AgentViewMode::Attach} runs BEFORE
     * the quick-action keys below. {@see AgentViewMode}'s own docblock says
     * "all keyboard input goes to the selected agent" in Attach mode, but the
     * previous ordering checked c/r/s/q as global shortcuts first — so a key
     * meant for the attached agent's own input (e.g. the letter "s") was
     * hijacked into StopAllAgentsCmd before Attach mode ever saw it. List and
     * Peek mode are unaffected: neither claims c/r/s/q for itself, so those
     * keys are still meant to reach the quick actions below in those modes.
     *
     * @return array{0: App, 1: ?KeyCmd}|null [newApp, command] or null if key not handled
     */
    private function handleAgentViewKey(string $key, App $app): ?array
    {
        if ($app->agentViewMode === AgentViewMode::Attach) {
            return $this->handleAgentAttachKey($key, $app);
        }

        // Quick action keys (c, r, s, q) work in List/Peek agent view modes.
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

        // Mode-specific handling (Attach already returned above).
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
     * Escape is the only key intercepted here for detaching. Every other
     * key is claimed with a no-op `[App, null]` (not `null`) so it can never
     * fall through to the quick-action shortcuts in {@see handleAgentViewKey()}
     * — that fallthrough was the P5.S4 bug. There is no live "forward this
     * keystroke to the attached agent process" Cmd anywhere in src/ yet (the
     * same pre-existing, repo-wide "no Cmd type is consumed by a main loop"
     * gap documented on {@see App::userInvocableSkills()}); wiring one is a
     * distinct, larger integration than this ordering fix and stays out of
     * scope here. What this method guarantees today: a key typed while
     * attached is never reinterpreted as CancelAgentCmd/ResumeAgentCmd/
     * StopAllAgentsCmd/QuitAgentViewCmd.
     *
     * @return array{0: App, 1: ?KeyCmd}
     */
    private function handleAgentAttachKey(string $key, App $app): array
    {
        // Escape - detach and return to list view
        if ($key === 'escape') {
            return [$app->withAgentViewMode(AgentViewMode::List), null];
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

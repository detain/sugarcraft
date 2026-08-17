<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

/**
 * The ONE list of raw keybindings, read by two surfaces:
 *
 * - the in-app reference screen ({@see \SugarCraft\Crush\Renderer::renderKeyHelp()},
 *   opened with `?` on an empty input line or with `/keys`), and
 * - {@see \SugarCraft\Crush\Tui\KeyboardHandler}, whose pane shell decides
 *   which `Ctrl+<rune>` chords it claims from {@see shellCtrlRunes()} /
 *   {@see chatCtrlRunes()} instead of from two hand-written const arrays.
 *
 * That second consumer is the point. This is the same unification
 * {@see CommandRegistry} already applied to slash commands (its docblock:
 * "a command added to one was silently missing from the other") — with the
 * stronger property that the shell's routing now DERIVES from the documented
 * list, so a chord the reference does not know about is a chord the shell
 * does not claim, and the divergence shows up as broken behaviour rather than
 * as silently stale documentation.
 *
 * What a key does still lives in the handlers. Adding a row here documents a
 * binding and (for a `Ctrl+<rune>` row) routes it; it does not implement one.
 * `tests/Commands/KeyBindingDriftTest.php` is what holds the two together: it
 * drives every live row through the real `Chat`/`KeyboardHandler` and fails
 * when a row describes an effect the handler no longer has.
 *
 * Rows carrying a `dormantReason` are excluded from the reference screen and
 * from nothing else — see {@see KeyBinding::$dormantReason}.
 *
 * Two spelling conventions the drift test enforces, because it reads both back
 * as keystrokes rather than treating them as prose:
 *
 * - a `$keys` label names literal chords — `Ctrl+P`, `↑ / ↓`, `Esc Esc`;
 * - a `(or …)` aside in a description names an ALTERNATE for the same binding,
 *   listed in the same order as the label. Hence `(or k / j)` beside `↑ / ↓`
 *   rather than the more idiomatic `j / k`: the two lists line up position by
 *   position, so `k` is the alternate for `↑`. It was `j / k` first, which read
 *   naturally and told the reader that `j` moves up.
 *
 * A key named OUTSIDE that form would be a promise nothing drives, so no
 * description may name one: every other parenthesised aside qualifies WHEN the
 * binding applies ("(empty input box)") or what it runs ("(runs /agents)").
 * `KeyBindingDriftTest::testNoDescriptionNamesAKeyOutsideTheOrForm()` is what
 * makes that a property rather than a habit — it is the test that caught
 * `chat.session-cycle`'s "(Ctrl+Shift+Tab for the previous)", which named a
 * chord the drift test could not read back and therefore never pressed. That
 * chord is now a row of its own (`chat.session-cycle-prev`).
 */
final class KeyBindingRegistry
{
    /** Keys the pane shell answers from any pane (`bin/sugarcrush` only). */
    public const CONTEXT_SHELL = 'Panes & windows';
    /** Keys the chat content model answers. */
    public const CONTEXT_CHAT = 'Chat';
    /** Keys the Ctrl+P command palette answers while it is open. */
    public const CONTEXT_PALETTE = 'Command palette';
    /** Keys the Ctrl+R session picker answers while it is open. */
    public const CONTEXT_PICKER = 'Session picker';
    /** Keys the blocking permission prompt answers while it is up. */
    public const CONTEXT_PERMISSION = 'Permission prompt';
    /** Keys the full-pane agent dashboard answers. */
    public const CONTEXT_AGENTS = 'Agent view';
    /** Keys the Ctrl+S skill picker answers while it is open. */
    public const CONTEXT_SKILLS = 'Skill picker';
    /** Keys the F10 menu bar answers while it is open. */
    public const CONTEXT_MENU = 'Menu bar';
    /** Mouse gestures, when the terminal reports them. */
    public const CONTEXT_MOUSE = 'Mouse';

    /**
     * Every binding, grouped by context in the order the reference screen
     * shows them.
     *
     * @return list<KeyBinding>
     */
    public static function all(): array
    {
        return [
            ...self::chat(),
            ...self::shell(),
            ...self::palette(),
            ...self::picker(),
            ...self::permission(),
            ...self::agents(),
            ...self::skills(),
            ...self::menu(),
            ...self::mouse(),
        ];
    }

    /**
     * The rows the in-app reference lists.
     *
     * @return list<KeyBinding>
     */
    public static function live(): array
    {
        return array_values(array_filter(self::all(), static fn(KeyBinding $b): bool => $b->isLive()));
    }

    /**
     * The claimed-but-not-yet-wired rows, kept so nothing about them is
     * hidden from code even though the reference does not advertise them.
     *
     * @return list<KeyBinding>
     */
    public static function dormant(): array
    {
        return array_values(array_filter(self::all(), static fn(KeyBinding $b): bool => !$b->isLive()));
    }

    /**
     * The live rows as `context => rows`, in declared order.
     *
     * @return array<string, list<KeyBinding>>
     */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::live() as $binding) {
            $groups[$binding->context][] = $binding;
        }

        return $groups;
    }

    /** The row with this id, or null. */
    public static function byId(string $id): ?KeyBinding
    {
        foreach (self::all() as $binding) {
            if ($binding->id === $id) {
                return $binding;
            }
        }

        return null;
    }

    /**
     * `Ctrl+<rune>` chords the pane SHELL answers.
     *
     * Includes dormant rows on purpose: an unclaimed chord does not become
     * inert, it falls through to `Chat`'s generic `KeyType::Char` arm and
     * types its own letter into the input box.
     *
     * @return list<string>
     */
    public static function shellCtrlRunes(): array
    {
        return self::ctrlRunesOf(self::CONTEXT_SHELL);
    }

    /**
     * `Ctrl+<rune>` chords the CHAT content model owns while the pane shell is
     * not itself driving the keyboard.
     *
     * In an ordinary pane that is "always": the shell claims none of these. The
     * exception is {@see chatCtrlRunesYieldedToShell()}, which is a strict
     * subset of this list rather than a hole in it.
     *
     * @return list<string>
     */
    public static function chatCtrlRunes(): array
    {
        return self::ctrlRunesOf(self::CONTEXT_CHAT);
    }

    /**
     * The chat chords the pane shell takes BACK while one of its own
     * keyboard-owning views is up — the F10 menu, `Pane::Agents`' full-pane
     * dashboard, an open skill picker.
     *
     * TWO conditions, both required:
     *
     * 1. the chord's only effect is a Chat overlay those views bury and whose
     *    own keys (`↑`/`↓`/`Enter`) they claim, so opening it there puts up a
     *    modal the user can neither see nor move; AND
     * 2. the shell's own `KeyboardHandler::handleCtrl()` answers the chord
     *    with a NO-OP, so taking it back means "nothing happens" — not
     *    "something else happens".
     *
     * `Ctrl+R` is the one row that fits. Condition 1, measured with the yield
     * lifted, in `Pane::Agents` with two stored sessions: the picker opens,
     * `Down` does not move its highlight, `Enter` switches the dashboard to
     * Peek mode instead of resuming, and only leaving the pane (`Esc`/`q`)
     * makes it drivable. Nothing about that is specific to the picker — the
     * claim layer decides before `Chat` sees the key, so it holds for whatever
     * overlay `Chat` has open, which the `Ctrl+P` rows below demonstrate
     * directly. Condition 2: `handleCtrl('r')` falls through to its `default`
     * arm, so the shell answers the yielded chord with `[$app, null]`.
     *
     * `Ctrl+P` is absent because it fails condition **2**, not condition 1.
     * Measured by driving `KeyboardHandler::handle('ctrl+p')` in all three
     * states and feeding the result to
     * {@see \SugarCraft\Crush\App\App::consumeShellCmd()}: the shell answers
     * with `ProviderSelectCmd`, which `consumeShellCmd()` runs as `/model`,
     * opening the palette in `providers` mode. Yielding `p` would therefore
     * not swallow the chord — it would rebind `Ctrl+P` to the model switcher
     * in precisely the states where an overlay cannot be seen, which is worse
     * than what it does now.
     * {@see \SugarCraft\Crush\Tests\Tui\KeyboardHandlerTest::testEveryYieldedChordIsAnsweredByANoOp()}
     * makes condition 2 a property of the derived set rather than a claim
     * about one row.
     *
     * ── the gap this leaves open (tracker #85) ────────────────────────────
     *
     * Condition 1 DOES hold for `Ctrl+P`, so refusing to yield it is not the
     * same as there being nothing wrong. Measured at 100×30, with the palette
     * opened by `Ctrl+P` — or by `Ctrl+K`, which the shell translates into the
     * same keystroke, so both doors lead to the same room and neither is a way
     * out of it:
     *
     * - `Pane::Agents` — `Chat::palette()` is set, the frame paints NO palette
     *   (`Tui\Renderer::renderAgentDashboard()` replaces the whole content
     *   band, so the hosted chat's frame — overlay and all — is not drawn),
     *   and `Down` moves the dashboard selection;
     * - an open skill picker — the palette IS painted, but `Down` moves the
     *   picker's highlight;
     * - an open F10 menu — the palette IS painted, but `Down` goes to the menu.
     *
     * So in the agent view `Ctrl+P` opens a palette that is invisible AND
     * undrivable until the user leaves the pane, which reveals it. Closing
     * that wants the shell either to composite a hosted overlay over its
     * full-pane views or to stand them down while one is open: a routing and
     * layout change, not a claim-set change — the claim-set change makes it
     * worse, as measured above.
     * {@see \SugarCraft\Crush\Tests\Tui\KeyboardHandlerTest::testTheAgentViewTakesAPaletteItNeitherPaintsNorDrives()}
     * pins the current behaviour, so the day it is fixed that test is what
     * says so.
     *
     * @return list<string>
     */
    public static function chatCtrlRunesYieldedToShell(): array
    {
        if (self::$yieldedRuneMemo !== null) {
            return self::$yieldedRuneMemo;
        }

        $runes = [];
        foreach (self::all() as $binding) {
            if ($binding->context !== self::CONTEXT_CHAT || !$binding->yieldsToShell()) {
                continue;
            }
            $rune = $binding->ctrlRune();
            if ($rune !== null && !in_array($rune, $runes, true)) {
                $runes[] = $rune;
            }
        }

        return self::$yieldedRuneMemo = $runes;
    }

    /**
     * Memo of the derived rune sets, keyed by context.
     *
     * The rows are compile-time data ({@see all()} is nine literal arrays with
     * no external input, on an all-static `final` class that is never
     * instantiated), so each derivation is a pure function of a constant and
     * caching it for the life of the process cannot go stale.
     *
     * What the hot path asks for, MEASURED rather than counted off the call
     * sites. Counting off the call sites got it wrong in both directions,
     * because PHP short-circuits two of the reads away before they happen.
     *
     * Domain of every figure below: one COLD keypress each — both memos
     * cleared, one {@see \SugarCraft\Crush\Tui\KeyboardHandler::handleKeyMsg()}
     * call, the populated memo slots counted afterwards. PHP 8.3.6.
     *
     * | keypress                              | derivations |
     * |---------------------------------------|-------------|
     * | any non-`Ctrl` key (`a`, `Enter`)     | 0           |
     * | `Ctrl+R` in an ordinary pane          | 1           |
     * | `Ctrl+N` in `Pane::Agents`            | 1           |
     * | `Ctrl+N`/`Ctrl+Z` in an ordinary pane | 2           |
     * | `Ctrl+R`/`Ctrl+W` in `Pane::Agents`   | 2           |
     *
     * Ordinary typing costs NOTHING, which is the half the call-site count
     * overstated: `chatOwns()` bails at `!$msg->ctrl ||` before reading
     * {@see chatCtrlRunes()}, and `claims()` bails at `$msg->ctrl &&` before
     * reading {@see shellCtrlRunes()}.
     *
     * TWO is the ceiling, and three is structurally impossible rather than
     * merely unobserved — the half the call-site count understated:
     * {@see shellCtrlRunes()} is read only when `shellOwnsKeyboard()` is FALSE
     * (`claims()` returns before reaching that read otherwise), and
     * {@see chatCtrlRunesYieldedToShell()} only when it is TRUE. The two reads
     * are mutually exclusive. Swept exhaustively over 2 menu states × 9 panes ×
     * 95 printable runes × Ctrl on/off = 3420 keypresses: 1710 derive nothing,
     * 938 derive one set, 772 derive two, none derive three.
     *
     * That sweep visits the panes at their DEFAULT sub-state, and a sub-state is
     * not neutral here: opening the skill picker in `Pane::Skills` flips
     * `KeyboardHandler::shellOwnsKeyboard()`, which is the very predicate
     * choosing which set derives (`ctrl+r` there goes `{Chat}` → `{Chat,
     * YIELDED}`). So the same rune × Ctrl sweep is run again over the 8
     * keyboard-owning sub-states = 1520 more keypresses: 760 derive nothing, 712
     * derive one, 48 derive two, none derive three. Both distributions are
     * properties of THIS row set — add a `Ctrl+<rune>` row and they move. The
     * ceiling of two is the part that belongs to the routing rule.
     *
     * So the yielded set is NOT asked for on the same keypresses as the other
     * two — it is asked for on precisely their COMPLEMENT. It still earns a
     * memo of its own ({@see $yieldedRuneMemo}) on a hot path of its own:
     * inside the shell's three keyboard-owning views every chat `Ctrl+<rune>`
     * chord derives it, and un-memoised each of those presses walks
     * {@see all()}.
     *
     * Cost on the host this lane ran on (PHP 8.3.6; 2k iterations cold, 200k
     * warm): the two derivations with the memos cleared each iteration
     * **49.6µs**, the same two lookups memoised **0.21µs**, one {@see all()}
     * walk **19.8µs**. The absolute figures are this machine's; the ratio is
     * the reason. Irrelevant at typing speed either way, but this is the hot
     * path and the const arrays this table replaced cost nothing.
     *
     * `KeyboardHandlerTest::testTheHotPathNeverDerivesMoreThanTwoRuneSets()` is
     * what keeps this accounting from drifting back into prose: it re-measures
     * the table rows and re-runs both sweeps, the 3420 and the 1520.
     *
     * @var array<string, list<string>>
     */
    private static array $ctrlRuneMemo = [];

    /**
     * Memo of {@see chatCtrlRunesYieldedToShell()}, which cannot share
     * {@see $ctrlRuneMemo}: that map is keyed by context, and this set is a
     * FILTERED view of one context rather than a context of its own. Null
     * (not `[]`) means "not yet derived" — the derived set being empty is a
     * legitimate answer, so `[]` cannot double as the "not yet" marker.
     *
     * Not "rebuilt on every keypress": rebuilt on every chat `Ctrl+<rune>`
     * chord pressed inside one of the shell's keyboard-owning views, which is
     * the complement of the keypresses the other two sets serve. See
     * {@see $ctrlRuneMemo} for the measured distribution and its domain.
     *
     * @var list<string>|null
     */
    private static ?array $yieldedRuneMemo = null;

    /**
     * @return list<string>
     */
    private static function ctrlRunesOf(string $context): array
    {
        if (isset(self::$ctrlRuneMemo[$context])) {
            return self::$ctrlRuneMemo[$context];
        }

        $runes = [];
        foreach (self::all() as $binding) {
            if ($binding->context !== $context) {
                continue;
            }
            $rune = $binding->ctrlRune();
            if ($rune !== null && !in_array($rune, $runes, true)) {
                $runes[] = $rune;
            }
        }

        return self::$ctrlRuneMemo[$context] = $runes;
    }

    /**
     * @return list<KeyBinding>
     */
    private static function chat(): array
    {
        $c = self::CONTEXT_CHAT;

        return [
            KeyBinding::new('chat.send', 'Enter', 'Send, or accept the highlighted "/" command', $c),
            KeyBinding::new('chat.newline', 'Alt+Enter', 'Insert a newline instead of sending', $c),
            KeyBinding::new('chat.slash-menu', '↑ / ↓', 'Move through the "/" command popup', $c),
            KeyBinding::new('chat.recall', '↑', 'Recall your last message (empty input box)', $c),
            KeyBinding::new('chat.backspace', 'Backspace', 'Delete the previous character', $c),
            KeyBinding::new('chat.word-delete', 'Ctrl+W', 'Delete the previous word (or Alt+Backspace)', $c),
            KeyBinding::new('chat.page', 'PgUp / PgDn', 'Scroll the transcript by a screenful', $c),
            KeyBinding::new('chat.palette', 'Ctrl+P', 'Open the command palette', $c),
            KeyBinding::new('chat.tool-output', 'Ctrl+O', 'Expand or collapse the newest tool output', $c),
            KeyBinding::new(
                'chat.session-picker',
                'Ctrl+R',
                'Open the session picker',
                $c,
                yieldsToShellReason: 'The picker is painted by Chat and driven by ↑/↓/Enter, all three '
                    . 'of which the shell\'s own keyboard-owning views take — so opening it from one of '
                    . 'them puts up a modal the user can neither see nor move. The shell swallows the '
                    . 'chord there instead, which is what it did before this table derived the claim '
                    . 'sets. See chatCtrlRunesYieldedToShell() for why Ctrl+P is not treated the same.',
            ),
            KeyBinding::new('chat.agents', 'Ctrl+A', 'List the active agents (runs /agents)', $c),
            KeyBinding::new('chat.session-cycle', 'Ctrl+Tab', 'Switch to the next session', $c),
            KeyBinding::new('chat.session-cycle-prev', 'Ctrl+Shift+Tab', 'Switch to the previous session', $c),
            // The reference's OWN keys (Esc/Enter/q to close, ↑↓/PgUp/PgDn to
            // scroll, and the second "?" that closes it while typing a literal
            // "?" so a message beginning with one is still composable) are not
            // rows here: they are painted in the box's own footer by
            // Renderer::renderKeyHelp(), because they apply only while that
            // screen is up and this table describes the keyboard behind it.
            // Chat::handleKeyHelpKey() is where they live and why.
            KeyBinding::new('chat.keys', '?', 'Show this reference (empty input box)', $c),
            KeyBinding::new('chat.cancel', 'Esc Esc', 'Cancel the turn in flight — twice, quickly', $c),
            KeyBinding::new('chat.quit', 'Ctrl+C', 'Quit SugarCrush', $c),
        ];
    }

    /**
     * @return list<KeyBinding>
     */
    private static function shell(): array
    {
        $c = self::CONTEXT_SHELL;

        return [
            KeyBinding::new('shell.pane-next', 'Tab', 'Focus the next pane', $c),
            KeyBinding::new('shell.menu', 'F10', 'Open the menu bar', $c),
            KeyBinding::new('shell.pane-chat', 'Esc', 'Leave the pane, back to the chat', $c),
            KeyBinding::new('shell.new-session', 'Ctrl+N', 'Start a fresh session', $c),
            KeyBinding::new('shell.palette', 'Ctrl+K', 'Open the command palette', $c),
            KeyBinding::new('shell.skills', 'Ctrl+S', 'Open the skill picker', $c),
            KeyBinding::new('shell.settings', 'Ctrl+,', 'Focus the settings pane', $c),
            KeyBinding::new(
                'shell.group-input',
                'Ctrl+G',
                'Group the input',
                $c,
                dormantReason: 'GroupInputCmd has no consumer — App::consumeShellCmd() names it '
                    . 'as one of the deliberately inert commands, so the chord is claimed (which is '
                    . 'what keeps it from typing a literal "g") but does nothing observable.',
            ),
        ];
    }

    /**
     * @return list<KeyBinding>
     */
    private static function palette(): array
    {
        $c = self::CONTEXT_PALETTE;

        return [
            KeyBinding::new('palette.move', '↑ / ↓', 'Move the highlighted row', $c),
            KeyBinding::new('palette.run', 'Enter', 'Run the highlighted command', $c),
            KeyBinding::new('palette.filter', 'any text', 'Filter the list as you type', $c),
            // Its own row rather than "Backspace erases" tacked onto the one
            // above: a key named in a description outside the `(or …)` form is
            // a promise the drift test cannot read back and therefore never
            // presses — the whole failure mode this table exists to close.
            KeyBinding::new('palette.erase', 'Backspace', 'Erase the last character of the filter', $c),
            KeyBinding::new('palette.close', 'Esc', 'Close the palette (or Ctrl+P)', $c),
        ];
    }

    /**
     * @return list<KeyBinding>
     */
    private static function picker(): array
    {
        $c = self::CONTEXT_PICKER;

        return [
            KeyBinding::new('picker.move', '↑ / ↓', 'Move the highlighted session (or k / j)', $c),
            KeyBinding::new('picker.resume', 'Enter', 'Resume the highlighted session', $c),
            KeyBinding::new('picker.preview', 'Space', 'Stay on the highlighted session', $c),
            KeyBinding::new('picker.branch', 'Ctrl+B', 'Filter to the current git branch, or all', $c),
            KeyBinding::new('picker.close', 'Esc', 'Close the picker', $c),
        ];
    }

    /**
     * @return list<KeyBinding>
     */
    private static function permission(): array
    {
        $c = self::CONTEXT_PERMISSION;

        return [
            KeyBinding::new('permission.once', 'y', 'Allow this one call', $c),
            KeyBinding::new('permission.always', 'a', 'Allow this tool for the whole session', $c),
            KeyBinding::new('permission.deny', 'n', 'Refuse the call (or Esc)', $c),
        ];
    }

    /**
     * @return list<KeyBinding>
     */
    private static function agents(): array
    {
        $c = self::CONTEXT_AGENTS;
        // The criterion is "nothing observable happens", not "the command is on
        // App::consumeShellCmd()'s inert list". That list names FIVE commands,
        // and the fifth — QuitAgentViewCmd, behind `q` — is live below: its
        // pane/selection half IS applied, by handleAgentViewKey() itself, so
        // pressing it visibly leaves the view. These three have no such half.
        $inert = 'KeyboardHandler::handleAgentViewKey() claims the key and returns a command with '
            . 'no consumer: App::consumeShellCmd() lists it among the deliberately inert ones, and '
            . 'unlike QuitAgentViewCmd it has no pane/selection half that lands anyway — the shell '
            . 'holds no worker pool to translate the action into. So the key does nothing the user '
            . 'can see, and the reference must not promise it.';

        return [
            KeyBinding::new('agents.move', '↑ / ↓', 'Move the selection (or k / j)', $c),
            KeyBinding::new('agents.peek', 'Enter', 'Look at the selected agent (or Space)', $c),
            KeyBinding::new('agents.slot', 'Alt+1…9', 'Jump to that numbered dashboard row', $c),
            KeyBinding::new('agents.back', 'Esc', 'Drop the selection, then leave the view', $c),
            KeyBinding::new('agents.quit', 'q', 'Leave the agent view', $c),
            KeyBinding::new('agents.cancel', 'c', 'Cancel the selected agent', $c, dormantReason: $inert),
            KeyBinding::new('agents.resume', 'r', 'Resume the selected agent', $c, dormantReason: $inert),
            KeyBinding::new('agents.stop-all', 's', 'Stop every agent', $c, dormantReason: $inert),
        ];
    }

    /**
     * @return list<KeyBinding>
     */
    private static function skills(): array
    {
        $c = self::CONTEXT_SKILLS;

        return [
            KeyBinding::new('skills.move', '↑ / ↓', 'Move the highlighted skill (or k / j)', $c),
            KeyBinding::new('skills.select', 'Enter', 'Enable the highlighted skill', $c),
            KeyBinding::new('skills.close', 'Esc', 'Dismiss the picker', $c),
        ];
    }

    /**
     * @return list<KeyBinding>
     */
    private static function menu(): array
    {
        $c = self::CONTEXT_MENU;

        return [
            KeyBinding::new('menu.switch', '← / →', 'Switch menu (or h / l)', $c),
            KeyBinding::new('menu.move', '↑ / ↓', 'Move the highlighted row (or k / j)', $c),
            KeyBinding::new('menu.run', 'Enter', 'Run the row (or o)', $c),
            KeyBinding::new('menu.close', 'Esc', 'Close the menu (or q)', $c),
        ];
    }

    /**
     * @return list<KeyBinding>
     */
    private static function mouse(): array
    {
        $c = self::CONTEXT_MOUSE;

        return [
            KeyBinding::new('mouse.wheel', 'Wheel', 'Scroll the transcript', $c),
            KeyBinding::new('mouse.tab', 'Click tab', 'Switch to that session', $c),
            KeyBinding::new('mouse.pane', 'Click pane', 'Focus that pane', $c),
            KeyBinding::new('mouse.tool-call', 'Click tool', 'Expand or collapse that call\'s output', $c),
            KeyBinding::new('mouse.palette-row', 'Click row', 'Run that palette row', $c),
        ];
    }
}

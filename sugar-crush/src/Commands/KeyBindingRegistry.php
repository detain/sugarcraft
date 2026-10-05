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
    /** Keys the live agents strip answers while it holds the keyboard (`Alt+↓`). */
    public const CONTEXT_AGENT_STRIP = 'Agents strip';
    /** Keys the read-only Agent View answers while it shows a run's transcript (P-C2). */
    public const CONTEXT_AGENT_TRANSCRIPT = 'Agent transcript';
    /** Keys the Ctrl+S skill picker answers while it is open. */
    public const CONTEXT_SKILLS = 'Skill picker';
    /** Keys the full-band settings view (`/settings`) answers while it is open. */
    public const CONTEXT_SETTINGS = 'Settings view';
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
            ...self::agentStrip(),
            ...self::agentTranscript(),
            ...self::skills(),
            ...self::settings(),
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
     * ── the ghost this left open (trackers #83/#85, E12) — now stood down ──
     *
     * Condition 1 DID hold for `Ctrl+P`, which is why the chord being
     * un-yielded was never the same as nothing being wrong. Measured at
     * 100×30 with the palette opened by `Ctrl+P` — or by `Ctrl+K`, which the
     * shell translates into the same keystroke, so both doors led to the same
     * room and neither was a way out of it: `Pane::Agents` set
     * `Chat::palette()` while painting NO palette
     * (`Tui\Renderer::renderAgentDashboard()` replaces the whole content
     * band, so the hosted chat's frame — overlay and all — is not drawn) and
     * `Down` moved the dashboard selection; an open skill picker or F10 menu
     * DID paint the palette, but `Down` drove the picker's highlight or the
     * menu instead. The chord left a ghost behind — invisible AND undrivable
     * in the agent view, painted-but-undrivable behind the other two states,
     * revealed the moment the user left.
     *
     * The ghost is closed by two routes, neither a claim-set change (which
     * measures worse, as above). The STAND-DOWN route:
     * {@see \SugarCraft\Crush\Tui\KeyboardHandler::paletteStandsDown()} makes
     * the chord a true no-op while the keyboard-owning shell views are up —
     * nothing opens, and nothing waits on the other side of the exit. The
     * ADOPTION route, for a palette already open when some OTHER door handed
     * the keyboard over (Tab, the agents toggle, F10, the skill picker): the
     * shared choke point {@see \SugarCraft\Crush\App\App::delegateToChat()}
     * closes the abandoned palette on the next fall-through keystroke
     * ({@see \SugarCraft\Crush\Tui\KeyboardHandler::paletteIsAbandoned()},
     * E666), and since E682 the composite no longer PAINTS what the keyboard
     * is not driving —
     * {@see \SugarCraft\Crush\Renderer::setPaletteAbandoned()}
     * ({@see \SugarCraft\Crush\App\App::view()}) drops the abandoned palette
     * from the shell frame, so the menu and picker states are no longer
     * painted-but-undrivable while the closure waits for its keystroke. The
     * full COMPOSITE route — the shell painting a hosted overlay over its
     * full-pane views so the chord becomes LIVE there — remains open as its
     * own layout item, recorded on the E12 entry in
     * docs/plans/crush_code_hardening_backlog.md.
     * {@see \SugarCraft\Crush\Tests\Tui\KeyboardHandlerTest::testTheAgentViewTakesAPaletteItNeitherPaintsNorDrives()}
     * pinned the ghost; it now pins the stand-down.
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
     * are mutually exclusive. Swept exhaustively over 2 menu states × 10 panes ×
     * 95 printable runes × Ctrl on/off = 3800 keypresses: 1900 derive nothing,
     * 1031 derive one set, 869 derive two, none derive three.
     *
     * That sweep visits the panes at their DEFAULT sub-state, and a sub-state is
     * not neutral here: opening the skill picker in `Pane::Skills` flips
     * `KeyboardHandler::shellOwnsKeyboard()`, which is the very predicate
     * choosing which set derives (`ctrl+r` there goes `{Chat}` → `{Chat,
     * YIELDED}`). So the same rune × Ctrl sweep is run again over a CORPUS of 8
     * keyboard-owning sub-states = 1520 more keypresses: 760 derive nothing, 704
     * derive one, 56 derive two, none derive three. A corpus, not an
     * enumeration: it covers `AgentViewMode` exhaustively but the agent view's
     * selection index only at -1 and 0, two skill options, and the menu strip's
     * first menu — `KeyboardHandlerTest::keyboardOwningSubStates()` states that
     * boundary, and its `assertCount(8, …)` pins the corpus SIZE, not that 8 is
     * how many such sub-states exist. Both distributions are
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
     * the table rows and re-runs both sweeps, the 3800 and the 1520.
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
            // Roadmap 1.C-3 (D6): mid-turn, Enter steers the running turn (the
            // agent reads it at its next step) and Tab queues it for after.
            KeyBinding::new('chat.steer', 'Enter', 'Mid-turn: steer the agent at its next step', $c),
            KeyBinding::new('chat.queue', 'Tab', 'Mid-turn: queue the draft for after this turn', $c),
            KeyBinding::new('chat.newline', 'Alt+Enter', 'Insert a newline instead of sending', $c),
            KeyBinding::new('chat.slash-menu', '↑ / ↓', 'Move through the "/" command popup', $c),
            // Declared in the SAME round the keystroke went live (W4), unlike
            // the editing rows below: without it the reference said Tab
            // "focuses the next pane" full stop, and the completion the user
            // asked for was invisible to the only in-app key list there is.
            KeyBinding::new('chat.slash-complete', 'Tab', 'Complete the highlighted "/" command', $c),
            // Audit 15b-15: the second thing a bare Tab completes, on the same
            // shell-yield contract (Chat::mentionOwnsTab()).
            KeyBinding::new('chat.mention-complete', 'Tab', 'Complete the @file path at the cursor', $c),
            KeyBinding::new('chat.recall', '↑', 'Walk back through past prompts (empty box)', $c),
            KeyBinding::new('chat.recall-next', '↓', 'Walk forward again, then back to your draft', $c),
            KeyBinding::new('chat.accept-suggestion', '→', 'Take the grayed suggestion (empty input box)', $c),
            // ── the draft's own editing keyboard ─────────────────────────
            //
            // Everything from here to `chat.space` is answered by the draft
            // editor (`candy-forms`' TextArea, wired in crush_code.md Phase 3
            // item 1). Every row here except `chat.backspace` and
            // `chat.word-delete` was declared in the round AFTER the keystroke
            // it describes went live, which is the gap
            // `ChatInputCursorTest::testEveryDelegatedKeyTypeIsDisclosedInTheReference()`
            // now closes: it fails if the next one arrives the same way.
            KeyBinding::new('chat.backspace', 'Backspace', 'Delete the previous character', $c),
            KeyBinding::new('chat.delete-forward', 'Delete', 'Delete the character under the cursor', $c),
            KeyBinding::new('chat.cursor', '← / →', 'Move the cursor one character', $c),
            // Labelled with the ALT spelling, and the Ctrl one carried as the
            // alternate, because of what reads this field: KeyBinding::ctrlRune()
            // takes the single-character tail of any `Ctrl+…` label as a RUNE
            // and hands it to chatCtrlRunes(), a set KeyboardHandler compares
            // against KeyMsg::$rune. An arrow never arrives as a rune, so a
            // `Ctrl+←` label would put a member in that claim set that nothing
            // can ever match. Both spellings are driven either way — the
            // `(or …)` form is what KeyBindingDriftTest presses.
            KeyBinding::new('chat.word-motion', 'Alt+← / Alt+→', 'Move one word (or Ctrl+← / Ctrl+→)', $c),
            KeyBinding::new('chat.line-ends', 'Home / End', 'Jump to the first or last column', $c),
            KeyBinding::new('chat.draft-rows', '↑ / ↓', 'Move between the rows of a multi-line draft', $c),
            KeyBinding::new('chat.word-delete', 'Ctrl+W', 'Delete the previous word (or Alt+Backspace)', $c),
            KeyBinding::new('chat.word-delete-back', 'Ctrl+Backspace', 'Delete the previous word', $c),
            KeyBinding::new('chat.word-delete-forward', 'Ctrl+Delete', 'Delete the word after the cursor', $c),
            KeyBinding::new('chat.space', 'Ctrl+Space', 'Insert a blank character (modifier ignored)', $c),
            KeyBinding::new('chat.page', 'PgUp / PgDn', 'Scroll the transcript by a screenful', $c),
            KeyBinding::new('chat.palette', 'Ctrl+P', 'Open the command palette', $c),
            KeyBinding::new('chat.tool-output', 'Ctrl+O', 'Expand or collapse newest tool output/thought', $c),
            // Audit 15b-15: a terminal pastes text only, so the clipboard's
            // IMAGE is read through the platform tool (Support\ClipboardImage).
            KeyBinding::new('chat.paste-image', 'Ctrl+V', 'Attach the clipboard image as an @ mention', $c),
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
            // Roadmap 1.C-4a: on an engine turn that reports its steps the
            // first Esc asks for a soft stop at the step boundary, and any
            // later Esc cancels hard; a turn with no steps keeps Esc Esc.
            // 1.C-4b: that first Esc also stops the call running right now
            // (`cancel_tool`) instead of waiting for it to finish.
            KeyBinding::new('chat.stop', 'Esc', 'Stop the running tool, then the turn', $c),
            KeyBinding::new('chat.cancel', 'Esc Esc', 'Cancel the turn in flight — twice, quickly', $c),
            // Roadmap P-B3: the one-row live agents strip above the input
            // takes the keyboard; its own keys are the `Agents strip` rows.
            KeyBinding::new('chat.agents-strip', 'Alt+↓', 'Focus the live agents strip', $c),
            // Roadmap 5.7-1 (decision D8): Shift+Tab stays `shell.pane-prev`.
            KeyBinding::new('chat.plan-mode', 'Alt+M', 'Toggle plan mode (between turns)', $c),
            // E744: with a draft selection held this chord COPIES first and the
            // next press quits (Chat's Ctrl+C arm). The nuance stays out of the
            // description — renderKeyHelp() clips long text and KeyHelpTest
            // demands every row paint in full — and lives in the README table.
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
            // The Tab pair cycles focus over the DOCKED frame — the panes
            // actually on screen, in left-to-right, stack-top-to-bottom order
            // (`App::paneCycleOrder()`), not the fixed six-pane strip these
            // rows promised before pane docking L2. The old pane-next text
            // carried the completion exception as prose ("unless a \"/\" popup
            // is open"); under L2 the exception is structural — completion is
            // answered by `chat.slash-complete` only while chat itself holds
            // focus, so the row no longer needs to say it, and a dock pane's
            // Tab cycles even with the popup open.
            KeyBinding::new('shell.pane-next', 'Tab', 'Focus the next docked pane', $c),
            KeyBinding::new('shell.pane-prev', 'Shift+Tab', 'Focus the previous docked pane', $c),
            KeyBinding::new('shell.menu', 'F10', 'Open the menu bar', $c),
            KeyBinding::new('shell.pane-chat', 'Esc', 'Leave the pane, back to the chat', $c),
            // Enter keeps its chat meaning (send) everywhere it had it; this
            // row is the one new door: with a dockable pane focused and the
            // draft empty, Enter opens the palette, which is how a read-only
            // list pane (Files, Tools, Skills) reaches the commands. The
            // Settings pane's door is the settings view instead (next row);
            // the Agents dashboard answers Enter itself.
            KeyBinding::new(
                'shell.pane-palette',
                'Enter',
                'Open the palette from a list pane (empty draft)',
                $c,
            ),
            KeyBinding::new(
                'shell.settings-open',
                'Enter',
                'Open settings view from its pane (empty draft)',
                $c,
            ),
            KeyBinding::new('shell.new-session', 'Ctrl+N', 'Start a fresh session', $c),
            KeyBinding::new('shell.palette', 'Ctrl+K', 'Open the command palette', $c),
            KeyBinding::new('shell.skills', 'Ctrl+S', 'Open the skill picker', $c),
            KeyBinding::new('shell.settings', 'Ctrl+,', 'Focus the settings pane; again to open the view', $c),
            // P-D3: GroupInputCmd became the Agent View composer's broadcast.
            KeyBinding::new('shell.group-input', 'Ctrl+G', 'Message every running agent at once', $c),
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
     * The session list's keys (Appendix P §3.2).
     *
     * The row actions are bare letters, so `/` opens the filter rather than
     * typing filtering: that is what keeps `k`/`j` moving the highlight. Inside
     * the filter the letters are text, so rename, delete and pin also answer
     * to a `Ctrl+` alias there — the `(or …)` asides below, which the drift
     * test presses too. Those aliases live in this context only:
     * {@see chatCtrlRunes()} and {@see shellCtrlRunes()} never read it, so the
     * rune-sweep figures documented on {@see $ctrlRuneMemo} do not move.
     *
     * @return list<KeyBinding>
     */
    private static function picker(): array
    {
        $c = self::CONTEXT_PICKER;

        return [
            KeyBinding::new('picker.move', '↑ / ↓', 'Move the highlighted session (or k / j)', $c),
            KeyBinding::new('picker.resume', 'Enter', 'Resume the highlighted session', $c),
            KeyBinding::new('picker.preview', 'Space', 'Preview the last messages of the session', $c),
            KeyBinding::new('picker.filter', '/', 'Filter the sessions as you type', $c),
            KeyBinding::new('picker.rename', 'r', 'Rename the session in place (or Ctrl+E)', $c),
            KeyBinding::new('picker.delete', 'd', 'Delete the session, pressed twice (or Ctrl+D)', $c),
            KeyBinding::new('picker.delete-children', 'D', 'Confirm a delete, its branch sessions too', $c),
            KeyBinding::new('picker.pin', 'p', 'Pin or unpin the session (or Ctrl+F)', $c),
            KeyBinding::new('picker.fork', 'f', 'Fork the session and switch to the copy', $c),
            KeyBinding::new('picker.archive', 'x', 'Archive the session', $c),
            KeyBinding::new('picker.unarchive', 'u', 'Bring an archived session back', $c),
            KeyBinding::new('picker.archived', 'a', 'Show or hide archived sessions', $c),
            KeyBinding::new('picker.children', 'Tab', 'Show or hide sub-agent sessions', $c),
            KeyBinding::new('picker.branch', 'Ctrl+B', 'Filter to the current git branch, or all', $c),
            KeyBinding::new('picker.close', 'Esc', 'Close the picker, or clear the filter first', $c),
        ];
    }

    /**
     * The prompt's four keys, and why the two descriptions that changed had to.
     *
     * A permission prompt only answers to a letter while it is ARMED, and one
     * keystroke that is not an answer disarms it
     * ({@see \SugarCraft\Crush\Permissions\PermissionPromptStage}). So `a` no
     * longer grants — it asks — and `Enter` is a live binding of its own rather
     * than an inert key, which is exactly the kind of promise this reference
     * exists to keep honest. Leaving the old wording would have this screen
     * telling a user that one keystroke buys a session-wide grant, which is the
     * behaviour the fix removed.
     *
     * @return list<KeyBinding>
     */
    private static function permission(): array
    {
        $c = self::CONTEXT_PERMISSION;

        return [
            KeyBinding::new('permission.once', 'y', 'Allow this one call', $c),
            // "Calls like this one", not "this call" or "every call to this
            // tool": a confirmed grant is remembered as a PATTERN on the engine
            // path (Permissions\SessionPermissionMemo — `git status` grants
            // `Bash(git status *)`, an edit grants that path) and as the exact
            // call on Chat's own path, which is a call like itself.
            KeyBinding::new('permission.always', 'a', 'Ask to allow calls like this one for the session', $c),
            KeyBinding::new('permission.deny', 'n', 'Refuse the call (or Esc)', $c),
            // R-KEYBIND (1.C-3 wave): refuse with words the model reads, and
            // refuse-and-stop (the turn ends at the step boundary).
            KeyBinding::new('permission.note', 'r', 'Refuse with a note the agent reads', $c),
            KeyBinding::new('permission.stop', 'x', 'Refuse the call and stop the turn', $c),
            KeyBinding::new('permission.rearm', 'Enter', 'Make the answer keys live again', $c),
        ];
    }

    /**
     * @return list<KeyBinding>
     */
    private static function agents(): array
    {
        $c = self::CONTEXT_AGENTS;

        // c/r/s went live with P-D3: each becomes an AgentControlMsg for the
        // selected row's run (every running run, for `s`), delivered through
        // the runs' mailboxes — App::consumeShellCmd() names none inert now.
        return [
            KeyBinding::new('agents.move', '↑ / ↓', 'Move the selection (or k / j)', $c),
            KeyBinding::new('agents.peek', 'Enter', 'Look at the selected agent (or Space)', $c),
            KeyBinding::new('agents.attach', 'Enter', 'Open that agent\'s transcript (in the peek)', $c),
            KeyBinding::new('agents.slot', 'Alt+1…9', 'Jump to that numbered dashboard row', $c),
            KeyBinding::new('agents.back', 'Esc', 'Drop the selection, then leave the view', $c),
            KeyBinding::new('agents.quit', 'q', 'Leave the view and any open agent transcript', $c),
            KeyBinding::new('agents.cancel', 'c', 'Cancel the selected agent; again to stop now', $c),
            KeyBinding::new('agents.resume', 'r', 'Resume the selected agent, or continue it', $c),
            KeyBinding::new('agents.stop-all', 's', 'Stop every running agent', $c),
        ];
    }

    /**
     * The live agents strip (roadmap P-B3, Appendix P §4.5) while `Alt+↓` has
     * given it the keyboard. {@see \SugarCraft\Crush\Tui\KeyboardHandler}
     * answers these; any other key hands the keyboard back to the input box
     * and lands there.
     *
     * @return list<KeyBinding>
     */
    private static function agentStrip(): array
    {
        $c = self::CONTEXT_AGENT_STRIP;

        return [
            KeyBinding::new('strip.move', '← / →', 'Move along the strip (or ↑ / ↓)', $c),
            KeyBinding::new('strip.open', 'Enter', 'Open the focused agent\'s transcript', $c),
            KeyBinding::new('strip.cancel', 'c', 'Stop the focused agent', $c),
            KeyBinding::new('strip.dismiss', 'x', 'Dismiss if finished, else stop it', $c),
            KeyBinding::new('strip.back', 'Esc', 'Back to the input box (or Alt+↑)', $c),
        ];
    }

    /**
     * The Agent View (roadmap P-C2, Appendix P §5.5): a delegated run's own
     * transcript in the main area, opened by a click on its live line,
     * `Enter` on the strip, or `Enter` in the dashboard's peek, with the
     * input box as that run's composer (P-D2).
     * {@see \SugarCraft\Crush\Tui\KeyboardHandler} answers these; every other
     * key reaches the chat as before.
     *
     * @return list<KeyBinding>
     */
    private static function agentTranscript(): array
    {
        $c = self::CONTEXT_AGENT_TRANSCRIPT;

        return [
            KeyBinding::new('agentview.back', 'Esc', 'Back to the main transcript (or Alt+↑)', $c),
            // P-D2: the input box is the agent's composer while its view is
            // open — a draft goes to the run's mailbox (or continues a
            // finished run), never to the main model.
            KeyBinding::new('agentview.send', 'Enter', 'Send the draft to the agent on screen', $c),
            // P-D3: the view's controls, behind a `Ctrl+X` leader (opencode's
            // chord) — the composer has the plain letters. Read back as
            // two-key sequences by KeyBindingDriftTest; their rune tail is
            // not one character, so they claim no Ctrl rune of their own.
            KeyBinding::new('agentview.cancel', 'Ctrl+X c', 'Cancel this agent; again to stop it now', $c),
            KeyBinding::new('agentview.pause', 'Ctrl+X p', 'Pause this agent, or let it go on', $c),
            KeyBinding::new('agentview.stop-all', 'Ctrl+X s', 'Stop every running agent', $c),
            KeyBinding::new('agentview.open-session', 'Ctrl+X o', 'Open this agent as a session', $c),
            KeyBinding::new(
                'agentview.background',
                'Ctrl+X b',
                'Send this agent to the background',
                $c,
                dormantReason: 'Promoting a running Task to the background needs roadmap 4.3 (P-E3): '
                    . 'the chord is claimed — so the `b` is not typed into the composer — but '
                    . 'nothing hands the run to the BackgroundSupervisor yet.',
            ),
            KeyBinding::new('agentview.next', 'Alt+N', 'Open the next agent of the same batch', $c),
            KeyBinding::new('agentview.prev', 'Alt+P', 'Open the previous agent of the same batch', $c),
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
     * The settings view (roadmap N-P1). Read-only in this phase, so there is no
     * save or reset key — and decision D7 keeps Ctrl+S / Ctrl+R off it in any
     * case, because both are shell chords already (`shell.skills`, the picker).
     * While the search box has focus every printable key types into it; the
     * `(or …)` letters below apply outside it.
     *
     * @return list<KeyBinding>
     */
    private static function settings(): array
    {
        $c = self::CONTEXT_SETTINGS;

        return [
            KeyBinding::new('settings.move', '↑ / ↓', 'Move between settings (or k / j)', $c),
            KeyBinding::new('settings.category', '← / →', 'Switch category (or h / l)', $c),
            KeyBinding::new('settings.search', '/', 'Search every category as you type', $c),
            KeyBinding::new('settings.search-keep', 'Enter', 'Stop typing the search, keep its matches', $c),
            KeyBinding::new('settings.erase', 'Backspace', 'Erase the last character of the search', $c),
            KeyBinding::new('settings.close', 'Esc', 'Clear the search, then close the view', $c),
            // The editor's own keys (W4-g built the save door; decision D7:
            // plain letters, no Ctrl+S / Ctrl+R). `s` and `y` are answered by
            // the shell (App::settingsShellKey()), which holds the writer.
            KeyBinding::new('settings.edit', 'Enter', 'Edit the highlighted setting', $c),
            KeyBinding::new('settings.stage', 'Enter', 'Stage the value being edited', $c),
            KeyBinding::new('settings.cancel-edit', 'Esc', 'Drop the value being edited', $c),
            KeyBinding::new('settings.reset', 'r', 'Stage a reset to the default', $c),
            KeyBinding::new('settings.tier', 't', 'Switch the file a save writes', $c),
            KeyBinding::new('settings.save', 's', 'Preview the save of what is staged', $c),
            KeyBinding::new('settings.confirm', 'y', 'Save the previewed changes (or Enter)', $c),
            KeyBinding::new('settings.back', 'n', 'Leave the preview unsaved (or Esc)', $c),
            KeyBinding::new('settings.trust', 'y', 'Confirm a project trust grant', $c),
            KeyBinding::new('settings.discard', 'd', 'Discard unsaved changes and close', $c),
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
            KeyBinding::new('mouse.wheel', 'Wheel', 'Scroll the transcript, or the pane under it', $c),
            KeyBinding::new('mouse.tab', 'Click tab', 'Switch to that session', $c),
            KeyBinding::new('mouse.pane', 'Click pane', 'Open the pane menu (palette)', $c),
            KeyBinding::new('mouse.tool-call', 'Click tool', 'Expand or collapse that call\'s output', $c),
            KeyBinding::new('mouse.side-row', 'Click side row', 'Expand or collapse that Tools or Agents pane row', $c),
            KeyBinding::new('mouse.palette-row', 'Click row', 'Run that palette row', $c),
            KeyBinding::new('mouse.session-action', 'Click ✎ ★ ✕', 'Rename, pin or delete the picker session', $c),
            KeyBinding::new('mouse.agent', 'Click agent', 'Open that agent\'s transcript (a Task line)', $c),
        ];
    }
}

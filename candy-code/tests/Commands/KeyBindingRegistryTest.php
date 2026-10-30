<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\KeyBinding;
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Shape of the keybinding table itself. What each row DOES is
 * {@see KeyBindingDriftTest}'s job; this covers the invariants both consumers
 * (the reference screen and {@see \SugarCraft\Code\Tui\KeyboardHandler}'s
 * claim sets) depend on.
 *
 * @see KeyBindingRegistry
 * @see KeyBinding
 */
final class KeyBindingRegistryTest extends TestCase
{
    use ResetsDerivedRuneSets;

    /**
     * Every test here reads a derived set, and the memos behind them are
     * process-global statics with no production reset — so without this the
     * first test to touch one warms it for the whole run and every later
     * assertion is served from a cache rather than from the accessor. See
     * {@see ResetsDerivedRuneSets} for the failure mode that hides.
     */
    protected function setUp(): void
    {
        $this->resetDerivedRuneSets();
    }

    /** Overrides are a process-wide static — clear-what-you-set, like launch. */
    protected function tearDown(): void
    {
        KeyBindingRegistry::useOverrides([]);

        parent::tearDown();
    }

    public function testIdsAreUnique(): void
    {
        $ids = array_map(static fn(KeyBinding $b): string => $b->id, KeyBindingRegistry::all());

        $this->assertSame(array_values(array_unique($ids)), $ids);
    }

    public function testEveryRowIsFullyPopulated(): void
    {
        $contexts = [
            KeyBindingRegistry::CONTEXT_SHELL,
            KeyBindingRegistry::CONTEXT_CHAT,
            KeyBindingRegistry::CONTEXT_PALETTE,
            KeyBindingRegistry::CONTEXT_PICKER,
            KeyBindingRegistry::CONTEXT_DIRPICKER,
            KeyBindingRegistry::CONTEXT_PERMISSION,
            KeyBindingRegistry::CONTEXT_AGENTS,
            KeyBindingRegistry::CONTEXT_AGENT_STRIP,
            KeyBindingRegistry::CONTEXT_AGENT_TRANSCRIPT,
            KeyBindingRegistry::CONTEXT_SKILLS,
            KeyBindingRegistry::CONTEXT_CONTEXT_PANE,
            KeyBindingRegistry::CONTEXT_SETTINGS,
            KeyBindingRegistry::CONTEXT_MEDIA,
            KeyBindingRegistry::CONTEXT_MENU,
            KeyBindingRegistry::CONTEXT_MOUSE,
            KeyBindingRegistry::CONTEXT_DIFF_REVIEW,
            KeyBindingRegistry::CONTEXT_MSG_PICKER,
            KeyBindingRegistry::CONTEXT_WIZARD,
        ];

        foreach (KeyBindingRegistry::all() as $binding) {
            $this->assertNotSame('', $binding->id);
            $this->assertNotSame('', $binding->keys, $binding->id . ' has no key label');
            $this->assertNotSame('', $binding->description, $binding->id . ' has no description');
            $this->assertContains($binding->context, $contexts, $binding->id . ' has an unknown context');
        }
    }

    public function testDormantRowsCarryAReasonAndAreKeptOutOfTheReference(): void
    {
        // None is dormant since P-E3; the contract still holds for the next.
        $dormant = [...KeyBindingRegistry::dormant(), KeyBinding::new('x.waiting', 'Ctrl+X z', 'd', 'c', dormantReason: 'waits')];

        foreach ($dormant as $binding) {
            $this->assertNotSame('', (string) $binding->dormantReason, $binding->id);
            $this->assertNotContains($binding, KeyBindingRegistry::live());
        }
    }

    public function testGroupedListsOnlyLiveRowsAndPreservesDeclaredOrder(): void
    {
        $flattened = [];
        foreach (KeyBindingRegistry::grouped() as $context => $bindings) {
            foreach ($bindings as $binding) {
                $this->assertSame($context, KeyBindingRegistry::contextLabel($binding->context));
                $flattened[] = $binding;
            }
        }

        $this->assertEquals(KeyBindingRegistry::live(), $flattened);
    }

    public function testByIdFindsDeclaredRowsAndNothingElse(): void
    {
        $this->assertNotNull(KeyBindingRegistry::byId('chat.palette'));
        $this->assertNull(KeyBindingRegistry::byId('chat.no-such-binding'));
    }

    /**
     * The shell's claim set, pinned. It is DERIVED from the table now, so this
     * is the test that has to be edited deliberately when a chord moves — the
     * point being that it cannot move by accident.
     */
    public function testShellClaimsExactlyTheChordsItsRowsDeclare(): void
    {
        $runes = KeyBindingRegistry::shellCtrlRunes();
        sort($runes);

        $this->assertSame([',', 'g', 'k', 'n', 's'], $runes);
    }

    public function testChatClaimsExactlyTheChordsItsRowsDeclare(): void
    {
        $runes = KeyBindingRegistry::chatCtrlRunes();
        sort($runes);

        $this->assertSame(['a', 'c', 'f', 'g', 'l', 'o', 'p', 'r', 'v', 'w'], $runes);
    }

    /**
     * The exceptions the shell's claim layer reads, pinned exactly — they are
     * the only holes in "content wins outright", and they may not grow by
     * accident. The polity has two shapes: `Ctrl+R` is SWALLOWED while the
     * shell owns the keyboard (its shell answer is a no-op), and `Ctrl+G` is
     * REBOUND to a live shell feature (Broadcast) there — the dual claim of
     * plan_comparison TUI-2, which keeps an ordinary pane's `Ctrl+G` falling
     * through to the history search while the agent dashboard, skill picker
     * and `F10` menu keep their broadcast chord. Both conditions of the yield
     * criterion, and each rune's polity, live in
     * {@see KeyBindingRegistry::chatCtrlRunesYieldedToShell()}; the rebind
     * half is driven, not asserted here, by
     * {@see \SugarCraft\Code\Tests\Tui\KeyboardHandlerTest::testEveryYieldedRebindChordStaysWithTheShell()}.
     *
     * Ctrl+P is deliberately NOT here: it fails condition 2 in its strict
     * form — the shell answers `ctrl+p` with `ProviderSelectCmd`, and that
     * rebind lands on `/model`, which is worse than swallowing, not a
     * feature the yielding states surface on screen.
     */
    public function testTheYieldedChatChordsAreExactlyCtrlRAndCtrlG(): void
    {
        $this->assertSame(['r', 'g'], KeyBindingRegistry::chatCtrlRunesYieldedToShell());
    }

    /**
     * Each derived set the hot path reads must be memoised AND must answer with
     * what it memoised.
     *
     * Asserted on the RETURN VALUE of a cold call, not on the stored property.
     * Reading the property proves only that something was written there: an
     * accessor that fills the memo correctly and then returns a different value
     * ( `self::$yieldedRuneMemo = $runes; return [];` ) passes a stored-value
     * check, and the process's very FIRST press is the one that gets the wrong
     * answer — measured, that press sends `Ctrl+R` through to Chat inside
     * `Pane::Agents` and opens the invisible undrivable picker the yield exists
     * to prevent, while every later press is served from the memo and behaves.
     * A test that cannot see a cold call cannot see that bug, which is why the
     * memos are reset in {@see setUp()}.
     *
     * Domain: one cold process-state per assertion (the reset in `setUp()`),
     * two calls per accessor.
     */
    public function testEveryDerivedSetTheHotPathReadsAnswersFromItsMemo(): void
    {
        // Cold: the first caller must get the real set, not the empty one.
        // In DECLARED row order, which is the order the accessors build in —
        // the sorted spellings live in the two tests above.
        $this->assertSame(['r', 'g'], KeyBindingRegistry::chatCtrlRunesYieldedToShell(), 'cold call');
        $this->assertSame(['w', 'p', 'o', 'f', 'l', 'v', 'r', 'g', 'a', 'c'], KeyBindingRegistry::chatCtrlRunes(), 'cold call');
        $this->assertSame(['n', 'k', 's', ',', 'g'], KeyBindingRegistry::shellCtrlRunes(), 'cold call');

        // Warm: the same answer, and now out of the memo rather than rebuilt.
        $this->assertSame(['r', 'g'], KeyBindingRegistry::chatCtrlRunesYieldedToShell(), 'warm call');
        $this->assertSame(['w', 'p', 'o', 'f', 'l', 'v', 'r', 'g', 'a', 'c'], KeyBindingRegistry::chatCtrlRunes(), 'warm call');
        $this->assertSame(['n', 'k', 's', ',', 'g'], KeyBindingRegistry::shellCtrlRunes(), 'warm call');

        $byContext = (new \ReflectionProperty(KeyBindingRegistry::class, 'ctrlRuneMemo'))->getValue();
        $yielded = (new \ReflectionProperty(KeyBindingRegistry::class, 'yieldedRuneMemo'))->getValue();

        $this->assertArrayHasKey(KeyBindingRegistry::CONTEXT_CHAT, $byContext);
        $this->assertArrayHasKey(KeyBindingRegistry::CONTEXT_SHELL, $byContext);
        // The stored value and the returned one are the same value — the half a
        // stored-value assertion gets right, kept, now that the returned half
        // is asserted above.
        $this->assertSame(KeyBindingRegistry::chatCtrlRunesYieldedToShell(), $yielded);
        $this->assertSame(KeyBindingRegistry::chatCtrlRunes(), $byContext[KeyBindingRegistry::CONTEXT_CHAT]);
    }

    /**
     * A cold reset really is cold, so the assertions above are measuring the
     * accessor rather than a memo an earlier test filled.
     *
     * Without this, {@see setUp()} could stop resetting and nothing would
     * notice — every test in the class would still pass, warm.
     */
    public function testTheMemosStartEachTestUnderived(): void
    {
        $this->assertSame(0, $this->derivedRuneSetCount(), 'setUp() must hand each test a cold process');

        KeyBindingRegistry::chatCtrlRunes();

        $this->assertSame(1, $this->derivedRuneSetCount(), 'and one lookup must derive exactly one set');
    }

    /**
     * The yielded set must be a strict SUBSET of the chat set. A rune yielded
     * without being chat-owned in the first place would be a routing rule no
     * layer reads: {@see \SugarCraft\Code\Tui\KeyboardHandler::chatOwns()}
     * only consults it after the chord is already known to be Chat's.
     */
    public function testEveryYieldedChordIsAChatChord(): void
    {
        foreach (KeyBindingRegistry::chatCtrlRunesYieldedToShell() as $rune) {
            $this->assertContains($rune, KeyBindingRegistry::chatCtrlRunes(), 'ctrl+' . $rune);
        }

        foreach (KeyBindingRegistry::all() as $binding) {
            if (!$binding->yieldsToShell()) {
                continue;
            }
            $this->assertSame(
                KeyBindingRegistry::CONTEXT_CHAT,
                $binding->context,
                $binding->id . ' declares a yield but is not a chat row, so nothing reads it',
            );
            $this->assertNotSame('', (string) $binding->yieldsToShellReason, $binding->id);
        }
    }

    /**
     * The shape of the table, stated as numbers because prose elsewhere states
     * them — {@see \SugarCraft\Code\Chat::handleKeyHelpKey()}'s "109 live rows
     * across 11 contexts — 113 in all", the sweep counts in
     * {@see \SugarCraft\Code\Tests\Renderer\KeyHelpTest}, and
     * {@see \SugarCraft\Code\Tests\Commands\KeyBindingDriftTest}'s KEYISH
     * docblock ("all 113 declared rows"). A prose number nobody measures is how
     * a reference goes stale; this is the measurement. Those three files are
     * the domain of that list: it is where `grep -rn` for the figures found
     * them, not a claim that no other file could grow one.
     *
     * 53 -> 54 live when `permission.rearm` was declared: a permission prompt
     * disarmed by a stray keystroke only answers again after Enter re-arms it,
     * so that Enter is a live binding and this reference has to say so.
     *
     * 54 -> 62 live when the draft's own editing keyboard was declared. Those
     * eight rows describe keystrokes that had been LIVE and undocumented since
     * the draft moved into `sugar-forms`' TextArea: forward Delete, ←/→,
     * Home/End, ↑/↓ on a multi-line draft, word motion, and the three ctrl
     * forms (Ctrl+Space, Ctrl+Backspace, Ctrl+Delete).
     *
     * 62 -> 63 live when `chat.slash-complete` was declared (W4): bare Tab
     * completes the highlighted "/" row, and `shell.pane-next` had been
     * promising that same Tab focuses the next pane with no exception stated.
     *
     * 63 -> 65 live with pane docking L2: `shell.pane-prev` declared the
     * Shift+Tab half of the focus cycle (live and claimed since the sidebar
     * era, listed by nothing), and `shell.pane-palette` declared the Enter door
     * — Enter on a docked pane with an empty draft opens the palette, the only
     * route from a read-only pane to the commands that change settings.
     *
     * 65 -> 66 live when `chat.accept-suggestion` was declared: → on an empty
     * box takes the grayed next-message suggestion as the draft.
     *
     * 66 -> 67 live (70 -> 71 all) when `chat.recall-next` was declared: ↓
     * steps forward through recalled prompts and then gives the draft back,
     * once ↑ recall became a walk over the cross-session prompt history.
     *
     * 67 -> 69 live (71 -> 73 all) with attachments (audit 15b-15):
     * `chat.mention-complete` (Tab completes an `@file` path) and
     * `chat.paste-image` (Ctrl+V attaches the clipboard's image).
     *
     * 69 -> 70 live (73 -> 74 all) when `mouse.side-row` was declared: a
     * click on a docked Tools or Agents pane row expands it in place.
     *
     * 70 -> 81 live (74 -> 85 all) with the revamped session picker (Appendix
     * P-A2): ten picker rows (`picker.filter`, `.rename`, `.delete`,
     * `.delete-children`, `.pin`, `.fork`, `.archive`, `.unarchive`,
     * `.archived`, `.children`) and `mouse.session-action`.
     *
     * 81 -> 88 live (85 -> 92 all) and 9 -> 10 contexts with the settings view
     * (roadmap N-P1): the six `settings.*` rows of the new `Settings view`
     * context and `shell.settings-open`, the settings pane's Enter door.
     *
     * 88 -> 89 live (92 -> 93 all) when `chat.stop` was declared (roadmap
     * 1.C-4a): one Esc on an engine turn that reports its steps stops it at the
     * step boundary.
     *
     * 89 -> 91 live (93 -> 95 all) with mid-turn steering (roadmap 1.C-3):
     * `chat.steer` (Enter mid-turn steers the running turn) and `chat.queue`
     * (Tab mid-turn queues the draft for after it).
     *
     * 91 -> 93 live (95 -> 97 all) with the permission modal's two new
     * refusals: `permission.note` (`r`, type why) and `permission.stop`
     * (`x`, refuse and stop the turn).
     *
     * 93 -> 99 live (97 -> 103 all) and 10 -> 11 contexts with the live
     * agents strip (roadmap P-B3): `chat.agents-strip` (`Alt+↓`) and the five
     * rows of the new `Agents strip` context (`strip.move`, `.open`,
     * `.cancel`, `.dismiss`, `.back`).
     *
     * 99 -> 109 live (103 -> 113 all) with the settings editor's keys (the
     * W4-g save door, bound): `settings.edit`, `.stage`, `.cancel-edit`,
     * `.reset`, `.tier`, `.save`, `.confirm`, `.back`, `.trust`, `.discard`.
     *
     * 109 -> 114 live (113 -> 118 all) and 11 -> 12 contexts with the
     * read-only Agent View (roadmap P-C2): the three rows of the new
     * `Agent transcript` context (`agentview.back`, `.next`, `.prev`),
     * `agents.attach` (the peek's `Enter`) and `mouse.agent`.
     *
     * 114 -> 115 live (118 -> 119 all) with the Agent View's composer
     * (roadmap P-D2): `agentview.send`, `Enter` sends the draft to the agent
     * on screen.
     *
     * 115 -> 123 live (119 -> 124 all) and 4 -> 1 dormant with the agent
     * controls (roadmap P-D3): `shell.group-input`, `agents.cancel`,
     * `.resume` and `.stop-all` went live, and the Agent View's `Ctrl+X`
     * chords arrived — `agentview.cancel`, `.pause`, `.stop-all` and
     * `.open-session` live, `agentview.background` dormant until 4.3.
     *
     * 123 -> 124 live (124 -> 125 all) with plan mode's toggle (roadmap
     * 5.7-1, decision D8): `chat.plan-mode`, `Alt+M`.
     *
     * 124 -> 125 live (125 all) and 1 -> 0 dormant with background
     * promotion (roadmap P-E3): `agentview.background`, `Ctrl+X b`.
     *
     * 125 -> 127 live (127 all) with the settings editor's polish (roadmap
     * N-P5): `settings.details` (`i`) and `settings.preview-scroll` (`↑ / ↓`).
     *
     * 127 -> 128 live (128 all) with the question-aware permission modal
     * (roadmap 5.7-2): `permission.choice`, `1…6` picks an AskUser choice.
     *
     * 128 -> 132 live (132 all) with the settings view's files and profiles
     * (roadmap N-P5 remainder): `settings.open-file` (`e`), `.export` (`x`),
     * `.import` (`p`) and `.profile-go` (`Enter` on the path prompt).
     *
     * 132 -> 133 live (133 all) with the permission modal's scope editor:
     * `permission.edit` (`e`) edits what `a` would remember.
     *
     * 133 -> 142 live (142 all), 12 -> 13 groups, with `/new`'s folder
     * picker: `dirpicker.move`, `.enter`, `.open`, `.up`, `.parent`,
     * `.hidden`, `.path`, `.start` and `.close`.
     *
     * 142 -> 143 live (143 all), 13 -> 14 groups, with the generation form's
     * launch chord (crush-media W3.10): `media.open_form` (`Ctrl+D`) — the
     * first row of the `Media form` context; the frame's own keys are painted
     * help, not registry rows, until W3.4 gives it more chords.
     *
     * 144 -> 145 live (145 all), 14 groups, with the rv-a11y chat `Ctrl+L`
     * repaint row (crush-review-1014) joining the claims, and earlier the permission modal's `p`:
     * `permission.persist` — `a`'s scope also written to `permissionRules`
     * so the grant outlives the session.
     
     * 145 -> 146 live (146 all), 14 groups, with the Ctrl+G history search
     * (plan_comparison TUI-2): `chat.history-search`, the first row of the
     * declared-live rebind polity in `chatCtrlRunesYieldedToShell()`.
     *
     * 146 -> 147 live (147 all), 14 groups, with the v2:a11y-F1 chat
     * `Ctrl+F` row-cursor row: `chat.tool-row`, the keyboard half of the
     * click-a-row affordance, claimed exactly like `chat.tool-output`.
     *
     * 147 -> 151 live (151 all), 15 groups, with P-CTX.e's docked Context
     * pane: `contextPane.expand`, `contextPane.drill` and `contextPane.back`
     * in the new "Context pane" group, plus the status-bar meter row
     * `mouse.context-meter` — the keys and the click that drive the box.
     *
     * 151 -> 152 live (152 all), 15 groups, with P-MENU.d's submenu walk:
     * `menu.submenu` — the row that opens the highlighted `▸` row and, on the
     * other key of the same binding, closes it again.
     *
     * 152 -> 153 live (153 all), 15 groups, with P-TABS.g's window strip:
     * `sessions.toggle` — the shell-group row behind `Alt+S`, which docks
     * and undocks the Sessions pane without ever moving focus.
     *
     * 153 -> 154 live (154 all), 15 groups, with P-AGENT.b's interrupt send:
     * `agentview.interrupt` — the `Ctrl+X i` chord of the Agent transcript,
     * the same draft Enter carries but delivered as `MessageMode::Interrupt`,
     * skipping the step's unstarted calls. C-AGT-4.b carries it on:
     * 154 -> 155 live (155 all) with `chat.agent-cycle`, the Alt+A primary
     * agent chord. C-TUI-3.e carries it on: 155 -> 156 live (156 all) with
     * `chat.verbose-toggle`, the Alt+O session-tier verbose dial. The chain
     * went un-carried there: C-TUI-2.e welded the seven `diffreview.*` rows
     * and the "Diff review" group without a note here, so this file rode red
     * from that weld (156 vs 163, 15 vs 16 — measured at 6fb38eef4).
     * C-TUI-3.d restores the chain and carries it on: 163 -> 168 live
     * (168 all), 17 groups, with `chat.message-picker` — the idle twin of
     * `chat.cancel` — and the four `msgpicker.*` rows of the "Message
     * picker" group. C-TUI-5.e carries it on: 168 -> 174 live (174 all),
     * 18 groups, with the six `wizard.*` rows of the "First-run wizard"
     * group — the band the launch offers once on a blank interactive
     * start, whose keys ride the same registry as every other slot.
     */
    public function testTheDeclaredShapeIsWhatTheDocblocksSayItIs(): void
    {
        $this->assertCount(174, KeyBindingRegistry::all(), 'update the docblocks that state this count');
        $this->assertCount(174, KeyBindingRegistry::live(), 'update the docblocks that state this count');
        $this->assertCount(0, KeyBindingRegistry::dormant(), 'update the docblocks that state this count');
        $this->assertCount(18, KeyBindingRegistry::grouped(), 'update the docblocks that state this count');
    }

    /**
     * The invariant that makes deriving both sets from one table safe: a chord
     * claimed by both layers reaches Chat only where the yield lets it, so an
     * accidental overlap would strand one binding. The ONE overlap permitted is
     * the declared dual claim — `Ctrl+G`, which is in the yielded set (so an
     * ordinary pane still falls through to `chat.history-search`) and in the
     * shell's set live (so the keyboard-owning states' rebind to Broadcast is
     * a real feature, not a dead chord). The yielded half of the property is
     * not incidental: a non-yielded rune in both sets is exactly the silent
     * kill this test was written to catch, and the roster pins it to `g`.
     */
    public function testTheTwoClaimSetsAreDisjoint(): void
    {
        $overlap = array_values(array_intersect(
            KeyBindingRegistry::shellCtrlRunes(),
            KeyBindingRegistry::chatCtrlRunes(),
        ));

        $this->assertSame(['g'], $overlap);
        $this->assertContains('g', KeyBindingRegistry::chatCtrlRunesYieldedToShell(), 'the overlap must be yielded — an unyielded shared chord strands Broadcast');
    }

    /**
     * `Ctrl+G` stays in the shell's claim set now that it is live (P-D3, the
     * composer's broadcast): it was claimed while dormant precisely so the
     * chord would not type a literal "g", and it is the same claim that
     * delivers it now.
     */
    public function testTheGroupInputChordIsLiveAndClaimed(): void
    {
        $this->assertContains('g', KeyBindingRegistry::shellCtrlRunes());
        $this->assertContains(
            'shell.group-input',
            array_map(static fn(KeyBinding $b): string => $b->id, KeyBindingRegistry::live()),
        );
    }

    /**
     * The last dormant row, `agentview.background` (`Ctrl+X b`), is live
     * since roadmap P-E3, after the agent dashboard's `c`/`r`/`s` went live
     * with P-D3 — and going live changed no routing: a two-key sequence has
     * no single-rune tail, so it feeds no derived rune set; the view's
     * `Ctrl+X` leader claims the `b` whether or not this table declares it.
     */
    public function testNoRowIsDormantAndTheBackgroundChordRoutesNothing(): void
    {
        $this->assertSame([], KeyBindingRegistry::dormant());

        $background = KeyBindingRegistry::byId('agentview.background');
        $this->assertNotNull($background);
        $this->assertTrue($background->isLive());
        $this->assertNull($background->ctrlRune(), 'a sequence feeds no derived rune set');

        foreach (['agents.cancel', 'agents.resume', 'agents.stop-all'] as $id) {
            $this->assertTrue(KeyBindingRegistry::byId($id)?->isLive(), "{$id} is live since P-D3");
        }
    }

    public function testCtrlRuneReadsOnlyBareSingleCharacterChords(): void
    {
        $this->assertSame('p', KeyBinding::new('x', 'Ctrl+P', 'd', 'c')->ctrlRune());
        $this->assertSame(',', KeyBinding::new('x', 'Ctrl+,', 'd', 'c')->ctrlRune());
        $this->assertNull(KeyBinding::new('x', 'Ctrl+Tab', 'd', 'c')->ctrlRune());
        $this->assertNull(KeyBinding::new('x', 'Ctrl+Shift+Tab', 'd', 'c')->ctrlRune());
        $this->assertNull(KeyBinding::new('x', 'Alt+Enter', 'd', 'c')->ctrlRune());
        $this->assertNull(KeyBinding::new('x', 'Enter', 'd', 'c')->ctrlRune());
    }

    public function testIsLiveTracksTheDormantReason(): void
    {
        $this->assertTrue(KeyBinding::new('x', 'Enter', 'd', 'c')->isLive());
        $this->assertFalse(KeyBinding::new('x', 'Enter', 'd', 'c', 'nothing consumes it')->isLive());
    }

    /**
     * {@see KeyBinding::new()} restates the constructor's whole signature, so a
     * field added to one and not the other drifts silently: the new parameter
     * would be unreachable through the factory every declaration in
     * {@see KeyBindingRegistry} uses, and nothing would say so — the factory
     * would keep compiling and keep passing the old six arguments positionally.
     *
     * The duplication is kept (the promoted constructor is where the per-field
     * documentation belongs, and `::new()` is this project's declared root
     * factory) and made non-silent instead. Compared field by field: name,
     * declared type, and default.
     */
    public function testTheFactoryAndTheConstructorTakeTheSameParameters(): void
    {
        $shape = static function (\ReflectionFunctionAbstract $f): array {
            $out = [];
            foreach ($f->getParameters() as $parameter) {
                $out[] = [
                    $parameter->getName(),
                    (string) $parameter->getType(),
                    $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : '<required>',
                ];
            }

            return $out;
        };

        $constructor = new \ReflectionMethod(KeyBinding::class, '__construct');
        $factory = new \ReflectionMethod(KeyBinding::class, 'new');

        $this->assertSame(
            $shape($constructor),
            $shape($factory),
            'KeyBinding::new() and its constructor have drifted apart — a parameter added to one must be '
            . 'added to the other, or the registry cannot declare it',
        );
        $this->assertSame(
            'self',
            (string) $factory->getReturnType(),
            'the factory must still return the class it constructs',
        );
    }

    // =====================================================================
    // keybindings overrides (ISS-047): useOverrides/chordFor/effective
    // =====================================================================

    public function testChordForAnswersTheShippedRowWhenNothingIsOverridden(): void
    {
        $this->assertSame('Ctrl+K', KeyBindingRegistry::chordFor('shell.palette'));
        $this->assertNull(KeyBindingRegistry::chordFor('no.such.binding'));
    }

    /**
     * THE consult order the setting exists for: a registered override wins
     * over the shipped row, and ids without one keep answering as declared.
     */
    public function testARegisteredOverrideWinsTheConsult(): void
    {
        KeyBindingRegistry::useOverrides(['shell.palette' => 'Ctrl+Q']);

        $this->assertSame('Ctrl+Q', KeyBindingRegistry::chordFor('shell.palette'));
        $this->assertSame('Ctrl+P', KeyBindingRegistry::chordFor('chat.palette'));
        $this->assertSame(['shell.palette' => 'Ctrl+Q'], KeyBindingRegistry::overrides());
    }

    public function testEffectiveRelabelsOnlyTheRowsAnOverrideTouches(): void
    {
        KeyBindingRegistry::useOverrides(['chat.palette' => 'Ctrl+Q']);

        $rows = KeyBindingRegistry::effective();
        $declared = KeyBindingRegistry::all();

        $this->assertCount(\count($declared), $rows);
        foreach ($rows as $index => $row) {
            $before = $declared[$index];
            if ($before->id !== 'chat.palette') {
                $this->assertEquals($before, $row, "untouched row {$before->id} must answer as declared");
                continue;
            }

            $this->assertSame('Ctrl+Q', $row->keys);
            // Every other argument survives the relabel — the row is the same
            // binding, answering to a different chord.
            $this->assertSame($before->id, $row->id);
            $this->assertSame($before->description, $row->description);
            $this->assertSame($before->context, $row->context);
            $this->assertSame($before->dormantReason, $row->dormantReason);
            $this->assertSame($before->yieldsToShellReason, $row->yieldsToShellReason);
        }
    }

    /**
     * With no overrides registered, effective() answers exactly as the shipped
     * table does — row-for-row, so the fast path cannot re-order or rebuild.
     */
    public function testEffectiveWithoutOverridesIsTheShippedTable(): void
    {
        $this->assertEquals(KeyBindingRegistry::all(), KeyBindingRegistry::effective());
    }

    /**
     * Pinned so its removal is a decision, not an accident: the alias lands at
     * the keystroke funnel (App::handleKey → shippedRuneFor) BEFORE any claim
     * decision, so the derived CLAIM sets still answer from the raw shipped
     * runes. The override chord is deliberately never claimed here — claiming
     * it as well would translate twice and strand the shipped rune.
     */
    public function testOverridesDoNotMoveTheClaimSets(): void
    {
        $before = KeyBindingRegistry::shellCtrlRunes();

        KeyBindingRegistry::useOverrides(['shell.palette' => 'Ctrl+Q']);
        $after = KeyBindingRegistry::shellCtrlRunes();

        $this->assertSame($before, $after);
        $this->assertContains('k', $after, 'the shipped chord stays claimed — the alias adds a path to it, never replaces it');
        $this->assertNotContains('q', $after, 'the override rune is honoured at the funnel, not in the claim set');
    }

    /**
     * The consult the funnel lands: an override chord maps to its shipped rune,
     * the shipped rune maps to itself (no-op translation), and an unrelated or
     * non-Ctrl-target rune maps to nothing.
     */
    public function testShippedRuneForHonoursAnOverrideAndItsBoundaries(): void
    {
        KeyBindingRegistry::useOverrides(['shell.palette' => 'Ctrl+Q']);

        $this->assertSame('k', KeyBindingRegistry::shippedRuneFor('q'), 'the alias resolves to the shipped rune');
        $this->assertSame('k', KeyBindingRegistry::shippedRuneFor('Q'), 'the consult is case-insensitive like the parser');
        $this->assertNull(KeyBindingRegistry::shippedRuneFor('z'), 'an unbound rune aliases to nothing');

        // The shipped rune must NOT translate to itself via the override, or a
        // press of `Ctrl+K` would loop — it is its own answer.
        $this->assertNull(KeyBindingRegistry::shippedRuneFor('k'), 'the shipped rune is not an alias source');
    }

    /**
     * A row shipped as a non-`Ctrl+<key>` chord (chat.send ships `Enter`) has no
     * rune to alias onto the RUNE lane; even a hand-registered override of it
     * must not invent a rune translation. Since plan_comparison TUI-4c such
     * rows are NOT inert — the chord lane ({@see \SugarCraft\Code\Commands\KeyBindingRegistry::aliasPress()}) carries them —
     * but the rune map stays exactly the bare-`Ctrl+<key>`-on-both-ends alphabet
     * the pre-4c funnel was, and this pins that boundary.
     */
    public function testANonCtrlTargetRowNeverAliasesOnTheRuneLane(): void
    {
        KeyBindingRegistry::useOverrides(['chat.send' => 'Ctrl+Q']);

        $this->assertNull(KeyBindingRegistry::shippedRuneFor('q'), 'an Enter-shipped row has no rune to alias to');
    }

    /**
     * The alias map is a function of the registered overrides: re-registering
     * must never serve a stale derivation.
     */
    public function testTheAliasMemoFollowsReRegistration(): void
    {
        KeyBindingRegistry::useOverrides(['chat.repaint' => 'Ctrl+Q']);
        $this->assertSame('l', KeyBindingRegistry::shippedRuneFor('q'));

        KeyBindingRegistry::useOverrides(['chat.palette' => 'Ctrl+Q']);
        $this->assertSame('p', KeyBindingRegistry::shippedRuneFor('q'), 'the old alias must not outlive its map');

        KeyBindingRegistry::useOverrides([]);
        $this->assertNull(KeyBindingRegistry::shippedRuneFor('q'));
    }

    // =====================================================================
    // plan_comparison TUI-4c: the canonical chord alphabet and the chord lane
    // =====================================================================

    /**
     * The parse IS the wire: every parseable shipped label round-trips —
     * press the reconstructed message and {@see KeyBinding::pressId()} answers
     * exactly the parsed id. A label that does not describe one press (dual,
     * sequence, range, mouse, prose) parses to null instead of guessing.
     */
    public function testParseChordIsTheWireItself(): void
    {
        $parsed = 0;
        foreach (KeyBindingRegistry::all() as $binding) {
            $id = $binding->chord();
            if ($id === null) {
                continue;
            }
            $parsed++;
            $msg = KeyBinding::keyMsgFromId($id);
            $this->assertNotNull($msg, "id {$id} must rebuild");
            $this->assertSame($id, KeyBinding::pressId($msg), "round trip of {$binding->keys}");
        }
        $this->assertGreaterThan(50, $parsed, 'the parseable majority of the table must parse');

        // The unpressable shapes are named, so a label that LOSES its
        // shape (a dual quietly rewritten as prose moves nothing visible)
        // cannot slip through, and a name that GAINS one flips this pin.
        $names = [];
        foreach (KeyBindingRegistry::all() as $binding) {
            if ($binding->chord() === null) {
                $names[] = $binding->id . ' => ' . $binding->keys;
            }
        }
        foreach ([
            'chat.cancel => Esc Esc',
            'agents.slot => Alt+1…9',
        ] as $row) {
            if (!in_array($row, $names, true)) {
                $this->fail("roster row {$row} changed shape without a decision");
            }
        }
        $this->assertNotContains('shell.menu => F10', $names, 'a function key IS one press');
    }

    /**
     * `shell.menu` ships `F10` — it parses; a hand-written roster entry naming
     * it null would catch a regression that turns named keys back into prose.
     */
    public function testNamedFunctionAndAltChordsParse(): void
    {
        $this->assertSame('f10', KeyBinding::parseChord('F10'));
        $this->assertSame('alt+enter', KeyBinding::parseChord('Alt+Enter'));
        $this->assertSame('ctrl+tab', KeyBinding::parseChord('Ctrl+Tab'));
        $this->assertSame('ctrl+shift+tab', KeyBinding::parseChord('Ctrl+Shift+Tab'));
        $this->assertSame('ctrl+space', KeyBinding::parseChord('Ctrl+Space'));
        $this->assertSame('alt+down', KeyBinding::parseChord('Alt+↓'));
        $this->assertSame('ctrl+left', KeyBinding::parseChord('Ctrl+←'));
        $this->assertSame('?', KeyBinding::parseChord('?'));
        $this->assertSame('ctrl+,', KeyBinding::parseChord('Ctrl+,'));

        $this->assertNull(KeyBinding::parseChord('Esc Esc'), 'a sequence is not a press');
        $this->assertNull(KeyBinding::parseChord('Ctrl+X b'), 'a sequence is not a press');
        $this->assertNull(KeyBinding::parseChord('Alt+1…9'), 'a range is not a press');
        $this->assertNull(KeyBinding::parseChord('↑ / ↓'), 'a dual is not a press');
        $this->assertNull(KeyBinding::parseChord('Click tab'), 'a gesture is not a press');
        $this->assertNull(KeyBinding::parseChord('Cmd+K'), 'no such modifier');
        $this->assertNull(KeyBinding::parseChord('Ctrl+'), 'a modifier alone is not a chord');
        $this->assertNull(KeyBinding::parseChord(''), 'empty is not a chord');
    }

    /**
     * THE WIDENING: an override aimed at a named-shape row lands at the chord
     * lane — Ctrl+Q rebuilt as Alt+Enter — while the rune lane stays silent,
     * the shipped chord keeps answering unchanged, and re-registering clears
     * the derived pairs (the chord memo obeys the same staleness law).
     */
    public function testTheChordLaneAliasesNamedShapes(): void
    {
        KeyBindingRegistry::useOverrides(['chat.newline' => 'Ctrl+Q']);

        $this->assertNull(KeyBindingRegistry::shippedRuneFor('q'), 'no rune pair — the row ships no bare Ctrl chord');

        $aliased = KeyBindingRegistry::aliasPress(new KeyMsg(KeyType::Char, 'q', ctrl: true));
        $this->assertNotNull($aliased, 'the chord lane must carry it');
        $this->assertSame(KeyType::Enter, $aliased->type);
        $this->assertTrue($aliased->alt, 'the rebuild is the SHIPPED shape: Alt+Enter');
        $this->assertFalse($aliased->ctrl);

        // The shipped chord is never stranded by the alias (ISS-047 law,
        // now over the wider alphabet): a real Alt+Enter press passes through.
        $this->assertNull(KeyBindingRegistry::aliasPress(new KeyMsg(KeyType::Enter, '', alt: true)));

        KeyBindingRegistry::useOverrides(['chat.session-cycle' => 'Ctrl+B']);
        $retargeted = KeyBindingRegistry::aliasPress(new KeyMsg(KeyType::Char, 'b', ctrl: true));
        $this->assertNotNull($retargeted, 'the old map must not outlive the re-registration');
        $this->assertSame(KeyType::Tab, $retargeted->type);
        $this->assertTrue($retargeted->ctrl);

        KeyBindingRegistry::useOverrides([]);
        $this->assertNull(KeyBindingRegistry::aliasPress(new KeyMsg(KeyType::Char, 'b', ctrl: true)));
    }

    /**
     * Byte-compat of the funnel widening: a bare-Ctrl pair still rides the
     * rune lane with the press's own modifier flags preserved (the pre-4c
     * rebuild), and an F-key target rebuilds named.
     */
    public function testTheFunnelKeepsTheRuneLaneFirstAndRebuildsFKeys(): void
    {
        KeyBindingRegistry::useOverrides(['chat.palette' => 'Ctrl+Q', 'shell.menu' => 'F12']);

        $runeLane = KeyBindingRegistry::aliasPress(new KeyMsg(KeyType::Char, 'q', ctrl: true, shift: true));
        $this->assertNotNull($runeLane);
        $this->assertSame('p', $runeLane->rune);
        $this->assertTrue($runeLane->shift, 'the rune lane rebuilds with the press\'s own flags, as before 4c');

        $function = KeyBindingRegistry::aliasPress(new KeyMsg(KeyType::F12));
        $this->assertNotNull($function);
        $this->assertSame(KeyType::F10, $function->type);
        $this->assertSame('f10', KeyBinding::pressId($function));
    }

    // =====================================================================
    // plan_comparison TUI-4b: unbind — `"<id>": null`
    // =====================================================================

    /**
     * The shell-regain leg: unbinding the CHAT side of the dual claim
     * `Ctrl+G` drops the rune from the derived chat set — the claim layer
     * is derived, so removing it there is the removal — while the raw
     * shipped rows keep answering for the shell, which now owns the key in
     * every ordinary pane. Re-registering restores both, memos and all.
     */
    public function testUnbindingOneSideOfADualClaimReturnsTheKeyToTheShell(): void
    {
        $before = KeyBindingRegistry::chatCtrlRunes();
        $this->assertContains('g', $before);

        KeyBindingRegistry::useOverrides(['chat.history-search' => null]);

        $this->assertSame(['chat.history-search'], KeyBindingRegistry::suppressed());
        $this->assertNotContains('g', KeyBindingRegistry::chatCtrlRunes(), 'the unbound chord must leave the derived chat set');
        $this->assertContains('g', KeyBindingRegistry::shellCtrlRunes(), 'the shell side ships the chord independently — untouched');
        $this->assertSame('unbound', KeyBindingRegistry::chordFor('chat.history-search'), 'the /keys column reads chordFor()');
        $this->assertSame('Ctrl+G', KeyBindingRegistry::chordFor('shell.group-input'), 'the live twin is unaffected');

        KeyBindingRegistry::useOverrides([]);
        $this->assertSame([], KeyBindingRegistry::suppressed());
        $this->assertContains('g', KeyBindingRegistry::chatCtrlRunes(), 'the clear must restore what the registration removed');
        $this->assertSame('Ctrl+G', KeyBindingRegistry::chordFor('chat.history-search'));
    }

    /**
     * The inert leg: when EVERY row shipping a chord is unbound, the funnel
     * rewrites the press to a rune no handler names ({@see
     * KeyBindingRegistry::UNBOUND_SENTINEL}) — the key does nothing rather
     * than falling through to ordinary typing. A chord with a live twin is
     * NOT inert: it dispatches the twin (the test above), so the rewrite
     * consults the rows, not just the suppression list.
     */
    public function testTheFunnelInertsUnboundChordsNobodyLeftToAnswer(): void
    {
        KeyBindingRegistry::useOverrides(['chat.palette' => null]);

        $inert = KeyBindingRegistry::aliasPress(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($inert, 'the vacated chord must be rewritten, not passed through');
        $this->assertSame(KeyBindingRegistry::UNBOUND_SENTINEL, $inert->rune);
        $this->assertTrue($inert->ctrl, 'the sentinel carries ctrl so Chat\'s inert control arm — never the typing arm — absorbs it');

        // The dual: unbinding the chat side alone leaves Ctrl+G live for the
        // shell, so the funnel must NOT rewrite it.
        KeyBindingRegistry::useOverrides(['chat.history-search' => null]);
        $this->assertNull(KeyBindingRegistry::aliasPress(new KeyMsg(KeyType::Char, 'g', ctrl: true)), 'a chord a live row still ships is no one\'s ghost');

        // Unbinding BOTH sides vacates it for real.
        KeyBindingRegistry::useOverrides(['chat.history-search' => null, 'shell.group-input' => null]);
        $both = KeyBindingRegistry::aliasPress(new KeyMsg(KeyType::Char, 'g', ctrl: true));
        $this->assertNotNull($both, 'with every shipper unbound the chord goes inert');
        $this->assertSame(KeyBindingRegistry::UNBOUND_SENTINEL, $both->rune);

        KeyBindingRegistry::useOverrides([]);
        $this->assertNull(KeyBindingRegistry::aliasPress(new KeyMsg(KeyType::Char, 'p', ctrl: true)));
    }

    /**
     * An unbound row contributes no yield and its vacated named chord goes
     * inert — the suppression and the alias lanes are disjoint by
     * construction (an id answers to at most one of them).
     */
    public function testAnUnboundRowYieldsNothingAndItsNamedChordGoesInert(): void
    {
        KeyBindingRegistry::useOverrides([
            'chat.newline' => null,
            'chat.session-picker' => null,
        ]);

        $newline = KeyBindingRegistry::aliasPress(new KeyMsg(KeyType::Enter, '', alt: true));
        $this->assertNotNull($newline, 'Alt+Enter had exactly one shipper');
        $this->assertSame(KeyBindingRegistry::UNBOUND_SENTINEL, $newline->rune);

        $this->assertNotContains('r', KeyBindingRegistry::chatCtrlRunesYieldedToShell());
        $this->assertContains('g', KeyBindingRegistry::chatCtrlRunesYieldedToShell(), 'the still-bound yielding row keeps its entry');
    }
}

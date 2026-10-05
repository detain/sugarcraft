<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Commands\KeyBinding;
use SugarCraft\Crush\Commands\KeyBindingRegistry;

/**
 * Shape of the keybinding table itself. What each row DOES is
 * {@see KeyBindingDriftTest}'s job; this covers the invariants both consumers
 * (the reference screen and {@see \SugarCraft\Crush\Tui\KeyboardHandler}'s
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
            KeyBindingRegistry::CONTEXT_PERMISSION,
            KeyBindingRegistry::CONTEXT_AGENTS,
            KeyBindingRegistry::CONTEXT_AGENT_STRIP,
            KeyBindingRegistry::CONTEXT_AGENT_TRANSCRIPT,
            KeyBindingRegistry::CONTEXT_SKILLS,
            KeyBindingRegistry::CONTEXT_SETTINGS,
            KeyBindingRegistry::CONTEXT_MENU,
            KeyBindingRegistry::CONTEXT_MOUSE,
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

        $this->assertSame(['a', 'c', 'o', 'p', 'r', 'v', 'w'], $runes);
    }

    /**
     * The exception the shell's claim layer reads, pinned exactly — it is the
     * one hole in "content wins outright", and it may not grow by accident.
     *
     * Ctrl+P is deliberately NOT here: see
     * {@see KeyBindingRegistry::chatCtrlRunesYieldedToShell()} for condition 2
     * it fails — the shell answers `ctrl+p` with `ProviderSelectCmd`, so
     * yielding it would rebind the chord rather than swallow it. That is
     * driven, not asserted here: see
     * {@see \SugarCraft\Crush\Tests\Tui\KeyboardHandlerTest::testEveryYieldedChordIsAnsweredByANoOp()}.
     */
    public function testExactlyOneChatChordIsYieldedBackToTheShell(): void
    {
        $this->assertSame(['r'], KeyBindingRegistry::chatCtrlRunesYieldedToShell());
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
        $this->assertSame(['r'], KeyBindingRegistry::chatCtrlRunesYieldedToShell(), 'cold call');
        $this->assertSame(['w', 'p', 'o', 'v', 'r', 'a', 'c'], KeyBindingRegistry::chatCtrlRunes(), 'cold call');
        $this->assertSame(['n', 'k', 's', ',', 'g'], KeyBindingRegistry::shellCtrlRunes(), 'cold call');

        // Warm: the same answer, and now out of the memo rather than rebuilt.
        $this->assertSame(['r'], KeyBindingRegistry::chatCtrlRunesYieldedToShell(), 'warm call');
        $this->assertSame(['w', 'p', 'o', 'v', 'r', 'a', 'c'], KeyBindingRegistry::chatCtrlRunes(), 'warm call');
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
     * layer reads: {@see \SugarCraft\Crush\Tui\KeyboardHandler::chatOwns()}
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
     * them — {@see \SugarCraft\Crush\Chat::handleKeyHelpKey()}'s "109 live rows
     * across 11 contexts — 113 in all", the sweep counts in
     * {@see \SugarCraft\Crush\Tests\Renderer\KeyHelpTest}, and
     * {@see \SugarCraft\Crush\Tests\Commands\KeyBindingDriftTest}'s KEYISH
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
     * the draft moved into `candy-forms`' TextArea: forward Delete, ←/→,
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
     */
    public function testTheDeclaredShapeIsWhatTheDocblocksSayItIs(): void
    {
        $this->assertCount(133, KeyBindingRegistry::all(), 'update the docblocks that state this count');
        $this->assertCount(133, KeyBindingRegistry::live(), 'update the docblocks that state this count');
        $this->assertCount(0, KeyBindingRegistry::dormant(), 'update the docblocks that state this count');
        $this->assertCount(12, KeyBindingRegistry::grouped(), 'update the docblocks that state this count');
    }

    /**
     * The one invariant that makes deriving both sets from one table safe: a
     * chord claimed by both layers would be claimed by the shell and never
     * reach the content model, silently killing a Chat binding.
     */
    public function testTheTwoClaimSetsAreDisjoint(): void
    {
        $this->assertSame(
            [],
            array_intersect(KeyBindingRegistry::shellCtrlRunes(), KeyBindingRegistry::chatCtrlRunes()),
        );
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
}

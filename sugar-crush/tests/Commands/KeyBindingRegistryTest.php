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
            KeyBindingRegistry::CONTEXT_SKILLS,
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
        $dormant = KeyBindingRegistry::dormant();
        $this->assertNotSame([], $dormant, 'the inert chords are documented, not dropped');

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
                $this->assertSame($context, $binding->context);
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

        $this->assertSame(['a', 'c', 'o', 'p', 'r', 'w'], $runes);
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
        $this->assertSame(['w', 'p', 'o', 'r', 'a', 'c'], KeyBindingRegistry::chatCtrlRunes(), 'cold call');
        $this->assertSame(['n', 'k', 's', ',', 'g'], KeyBindingRegistry::shellCtrlRunes(), 'cold call');

        // Warm: the same answer, and now out of the memo rather than rebuilt.
        $this->assertSame(['r'], KeyBindingRegistry::chatCtrlRunesYieldedToShell(), 'warm call');
        $this->assertSame(['w', 'p', 'o', 'r', 'a', 'c'], KeyBindingRegistry::chatCtrlRunes(), 'warm call');
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
     * The shape of the table, stated as numbers because three docblocks state
     * them in prose — {@see \SugarCraft\Crush\Chat::handleKeyHelpKey()}'s "54
     * live rows across 9 contexts", and the sweep counts in
     * {@see \SugarCraft\Crush\Tests\Renderer\KeyHelpTest}. A prose number
     * nobody measures is how a reference goes stale; this is the measurement.
     *
     * 53 -> 54 live when `permission.rearm` was declared: a permission prompt
     * disarmed by a stray keystroke only answers again after Enter re-arms it,
     * so that Enter is a live binding and this reference has to say so.
     */
    public function testTheDeclaredShapeIsWhatTheDocblocksSayItIs(): void
    {
        $this->assertCount(58, KeyBindingRegistry::all(), 'update the docblocks that state this count');
        $this->assertCount(54, KeyBindingRegistry::live(), 'update the docblocks that state this count');
        $this->assertCount(4, KeyBindingRegistry::dormant(), 'update the docblocks that state this count');
        $this->assertCount(9, KeyBindingRegistry::grouped(), 'update the docblocks that state this count');
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
     * A dormant chord must stay in the claim set: an unclaimed Ctrl+<rune>
     * does not become inert, it falls through to Chat's generic Char arm and
     * types its own letter into the input box.
     */
    public function testADormantShellChordIsStillClaimed(): void
    {
        $this->assertContains('g', KeyBindingRegistry::shellCtrlRunes());
        $this->assertNotContains(
            'shell.group-input',
            array_map(static fn(KeyBinding $b): string => $b->id, KeyBindingRegistry::live()),
        );
    }

    /**
     * The other half of {@see KeyBinding::$dormantReason}'s per-row rationale:
     * the three dormant agent-view rows route NOTHING. None of them is a
     * `Ctrl+<rune>` chord, so none can reach a rune set, which is why
     * "a dormant chord must keep being claimed or it types its own letter"
     * is true of `shell.group-input` alone — `handleAgentViewKey()` claims
     * c/r/s whether or not this table declares them.
     */
    public function testTheDormantAgentRowsRouteNothing(): void
    {
        $agents = 0;
        foreach (KeyBindingRegistry::dormant() as $binding) {
            if ($binding->context !== KeyBindingRegistry::CONTEXT_AGENTS) {
                continue;
            }
            $agents++;
            $this->assertNull(
                $binding->ctrlRune(),
                $binding->id . ' would now feed a derived rune set, so its dormant reason is wrong',
            );
        }

        $this->assertSame(3, $agents);
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

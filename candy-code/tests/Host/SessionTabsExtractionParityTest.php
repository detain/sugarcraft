<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\SessionSwitch;
use SugarCraft\Code\Host\SessionTabState;
use SugarCraft\Code\Tests\Support\SlicesDeclaredMethodsTrait;
use SugarCraft\Code\Tui\SessionTabs;

/**
 * W6-A extraction-parity pins: the session-tab surface moved out of Chat
 * into {@see SessionTabState} (the ISS-102 tab strip's value object) and
 * {@see SessionSwitch} (the stateless switch computations), per v4
 * split-plan S3.
 *
 * These are RELOCATION anchors, not behaviour tests — the behaviour is
 * pinned by the pre-existing Session/Tab/Palette families. Each pin fails
 * loudly if a delegate is re-inlined, a moved body re-grows logic on the
 * Chat side, or the state value object silently loses a slot the mutate()
 * carry still routes through it (the pattern W4-A and W5-A shipped).
 */
final class SessionTabsExtractionParityTest extends TestCase
{
    use SlicesDeclaredMethodsTrait;

    /**
     * Slice a declared method's source, asserting it is declared on the
     * expected class so a move or a shadow cannot silently redirect the pin.
     *
     * @param class-string $class
     */
    private static function bodyOf(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        self::assertSame(
            $class,
            $reflection->getDeclaringClass()->getName(),
            $method . '() must stay declared on ' . $class . '; a re-inline or a move elsewhere '
            . 'redirects every delegate-arity pin below without changing behaviour, which is exactly the drift this file exists to catch.',
        );
        $filename = $reflection->getFileName();
        self::assertNotFalse($filename);
        $lines = file($filename);
        self::assertNotFalse($lines);

        return self::declaredSlice($lines, $method, (int) $reflection->getStartLine(), (int) $reflection->getEndLine());
    }

    public function testTheStateValueObjectOwnsExactlyTheTabSurfaceSlots(): void
    {
        $properties = [];
        foreach ((new ReflectionClass(SessionTabState::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            self::assertTrue($property->isReadOnly(), $property->getName() . ' must stay public readonly (PaletteState law).');
            $properties[] = $property->getName();
        }
        sort($properties);
        self::assertSame(
            ['lastClick', 'tabs'],
            $properties,
            'SessionTabState owns precisely the two tab-surface fields extracted from Chat; a third is a redesign and a loss strands a mutate() key.',
        );
    }

    public function testOverrideKeysIsABijectionOntoTheValueObject(): void
    {
        $keys = array_keys(SessionTabState::OVERRIDE_KEYS);
        $values = array_values(SessionTabState::OVERRIDE_KEYS);
        sort($keys);
        sort($values);
        self::assertCount(2, $values);
        self::assertSame($values, array_unique($values), 'two mutate() keys may not fold onto one slot — the later write would silently win.');
        self::assertSame(['lastClick', 'tabs'], $values);
        self::assertSame(
            ['lastTabClick', 'openTabs'],
            $keys,
            'the Chat-era mutate() key spellings are a public vocabulary (Chat call sites and tests drive them); renaming one is a behaviour change, not a cleanup.',
        );
    }

    public function testChatCarriesTheFoldedStateAndNoLongerTheTwoFields(): void
    {
        $params = array_map(
            static fn (object $p): string => $p->getName(),
            (new ReflectionClass(Chat::class))->getConstructor()?->getParameters() ?? [],
        );
        self::assertContains('tabState', $params, 'Chat must carry the single state slot the fold collapsed the fields into.');
        self::assertSame(
            [],
            array_intersect($params, array_keys(SessionTabState::OVERRIDE_KEYS)),
            'a resurrected field on the Chat constructor re-introduces the double-carry the VO fold removed.',
        );
        foreach ((new ReflectionClass(Chat::class))->getProperties() as $property) {
            self::assertNotContains(
                $property->getName(),
                ['openTabs', 'lastTabClick'],
                'Chat still declares ' . $property->getName() . '; SessionTabState owns it.',
            );
        }
    }

    public function testMutateFoldsTabKeysThroughTheValueObjectOnce(): void
    {
        $body = self::bodyOf(Chat::class, 'mutate');
        self::assertSame(1, substr_count($body, "'tabState' => \$this->tabState,"));
        self::assertSame(1, substr_count($body, "\$constructorProps['tabState'] = \$this->tabState->with(\$changes);"));
        self::assertSame(1, substr_count($body, 'foreach (array_keys(SessionTabState::OVERRIDE_KEYS) as $tabKey)'));
        foreach (array_keys(SessionTabState::OVERRIDE_KEYS) as $key) {
            self::assertSame(
                0,
                substr_count($body, "'$key' => \$this->"),
                "mutate() must not carry '$key' directly again: two carriers for one slot is the drift the fold deleted.",
            );
        }
    }

    /**
     * @return array<array{0:string,1:string}>
     */
    public static function movedMethodFamilies(): array
    {
        return [
            [SessionTabState::class, 'forLaunch'],
            [SessionTabState::class, 'withClick'],
            [SessionTabState::class, 'opening'],
            [SessionTabState::class, 'cycled'],
            [SessionTabState::class, 'hasTab'],
            [SessionTabState::class, 'isDoubleClick'],
            [SessionSwitch::class, 'resets'],
            [SessionSwitch::class, 'storedName'],
            [SessionSwitch::class, 'storedTitleSource'],
            [SessionSwitch::class, 'resumed'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('movedMethodFamilies')]
    public function testTheMovedBodiesLiveOnTheirNewClasses(string $class, string $method): void
    {
        self::assertTrue((new ReflectionMethod($class, $method))->isPublic(), "$class::$method() is the public extraction surface.");
        self::bodyOf($class, $method); // declaring-class anchor
    }

    public function testTheMovedChatMethodsAreGone(): void
    {
        foreach (['tabsForLaunch', 'tabsOpening', 'sessionChangeResets', 'storedSessionName', 'storedTitleSource'] as $moved) {
            self::assertFalse(
                method_exists(Chat::class, $moved),
                "Chat::$moved() was extracted; a re-grown copy re-opens the drift the parity pins close.",
            );
        }
    }

    public function testTheSwitchRoutesDelegateThinly(): void
    {
        $switch = self::bodyOf(Chat::class, 'switchToSession');
        self::assertSame(1, substr_count($switch, 'SessionSwitch::resumed($history, $this->tabState, $this->sessionStore, $sessionId, $name)'));
        self::assertSame(1, substr_count($switch, '$transcripts->flush();'), 'the leave-a-save-behind cure must stay BEFORE the load.');
        self::assertStringNotContainsString("'currentSessionName' =>", $switch, 'the payload belongs to SessionSwitch::resumed().');

        $cycle = self::bodyOf(Chat::class, 'cycleSessionTab');
        self::assertSame(1, substr_count($cycle, '$this->tabState->cycled('));
        self::assertStringNotContainsString('handleKey(', $cycle, 'the widget key language moved into the VO.');

        $select = self::bodyOf(Chat::class, 'selectSessionTab');
        self::assertSame(1, substr_count($select, '$this->tabState->isDoubleClick('));
        self::assertStringNotContainsString('$previous[0]', $select, 'the click memo comparison moved into the VO.');

        $tab = self::bodyOf(Chat::class, 'switchToTab');
        self::assertSame(1, substr_count($tab, '$this->tabState->hasTab('));
        self::assertStringNotContainsString('array_column(', $tab, 'tab membership is the VO\'s question to answer.');
    }

    public function testTheResetRosterIsExactlyTheSixChatEraLatches(): void
    {
        self::assertSame(
            ['pendingCompactionId', 'pendingIdleCompaction', 'queuedPrompts', 'scrollOffset', 'consecutiveRefillCompactions', 'lastActivityAt'],
            array_keys(SessionSwitch::resets()),
            'one latch gained or lost on the session-change reset list is a behaviour change — audit 15b-06 made this list load-bearing.',
        );
    }

    public function testForLaunchSeedsTheOneTabShape(): void
    {
        $library = SessionTabState::forLaunch(null, null);
        self::assertCount(1, $library->tabs->tabs(), 'with no session at all the library default tab stands.');

        $launched = SessionTabState::forLaunch('s1', 'First');
        $tabs = $launched->tabs->tabs();
        self::assertCount(1, $tabs);
        self::assertSame('s1', $tabs[0]->id);
        self::assertSame('First', $tabs[0]->sessionName);
        self::assertNull($launched->lastClick, 'a launch owes no click memo.');
    }

    public function testOpeningFocusesAnExistingTabWithoutDuplicatingIt(): void
    {
        $state = SessionTabState::forLaunch('a', null);

        $focused = $state->opening('a', 'Renamed');
        self::assertCount(1, $focused->tabs(), 'SessionTabs::openTab appends unconditionally — the id guard is load-bearing.');
        self::assertSame('a', $focused->activeTab()?->id);

        $second = $state->opening('b', 'Bee');
        self::assertCount(2, $second->tabs());
        self::assertSame('b', $second->activeTab()?->id);
    }

    public function testCycledAnswersNullForNoOpsAndTheNewStateOtherwise(): void
    {
        self::assertNull(SessionTabState::forLaunch('only', null)->cycled(1), 'one tab has nowhere to cycle to.');

        $base = SessionTabState::forLaunch('a', null);
        $two = $base->with(['openTabs' => $base->tabs->openTab('b', '')]);
        self::assertSame('b', $two->tabs->activeTab()?->id);

        $forward = $two->cycled(1);
        self::assertNotNull($forward);
        self::assertSame('a', $forward->tabs->activeTab()?->id);
        self::assertSame('b', $two->tabs->activeTab()?->id, 'cycled() is pure: the receiver never moves.');
    }

    public function testTheDoubleClickMemoRoundTripsThroughWithClick(): void
    {
        $now = 1_000.0;
        $state = SessionTabState::forLaunch('s1', null)->withClick(['s1', $now - 0.1]);

        self::assertTrue($state->isDoubleClick('s1', $now, Chat::TAB_DOUBLE_CLICK_SECONDS));
        self::assertFalse($state->isDoubleClick('s2', $now, Chat::TAB_DOUBLE_CLICK_SECONDS), 'a click pair needs the SAME tab.');
        self::assertFalse($state->isDoubleClick('s1', $now + Chat::TAB_DOUBLE_CLICK_SECONDS + 0.01, Chat::TAB_DOUBLE_CLICK_SECONDS), 'and it needs to land inside the window.');
        self::assertFalse(SessionTabState::forLaunch('s1', null)->isDoubleClick('s1', $now, Chat::TAB_DOUBLE_CLICK_SECONDS), 'no memo, no double.');
        self::assertNull(SessionTabState::forLaunch('s1', null)->withClick(['s1', $now])->withClick(null)->lastClick);
    }

    public function testTheResumedPayloadCarriesTheTabAndIdentityWrites(): void
    {
        $state = SessionTabState::forLaunch('old', null);

        $payload = SessionSwitch::resumed([], $state, null, 'fresh', null);

        self::assertSame('fresh', $payload['currentSessionId']);
        self::assertNull($payload['currentSessionName']);
        self::assertNull($payload['sessionMint'], 'switching away abandons the launched session\'s pending mint (ISS-102).');
        self::assertInstanceOf(SessionTabs::class, $payload['openTabs']);
        self::assertSame('fresh', $payload['openTabs']->activeTab()?->id, 'wherever a session comes to the front, its tab opens.');
        self::assertArrayHasKey('pendingCompactionId', $payload, 'resumed() must spread resets() — the latch list cannot be re-forgotten.');
    }
}

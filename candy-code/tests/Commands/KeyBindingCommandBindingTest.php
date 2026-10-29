<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Config\Settings\Validator\KeyBindingsValidator;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * C-EXT-5 (plan_comparison): `command:<name>` keybinding entries — the
 * grammar the setting accepts, the dispatch map the registry splits them
 * into, and the collision notice they still answer to.
 *
 * The design keeps the entries in the SAME map as ordinary overrides (they
 * ride `overrides()` so the launch collision drain sees them) but gives them
 * their own consult (`commandFor()`), consulted above the alias funnel. The
 * pins below cover each seam from the outside: validator refusals and
 * acceptances, the split's content, chord-spelling normalisation, the
 * first-winner law for two commands on one chord, the hot-path short-circuit
 * that must not touch the memo, and the shipped-chord collision row — plus
 * the deliberate silence when the chord is unshipped or the victim vacated.
 *
 * Fixtures use `Ctrl+Z` (shipped by no row, so binding a command to it is
 * harmless) and `Ctrl+P` (`chat.palette`'s shipped chord, the collision
 * victim), the same pairing {@see \SugarCraft\Code\Tests\Cli\KeybindingCollisionNoticeTest}
 * uses for its own reasons.
 */
final class KeyBindingCommandBindingTest extends TestCase
{
    private const NAME_REFUSAL_FRAGMENT = 'is not a bindable command name';

    private const CHORD_REFUSAL_FRAGMENT = 'must be a chord';

    private const COLLISION_FRAGMENT = 'collides with the shipped chord';

    /** @var array<string, string> */
    private array $overridesBefore = [];

    /** @var list<string> */
    private array $suppressedBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->overridesBefore = KeyBindingRegistry::overrides();
        $this->suppressedBefore = KeyBindingRegistry::suppressed();

        KeyBindingRegistry::useOverrides([]);
    }

    protected function tearDown(): void
    {
        KeyBindingRegistry::useOverrides(
            $this->overridesBefore + array_fill_keys($this->suppressedBefore, null),
        );

        parent::tearDown();
    }

    public function testTheCommandGrammarIsTheRegistrysOwnNamePattern(): void
    {
        // The validator mirrors CommandSpec::NAME_PATTERN rather than
        // re-declaring a drifted copy — every name the loader will accept
        // for a file or bundle command must be bindable, and one that the
        // loader would refuse on disk must not seat as a dead binding.
        // Identity of the two pattern strings is the whole pin.
        $loader = (new ReflectionClass(CommandSpec::class))->getConstant('NAME_PATTERN');
        $setting = (new ReflectionClass(KeyBindingsValidator::class))->getConstant('COMMAND_NAME_PATTERN');

        self::assertIsString($loader);
        self::assertSame($loader, $setting, 'the keybindings grammar drifted from the command loader grammar');
    }

    public function testTheSettingAcceptsBoundableCommandNames(): void
    {
        foreach (['command:keys', 'command:my-deploy', 'command:bundle:name', 'command:mcp:serve/tool'] as $id) {
            self::assertNull(
                KeyBindingsValidator::new()->validate([$id => 'Ctrl+Z']),
                "the grammar must answer '{$id}' bindable",
            );
        }

        // Unbinding is as legal as for any shipped row: the entry just stops firing.
        self::assertNull(KeyBindingsValidator::new()->validate(['command:keys' => null]));
    }

    public function testTheSettingRefusesUnboundableCommandNames(): void
    {
        foreach (['command:', 'command:a:b:c', 'command:has space', 'command:-leading'] as $id) {
            $refusal = KeyBindingsValidator::new()->validate([$id => 'Ctrl+Z']);

            self::assertIsString($refusal, "the grammar must refuse '{$id}'");
            self::assertStringContainsString(self::NAME_REFUSAL_FRAGMENT, $refusal);
            self::assertStringContainsString($id, $refusal, 'the refusal names the offending entry');
        }
    }

    public function testACommandChordKeepsTheOrdinaryOverrideChordRules(): void
    {
        foreach (['z', 'Ctrl+X y'] as $chord) {
            $refusal = KeyBindingsValidator::new()->validate(['command:keys' => $chord]);

            self::assertIsString($refusal);
            self::assertStringContainsString(self::CHORD_REFUSAL_FRAGMENT, $refusal);
            self::assertStringContainsString('command:keys', $refusal);
        }

        self::assertNull(KeyBindingsValidator::new()->validate(['command:keys' => 'F13']));
    }

    public function testCommandEntriesSplitIntoTheirOwnConsult(): void
    {
        KeyBindingRegistry::useOverrides([
            'chat.palette' => 'Ctrl+B',
            'command:keys' => 'Ctrl+Z',
        ]);

        self::assertSame('keys', KeyBindingRegistry::commandFor(self::chordPress('z')));
        self::assertNull(KeyBindingRegistry::commandFor(self::chordPress('b')), 'an ordinary override is not a command');
        self::assertSame(['command:keys' => 'Ctrl+Z'], KeyBindingRegistry::commandBindings());

        // The entries stay in overrides() on purpose: the launch collision
        // drain reads that map, and a command entry must answer to it like
        // any other override.
        self::assertArrayHasKey('chat.palette', KeyBindingRegistry::overrides());
        self::assertArrayHasKey('command:keys', KeyBindingRegistry::overrides());
    }

    public function testTheCommandChordSpellingIsNormalisedLikeAnOverrideChord(): void
    {
        KeyBindingRegistry::useOverrides(['command:keys' => 'ctrl+z']);

        self::assertSame('keys', KeyBindingRegistry::commandFor(self::chordPress('z')), 'the user may spell the chord in any case');
    }

    public function testJunkEntriesRegisteredDirectlyCannotFireButStayListed(): void
    {
        // The validator is the door; the registry does not second-guess what
        // slips past it (or what a test plants directly). An empty name and
        // an unparseable chord never fire, and /keys still lists both —
        // display honesty over silent disappearance.
        KeyBindingRegistry::useOverrides([
            'command:' => 'Ctrl+Z',
            'command:junk' => 'two keys',
        ]);

        self::assertNull(KeyBindingRegistry::commandFor(self::chordPress('z')));
        self::assertSame(
            ['command:' => 'Ctrl+Z', 'command:junk' => 'two keys'],
            KeyBindingRegistry::commandBindings(),
        );
    }

    public function testTwoCommandsOnOneChordResolveToTheFirstRegistered(): void
    {
        // The dispatch memos take one winner per pressed chord (`??=` over
        // registration order) — the same law the alias memos obey for
        // shipped overrides. Deterministic and documented; the launch notice
        // names only shipped-chord steals, not this shape.
        KeyBindingRegistry::useOverrides([
            'command:first' => 'Ctrl+Z',
            'command:second' => 'Ctrl+Z',
        ]);

        self::assertSame('first', KeyBindingRegistry::commandFor(self::chordPress('z')));
    }

    public function testTheConsultShortCircuitsOnAnEmptyMapWithoutTouchingTheMemo(): void
    {
        // Every press walks handleKey's first lines; with no command entries
        // the consult must answer from the short-circuit and leave the memo
        // untouched (null, never an empty derived array), so the common case
        // pays no derivation.
        self::assertNull(KeyBindingRegistry::commandFor(self::chordPress('z')));

        $memo = new ReflectionProperty(KeyBindingRegistry::class, 'commandMemo');
        self::assertNull($memo->getValue(), 'the empty-map hot path must not build the memo');
    }

    public function testACommandOnAShippedChordEarnsTheCollisionNotice(): void
    {
        // collisionNotices() walks the shipped rows against the passed map,
        // and a command entry sits in that map under its own id — the row
        // names the entry, the chord, and the victim it steps on.
        $notices = KeyBindingsValidator::new()->collisionNotices(['command:keys' => 'Ctrl+P']);

        self::assertCount(1, $notices, 'got: ' . print_r($notices, true));
        self::assertStringContainsString(self::COLLISION_FRAGMENT, $notices[0]);
        self::assertStringContainsString('command:keys', $notices[0]);
        self::assertStringContainsString('chat.palette', $notices[0]);
    }

    public function testACommandOnAnUnshippedChordOrAVacatedVictorWarnsNothing(): void
    {
        self::assertSame([], KeyBindingsValidator::new()->collisionNotices(['command:keys' => 'Ctrl+Z']));

        // With `chat.palette` unbound by the user, Ctrl+P belongs to nobody
        // and the command steals from no one — the same vacated-victim law
        // ordinary overrides obey, because the check consults the live
        // suppression, not the map passed in.
        KeyBindingRegistry::useOverrides(['chat.palette' => null]);

        self::assertSame([], KeyBindingsValidator::new()->collisionNotices(['command:keys' => 'Ctrl+P']));
    }

    private static function chordPress(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune, ctrl: true);
    }
}

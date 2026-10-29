<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Config\Settings\Validator\KeyBindingsValidator;

/**
 * rv6's warning half of ISS-047: an override that lands on ANOTHER binding's
 * shipped chord steals that binding's key silently — the alias funnel
 * dispatches the rune to the override target before any claim decision, and
 * the stolen row never sees the press. The launch-notice channel says so.
 *
 * WHY NOT A REFUSAL: the dispatch is correct in this shape; the user chose
 * the trade. Only the SILENCE was the defect, and a row at launch is the
 * house's answer to a launch-time consequence the model already knows about.
 *
 * The notices ride the real seam ({@see Bootstrap::launchNotices()} after the
 * real drain), with the statics snapshotted and restored the way
 * {@see \SugarCraft\Code\Tests\Commands\NoticesCommandTest} does for its own
 * reasons. Fixtures use rows that actually ship bare `Ctrl+<rune>` chords
 * (`chat.palette` ships `Ctrl+P`, `chat.repaint` ships `Ctrl+L`); `Ctrl+Z`
 * is shipped by no row, so it is the harmless counterpart.
 */
final class KeybindingCollisionNoticeTest extends TestCase
{
    /** The fragment every collision row carries and no other row says. */
    private const ROW_FRAGMENT = 'collides with the shipped chord';

    /** @var array<int, string> */
    private array $seatedNoticesBefore = [];

    /** @var array<string, bool> */
    private array $seatedDroppedBefore = [];

    /** @var array<string, bool> */
    private array $reportedBefore = [];

    /** @var array<string, string> */
    private array $overridesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seatedNoticesBefore = self::readNoticeStatic('launchNotices');
        $this->seatedDroppedBefore = self::readNoticeStatic('launchNoticesDropped');
        $this->reportedBefore = self::readNoticeStatic('reportedPermissionConfigWarnings');
        $this->overridesBefore = KeyBindingRegistry::overrides();

        self::writeNoticeStatic('launchNotices', []);
        self::writeNoticeStatic('launchNoticesDropped', []);
        self::writeNoticeStatic('reportedPermissionConfigWarnings', []);
    }

    protected function tearDown(): void
    {
        KeyBindingRegistry::useOverrides($this->overridesBefore);
        self::writeNoticeStatic('launchNotices', $this->seatedNoticesBefore);
        self::writeNoticeStatic('launchNoticesDropped', $this->seatedDroppedBefore);
        self::writeNoticeStatic('reportedPermissionConfigWarnings', $this->reportedBefore);

        parent::tearDown();
    }

    public function testAnOverrideOntoAnotherRowsShippedChordIsAnnounced(): void
    {
        // `chat.repaint` moved onto `Ctrl+P` — the chord `chat.palette` ships.
        KeyBindingRegistry::useOverrides(['chat.repaint' => 'Ctrl+P']);

        Bootstrap::reportKeybindingCollisions();

        $rows = self::collisionRows();
        self::assertCount(1, $rows, 'the steal of a shipped chord must be announced once, got: ' . print_r($rows, true));
        self::assertStringContainsString('chat.repaint', $rows[0]);
        self::assertStringContainsString('Ctrl+P', $rows[0]);
        self::assertStringContainsString('chat.palette', $rows[0]);
    }

    public function testAHarmlessAndAnIdentityOverrideStaySilent(): void
    {
        // `Ctrl+Z` is shipped by no row; `Ctrl+L` is chat.repaint's OWN
        // chord — the funnel skips `$pressed === $shipped`, so nothing is
        // stolen and nothing may be announced.
        KeyBindingRegistry::useOverrides([
            'chat.repaint' => 'Ctrl+Z',
            'chat.paste-image' => 'Ctrl+V',
        ]);

        Bootstrap::reportKeybindingCollisions();

        self::assertSame([], self::collisionRows());
        self::assertNotSame([], KeyBindingRegistry::overrides(), 'fixture: the overrides must be registered or this proves nothing');
    }

    public function testARowThatVacatedItsShippedChordIsNotAVictim(): void
    {
        // The user moved `chat.palette` to `Ctrl+Z` first, then moved
        // `chat.repaint` onto the rune it vacated: /keys shows both rows at
        // their new chords, and the press dispatches where the reference
        // says. No silent steal, so no row.
        KeyBindingRegistry::useOverrides([
            'chat.palette' => 'Ctrl+Z',
            'chat.repaint' => 'Ctrl+P',
        ]);

        Bootstrap::reportKeybindingCollisions();

        self::assertSame([], self::collisionRows());
    }

    public function testASuppressedVictimIsNotACollision(): void
    {
        // `chat.session-picker` unbinds to null, vacating `Ctrl+R`. Moving
        // `chat.palette` onto the vacated chord steals from nobody — the row
        // that used to answer is silent by the user's own hand — so no row
        // may print. The suppression rides the registry static (plan-
        // comparison TUI-4b), not the map passed in, which is exactly the
        // seam this pins: collisionNotices() must consult it.
        KeyBindingRegistry::useOverrides(['chat.session-picker' => null]);

        self::assertSame([], KeyBindingsValidator::new()->collisionNotices(['chat.palette' => 'Ctrl+R']));

        // Control: with the picker live, the same move is a steal and the
        // launch must name it.
        KeyBindingRegistry::useOverrides([]);

        $notices = KeyBindingsValidator::new()->collisionNotices(['chat.palette' => 'Ctrl+R']);
        self::assertCount(1, $notices);
        self::assertStringContainsString(self::ROW_FRAGMENT, $notices[0]);
        self::assertStringContainsString('chat.session-picker', $notices[0]);
    }

    public function testTheValidatorAnswersWithoutTheSeam(): void
    {
        $notices = KeyBindingsValidator::new()->collisionNotices(['chat.repaint' => 'Ctrl+P']);
        self::assertCount(1, $notices);
        self::assertStringContainsString(self::ROW_FRAGMENT, $notices[0]);

        self::assertSame([], KeyBindingsValidator::new()->collisionNotices([]));
    }

    /**
     * TUI-4 (plan_comparison TUI-4b): two overrides claiming the SAME chord
     * were silent. The alias memos take one winner per pressed chord
     * (`$pairs[$pressed] ??=` over the /keys declaration order), so the row
     * listed first wins and the loser's override answers no key at all —
     * deterministic, but invisible. The row names both ids and the winner;
     * the law itself is documented beside it (`docs/SETTINGS.md`, the
     * "`/keys reload`" section).
     *
     * `Ctrl+Z` ships on no row, so no override-versus-shipped line can join
     * the census — the ONE row is provably the shared-chord shape.
     */
    public function testTwoOverridesSharingOneChordNameBothAndTheWinner(): void
    {
        $notices = KeyBindingsValidator::new()->collisionNotices([
            'chat.palette' => 'Ctrl+Z',
            'chat.repaint' => 'Ctrl+Z',
        ]);

        self::assertSame(1, \count($notices), 'a shared chord announces exactly one row, got: ' . print_r($notices, true));
        self::assertStringContainsString('both claim Ctrl+Z', $notices[0]);
        self::assertStringContainsString('"chat.palette" is listed first', $notices[0], 'chat.palette is declared at the reference before chat.repaint, and the memos walk the reference order');
        self::assertStringContainsString('"chat.repaint" answers no key at all', $notices[0], 'the loser is named, not merely out-venced');
        self::assertStringNotContainsString(self::ROW_FRAGMENT, $notices[0], 'this is its own shape, not a stolen shipped chord');

        // And the row rides the launch seam, not just the validator call.
        KeyBindingRegistry::useOverrides(['chat.palette' => 'Ctrl+Z', 'chat.repaint' => 'Ctrl+Z']);

        Bootstrap::reportKeybindingCollisions();

        $rows = array_values(array_filter(
            Bootstrap::launchNotices(),
            static fn (string $row): bool => str_contains($row, 'both claim'),
        ));
        self::assertCount(1, $rows, 'the shared-chord row must seat at launch like every other collision row');
    }

    public function testTheDrainRunsWhereTheOverridesAreRegistered(): void
    {
        // The whole value of the row is that it prints at launch. A refactor
        // that drops the call next to useOverrides() — or moves it before the
        // registration, where overrides() would still be empty — must redden
        // this pin rather than silently unannounce every future collision.
        $source = (string) file_get_contents((new \ReflectionClass(Bootstrap::class))->getFileName());

        self::assertSame(
            1,
            preg_match(
                '/useOverrides\(self::keybindingsSetting\(\)\);\s*self::reportKeybindingCollisions\(\);/',
                $source,
            ),
            'chat() no longer drains collisions immediately after registering the overrides',
        );
    }

    public function testACommandEntryCollidingWithAShippedChordEarnsTheLaunchRow(): void
    {
        // C-EXT-5: command entries ride overrides() precisely so this fires.
        // The first loop is id-generic — it walks the map and compares the
        // entry's chord against every shipped row — so a command entry
        // stepping on `chat.repaint`'s Ctrl+L is announced exactly like an
        // ordinary override would be, with the entry id named in the row.
        KeyBindingRegistry::useOverrides(['command:repaint' => 'Ctrl+L']);

        Bootstrap::reportKeybindingCollisions();

        $rows = self::collisionRows();
        self::assertCount(1, $rows, 'the command entry must be announced like any other override, got: ' . print_r($rows, true));
        self::assertStringContainsString('command:repaint', $rows[0]);
        self::assertStringContainsString('Ctrl+L', $rows[0]);
        self::assertStringContainsString('chat.repaint', $rows[0]);
    }

    public function testACommandSharingItsChordWithAnOverrideIsDeliberatelySilent(): void
    {
        // The documented silence (docs/SETTINGS.md, the `command:<name>`
        // section): the shared-chord loop walks the shipped ROWS, and a
        // command id is not a row — so a command and an override on the same
        // unshipped chord earn no row either shape. The command wins at the
        // funnel itself (the consult sits above the alias translation), not
        // through an announcement; this pin keeps the silence declared
        // rather than discovered.
        KeyBindingRegistry::useOverrides([
            'chat.palette' => 'Ctrl+Z',
            'command:keys' => 'Ctrl+Z',
        ]);

        Bootstrap::reportKeybindingCollisions();

        self::assertSame([], self::collisionRows());
        self::assertSame([], array_values(array_filter(
            Bootstrap::launchNotices(),
            static fn (string $row): bool => str_contains($row, 'both claim'),
        )), 'the shared-chord row names shipped rows against each other; a command entry must not force its way into it');
    }

    /** @return list<string> every seated notice row naming a chord collision */
    private static function collisionRows(): array
    {
        return array_values(array_filter(
            Bootstrap::launchNotices(),
            static fn (string $row): bool => str_contains($row, self::ROW_FRAGMENT),
        ));
    }

    /** @return array<int|string, mixed> */
    private static function readNoticeStatic(string $name): array
    {
        $property = new \ReflectionProperty(LaunchNotices::class, $name);
        $property->setAccessible(true);

        /** @var array<int|string, mixed> $value */
        $value = $property->getValue();

        return $value;
    }

    /** @param array<int|string, mixed> $value */
    private static function writeNoticeStatic(string $name, array $value): void
    {
        $property = new \ReflectionProperty(LaunchNotices::class, $name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}

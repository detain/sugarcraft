<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\SuggestionsReadyMsg;
use SugarCraft\Forms\TextArea\TextAreaEditedMsg;
use SugarCraft\Forms\Field\Color;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Date;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Slider;
use SugarCraft\Forms\Field\Text;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * E736 5.10 (round 89, lane y3): read-only mode. Two refusal shapes per the
 * ruling — value editors refuse every keystroke and release their consumes()
 * claims (arrows fall back to form navigation), pickers keep the cursor-
 * motion keys and refuse the mutating ones. Render affordance: the title
 * line gains ' (read-only)'; the default flag renders byte-identical.
 *
 * Carrier survival rides the shared CarriesNonCtorState leg (the widened
 * TraitStateCarryFamilyTest census pins the slot roster); this file pins
 * Confirm's private-carrier leg explicitly plus per-class behaviour.
 */
final class ReadonlyModeTest extends TestCase
{
    /**
     * A read-only value editor returns [$this, null] for the very keystroke
     * that changes its value while open — identity object, no Cmd.
     *
     * @return array<string, array{0: object, 1: KeyMsg}>
     */
    public static function refuseProvider(): array
    {
        return [
            'Input char'      => [Input::new('k'), new KeyMsg(KeyType::Char, 'a')],
            'Text char'       => [Text::new('k'), new KeyMsg(KeyType::Char, 'a')],
            'Text enter'      => [Text::new('k'), new KeyMsg(KeyType::Enter)],
            'Confirm y'       => [Confirm::new('k'), new KeyMsg(KeyType::Char, 'y')],
            'Color down'      => [Color::new('k'), new KeyMsg(KeyType::Down)],
            'Date day+1'      => [Date::new('k'), new KeyMsg(KeyType::Char, 'l')],
            'Slider right'    => [Slider::new('k'), new KeyMsg(KeyType::Right)],
            'FilePicker up'   => [FilePicker::new('k'), new KeyMsg(KeyType::Up)],
            'Select slash'    => [Select::new('k')->withOptions('a', 'b'), new KeyMsg(KeyType::Char, '/')],
            'MultiSelect sp'  => [MultiSelect::new('k')->withOptions('a', 'b'), new KeyMsg(KeyType::Space)],
            'MultiSelect dig' => [MultiSelect::new('k')->withOptions('a', 'b'), new KeyMsg(KeyType::Char, '1')],
        ];
    }

    #[DataProvider('refuseProvider')]
    public function testReadonlyRefusesValueChangingKeystrokes(object $field, KeyMsg $msg): void
    {
        [$open, ]            = $field->focus();
        [$changed, $cmdOpen] = $open->update($msg);
        self::assertNotSame($open, $changed, 'fixture key must change an open field (else this test is vacuous)');

        [$ro, ]              = $open->withReadonly(true)->focus();
        [$still, $cmdRo]     = $ro->update($msg);
        self::assertSame($ro, $still, 'read-only field must return identity for a mutating key');
        self::assertNull($cmdRo);
    }

    #[DataProvider('refuseProvider')]
    public function testDefaultIsNotReadonly(object $field, KeyMsg $msg): void
    {
        self::assertFalse($field->isReadonly());
        $ro = $field->withReadonly(true);
        self::assertTrue($ro->isReadonly());
        self::assertFalse($ro->withReadonly(false)->isReadonly());
        self::assertNotSame($field, $ro, 'withReadonly must rebuild');
    }

    /**
     * The picker half of the ruling: cursor motion still works while every
     * other key stays refused (refusal pinned by the provider above).
     */
    public function testSelectReadonlyKeepsCursorMotion(): void
    {
        [$ro, ] = Select::new('k')->withOptions('a', 'b', 'c')->withReadonly(true)->focus();
        [$moved, ] = $ro->update(new KeyMsg(KeyType::Down));
        self::assertNotSame($ro, $moved, 'Down must still move the highlight');
        self::assertTrue($moved->isReadonly());
        self::assertSame($moved->value(), 'b');

        [$still, ] = $moved->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame($moved, $still, 'typed text must stay refused');
    }

    public function testMultiSelectReadonlyKeepsCursorMotion(): void
    {
        [$ro, ] = MultiSelect::new('k')->withOptions('a', 'b', 'c')->withReadonly(true)->focus();
        [$moved, ] = $ro->update(new KeyMsg(KeyType::Char, 'j'));
        self::assertNotSame($ro, $moved, 'vim j must still move the checkbox cursor');
        self::assertSame($moved->value(), [], 'moving must not select');

        [$still, ] = $moved->update(new KeyMsg(KeyType::Space));
        self::assertSame($moved, $still, 'Space toggle must stay refused');
    }

    /**
     * Refuse-all claimers must release consumes() so the Form reclaims the
     * key for field navigation instead of forwarding into a dead refusal.
     *
     * @return array<string, array{0: object, 1: KeyMsg}>
     */
    public static function claimReleaseProvider(): array
    {
        return [
            'Color up'          => [Color::new('k'), new KeyMsg(KeyType::Up)],
            'Date down'         => [Date::new('k'), new KeyMsg(KeyType::Down)],
            'Text enter'        => [Text::new('k'), new KeyMsg(KeyType::Enter)],
            'FilePicker enter'  => [FilePicker::new('k'), new KeyMsg(KeyType::Enter)],
        ];
    }

    #[DataProvider('claimReleaseProvider')]
    public function testReadonlyReleasesConsumesClaims(object $field, KeyMsg $msg): void
    {
        [$open, ] = $field->focus();
        self::assertTrue($open->consumes($msg), 'fixture must claim the key while open (else vacuous)');

        [$ro, ] = $open->withReadonly(true)->focus();
        self::assertFalse($ro->consumes($msg), 'read-only field must release the claim');
    }

    /** Pickers keep claiming Up/Down while read-only (the highlight still moves). */
    public function testReadonlyPickersStillClaimNavigation(): void
    {
        [$ro, ] = Select::new('k')->withOptions('a', 'b')->withReadonly(true)->focus();
        self::assertTrue($ro->consumes(new KeyMsg(KeyType::Down)));

        [$mro, ] = MultiSelect::new('k')->withOptions('a', 'b')->withReadonly(true)->focus();
        self::assertTrue($mro->consumes(new KeyMsg(KeyType::Up)));
    }

    /**
     * Render affordance: marker on the title line iff read-only; the default
     * view stays byte-identical (every existing view pin doubles as proof —
     * this pins the two polarities explicitly).
     */
    public function testReadonlyMarkerOnTitleLine(): void
    {
        $base = Input::new('k')->withTitle('Name');
        self::assertStringNotContainsString('(read-only)', $base->view());

        $ro = $base->withReadonly(true);
        self::assertStringContainsString('Name (read-only)', $ro->view());

        // An untitled read-only field shows no bare marker line (affordance
        // rides the title row by design).
        $titleless = Input::new('k')->withReadonly(true);
        self::assertStringNotContainsString('(read-only)', $titleless->view());
    }

    public function testNoteCarriesTheMarkerToo(): void
    {
        $note = Note::new('k')->withTitle('Info');
        self::assertStringNotContainsString('(read-only)', $note->view());
        self::assertStringContainsString('Info (read-only)', $note->withReadonly(true)->view());
    }

    /**
     * The shared carrier leg: every label-trait field must still answer
     * isReadonly() after a mutate()-driven rebuild (withTitle). Deleting the
     * `$next->readonly` leg reddens all ten rows.
     *
     * @return array<string, array{0: class-string}>
     */
    public static function readonlyCarrierClasses(): array
    {
        return [
            Input::class       => [Input::class],
            Text::class        => [Text::class],
            Select::class      => [Select::class],
            MultiSelect::class => [MultiSelect::class],
            Note::class        => [Note::class],
            FilePicker::class  => [FilePicker::class],
            Date::class        => [Date::class],
            Slider::class      => [Slider::class],
            Color::class       => [Color::class],
            Confirm::class     => [Confirm::class],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('readonlyCarrierClasses')]
    public function testReadonlySurvivesMutateOnEveryField(string $class): void
    {
        $ro = $class::new('k')->withReadonly(true);
        self::assertTrue($ro->withTitle('T')->isReadonly(), "$class mutate() dropped the readonly slot");
    }

    /**
     * Carrier legs: the shared one is proven for all nine carrier classes by
     * the widened TraitStateCarryFamilyTest census roster; Confirm owns a
     * private carrier and needs the explicit leg pin.
     */
    public function testReadonlySurvivesConfirmRebuildPaths(): void
    {
        $field = Confirm::new('k')
            ->withValidator(static fn ($v): ?string => $v === true ? null : 'must confirm')
            ->withReadonly(true);

        self::assertTrue($field->withTitle('T')->isReadonly(), 'mutate dropped readonly');
        [$f, ] = $field->focus();
        self::assertTrue($f->blur()->isReadonly(), 'focus/blur dropped readonly');
        self::assertTrue($field->revalidate()->isReadonly(), 'revalidate (new self path) dropped readonly');
    }

    // ------------------------------------------------------------------
    // Round-90 audit #1: the mutation door is the buffer-mutating MESSAGE
    // set, not just KeyMsg. PasteMsg (bracketed paste, E736 5.13) and
    // TextAreaEditedMsg (external-editor round-trip) wrote straight through
    // a read-only Text via its inner TextArea while the gate pattern-matched
    // keys only; HasReadonly::isReadonlyMutatingMsg() now closes the door at
    // every field of the family.
    // ------------------------------------------------------------------

    /** Paste and editor results on a read-only Text: identity refusal, frozen buffer. */
    public function testReadonlyTextRefusesPasteAndEditorWrites(): void
    {
        [$open, ] = Text::new('k')->withValue('seed')->focus();
        [$pasted, ] = $open->update(new PasteMsg('pwned'));
        self::assertSame('seedpwned', $pasted->value(), 'fixture: paste writes an open Text (else vacuous)');
        [$edited, ] = $open->update(new TextAreaEditedMsg('editor-injected'));
        self::assertSame('editor-injected', $edited->value(), 'fixture: editor result writes an open Text (else vacuous)');

        [$ro, ] = Text::new('k')->withValue('seed')->withReadonly(true)->focus();
        foreach ([new PasteMsg('pwned'), new TextAreaEditedMsg('editor-injected')] as $write) {
            [$still, $cmd] = $ro->update($write);
            self::assertSame($ro, $still, $write::class . ' must be refused by a read-only Text');
            self::assertNull($cmd);
            self::assertSame('seed', $still->value(), 'buffer must stay frozen against ' . $write::class);
        }
    }

    /**
     * Family door: every read-only field identity-refuses the paste envelope
     * at the FIELD gate — no reliance on inner widgets ignoring it (the
     * self-contained ones already did via their `!KeyMsg` guard; the
     * delegating ones now refuse explicitly, round 90).
     *
     * @return array<string, array{0: object}>
     */
    public static function readonlyPasteDoorProvider(): array
    {
        return [
            'Input'       => [Input::new('k')],
            'Text'        => [Text::new('k')],
            'FilePicker'  => [FilePicker::new('k')],
            'Select'      => [Select::new('k')->withOptions('a', 'b')],
            'MultiSelect' => [MultiSelect::new('k')->withOptions('a', 'b')],
            'Confirm'     => [Confirm::new('k')],
            'Date'        => [Date::new('k')],
            'Slider'      => [Slider::new('k')],
            'Color'       => [Color::new('k')],
        ];
    }

    #[DataProvider('readonlyPasteDoorProvider')]
    public function testReadonlyFieldsIdentityRefuseThePasteEnvelope(object $field): void
    {
        [$ro, ] = $field->withReadonly(true)->focus();
        [$still, $cmd] = $ro->update(new PasteMsg('pwned'));
        self::assertSame($ro, $still, $field::class . ' must identity-refuse paste while read-only');
        self::assertNull($cmd);
    }

    /**
     * Audit answer for "mouse?": the only widget in this lib consuming
     * MouseMsg is ItemList, where it places the picker cursor — sanctioned
     * NAVIGATION under the 5.10 ruling (a picker's value IS the highlighted
     * entry, so motion drifts the reported value by accepted design). A
     * read-only Text has no mouse path at all: its buffer stays frozen.
     */
    public function testMouseIsInertOnTextAndSanctionedAsNavigationOnSelect(): void
    {
        [$text, ] = Text::new('k')->withValue('seed')->withReadonly(true)->focus();
        [$textAfter, ] = $text->update(new MouseWheelMsg(1, 1, MouseButton::WheelDown, MouseAction::Press));
        self::assertSame('seed', $textAfter->value(), 'mouse must not write a read-only Text buffer');

        [$select, ] = Select::new('k')->withOptions('a', 'b', 'c')->withReadonly(true)->focus();
        [$moved, ] = $select->update(new MouseWheelMsg(1, 1, MouseButton::WheelDown, MouseAction::Press));
        self::assertNotSame($select, $moved, 'wheel must still move a read-only picker cursor (sanctioned navigation)');
        self::assertSame('b', $moved->value());
        self::assertTrue($moved->isReadonly(), 'navigation must not clear the read-only flag');
    }

    /**
     * Round-90 review follow-up: the async-results arm runs BEFORE the
     * read-only door, and for a picker whose value IS the highlighted entry a
     * {@see SuggestionsReadyMsg} that swaps the candidate list drifts that
     * value. Select therefore refuses the arm itself while read-only. The
     * non-readonly polarity leg proves the guard is load-bearing (without it
     * the read-only leg would pass vacuously — the arm genuinely re-points the
     * value when the field is editable).
     */
    public function testReadonlySelectRefusesAsyncSuggestionValueDrift(): void
    {
        // Polarity: editable Select accepts the async result and its value moves.
        [$open, ] = Select::new('k')->withOptions('a', 'b', 'c')->focus();
        [$filled, ] = $open->update(new SuggestionsReadyMsg('k', ['Z']));
        self::assertNotSame($open, $filled, 'fixture: async result must re-point an editable picker (else vacuous)');
        self::assertSame('Z', $filled->value(), 'fixture: editable picker follows the delivered candidate');

        // The guard: read-only Select refuses the same message, value frozen.
        [$ro, ] = Select::new('k')->withOptions('a', 'b', 'c')->withReadonly(true)->focus();
        [$still, $cmd] = $ro->update(new SuggestionsReadyMsg('k', ['Z']));
        self::assertSame($ro, $still, 'read-only Select must identity-refuse an async suggestion');
        self::assertNull($cmd);
        self::assertSame('a', $still->value(), 'frozen picker value must not drift via the async arm');
        self::assertTrue($still->isReadonly());
    }
}

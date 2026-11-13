<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Color;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\RadioPills;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\Util\RenderSafe;
use SugarCraft\Forms\Validator\Required;

/**
 * SF-24 (sugar-forms-026) acceptance matrix: every selectable field answers
 * withValidator — attach-judges immediately (the Confirm precedent), a
 * verdict rides the value that earned it (the trait's mutate hook), the
 * inline row shares the house `! ` shape, and Form::validateAll() finally
 * sees the fields it used to pass through untouched.
 *
 * The trait plumbing itself is census-guarded next door
 * (TraitStateCarryFamilyTest); this file pins BEHAVIOR per field, including
 * the two pipeline overrides (NumberField commits, MultiSelect counts first).
 */
final class ValidationMatrixTest extends TestCase
{
    // ------------------------------------------------------------------
    // Select
    // ------------------------------------------------------------------

    public function testSelectVerdictTracksTheHighlightedEntry(): void
    {
        $field = Select::new('s')->withOptions('a', 'b')
            ->withValidator(static fn (mixed $v): ?string => $v === 'b' ? 'B-forbidden' : null);
        $this->assertNull($field->error(), 'attach judged "a" as pass');

        [$focused, ] = $field->focus();
        [$moved, ]   = $focused->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame('b', $moved->value());
        $this->assertSame('B-forbidden', $moved->error(), 'a value flip re-runs the rule the same step');

        [$back, ] = $moved->update(new KeyMsg(KeyType::Char, 'k'));
        $this->assertNull($back->error(), 'a fresh legal value clears the old row immediately');
        $this->assertNull($back->getError(), 'the deprecated read mirrors the bare one');
    }

    public function testSelectNullClearsAndValidatorInstanceCastReadsNullAsEmpty(): void
    {
        $always = Select::new('s')->withOptions('a')->withValidator(static fn (): ?string => 'X');
        $this->assertSame('X', $always->error());
        $this->assertNull($always->withValidator(null)->error(), 'null clears rule and verdict');

        // Required() on an EMPTY list: value is null, the string cast reads
        // '' (the Confirm precedent) — the whole point of the cast leg.
        $empty = Select::new('s')->withValidator(new Required());
        $this->assertSame('Value is required', $empty->error());
    }

    // ------------------------------------------------------------------
    // Color
    // ------------------------------------------------------------------

    public function testColorVerdictRidesAxisMoves(): void
    {
        $field = Color::new('c')->withValidator(
            static fn (mixed $hex): ?string => $hex === '#000000' ? 'black-refused' : null,
        );
        $this->assertSame('black-refused', $field->error(), 'attach judges the current hex');

        $brighter = $field->withValue('#ffffff');
        $this->assertNull($brighter->error());

        $viewed = $brighter->withValidator(static fn (): ?string => 'nope');
        $this->assertStringContainsString("\n! nope", $viewed->view());
        $this->assertStringNotContainsString('!', $viewed->view(false), 'the showErrors door governs the row');
    }

    // ------------------------------------------------------------------
    // RadioPills
    // ------------------------------------------------------------------

    public function testRadioPillsErrorRowLandsAfterThePillLineWithoutShiftingClickZones(): void
    {
        $field = RadioPills::new('r')->withOptions('one', 'two')
            ->withValidator(static fn (): ?string => 'stop');
        $view = $field->view();
        $this->assertStringContainsString("\n! stop", $view);
        $pillLine = (int) array_search(
            true,
            array_map(static fn (string $l): bool => str_contains($l, 'one'), explode("\n", $view)),
            true,
        );
        // title/description are empty, so the pill row is still line 0 of
        // the widget's own view — the geometry pillsRow() promises the mouse
        // hit-test never counts an error line.
        $this->assertSame(0, $pillLine, 'an error row must not push the pills off their measured line');
    }

    // ------------------------------------------------------------------
    // FilePicker
    // ------------------------------------------------------------------

    public function testFilePickerVerdictFollowsSelectionAcrossHydration(): void
    {
        $field = FilePicker::new('p')->withValidator(
            static fn (mixed $path): ?string => is_string($path) && str_ends_with($path, '.exe') ? 'no-executables' : null,
        );
        $this->assertNull($field->error(), 'nothing picked yet, nothing judged');

        $notes  = [];
        $picked = $field->hydrateValue('/tmp/setup.exe', $notes);
        $this->assertSame('no-executables', $picked->error());
        $this->assertStringContainsString("\n! no-executables", $picked->view());

        $cleared = $picked->hydrateValue(null, $notes);
        $this->assertNull($cleared->error(), 'a browse that clears the selection re-runs the rule');
    }

    // ------------------------------------------------------------------
    // NumberField — the commit-pipeline host
    // ------------------------------------------------------------------

    public function testNumberFieldParsesFirstAndJudgesTheCommittedValue(): void
    {
        $field = NumberField::new('n', 0, 10, 1)
            ->withValidator(static fn (int|float $v): ?string => $v < 3 ? 'too-small' : null);
        $this->assertNull($field->error(), 'the never-set box carries no verdict');

        [$focused, ] = $field->focus();
        [$typed, ]   = $focused->update(new KeyMsg(KeyType::Char, '2'));
        $this->assertNull($typed->error(), 'draft edits clear the slot; the rule waits for a commit');

        [$committed, ] = $typed->update(new KeyMsg(KeyType::Enter));
        $this->assertSame('too-small', $committed->error(), 'commit writes the verdict, not a bare clear');
        $this->assertSame(2, $committed->value());

        [$dirty, ] = $committed->update(new KeyMsg(KeyType::Char, 'x'));
        [$refused, ] = $dirty->update(new KeyMsg(KeyType::Enter));
        $this->assertNotSame('too-small', $refused->error(), 'a failed parse owns the row');
        $this->assertSame(2, $refused->value(), 'the committed value survives the refused draft');

        $this->assertNull($refused->withValue(5)->error(), 'a passing commit clears the verdict');
        $this->assertNull($field->withValue(5)->withoutValue()->error(), 'never-set again: no verdict');
    }

    public function testNumberFieldValidatorInstanceReceivesTheStringCast(): void
    {
        $field = NumberField::new('n', 0, 10, 1)->withValue(5)->withValidator(new Required());
        $this->assertNull($field->error(), '5 casts to "5" — Required passes');
        $zero  = NumberField::new('n', -5, 5, 1)->withValue(0)->withValidator(new Required());
        $this->assertNull($zero->error(), '0 casts to "0", not empty — the cast is string-shape, not truthiness');
    }

    // ------------------------------------------------------------------
    // MultiSelect — count bounds own the row, then the custom slot
    // ------------------------------------------------------------------

    public function testMultiSelectCustomSlotRunsAfterTheCountBounds(): void
    {
        $rule = static fn (array $labels): ?string => count($labels) === 2 ? 'pairs-refused' : null;
        $field = MultiSelect::new('m')->withOptions('a', 'b', 'c')->withValidator($rule);
        $this->assertNull($field->error(), 'attach on zero picks: rule passes, no verdict');

        [$f1, ] = $field->focus();
        [$f2, ] = $f1->update(new KeyMsg(KeyType::Space));       // pick a
        $this->assertNull($f2->error());
        [$f2b, ] = $f2->update(new KeyMsg(KeyType::Char, 'j'));  // step to b
        [$f3, ] = $f2b->update(new KeyMsg(KeyType::Space));      // pick b -> pair
        $this->assertSame('pairs-refused', $f3->error(), 'the custom slot fires once bounds pass');

        $strict = $f2->withMin(3);
        // withMin moves the bound, not the selection: the constraint verdict
        // (count lane) is recomputed by the next toggle or revalidate.
        $this->assertSame(
            'Pick at least 3.',
            $strict->revalidate()->error(),
            'the count message owns the row over the rule (bounds first)',
        );
    }

    public function testMultiSelectSlotIsClosureOnlyByDesign(): void
    {
        $field = MultiSelect::new('m')->withOptions('a');
        $this->assertNull($field->withValidator(null)->error());
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line intentional fail-loud leg */
        $field->withValidator(new Required());
    }

    // ------------------------------------------------------------------
    // Cross-cutting
    // ------------------------------------------------------------------

    public function testFormValidateAllSeesTheFieldsItUsedToPassThrough(): void
    {
        $form = \SugarCraft\Forms\Form::new(
            Select::new('s')->withOptions('a', 'b')->withValidator(static fn (): ?string => 'select-nope'),
            Color::new('c')->withValidator(static fn (): ?string => 'color-nope'),
        );
        $this->assertSame(
            ['s' => 'select-nope', 'c' => 'color-nope'],
            $form->validateAll(),
        );
    }

    public function testValidatorMessagesAreCleanedAtTheDisplaySite(): void
    {
        // RenderSafe policy: true SGR survives (it is paintable), dangerous
        // CSI payloads are stripped — a validator message must not be able
        // to clear the host's screen through the error row.
        $hostile = "a\033[2Jb";
        $field   = Select::new('s')->withOptions('a')->withValidator(
            static fn (): ?string => $hostile,
        );
        $view    = $field->view();
        $this->assertStringNotContainsString("\033[2J", $view, 'the verdict row is RenderSafe-cleaned');
        $this->assertSame($hostile, $field->error(), 'the stored verdict stays raw (getError law)');
    }

    public function testTextKeepsClosureReplaceSemanticsWithTheWarnedDocblock(): void
    {
        // SF-24 ships the warning, not a semantics change: the second call
        // REPLACES the first (unlike Input's chain) — pinned so the README
        // matrix cannot drift from the code.
        $field = Text::new('t')
            ->withValidator(static fn (string $v): ?string => 'first')
            ->withValidator(static fn (string $v): ?string => 'second');
        $this->assertSame('second', $field->revalidate()->error());

        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Field/Text.php');
        $pos = strpos($src, 'public function withValidator');
        $this->assertIsInt($pos);
        $docStart = strrpos(substr($src, 0, (int) $pos), '/**');
        $this->assertIsInt($docStart);
        $docblock = substr($src, (int) $docStart, (int) $pos - (int) $docStart);
        $this->assertMatchesRegularExpression(
            '/REPLACES/',
            $docblock,
            'Text::withValidator must keep a docblock that WARNS about replace semantics (SF-24)',
        );
    }
}

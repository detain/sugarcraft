<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\RadioPills;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Slider;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\TextArea\TextArea as TextAreaWidget;
use SugarCraft\Forms\TextInput\TextInput as TextInputWidget;
use SugarCraft\Forms\Group;
use SugarCraft\Forms\KeyMap;
use SugarCraft\Forms\TextInput\ValidateOn;
use SugarCraft\Forms\Validator\Required;

/**
 * Verifies short-form aliases produce rendered output byte-identical to
 * the upstream-mirroring `with*` long forms. Comparing render output
 * (rather than instance equality) sidesteps internal-counter noise like
 * `Cursor::$id` while still asserting end-user-visible parity.
 */
final class ShortAliasesTest extends TestCase
{
    public function testInputAliases(): void
    {
        $long = Input::new('k')
            ->withTitle('T')->withDescription('D')->withPlaceholder('P')
            ->withCharLimit(8)->withWidth(20)->view();
        $short = Input::new('k')
            ->title('T')->desc('D')->placeholder('P')
            ->charLimit(8)->width(20)->view();
        $this->assertSame($long, $short);
    }

    public function testTextAliases(): void
    {
        $long = Text::new('k')
            ->withTitle('T')->withDescription('D')->withPlaceholder('P')
            ->withWidth(40)->withHeight(5)->view();
        $short = Text::new('k')
            ->title('T')->desc('D')->placeholder('P')
            ->width(40)->height(5)->view();
        $this->assertSame($long, $short);
    }

    public function testTextInputWidgetAliases(): void
    {
        $long  = TextInputWidget::new()->withPlaceholder('p')->withCharLimit(8)->withWidth(20);
        $short = TextInputWidget::new()->placeholder('p')->charLimit(8)->width(20);
        $this->assertSame($long->view(), $short->view());
    }

    public function testTextInputWidgetValidatorAlias(): void
    {
        $fn = fn(string $v): ?string => $v === '' ? 'required' : null;
        $long  = TextInputWidget::new()->withValidator($fn);
        $short = TextInputWidget::new()->validator($fn);
        // validateOn defaults to None → the validator runs at attach time.
        $this->assertSame('required', $long->err());
        $this->assertSame($long->err(), $short->err());
    }

    public function testTextAreaWidgetAliases(): void
    {
        $long  = TextAreaWidget::new()->withPlaceholder('p')->withCharLimit(100)->withWidth(40)->withHeight(5);
        $short = TextAreaWidget::new()->placeholder('p')->charLimit(100)->width(40)->height(5);
        $this->assertSame($long->view(), $short->view());
    }

    public function testConfirmAliases(): void
    {
        $long = Confirm::new('k', false)
            ->withTitle('T')->withDescription('D')
            ->withLabels('Yep', 'Nope')->withDefault(true)->view();
        $short = Confirm::new('k', false)
            ->title('T')->desc('D')
            ->labels('Yep', 'Nope')->default(true)->view();
        $this->assertSame($long, $short);
    }

    public function testSelectAliases(): void
    {
        $long  = Select::new('k')->withOptions('a', 'b')->withTitle('T')->withDescription('D')->withHeight(3)->view();
        $short = Select::new('k')->options('a', 'b')->title('T')->desc('D')->height(3)->view();
        $this->assertSame($long, $short);
    }

    public function testMultiSelectAliases(): void
    {
        $long  = MultiSelect::new('k')->withOptions('a', 'b')->withTitle('T')->withMin(1)->withMax(2)->view();
        $short = MultiSelect::new('k')->options('a', 'b')->title('T')->min(1)->max(2)->view();
        $this->assertSame($long, $short);
    }

    public function testNoteAliases(): void
    {
        $long  = Note::new('k')->withTitle('T')->withDescription('D')->withHeight(3)->withNext(true)->withNextLabel('Go')->view();
        $short = Note::new('k')->title('T')->desc('D')->height(3)->next(true)->nextLabel('Go')->view();
        $this->assertSame($long, $short);
    }

    public function testNumberFieldAliases(): void
    {
        // NumberField's lattice knobs were the gap in the README :99 "every
        // field" alias contract: min/max/step now ride the with* long forms.
        $long  = NumberField::new('k')
            ->withTitle('T')->withDescription('D')->withPlaceholder('P')
            ->withMin(5)->withMax(50)->withStep(5)->view();
        $short = NumberField::new('k')
            ->title('T')->desc('D')->placeholder('P')
            ->min(5)->max(50)->step(5)->view();
        $this->assertSame($long, $short);

        // Lattice effect is real, not just render noise: the seeded value
        // re-coerces onto [min,max]'s step grid under either spelling.
        $viaShort = NumberField::new('k', 0, 100, 1, 17)->step(10)->min(0)->max(100);
        $viaLong  = NumberField::new('k', 0, 100, 1, 17)->withStep(10)->withMin(0)->withMax(100);
        $this->assertSame($viaLong->value(), $viaShort->value());
    }

    public function testSliderAliases(): void
    {
        // Same README :99 contract as NumberField: Slider already carried
        // title/desc/width/default but not its lattice trio or validator.
        $long  = Slider::new('k')
            ->withMin(0)->withMax(40)->withStep(4)->withValue(12)->view();
        $short = Slider::new('k')
            ->min(0)->max(40)->step(4)->default(12)->view();
        $this->assertSame($long, $short);

        // Slider feeds its validator the numeric value (not a string): the
        // alias must forward the identical contract.
        $this->assertSame(
            Slider::new('k')->withValidator(static fn (int|float $v): ?string => null)->view(),
            Slider::new('k')->validator(static fn (int|float $v): ?string => null)->view(),
        );
    }

    public function testRadioPillsAliases(): void
    {
        $long  = RadioPills::new('k')
            ->withTitle('T')->withDescription('D')
            ->withOptions('a', 'b', 'c')->view();
        $short = RadioPills::new('k')
            ->title('T')->desc('D')
            ->options('a', 'b', 'c')->view();
        $this->assertSame($long, $short);

        $windowedLong  = RadioPills::new('k')->withOptions('alpha', 'beta', 'gamma')->withWidth(12)->view();
        $windowedShort = RadioPills::new('k')->options('alpha', 'beta', 'gamma')->width(12)->view();
        $this->assertSame($windowedLong, $windowedShort);
    }

    public function testFilePickerKnobsRoundTripViaAccessors(): void
    {
        // FilePicker.view() depends on the live filesystem cursor; compare configured
        // knobs via accessors instead.
        $long  = FilePicker::new('k')->withShowHidden(true)->withAllowedExtensions(['.php'])->withDirAllowed(false)->withFileAllowed(true);
        $short = FilePicker::new('k')->showHidden(true)->exts(['.php'])->dirAllowed(false)->fileAllowed(true);
        $this->assertSame($long->getTitle(), $short->getTitle());
        $this->assertSame($long->getDescription(), $short->getDescription());
    }

    public function testGroupAliases(): void
    {
        $long  = Group::new(Input::new('a'))->withTitle('T')->withDescription('D')->withShowHelp(false);
        $short = Group::new(Input::new('a'))->title('T')->desc('D')->showHelp(false);
        $this->assertSame($long->title, $short->title);
        $this->assertSame($long->description, $short->description);
        $this->assertSame($long->showHelp, $short->showHelp);
    }

    public function testFormAliases(): void
    {
        $long  = Form::new(Input::new('a'))->withWidth(80)->withHeight(20)->withShowHelp(false)->withTimeout(5000);
        $short = Form::new(Input::new('a'))->width(80)->height(20)->showHelp(false)->timeout(5000);
        $this->assertSame($long->timeoutMs(), $short->timeoutMs());
    }

    // ---------------------------------------------------------------
    // SF-44: newly filled short forms + picker ctor parity.
    // ---------------------------------------------------------------

    public function testSelectNewShortFormsMatchLongForms(): void
    {
        $long  = Select::new('k')->withOptions('a', 'b', 'c')->withSelected('b')->withVisibleLines(3)->view();
        $short = Select::new('k', 'a', 'b', 'c')->selected('b')->visibleLines(3)->view();
        $this->assertSame($long, $short);
        $this->assertSame($long, Select::new('k', ['a', 'b', 'c'])->selectedIndex(1)->visibleLines(3)->view());
    }

    public function testInputPasswordMaskShortFormRoutesLikeLongForm(): void
    {
        $maskedLong  = Input::new('k')->password(true)->withPasswordMask('[0-9]')->withValue('secret');
        $maskedShort = Input::new('k')->password(true)->passwordMask('[0-9]')->withValue('secret');
        $plain       = Input::new('k')->password(true)->withValue('secret');
        $this->assertSame($maskedLong->view(), $maskedShort->view());
        $this->assertNotSame($maskedLong->view(), $plain->view(), 'the mask itself must be live for the comparison to mean anything');
    }

    public function testInputAndTextValidateOnShortFormsRouteLikeLongForms(): void
    {
        $onBlur  = Input::new('k')->withValidator(new Required())->withValidateOn(ValidateOn::Blur)->blur();
        $onBlurS = Input::new('k')->withValidator(new Required())->validateOn(ValidateOn::Blur)->blur();
        $onSubmit  = Input::new('k')->withValidator(new Required())->withValidateOn(ValidateOn::Submit)->blur();
        $onSubmitS = Input::new('k')->withValidator(new Required())->validateOn(ValidateOn::Submit)->blur();
        $this->assertNotNull($onBlur->error(), 'sanity: Blur timing validates on blur');
        $this->assertSame($onBlur->error(), $onBlurS->error());
        $this->assertNull($onSubmit->error(), 'sanity: Submit timing defers past blur');
        $this->assertSame($onSubmit->error(), $onSubmitS->error());

        $textBlur  = Text::new('t')->withValidator(static fn(string $v): ?string => $v === '' ? 'req' : null)->withValidateOn(ValidateOn::Blur)->blur();
        $textBlurS = Text::new('t')->withValidator(static fn(string $v): ?string => $v === '' ? 'req' : null)->validateOn(ValidateOn::Blur)->blur();
        $this->assertSame($textBlur->error(), $textBlurS->error());
        $this->assertNotNull($textBlur->error());
    }

    public function testTraitShortFormsRouteLikeLongForms(): void
    {
        $cap = static fn(): string => 'T';
        $titleLong  = Input::new('k')->withTitleFunc($cap);
        $titleShort = Input::new('k')->titleFunc($cap);
        $this->assertSame($titleLong->getTitle(), $titleShort->getTitle());
        $this->assertSame('T', $titleShort->getTitle(), 'sanity: the func is live');

        $descLong  = Input::new('k')->withDescriptionFunc($cap);
        $descShort = Input::new('k')->descriptionFunc($cap);
        $this->assertSame($descLong->getDescription(), $descShort->getDescription());

        $hideLong  = Input::new('k')->withHideFunc(static fn(): bool => true);
        $hideShort = Input::new('k')->hideFunc(static fn(): bool => true);
        $this->assertTrue($hideShort->isHidden([]));
        $this->assertSame($hideLong->isHidden([]), $hideShort->isHidden([]));

        $roLong  = Input::new('k')->withReadonly()->withValue('typed');
        $roShort = Input::new('k')->readonly()->withValue('typed');
        $this->assertSame($roLong->view(), $roShort->view());
    }

    public function testFilePickerPathReadMatchesValue(): void
    {
        $picker = FilePicker::new('p');
        $this->assertSame($picker->value(), $picker->path());
        $hydrated = $picker->hydrateValue('/tmp/x.txt');
        $this->assertSame('/tmp/x.txt', $hydrated->path());
    }

    public function testFormNewShortFormsRouteLikeLongForms(): void
    {
        $base = Form::new(Input::new('a'), Input::new('b'));
        $long  = $base->withKeyMap(KeyMap::new());
        $short = $base->keyMap(KeyMap::new());
        $this->assertSame(
            $long->activeKeyMap()->labelsFor('submit'),
            $short->activeKeyMap()->labelsFor('submit'),
        );

        $cLong  = $base->withConstraints([]);
        $cShort = $base->constraints([]);
        $this->assertSame($cLong->view(), $cShort->view());

        $fLong  = $base->withFocus('b');
        $fShort = $base->focus('b');
        $this->assertSame('b', $fShort->focusedField()?->key());
        $this->assertSame($fLong->focusedField()?->key(), $fShort->focusedField()?->key());
    }

    public function testPickerConstructorsShareTheRadioPillsRecipe(): void
    {
        // The copy-paste fix (sugar-forms-065): one construction shape for
        // all three picker fields, bare strings or a single list.
        $select  = Select::new('s', 'a', 'b')->view();
        $multi   = MultiSelect::new('m', 'a', 'b')->view();
        $pills   = RadioPills::new('r', 'a', 'b')->view();
        $this->assertStringContainsString('a', $select);
        $this->assertStringContainsString('b', $select);
        $this->assertStringContainsString('a', $multi);
        $this->assertStringContainsString('b', $pills);
        $this->assertSame(Select::new('s', ['a', 'b'])->view(), $select);
        $this->assertSame(MultiSelect::new('m', ['a', 'b'])->view(), $multi);
    }
}

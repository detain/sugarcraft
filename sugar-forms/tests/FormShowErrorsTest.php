<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\Form;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\TextInput\ValidateOn;
use PHPUnit\Framework\TestCase;

/**
 * SF-7 (sugar-forms-005): withShowErrors was a dead knob — stored, never
 * read — while the docblock promised suppression of the inline `! error`
 * line. The knob now reaches every inline render site: Form::view() passes
 * it into each field's view($showErrors), the accessible view gates its
 * `! ` row the same way, and the error-help satellite hides with its
 * error. The end-of-form error SUMMARY is a separate composer
 * (withErrorSummary) and is deliberately NOT affected by this knob — that
 * boundary is pinned here too.
 */
final class FormShowErrorsTest extends TestCase
{
    private function erroredInput(string $key = 'name'): Input
    {
        $field = Input::new($key)
            ->title('Name')
            ->withValidator(static fn (string $v): ?string => 'boom');
        $field = $field->revalidate();
        $this->assertSame('boom', $field->getError());
        return $field;
    }

    private function formWithError(bool $showErrors): Form
    {
        $form = Form::new($this->erroredInput());
        return $showErrors ? $form : $form->withShowErrors(false);
    }

    public function testInlineErrorRowPresentByDefault(): void
    {
        $this->assertStringContainsString('! boom', $this->formWithError(true)->view());
    }

    public function testInlineErrorRowSuppressedWhenOff(): void
    {
        $rendered = $this->formWithError(false)->view();
        $this->assertStringNotContainsString('! boom', $rendered);
        // The field itself still renders its title row.
        $this->assertStringContainsString('Name', $rendered);
    }

    public function testStandaloneFieldKeepsInlineErrorByDefault(): void
    {
        $field = $this->erroredInput('solo');
        $this->assertStringContainsString('! boom', $field->view());
        $this->assertStringContainsString('! boom', $field->view(true));
        $this->assertStringNotContainsString('! boom', $field->view(false));
    }

    public function testErrorHelpSatelliteHidesWithTheErrorRow(): void
    {
        $field = $this->erroredInput()->withErrorHelp('try again');
        $this->assertStringContainsString('? try again', $field->view());
        $this->assertStringNotContainsString('? try again', $field->view(false));
        $form = Form::new($field)->withShowErrors(false);
        $this->assertStringNotContainsString('? try again', $form->view());
    }

    public function testErrorSummaryIsNotAffectedByTheKnob(): void
    {
        $form = Form::new($this->erroredInput())->withErrorSummary(true)->withShowErrors(false);
        // Inline suppressed…
        $this->assertStringNotContainsString('! boom', $form->view());
        // …but the summary composer still surfaces the error (Name: boom).
        $this->assertStringContainsString('Name: boom', $form->view());
    }

    public function testAccessibleViewGatesTheInlineRow(): void
    {
        [$form, ] = Form::new($this->erroredInput())->withAccessible(true)->focusField('name');
        $this->assertStringContainsString('! boom', $form->view());
        $this->assertStringNotContainsString('! boom', $form->withShowErrors(false)->view());
    }

    /**
     * The other three inline renderers (Text / MultiSelect / NumberField)
     * must honour the same gate — driven with each field's proven way of
     * producing a live error.
     *
     * @return array<string, array{0: object}>
     */
    public static function inlineRendererProvider(): array
    {
        $text = Text::new('k')
            ->withValidator(static fn (string $_v): ?string => 'boom')
            ->withValidateOn(ValidateOn::Blur)
            ->withErrorHelp('markdown allowed');
        [$text, ] = $text->focus();
        $text = $text->blur();

        $ms = MultiSelect::new('k')
            ->withOptions('a', 'b')
            ->withMax(1)
            ->withErrorHelp('choose exactly one');
        [$ms, ] = $ms->focus();
        [$ms, ] = $ms->update(new KeyMsg(KeyType::Space));
        [$ms, ] = $ms->update(new KeyMsg(KeyType::Down));
        [$ms, ] = $ms->update(new KeyMsg(KeyType::Space));

        $nf = NumberField::new('k')->withTitle('Seed');
        [$nf, ] = $nf->focus();
        [$nf, ] = $nf->update(new KeyMsg(KeyType::Char, '!'));
        [$nf, ] = $nf->update(new KeyMsg(KeyType::Char, '!'));
        [$nf, ] = $nf->update(new KeyMsg(KeyType::Enter));

        foreach ([$text, $ms, $nf] as $f) {
            self::assertNotNull($f->error(), 'fixture must carry a live error');
        }
        return ['Text' => [$text], 'MultiSelect' => [$ms], 'NumberField' => [$nf]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inlineRendererProvider')]
    public function testAllInlineRenderersHonourTheFlag(object $field): void
    {
        $this->assertStringContainsString('! ', $field->view());
        $this->assertStringNotContainsString('! ', $field->view(false));
        $help = method_exists($field, 'errorHelp') ? $field->errorHelp() : null;
        if ($help !== null && $help !== '') {
            $this->assertStringContainsString('? ' . $help, $field->view());
            $this->assertStringNotContainsString('? ', $field->view(false));
        }
    }

    public function testEveryFieldViewAcceptsTheContextParameter(): void
    {
        $ref = new \ReflectionMethod(Field::class, 'view');
        $params = $ref->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('showErrors', $params[0]->getName());
        $this->assertTrue($params[0]->isDefaultValueAvailable());
        $this->assertTrue($params[0]->getDefaultValue());

        $classes = [
            Input::class,
            \SugarCraft\Forms\Field\Text::class,
            \SugarCraft\Forms\Field\MultiSelect::class,
            \SugarCraft\Forms\Field\NumberField::class,
            \SugarCraft\Forms\Field\Select::class,
            \SugarCraft\Forms\Field\Confirm::class,
            \SugarCraft\Forms\Field\Slider::class,
            \SugarCraft\Forms\Field\Note::class,
            \SugarCraft\Forms\Field\Color::class,
            \SugarCraft\Forms\Field\Date::class,
            \SugarCraft\Forms\Field\FilePicker::class,
            \SugarCraft\Forms\Field\RadioPills::class,
        ];
        foreach ($classes as $class) {
            $m = new \ReflectionMethod($class, 'view');
            $p = $m->getParameters();
            $this->assertCount(1, $p, $class);
            $this->assertSame('showErrors', $p[0]->getName(), $class);
            $this->assertTrue($p[0]->getDefaultValue(), $class);
        }
    }
}

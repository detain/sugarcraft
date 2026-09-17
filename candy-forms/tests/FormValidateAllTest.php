<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Form;
use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Group;

final class FormValidateAllTest extends TestCase
{
    public function testValidateAllReturnsEmptyWhenNoErrors(): void
    {
        $form = Form::new(
            Select::new('color')->withOptions('Red', 'Green', 'Blue'),
        );
        $this->assertSame([], $form->validateAll());
    }

    public function testValidateAllSkipsHiddenGroups(): void
    {
        $form = Form::new(
            Note::new('hidden note'),
        );
        $errors = $form->validateAll();
        $this->assertSame([], $errors);
    }

    public function testValidateAllSkipsSkippableFields(): void
    {
        $form = Form::new(
            Note::new('just a note'),
        );
        $errors = $form->validateAll();
        $this->assertArrayNotHasKey('note', $errors);
    }

    public function testValidateAllReturnsSameAsErrorsMethod(): void
    {
        $form = Form::new(
            Select::new('color')->withOptions('Red', 'Green', 'Blue'),
        );
        $this->assertSame($form->errors(), $form->validateAll());
    }

    public function testValidateAllWithMinViolationTriggersError(): void
    {
        $form = Form::new(
            MultiSelect::new('foods')
                ->withOptions('Pizza', 'Burger', 'Salad')
                ->withMin(1),
        );
        // MultiSelect with min=1: first toggle satisfies min.
        [$form, ] = $form->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Tab));
        [$form, ] = $form->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Space));

        // Min is satisfied — no error.
        $errors = $form->validateAll();
        $this->assertArrayNotHasKey('foods', $errors);

        // Toggle again to deselect — now min is violated.
        [$form, ] = $form->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Space));
        $errors = $form->validateAll();
        $this->assertArrayHasKey('foods', $errors);
    }

    public function testValidateAllWithMaxViolationTriggersError(): void
    {
        // Test that validateAll() surfaces a max constraint error when
        // count exceeds max. Since MultiSelect with max=1 prevents having
        // 2 items simultaneously via the toggle UX, we test via the min
        // path: untouched field (count=0, min=1) triggers "Pick at least 1."
        $form = Form::new(
            MultiSelect::new('foods')
                ->withOptions('Pizza', 'Burger', 'Salad')
                ->withMin(1),
        );
        // Field is untouched (count=0, min=1) — validateAll() must surface error.
        $errors = $form->validateAll();
        $this->assertArrayHasKey('foods', $errors);
    }

    /**
     * validateAll() calls revalidate() on each field, which forces validators
     * to run even on untouched fields. errors() only reads the cached error
     * state, so they differ for untouched-but-invalid fields.
     */
    public function testValidateAllInvokesValidatorOnUntouchedField(): void
    {
        $form = Form::new(
            Input::new('name')->required(),
        );
        // Field is untouched — errors() returns [] (reads cached error state).
        $this->assertSame([], $form->errors());
        // validateAll() calls revalidate() which runs validators.
        $allErrors = $form->validateAll();
        $this->assertArrayHasKey('name', $allErrors);
        $this->assertSame('Value is required', $allErrors['name']);
    }

    // ---------------------------------------------------------------------
    // E736-F2 (round 85): single-pass validateAll + progressive visibility
    // ---------------------------------------------------------------------

    public function testValidateAllJudgesGroupVisibilityProgressively(): void
    {
        // The hidden-group decision now reads the ACCUMULATED-SO-FAR value
        // map — the same convention values() uses (E736-F2/2.1). A hideFunc
        // that reaches FORWARD to a field defined in a later group therefore
        // sees that field's default, not its final value: the gated group
        // stays visible and its validator runs. Under the retired two-pass
        // walk the FULL map was consulted and this row was silently skipped.
        $gated = Input::new('gated')
            ->withValidator(static fn(string $v): ?string => 'always fails');
        $late = Input::new('late')->withValue('trigger');
        $form = Form::groups(
            Group::new($gated)->hideIf(static fn(array $values): bool => ($values['late'] ?? '') === 'trigger'),
            Group::new($late),
        );
        $this->assertArrayHasKey('gated', $form->validateAll(), 'progressive pass cannot see the forward value');
        $this->assertArrayHasKey('gated', $form->values(), 'validateAll and values() agree on visibility');
    }

    public function testValidateAllSkipsGroupHiddenByEarlierValue(): void
    {
        // Backward dependency (the ordinary case): the switch is accumulated
        // before the group is judged, so the hidden group is skipped — the
        // single pass keeps the behaviour every prior test already relied on.
        $inner = Input::new('inner')
            ->withValidator(static fn(string $v): ?string => 'always fails');
        $switch = Input::new('switch')->withValue('hide');
        $form = Form::groups(
            Group::new($switch),
            Group::new($inner)->hideIf(static fn(array $values): bool => ($values['switch'] ?? '') === 'hide'),
        );
        $this->assertSame([], $form->validateAll());
        $this->assertSame([], $form->errors());
    }

    public function testValidateAllCarriesEveryVisibleFieldErrorInOneWalk(): void
    {
        // Single O(n) pass: two erroring fields across two groups land in the
        // map together, keyed and ordered by field attachment (no second
        // pass could reorder or drop them).
        $a = Input::new('a')->withValidator(static fn(string $v): ?string => 'A!');
        $b = Input::new('b')->withValidator(static fn(string $v): ?string => 'B!');
        $form = Form::new($a, $b);
        $this->assertSame(['a' => 'A!', 'b' => 'B!'], $form->validateAll());
    }

}

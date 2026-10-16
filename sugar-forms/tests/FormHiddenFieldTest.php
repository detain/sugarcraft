<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Group;
use SugarCraft\Forms\Theme;

/**
 * Field-level `isHidden()` is enforced by Form, as the {@see \SugarCraft\Forms\Field}
 * contract promises: navigation, the submit gate, rendering and every
 * value/error collector treat a hidden field as if it didn't exist. Before
 * the fix Form only consulted Group::isHidden(), so a field attached via
 * withHideFunc() still took focus, still appeared in values(), and a
 * hidden required field blocked submission.
 */
final class FormHiddenFieldTest extends TestCase
{
    private static function hidden(): \Closure
    {
        return static fn(array $v): bool => true;
    }

    public function testHiddenFieldIsExcludedFromValues(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b')->withValue('stale')->withHideFunc(self::hidden()),
            Input::new('c'),
        );
        $this->assertSame(['a' => '', 'c' => ''], $form->values());
        $this->assertNull($form->get('b'));
        $this->assertSame('fallback', $form->getString('b', 'fallback'));
    }

    public function testNavigationSkipsHiddenFieldsBothWays(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b')->withHideFunc(self::hidden()),
            Input::new('c'),
        );
        [$form] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame('c', $form->focusedField()->key());
        [$form] = $form->update(new KeyMsg(KeyType::Up));
        $this->assertSame('a', $form->focusedField()->key());
    }

    public function testHiddenLeadingFieldIsNotTheInitialFocus(): void
    {
        $form = Form::new(
            Input::new('a')->withHideFunc(self::hidden()),
            Input::new('b'),
        );
        $this->assertSame('b', $form->focusedField()->key());
        $this->assertTrue($form->focusedField()->isFocused());
    }

    public function testEnterOnLastVisibleFieldSubmitsPastAHiddenTail(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b')->required()->withHideFunc(self::hidden()),
        );
        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($form->isSubmitted(), 'a hidden required field must not block submit');
        $this->assertSame(['a' => ''], $form->values());
    }

    public function testHiddenRequiredFieldIsExcludedFromEveryErrorCollector(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b')->required()->withHideFunc(self::hidden())->revalidate(),
        );
        $this->assertSame([], $form->errors());
        $this->assertFalse($form->hasErrors());
        $this->assertSame([], $form->validateAll());

        $async = null;
        $form->validateAsync()->then(static function (array $errors) use (&$async): void {
            $async = $errors;
        });
        $this->assertSame([], $async);
    }

    public function testPredicateSeesEarlierVisibleValuesProgressively(): void
    {
        $seen = [];
        $form = Form::groups(
            Group::new(Input::new('a')->withValue('x')),
            Group::new(
                Input::new('b')->withValue('y'),
                Input::new('c')->withHideFunc(static function (array $v) use (&$seen): bool {
                    $seen = $v;
                    return ($v['a'] ?? null) === 'x';
                }),
                Input::new('d'),
            ),
        );
        $this->assertSame(['a' => 'x', 'b' => 'y', 'd' => ''], $form->values());
        $this->assertSame(['a' => 'x', 'b' => 'y'], $seen, 'only values before the field are visible to it');
    }

    public function testFieldBecomesVisibleWhenTheEarlierValueChanges(): void
    {
        $form = Form::new(
            Input::new('kind'),
            Input::new('detail')->withHideFunc(static fn(array $v): bool => ($v['kind'] ?? '') !== 'other'),
            Input::new('last'),
        );
        [$f1] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame('last', $f1->focusedField()->key());

        foreach (str_split('other') as $ch) {
            [$form] = $form->update(new KeyMsg(KeyType::Char, $ch));
        }
        [$form] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame('detail', $form->focusedField()->key());
        $this->assertArrayHasKey('detail', $form->values());
    }

    public function testHiddenFieldDoesNotRender(): void
    {
        $form = Form::new(
            Input::new('a')->withTitle('Alpha'),
            Input::new('b')->withTitle('Bravo')->withHideFunc(self::hidden()),
        )->withTheme(Theme::plain());
        $out = $form->view();
        $this->assertStringContainsString('Alpha', $out);
        $this->assertStringNotContainsString('Bravo', $out);

        // The programmatic escape hatch may focus it by name; then it renders.
        $this->assertStringContainsString('Bravo', $form->withFocus('b')->view());
    }

    public function testSingleGroupWhoseOnlyFieldIsHiddenSubmitsOnEnter(): void
    {
        $form = Form::new(Input::new('a')->withTitle('Alpha')->withHideFunc(self::hidden()))
            ->withTheme(Theme::plain());

        // Nothing focusable: no field holds focus, none renders, none eats keys.
        $this->assertNull($form->focusedField());
        $this->assertStringNotContainsString('Alpha', $form->view());
        [$form] = $form->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertStringNotContainsString('x', $form->view());

        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($form->isSubmitted());
        $this->assertSame([], $form->values());
    }

    public function testFullyHiddenLastGroupIsSkippedAndTheFormSubmits(): void
    {
        $form = Form::groups(
            Group::new(Input::new('a')),
            Group::new(
                Input::new('b')->withTitle('Bravo')
                    ->withHideFunc(static fn(array $v): bool => ($v['a'] ?? '') === ''),
            ),
        )->withTheme(Theme::plain());

        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($form->isSubmitted(), 'Enter on the last visible field must submit');
        $this->assertSame(0, $form->activeGroupIndex());
        $this->assertSame('a', $form->focusedField()->key());
        $this->assertStringNotContainsString('Bravo', $form->view());
        $this->assertSame(['a' => ''], $form->values());
    }

    public function testFullyHiddenMiddleGroupIsSkippedBothWays(): void
    {
        $form = Form::groups(
            Group::new(Input::new('a')),
            Group::new(Input::new('b')->withHideFunc(self::hidden())),
            Group::new(Input::new('c')),
        );

        [$form] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(2, $form->activeGroupIndex());
        $this->assertSame('c', $form->focusedField()->key());

        [$form] = $form->update(new KeyMsg(KeyType::Up));
        $this->assertSame(0, $form->activeGroupIndex());
        $this->assertSame('a', $form->focusedField()->key());
    }

    public function testFullyHiddenLeadingGroupIsNotTheStartGroup(): void
    {
        $form = Form::groups(
            Group::new(Input::new('a')->withHideFunc(self::hidden())),
            Group::new(Input::new('b')),
        );
        $this->assertSame(1, $form->activeGroupIndex());
        $this->assertSame('b', $form->focusedField()->key());
        $this->assertTrue($form->focusedField()->isFocused());
    }

    public function testFocusNeverRestsOnAHiddenFieldBesideAPassiveNote(): void
    {
        $form = Form::groups(
            Group::new(Input::new('a')),
            Group::new(
                Input::new('b')->withTitle('Bravo')->withHideFunc(self::hidden()),
                Note::new('n')->withTitle('Thanks'),
            ),
        )->withTheme(Theme::plain());

        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(1, $form->activeGroupIndex());
        $this->assertSame('n', $form->focusedField()->key());
        $out = $form->view();
        $this->assertStringContainsString('Thanks', $out);
        $this->assertStringNotContainsString('Bravo', $out);
    }

    public function testNoteOnlyLastGroupSubmitsOnEnter(): void
    {
        $form = Form::groups(
            Group::new(Input::new('a')),
            Group::new(Note::new('n')->withTitle('Done')),
        );
        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(1, $form->activeGroupIndex());
        $this->assertFalse($form->isSubmitted());

        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($form->isSubmitted(), 'a page with no interactive field must not dead-end');
    }

    public function testHiddenPassiveNoteDoesNotRender(): void
    {
        $form = Form::new(
            Input::new('a'),
            Note::new('n')->withTitle('Secret')->withHideFunc(self::hidden()),
        )->withTheme(Theme::plain());
        $this->assertStringNotContainsString('Secret', $form->view());
    }

    public function testEnterOnAHostFocusedHiddenTrailingFieldSubmits(): void
    {
        // withFocus() deliberately does not refuse a hidden field. Focus parked
        // on a hidden field after the last visible one is past the last
        // interactive field, so Enter must submit rather than silently no-op.
        $form = Form::new(
            Input::new('a'),
            Input::new('b')->withHideFunc(self::hidden()),
        )->withFocus('b');
        $this->assertSame(1, $form->focusedIndex);

        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($form->isSubmitted(), 'Enter on a host-focused hidden tail must submit');
    }

    public function testEnterOnAHostFocusedHiddenTrailingFieldMovesToTheNextGroup(): void
    {
        $form = Form::groups(
            Group::new(Input::new('a'), Input::new('b')->withHideFunc(self::hidden())),
            Group::new(Input::new('c')),
        )->withFocus('b');
        $this->assertSame(0, $form->activeGroupIndex());

        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertFalse($form->isSubmitted());
        $this->assertSame(1, $form->activeGroupIndex());
        $this->assertSame('c', $form->focusedField()?->key());
    }

    public function testEnterOnAHostFocusedHiddenMiddleFieldAdvancesInsteadOfSubmitting(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b')->withHideFunc(self::hidden()),
            Input::new('c'),
        )->withFocus('b');

        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertFalse($form->isSubmitted(), 'a visible field still follows, so Enter only advances');
        $this->assertSame('c', $form->focusedField()?->key());
    }
}

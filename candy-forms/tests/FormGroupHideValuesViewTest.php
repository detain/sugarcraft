<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Group;

/**
 * A group's hide predicate is evaluated against ONE values view everywhere:
 * the progressive, hidden-filtered map {@see Form::values()} builds — the
 * visible fields of every earlier visible group, and nothing else. Navigation
 * (Tab / Shift-Tab / Enter and the last-group submit check) used to feed the
 * predicate the raw map of every group before the *current* one instead, so
 *
 *  - a field hidden by its own hide func still leaked its value into a later
 *    group's predicate during navigation, while values() excluded it, and
 *  - a predicate reading a field of the page being left saw nothing (the raw
 *    map stopped before the current group), while values() saw it.
 *
 * Either way the page the user was shown and the page values() reported
 * disagreed. Upstream huh evaluates a group's hide func against one shared
 * state for navigation and rendering alike; these tests pin that agreement.
 */
final class FormGroupHideValuesViewTest extends TestCase
{
    /** Group shown only while `secret` is absent from the values view. */
    private static function hiddenWhenSecretSeen(Input ...$fields): Group
    {
        return Group::new(...$fields)->withHideFunc(
            static fn(array $v): bool => array_key_exists('secret', $v),
        );
    }

    /**
     * g0: `secret` (hidden by its own hide func, carries a value)
     * g1: `a`
     * g2: hidden iff `secret` is in the values view
     * g3: `c`
     */
    private static function formGatedOnHiddenField(): Form
    {
        return Form::groups(
            Group::new(
                Input::new('secret')->withValue('leak')->withHideFunc(static fn(array $v): bool => true),
            ),
            Group::new(Input::new('a')),
            self::hiddenWhenSecretSeen(Input::new('b')),
            Group::new(Input::new('c')),
        );
    }

    public function testValuesViewTreatsGroupGatedOnHiddenFieldAsVisible(): void
    {
        $form = self::formGatedOnHiddenField();
        $this->assertSame(['a' => '', 'b' => '', 'c' => ''], $form->values());
        $this->assertSame(1, $form->activeGroupIndex(), 'the fully-hidden g0 is not the start page');
    }

    public function testForwardNavigationLandsOnGroupGatedOnHiddenField(): void
    {
        [$form] = self::formGatedOnHiddenField()->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(2, $form->activeGroupIndex(), 'g2 is visible per values(); navigation must agree');
        $this->assertSame('b', $form->focusedField()?->key());
    }

    public function testBackwardNavigationLandsOnGroupGatedOnHiddenField(): void
    {
        $form = self::formGatedOnHiddenField()->withFocus('c');
        $this->assertSame(3, $form->activeGroupIndex());

        [$form] = $form->update(new KeyMsg(KeyType::Up));
        $this->assertSame(2, $form->activeGroupIndex());
        $this->assertSame('b', $form->focusedField()?->key());
    }

    public function testEnterDoesNotSubmitPastAGroupGatedOnHiddenField(): void
    {
        // g2 is the last group here: Enter on g1 must move to it, not submit
        // because the leaked hidden value made it look hidden.
        $form = Form::groups(
            Group::new(
                Input::new('secret')->withValue('leak')->withHideFunc(static fn(array $v): bool => true),
            ),
            Group::new(Input::new('a')),
            self::hiddenWhenSecretSeen(Input::new('b')),
        );
        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertFalse($form->isSubmitted());
        $this->assertSame(2, $form->activeGroupIndex());
        $this->assertSame('b', $form->focusedField()?->key());
    }

    public function testGroupGatedOnFieldHiddenWithinSameEarlierGroupStaysVisible(): void
    {
        // The hiding field sits beside a visible one in an earlier page.
        $form = Form::groups(
            Group::new(
                Input::new('a'),
                Input::new('secret')->withValue('leak')->withHideFunc(static fn(array $v): bool => true),
            ),
            Group::new(Input::new('x')),
            self::hiddenWhenSecretSeen(Input::new('b')),
        );
        $this->assertSame(['a' => '', 'x' => '', 'b' => ''], $form->values());

        [$form] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(1, $form->activeGroupIndex());
        [$form] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(2, $form->activeGroupIndex());
        $this->assertSame('b', $form->focusedField()?->key());
    }

    public function testFieldShownOnlyWhenItsHiddenSiblingIsAbsentAgreesAcrossViews(): void
    {
        // The field-level predicate already used the filtered view; this pins
        // that a group predicate and a field predicate reading the same hidden
        // key reach the same verdict.
        $form = Form::groups(
            Group::new(
                Input::new('a'),
                Input::new('secret')->withValue('leak')->withHideFunc(static fn(array $v): bool => true),
            ),
            Group::new(
                Input::new('b')->withHideFunc(static fn(array $v): bool => array_key_exists('secret', $v)),
            ),
            self::hiddenWhenSecretSeen(Input::new('c')),
        );
        $this->assertSame(['a' => '', 'b' => '', 'c' => ''], $form->values());

        [$form] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame('b', $form->focusedField()?->key());
        [$form] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame('c', $form->focusedField()?->key());
    }

    public function testGroupPredicateSeesTheValuesOfThePageBeingLeft(): void
    {
        // g1 hides when g0's visible `a` says so. values() sees `a`; Tab off
        // g0 must too, instead of evaluating g1 against an empty map.
        $form = Form::groups(
            Group::new(Input::new('a')->withValue('skip')),
            Group::new(Input::new('b'))->withHideFunc(
                static fn(array $v): bool => ($v['a'] ?? '') === 'skip',
            ),
            Group::new(Input::new('c')),
        );
        $this->assertSame(['a' => 'skip', 'c' => ''], $form->values());

        [$form] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(2, $form->activeGroupIndex());
        $this->assertSame('c', $form->focusedField()?->key());
    }

    public function testEnterSubmitsWhenTheOnlyLaterGroupIsHiddenByThisPagesValue(): void
    {
        $form = Form::groups(
            Group::new(Input::new('a')->withValue('skip')),
            Group::new(Input::new('b'))->withHideFunc(
                static fn(array $v): bool => ($v['a'] ?? '') === 'skip',
            ),
        );
        [$form] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($form->isSubmitted(), 'g1 is hidden per values(), so g0 is the last page');
        $this->assertSame(['a' => 'skip'], $form->values());
    }

    public function testBackwardNavigationEvaluatesEachCandidateAgainstItsOwnPrefix(): void
    {
        // g1 hides once its OWN field `b` says so. values() evaluates g1
        // against the pages before it (just g0), where `b` is absent, so g1
        // is visible. Stepping back from g2 used to hand every candidate the
        // raw map of all pages before the current one — g0 AND g1 — so g1's
        // predicate saw its own `b` and Shift-Tab skipped it to g0.
        $form = Form::groups(
            Group::new(Input::new('a')),
            Group::new(Input::new('b')->withValue('x'))->withHideFunc(
                static fn(array $v): bool => ($v['b'] ?? '') === 'x',
            ),
            Group::new(Input::new('c')),
        )->withFocus('c');
        $this->assertSame(['a' => '', 'b' => 'x', 'c' => ''], $form->values());

        [$form] = $form->update(new KeyMsg(KeyType::Up));
        $this->assertSame(1, $form->activeGroupIndex());
        $this->assertSame('b', $form->focusedField()?->key());
    }

    public function testBackwardCandidateDoesNotSeeTheCurrentPagesValues(): void
    {
        // g1 hides once `c` — a field on the page being left, i.e. LATER than
        // g1 — is set. g1's predicate must see only the pages before g1, as
        // values() does, so Shift-Tab from g2 lands on g1. Guards against
        // evaluating backward candidates against the full values() map (or
        // any map that includes the current page).
        $form = Form::groups(
            Group::new(Input::new('a')),
            Group::new(Input::new('b'))->withHideFunc(
                static fn(array $v): bool => array_key_exists('c', $v),
            ),
            Group::new(Input::new('c')->withValue('set')),
        )->withFocus('c');
        $this->assertSame(['a' => '', 'b' => '', 'c' => 'set'], $form->values());

        [$form] = $form->update(new KeyMsg(KeyType::Up));
        $this->assertSame(1, $form->activeGroupIndex());
        $this->assertSame('b', $form->focusedField()?->key());
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Group;
use PHPUnit\Framework\TestCase;

/**
 * E736 5.3 (round 89) — programmatic focus: Form::focusField() / Form::withFocus().
 */
final class FormFocusFieldTest extends TestCase
{
    public function testWithFocusMovesWithinGroupAndSwapsFocusState(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b'),
            Input::new('c'),
        );
        // Sanity: the builder starts on 'a'.
        $this->assertSame('a', $form->focusedField()->key());

        $next = $form->withFocus('c');

        $this->assertSame(2, $next->focusedIndex);
        $this->assertSame('c', $next->focusedField()->key());
        $this->assertTrue($next->focusedField()->isFocused());
        // The previous field must be blurred — a stranded second cursor is
        // exactly the defect advance() guards against (see submitOrGate).
        $this->assertFalse($next->activeFields()[0]->isFocused());
        // Fluent law: original untouched.
        $this->assertNotSame($form, $next);
        $this->assertSame(0, $form->focusedIndex);
        $this->assertTrue($form->focusedField()->isFocused());
    }

    public function testUnknownKeyEarlyExitsToIdentity(): void
    {
        $form = Form::new(Input::new('a'), Input::new('b'));
        $this->assertSame($form, $form->withFocus('nope'));
        $this->assertSame($form, $form->focusField('nope')[0]);
        $this->assertNull($form->focusField('nope')[1]);
    }

    public function testAlreadyFocusedKeyEarlyExitsToIdentity(): void
    {
        $form = Form::new(Input::new('a'), Input::new('b'));
        $this->assertSame($form, $form->withFocus('a'));
        $this->assertSame($form, $form->focusField('a')[0]);
    }

    public function testSkippableTargetIsRefused(): void
    {
        $form = Form::new(Input::new('a'), Note::new('mid'), Input::new('b'));
        // Note::skippable() is true when not acting as a Next button —
        // focus must never land on it (would strand an un-focusable cursor).
        $this->assertTrue($form->activeFields()[1]->skippable());
        $this->assertSame($form, $form->withFocus('mid'));
        $this->assertSame(0, $form->focusedIndex);
    }

    public function testCrossGroupJumpMovesPageAndFocusesTarget(): void
    {
        $form = Form::groups(
            Group::new(Input::new('a'), Input::new('b')),
            Group::new(Input::new('x'), Input::new('y')),
        );
        $this->assertSame(0, $form->groupIndex);

        $next = $form->withFocus('y');

        $this->assertSame(1, $next->groupIndex);
        $this->assertSame(1, $next->focusedIndex);
        $this->assertSame('y', $next->focusedField()->key());
        $this->assertTrue($next->focusedField()->isFocused());
        // The field left behind on the previous page must be blurred too.
        $this->assertFalse($next->fieldsByGroup[0][0]->isFocused());
    }

    public function testFocusFieldSurfacesTheFocusCmd(): void
    {
        $form = Form::new(Input::new('a'), Input::new('b'));
        [$next, $cmd] = $form->focusField('b');

        $this->assertSame(1, $next->focusedIndex);
        // Input::focus() rides TextInput's cursor-blink Cmd — the whole point
        // of the Cmd-bearing sibling over the fluent withFocus().
        $this->assertNotNull($cmd);
        $this->assertIsCallable($cmd);
    }

    public function testWithFocusDiscardsCmdButKeepsStateMove(): void
    {
        $form = Form::new(Input::new('a'), Input::new('b'));
        $next = $form->withFocus('b');

        // The Cmd-bearing sibling lands the same state, and re-focusing an
        // already-focused key is the identity short-circuit on both verbs.
        $viaCmd = $form->focusField('b')[0];
        $this->assertSame($viaCmd->focusedIndex, $next->focusedIndex);
        $this->assertSame($viaCmd, $viaCmd->withFocus('b'));
        $this->assertSame($next, $next->withFocus('b'));
        $this->assertSame(1, $next->focusedIndex);
        $this->assertTrue($next->focusedField()->isFocused());
    }
}

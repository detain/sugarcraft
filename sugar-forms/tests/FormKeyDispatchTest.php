<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\KeyMap;

/**
 * SF-5 (M17): navigation keys bound in the KeyMap are eaten at the FORM
 * door before the focused field ever sees them. Form::update() consults
 * each field's consumes() claim first — only fields that claim a message
 * receive it ahead of the navigation match; everything else goes through
 * next/prev/submit/abort and only falls through to the field when no
 * binding owns the key.
 *
 * These pins hold that dispatch law from both polarities with the
 * Confirm Tab-toggle arm (E736 F3) as the witness: under the default
 * KeyMap Tab navigates and never reaches the arm; under a map that frees
 * Tab from navigation the same key reaches the field and toggles.
 */
final class FormKeyDispatchTest extends TestCase
{
    public function testBoundNavigationKeysStopAtTheFormDoor(): void
    {
        $form = Form::new(Confirm::new('c'), Input::new('next'));

        [$form] = $form->update(new KeyMsg(KeyType::Tab));

        // Tab is bound as `next`: focus advances and the Confirm value is
        // untouched — its own Tab-toggle arm never saw the key. (The live
        // focused clones live in fieldsByGroup; groups[] keeps the
        // construction-time copies.)
        $this->assertSame(1, $form->focusedIndex);
        $this->assertFalse($form->fieldsByGroup[0][0]->value);
    }

    public function testFreedKeysReachTheFieldArms(): void
    {
        $keyMap = KeyMap::new()
            ->withNext([['type' => KeyType::Down]])
            ->withPrev([['type' => KeyType::Up]]);
        $form = Form::new(Confirm::new('c'), Input::new('next'))
            ->withKeyMap($keyMap);

        [$form] = $form->update(new KeyMsg(KeyType::Tab));

        // With Tab unbound from navigation it falls through to the focused
        // field: the Confirm toggle arm fires and focus stays put.
        $this->assertSame(0, $form->focusedIndex);
        $this->assertTrue($form->fieldsByGroup[0][0]->value);
    }
}

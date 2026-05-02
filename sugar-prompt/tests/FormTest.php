<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Tests;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Prompt\Field\Confirm;
use CandyCore\Prompt\Field\Input;
use CandyCore\Prompt\Field\Note;
use CandyCore\Prompt\Field\Select;
use CandyCore\Prompt\Form;
use PHPUnit\Framework\TestCase;

final class FormTest extends TestCase
{
    public function testFirstNonSkippableFieldStartsFocused(): void
    {
        $form = Form::new(
            Note::new('intro'),
            Input::new('name'),
            Confirm::new('ok'),
        );
        $this->assertSame(1, $form->focusedIndex);
        $this->assertTrue($form->focusedField()->isFocused());
    }

    public function testTabAdvancesFocus(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b'),
            Input::new('c'),
        );
        [$form, ] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(1, $form->focusedIndex);
        $this->assertSame('b', $form->focusedField()->key());
    }

    public function testTabSkipsNote(): void
    {
        $form = Form::new(
            Input::new('a'),
            Note::new('mid'),
            Input::new('b'),
        );
        [$form, ] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(2, $form->focusedIndex);
    }

    public function testUpReturnsToPrevious(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b'),
        );
        [$form, ] = $form->update(new KeyMsg(KeyType::Tab));
        [$form, ] = $form->update(new KeyMsg(KeyType::Up));
        $this->assertSame(0, $form->focusedIndex);
    }

    public function testEnterOnLastFieldSubmits(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b'),
        );
        [$form, ] = $form->update(new KeyMsg(KeyType::Tab));
        $this->assertFalse($form->isSubmitted());
        [$form, $cmd] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($form->isSubmitted());
        $this->assertNotNull($cmd);
    }

    public function testEnterOnNonLastFieldAdvances(): void
    {
        $form = Form::new(
            Input::new('a'),
            Input::new('b'),
        );
        [$form, ] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(1, $form->focusedIndex);
        $this->assertFalse($form->isSubmitted());
    }

    public function testEscapeAborts(): void
    {
        $form = Form::new(Input::new('a'));
        [$form, $cmd] = $form->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($form->isAborted());
        $this->assertNotNull($cmd);
    }

    public function testCtrlCAborts(): void
    {
        $form = Form::new(Input::new('a'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertTrue($form->isAborted());
    }

    public function testForwardsKeysToFocusedField(): void
    {
        $form = Form::new(Input::new('a'), Input::new('b'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'h'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertSame(['a' => 'hi', 'b' => ''], $form->values());
    }

    public function testValuesSkipsNotes(): void
    {
        $form = Form::new(
            Note::new('intro'),
            Input::new('name'),
            Confirm::new('ok')->withDefault(true),
            Select::new('lang')->withOptions('PHP', 'Go'),
        );
        $this->assertSame(['name' => '', 'ok' => true, 'lang' => 'PHP'], $form->values());
    }

    public function testIgnoresKeysAfterSubmit(): void
    {
        $form = Form::new(Input::new('a'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($form->isSubmitted());
        [$form2, $cmd] = $form->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame($form, $form2);
        $this->assertNull($cmd);
    }

    public function testFocusedFieldOnlyOne(): void
    {
        $form = Form::new(Input::new('a'), Input::new('b'), Input::new('c'));
        [$form, ] = $form->update(new KeyMsg(KeyType::Tab));
        $focusedCount = 0;
        foreach ($form->fields as $f) {
            if ($f->isFocused()) $focusedCount++;
        }
        $this->assertSame(1, $focusedCount);
    }
}

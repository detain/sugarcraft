<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Testing\Input\ScriptedInput;

final class ScriptedInputTest extends TestCase
{
    public function testNewReturnsEmptyInput(): void
    {
        $input = ScriptedInput::new();

        $this->assertSame(0, $input->count());
        $this->assertSame([], $input->build());
    }

    public function testKeyAppendsCharacterKeyMsg(): void
    {
        $input = ScriptedInput::new()->key('a');

        $messages = $input->build();

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(KeyMsg::class, $messages[0]);
        /** @var KeyMsg */
        $msg = $messages[0];
        $this->assertSame('a', $msg->rune);
        $this->assertSame(KeyType::Char, $msg->type);
    }

    public function testKeyWithModifiers(): void
    {
        $input = ScriptedInput::new()->key('c', ctrl: true, alt: true, shift: true);

        $messages = $input->build();
        /** @var KeyMsg */
        $msg = $messages[0];

        $this->assertTrue($msg->ctrl);
        $this->assertTrue($msg->alt);
        $this->assertTrue($msg->shift);
    }

    public function testEnterAppendsEnterKeyMsg(): void
    {
        $input = ScriptedInput::new()->enter();

        $messages = $input->build();
        /** @var KeyMsg */
        $msg = $messages[0];

        $this->assertSame(KeyType::Enter, $msg->type);
    }

    public function testEscapeAppendsEscapeKeyMsg(): void
    {
        $input = ScriptedInput::new()->escape();

        $messages = $input->build();
        /** @var KeyMsg */
        $msg = $messages[0];

        $this->assertSame(KeyType::Escape, $msg->type);
    }

    public function testArrowAppendsCorrectArrowKey(): void
    {
        $inputDown = ScriptedInput::new()->arrow('down');
        $inputUp = ScriptedInput::new()->arrow('up');
        $inputLeft = ScriptedInput::new()->arrow('left');
        $inputRight = ScriptedInput::new()->arrow('right');

        /** @var KeyMsg */
        $msgDown = $inputDown->build()[0];
        $this->assertSame(KeyType::Down, $msgDown->type);

        /** @var KeyMsg */
        $msgUp = $inputUp->build()[0];
        $this->assertSame(KeyType::Up, $msgUp->type);

        /** @var KeyMsg */
        $msgLeft = $inputLeft->build()[0];
        $this->assertSame(KeyType::Left, $msgLeft->type);

        /** @var KeyMsg */
        $msgRight = $inputRight->build()[0];
        $this->assertSame(KeyType::Right, $msgRight->type);
    }

    public function testArrowThrowsOnInvalidDirection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ScriptedInput::new()->arrow('north');
    }

    public function testChainingWorks(): void
    {
        $input = ScriptedInput::new()
            ->key('h', ctrl: true)
            ->arrow('down')
            ->enter()
            ->key('q')
            ->build();

        $this->assertCount(4, $input);
    }

    public function testQuitAppendsQuitMsg(): void
    {
        $input = ScriptedInput::new()->quit();

        $messages = $input->build();

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\QuitMsg::class, $messages[0]);
    }

    public function testResizeAppendsWindowSizeMsg(): void
    {
        $input = ScriptedInput::new()->resize(120, 40);

        $messages = $input->build();

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\WindowSizeMsg::class, $messages[0]);
        /** @var \SugarCraft\Core\Msg\WindowSizeMsg */
        $msg = $messages[0];
        $this->assertSame(120, $msg->cols);
        $this->assertSame(40, $msg->rows);
    }

    public function testPushAppendsArbitraryMsg(): void
    {
        $customMsg = new class () implements \SugarCraft\Core\Msg {};
        $input = ScriptedInput::new()->push($customMsg);

        $messages = $input->build();

        $this->assertCount(1, $input->build());
        $this->assertSame($customMsg, $messages[0]);
    }

    public function testCountReturnsMessageCount(): void
    {
        $input = ScriptedInput::new()
            ->key('a')
            ->key('b')
            ->enter();

        $this->assertSame(3, $input->count());
    }

    public function testTicksAppendsTickMessages(): void
    {
        $input = ScriptedInput::new()->ticks(3);

        $messages = $input->build();

        $this->assertCount(3, $messages);
        foreach ($messages as $msg) {
            $this->assertInstanceOf(\SugarCraft\Testing\Input\TickMsg::class, $msg);
            $this->assertSame(1.0, $msg->seconds);
        }
    }

    public function testTicksWithCustomInterval(): void
    {
        $input = ScriptedInput::new()->ticks(2, 0.5);

        $messages = $input->build();

        $this->assertCount(2, $messages);
        foreach ($messages as $msg) {
            $this->assertInstanceOf(\SugarCraft\Testing\Input\TickMsg::class, $msg);
            $this->assertSame(0.5, $msg->seconds);
        }
    }

    public function testMouseAppendsMouseMsg(): void
    {
        $input = ScriptedInput::new()->mouse(
            MouseButton::Left,
            MouseAction::Press,
            10,
            20
        );

        $messages = $input->build();

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(MouseMsg::class, $messages[0]);
        /** @var MouseMsg */
        $msg = $messages[0];
        $this->assertSame(MouseButton::Left, $msg->button);
        $this->assertSame(MouseAction::Press, $msg->action);
        $this->assertSame(10, $msg->x);
        $this->assertSame(20, $msg->y);
    }

    public function testBackspaceAppendsBackspaceKeyMsg(): void
    {
        $input = ScriptedInput::new()->backspace();

        $messages = $input->build();

        $this->assertCount(1, $messages);
        /** @var KeyMsg */
        $msg = $messages[0];
        $this->assertSame(KeyType::Backspace, $msg->type);
    }

    public function testTabAppendsTabKeyMsg(): void
    {
        $input = ScriptedInput::new()->tab();

        $messages = $input->build();

        $this->assertCount(1, $messages);
        /** @var KeyMsg */
        $msg = $messages[0];
        $this->assertSame(KeyType::Tab, $msg->type);
    }
}

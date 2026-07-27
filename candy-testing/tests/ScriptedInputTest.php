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

    public function testNamedKeyAppendsKeyMsgWithGivenType(): void
    {
        $input = ScriptedInput::new()->namedKey(KeyType::F1, 'F1');

        $messages = $input->build();

        $this->assertCount(1, $messages);
        /** @var KeyMsg */
        $msg = $messages[0];
        $this->assertSame(KeyType::F1, $msg->type);
        $this->assertSame('F1', $msg->rune);
        $this->assertFalse($msg->ctrl);
        $this->assertFalse($msg->alt);
        $this->assertFalse($msg->shift);
    }

    public function testNamedKeyWithNoRune(): void
    {
        $input = ScriptedInput::new()->namedKey(KeyType::Insert);

        $messages = $input->build();

        /** @var KeyMsg */
        $msg = $messages[0];
        $this->assertSame(KeyType::Insert, $msg->type);
        $this->assertSame('', $msg->rune);
    }

    public function testWheelAppendsMouseWheelMsg(): void
    {
        $input = ScriptedInput::new()->wheel(
            \SugarCraft\Core\MouseButton::WheelUp,
            10,
            20
        );

        $messages = $input->build();

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\MouseWheelMsg::class, $messages[0]);
        /** @var \SugarCraft\Core\Msg\MouseWheelMsg */
        $msg = $messages[0];
        $this->assertSame(10, $msg->x);
        $this->assertSame(20, $msg->y);
        $this->assertSame(\SugarCraft\Core\MouseButton::WheelUp, $msg->button);
        $this->assertSame(MouseAction::Press, $msg->action);
    }

    public function testPasteAppendsPasteMsg(): void
    {
        $input = ScriptedInput::new()->paste('hello world');

        $messages = $input->build();

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\PasteMsg::class, $messages[0]);
        /** @var \SugarCraft\Core\Msg\PasteMsg */
        $msg = $messages[0];
        $this->assertSame('hello world', $msg->content);
    }

    public function testPastePreservesNewlinesAndControls(): void
    {
        $content = "line1\nline2\r\nline3";
        $input = ScriptedInput::new()->paste($content);

        $messages = $input->build();

        /** @var \SugarCraft\Core\Msg\PasteMsg */
        $msg = $messages[0];
        $this->assertSame($content, $msg->content);
    }

    public function testClipboardAppendsClipboardMsg(): void
    {
        $input = ScriptedInput::new()->clipboard('clipboard content');

        $messages = $input->build();

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\ClipboardMsg::class, $messages[0]);
        /** @var \SugarCraft\Core\Msg\ClipboardMsg */
        $msg = $messages[0];
        $this->assertSame('clipboard content', $msg->content);
        $this->assertSame('c', $msg->selection);
    }

    public function testClipboardWithCustomSelection(): void
    {
        $input = ScriptedInput::new()->clipboard('secondary', 's');

        $messages = $input->build();

        /** @var \SugarCraft\Core\Msg\ClipboardMsg */
        $msg = $messages[0];
        $this->assertSame('secondary', $msg->content);
        $this->assertSame('s', $msg->selection);
    }

    public function testKeyboardEnhancementsAppendsKeyboardEnhancementsMsg(): void
    {
        $flags = 0x01 | 0x02; // Example flags
        $input = ScriptedInput::new()->keyboardEnhancements($flags);

        $messages = $input->build();

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\KeyboardEnhancementsMsg::class, $messages[0]);
        /** @var \SugarCraft\Core\Msg\KeyboardEnhancementsMsg */
        $msg = $messages[0];
        $this->assertSame($flags, $msg->flags);
    }

    public function testKeyboardEnhancementsWithZeroFlags(): void
    {
        $input = ScriptedInput::new()->keyboardEnhancements(0);

        $messages = $input->build();

        /** @var \SugarCraft\Core\Msg\KeyboardEnhancementsMsg */
        $msg = $messages[0];
        $this->assertSame(0, $msg->flags);
    }

    public function testChainingAllMethodsTogether(): void
    {
        $input = ScriptedInput::new()
            ->namedKey(KeyType::F2, 'F2')
            ->wheel(\SugarCraft\Core\MouseButton::WheelDown, 5, 10)
            ->paste('pasted text')
            ->clipboard('clip', 'c')
            ->keyboardEnhancements(0xFF)
            ->build();

        $this->assertCount(5, $input);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\KeyMsg::class, $input[0]);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\MouseWheelMsg::class, $input[1]);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\PasteMsg::class, $input[2]);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\ClipboardMsg::class, $input[3]);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\KeyboardEnhancementsMsg::class, $input[4]);
    }
}

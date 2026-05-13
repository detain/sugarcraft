<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Sequence;
use SugarCraft\Dash\Grid\SequenceParticipant;
use SugarCraft\Dash\Grid\SequenceMessage;

final class SequenceTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $sequence = Sequence::new();
        $this->assertInstanceOf(Sequence::class, $sequence);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $sequence = Sequence::new();
        $result = $sequence->setSize(70, 20);
        $this->assertInstanceOf(\SugarCraft\Dash\Grid\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $sequence = Sequence::new()->setSize(70, 20);
        $rendered = $sequence->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $sequence = Sequence::new()->setSize(70, 20);
        $rendered = $sequence->render();
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testWithParticipant(): void
    {
        $sequence = Sequence::new();
        $participant = SequenceParticipant::actor('client', 'Client');
        $result = $sequence->withParticipant($participant);
        $this->assertInstanceOf(Sequence::class, $result);
    }

    public function testAddParticipant(): void
    {
        $sequence = Sequence::new();
        $result = $sequence->addParticipant('server', 'Server');
        $this->assertInstanceOf(Sequence::class, $result);
    }

    public function testWithParticipants(): void
    {
        $sequence = Sequence::new();
        $participants = [
            SequenceParticipant::actor('client', 'Client'),
            SequenceParticipant::object('server', 'Server'),
        ];
        $result = $sequence->withParticipants($participants);
        $this->assertInstanceOf(Sequence::class, $result);
    }

    public function testWithMessage(): void
    {
        $sequence = Sequence::new();
        $message = new SequenceMessage('1', 'client', 'server', 'Request');
        $result = $sequence->withMessage($message);
        $this->assertInstanceOf(Sequence::class, $result);
    }

    public function testAddMessage(): void
    {
        $sequence = Sequence::new();
        $result = $sequence->addMessage('1', 'client', 'server', 'Request');
        $this->assertInstanceOf(Sequence::class, $result);
    }

    public function testAddReply(): void
    {
        $sequence = Sequence::new();
        $result = $sequence->addReply('2', 'server', 'client', 'Response');
        $this->assertInstanceOf(Sequence::class, $result);
    }

    public function testWithMessages(): void
    {
        $sequence = Sequence::new();
        $messages = [
            new SequenceMessage('1', 'client', 'server', 'Request'),
            SequenceMessage::reply('2', 'server', 'client', 'Response'),
        ];
        $result = $sequence->withMessages($messages);
        $this->assertInstanceOf(Sequence::class, $result);
    }

    public function testWithShowLabels(): void
    {
        $sequence = Sequence::new();
        $result = $sequence->withShowLabels(false);
        $this->assertInstanceOf(Sequence::class, $result);
    }

    public function testWithShowActivations(): void
    {
        $sequence = Sequence::new();
        $result = $sequence->withShowActivations(false);
        $this->assertInstanceOf(Sequence::class, $result);
    }

    public function testWithStyle(): void
    {
        $sequence = Sequence::new();
        $result = $sequence->withStyle('bold');
        $this->assertInstanceOf(Sequence::class, $result);
    }

    public function testGetInnerSize(): void
    {
        $sequence = Sequence::new()->setSize(70, 20);
        $size = $sequence->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(70, $size[0]);
        $this->assertEquals(20, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $sequence = Sequence::new()->setSize(10, 5);
        $rendered = $sequence->render();
        $this->assertSame('', $rendered);
    }

    public function testWithLifelineColor(): void
    {
        $sequence = Sequence::new();
        $result = $sequence->withLifelineColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertInstanceOf(Sequence::class, $result);
    }

    public function testWithMessageColor(): void
    {
        $sequence = Sequence::new();
        $result = $sequence->withMessageColor(\SugarCraft\Core\Util\Color::hex('#00FF00'));
        $this->assertInstanceOf(Sequence::class, $result);
    }

    public function testWithTextColor(): void
    {
        $sequence = Sequence::new();
        $result = $sequence->withTextColor(\SugarCraft\Core\Util\Color::hex('#0000FF'));
        $this->assertInstanceOf(Sequence::class, $result);
    }

    public function testSequenceParticipantHelpers(): void
    {
        $actor = SequenceParticipant::actor('1', 'Actor');
        $this->assertEquals('Actor', $actor->label);
        $this->assertEquals('1', $actor->id);

        $object = SequenceParticipant::object('2', 'Object');
        $this->assertEquals('Object', $object->label);
        $this->assertEquals('2', $object->id);
    }

    public function testSequenceMessageReply(): void
    {
        $reply = SequenceMessage::reply('1', 'server', 'client', 'OK');
        $this->assertTrue($reply->isReply);
        $this->assertEquals('server', $reply->from);
        $this->assertEquals('client', $reply->to);
    }
}

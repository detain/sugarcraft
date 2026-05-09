<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Msg;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Msg\BlurMsg;
use SugarCraft\Core\Msg\CursorPositionMsg;
use SugarCraft\Core\Msg\FocusMsg;
use SugarCraft\Core\Msg\ForegroundColorMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\PasteEndMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\PasteStartMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Vcr\Msg\BuiltinSerializer;

final class BuiltinSerializerTest extends TestCase
{
    public function testKeyMsgRoundTrip(): void
    {
        $msg = new KeyMsg(KeyType::Char, 'j', alt: true, ctrl: false, shift: true);
        $envelope = (new BuiltinSerializer())->encode($msg);

        $this->assertSame('KeyMsg', $envelope['@type']);
        $this->assertSame('char', $envelope['type']);
        $this->assertSame('j', $envelope['rune']);
        $this->assertTrue($envelope['alt']);
        $this->assertFalse($envelope['ctrl']);
        $this->assertTrue($envelope['shift']);

        $decoded = (new BuiltinSerializer())->decode($envelope);
        $this->assertInstanceOf(KeyMsg::class, $decoded);
        $this->assertSame(KeyType::Char, $decoded->type);
        $this->assertSame('j', $decoded->rune);
        $this->assertTrue($decoded->alt);
        $this->assertFalse($decoded->ctrl);
        $this->assertTrue($decoded->shift);
    }

    public function testKeyMsgNamedKeyRoundTrip(): void
    {
        $msg = new KeyMsg(KeyType::Up);
        $envelope = (new BuiltinSerializer())->encode($msg);
        $this->assertSame('up', $envelope['type']);
        $decoded = (new BuiltinSerializer())->decode($envelope);
        $this->assertSame(KeyType::Up, $decoded->type);
    }

    /**
     * @dataProvider mouseMsgs
     */
    public function testMouseMsgRoundTrip(string $expectedTag, Msg $msg): void
    {
        $envelope = (new BuiltinSerializer())->encode($msg);
        $this->assertSame($expectedTag, $envelope['@type']);
        $decoded = (new BuiltinSerializer())->decode($envelope);
        $this->assertInstanceOf($msg::class, $decoded);
        /** @var \SugarCraft\Core\Msg\MouseMsg $decoded */
        /** @var \SugarCraft\Core\Msg\MouseMsg $msg */
        $this->assertSame($msg->x, $decoded->x);
        $this->assertSame($msg->y, $decoded->y);
        $this->assertSame($msg->button, $decoded->button);
        $this->assertSame($msg->action, $decoded->action);
        $this->assertSame($msg->shift, $decoded->shift);
        $this->assertSame($msg->alt, $decoded->alt);
        $this->assertSame($msg->ctrl, $decoded->ctrl);
    }

    /** @return array<string, array{0:string, 1:Msg}> */
    public static function mouseMsgs(): array
    {
        return [
            'click' => ['MouseClickMsg', new MouseClickMsg(10, 5, MouseButton::Left, MouseAction::Press, ctrl: true)],
            'motion' => ['MouseMotionMsg', new MouseMotionMsg(20, 8, MouseButton::None, MouseAction::Motion, alt: true)],
            'wheel' => ['MouseWheelMsg', new MouseWheelMsg(0, 0, MouseButton::WheelUp, MouseAction::Press)],
            'release' => ['MouseReleaseMsg', new MouseReleaseMsg(15, 3, MouseButton::Right, MouseAction::Release, shift: true)],
        ];
    }

    public function testWindowSizeMsgRoundTrip(): void
    {
        $msg = new WindowSizeMsg(120, 40);
        $envelope = (new BuiltinSerializer())->encode($msg);
        $this->assertSame('WindowSizeMsg', $envelope['@type']);
        $this->assertSame(120, $envelope['cols']);
        $this->assertSame(40, $envelope['rows']);

        $decoded = (new BuiltinSerializer())->decode($envelope);
        $this->assertInstanceOf(WindowSizeMsg::class, $decoded);
        $this->assertSame(120, $decoded->cols);
        $this->assertSame(40, $decoded->rows);
    }

    /**
     * @dataProvider stateMsgs
     */
    public function testStatelessMsgRoundTrip(string $tag, Msg $msg): void
    {
        $s = new BuiltinSerializer();
        $envelope = $s->encode($msg);
        $this->assertSame($tag, $envelope['@type']);
        $decoded = $s->decode($envelope);
        $this->assertInstanceOf($msg::class, $decoded);
    }

    /** @return array<string, array{0:string, 1:Msg}> */
    public static function stateMsgs(): array
    {
        return [
            'focus' => ['FocusMsg', new FocusMsg()],
            'blur' => ['BlurMsg', new BlurMsg()],
            'paste-start' => ['PasteStartMsg', new PasteStartMsg()],
            'paste-end' => ['PasteEndMsg', new PasteEndMsg()],
        ];
    }

    public function testPasteMsgRoundTrip(): void
    {
        $msg = new PasteMsg("hello\nworld");
        $envelope = (new BuiltinSerializer())->encode($msg);
        $this->assertSame('PasteMsg', $envelope['@type']);
        $this->assertSame("hello\nworld", $envelope['content']);

        $decoded = (new BuiltinSerializer())->decode($envelope);
        $this->assertInstanceOf(PasteMsg::class, $decoded);
        $this->assertSame("hello\nworld", $decoded->content);
    }

    public function testForegroundAndBackgroundColorMsgRoundTrip(): void
    {
        $s = new BuiltinSerializer();

        $fg = new ForegroundColorMsg(255, 128, 0);
        $envelope = $s->encode($fg);
        $this->assertSame('ForegroundColorMsg', $envelope['@type']);
        $decoded = $s->decode($envelope);
        $this->assertInstanceOf(ForegroundColorMsg::class, $decoded);
        $this->assertSame([255, 128, 0], [$decoded->r, $decoded->g, $decoded->b]);

        $bg = new BackgroundColorMsg(10, 20, 30);
        $envelope = $s->encode($bg);
        $this->assertSame('BackgroundColorMsg', $envelope['@type']);
        $decoded = $s->decode($envelope);
        $this->assertInstanceOf(BackgroundColorMsg::class, $decoded);
        $this->assertSame([10, 20, 30], [$decoded->r, $decoded->g, $decoded->b]);
    }

    public function testCursorPositionMsgRoundTrip(): void
    {
        $msg = new CursorPositionMsg(7, 12);
        $envelope = (new BuiltinSerializer())->encode($msg);
        $this->assertSame('CursorPositionMsg', $envelope['@type']);
        $decoded = (new BuiltinSerializer())->decode($envelope);
        $this->assertInstanceOf(CursorPositionMsg::class, $decoded);
        $this->assertSame(7, $decoded->row);
        $this->assertSame(12, $decoded->col);
    }

    public function testCanEncodeKnownTypes(): void
    {
        $s = new BuiltinSerializer();
        $this->assertTrue($s->canEncode(new KeyMsg(KeyType::Tab)));
        $this->assertTrue($s->canEncode(new WindowSizeMsg(80, 24)));
        $this->assertTrue($s->canEncode(new FocusMsg()));
    }

    public function testCannotEncodeUnknownTypes(): void
    {
        $s = new BuiltinSerializer();
        $this->assertFalse($s->canEncode(new QuitMsg()));
    }

    public function testCanDecodeKnownTags(): void
    {
        $s = new BuiltinSerializer();
        $this->assertTrue($s->canDecode(['@type' => 'KeyMsg']));
        $this->assertTrue($s->canDecode(['@type' => 'WindowSizeMsg']));
        $this->assertTrue($s->canDecode(['@type' => 'FocusMsg']));
    }

    public function testCannotDecodeUnknownTags(): void
    {
        $s = new BuiltinSerializer();
        $this->assertFalse($s->canDecode(['@type' => 'NoSuchMsg']));
        $this->assertFalse($s->canDecode([]));
        $this->assertFalse($s->canDecode(['@type' => 123]));
    }

    public function testEncodeUnknownThrows(): void
    {
        $this->expectException(\LogicException::class);
        (new BuiltinSerializer())->encode(new QuitMsg());
    }

    public function testDecodeUnknownThrows(): void
    {
        $this->expectException(\LogicException::class);
        (new BuiltinSerializer())->decode(['@type' => 'NoSuchMsg']);
    }

    public function testTagsListContainsExpected(): void
    {
        $tags = BuiltinSerializer::tags();
        $this->assertContains('KeyMsg', $tags);
        $this->assertContains('MouseClickMsg', $tags);
        $this->assertContains('MouseMotionMsg', $tags);
        $this->assertContains('MouseWheelMsg', $tags);
        $this->assertContains('MouseReleaseMsg', $tags);
        $this->assertContains('WindowSizeMsg', $tags);
        $this->assertContains('FocusMsg', $tags);
        $this->assertContains('BlurMsg', $tags);
        $this->assertContains('PasteStartMsg', $tags);
        $this->assertContains('PasteEndMsg', $tags);
        $this->assertContains('PasteMsg', $tags);
        $this->assertContains('BackgroundColorMsg', $tags);
        $this->assertContains('ForegroundColorMsg', $tags);
        $this->assertContains('CursorPositionMsg', $tags);
        $this->assertCount(14, $tags);
    }
}

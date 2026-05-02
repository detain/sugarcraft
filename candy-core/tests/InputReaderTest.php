<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests;

use CandyCore\Core\InputReader;
use CandyCore\Core\KeyType;
use CandyCore\Core\MouseAction;
use CandyCore\Core\MouseButton;
use CandyCore\Core\Msg\BlurMsg;
use CandyCore\Core\Msg\FocusMsg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Msg\MouseMsg;
use CandyCore\Core\Msg\PasteMsg;
use PHPUnit\Framework\TestCase;

final class InputReaderTest extends TestCase
{
    public function testPrintableAscii(): void
    {
        $r = new InputReader();
        $msgs = $r->parse('abc');
        $this->assertCount(3, $msgs);
        foreach (['a', 'b', 'c'] as $i => $rune) {
            $this->assertInstanceOf(KeyMsg::class, $msgs[$i]);
            $this->assertSame(KeyType::Char, $msgs[$i]->type);
            $this->assertSame($rune, $msgs[$i]->rune);
            $this->assertFalse($msgs[$i]->ctrl);
            $this->assertFalse($msgs[$i]->alt);
        }
    }

    public function testCtrlC(): void
    {
        $msgs = (new InputReader())->parse("\x03");
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Char, $msgs[0]->type);
        $this->assertSame('c', $msgs[0]->rune);
        $this->assertTrue($msgs[0]->ctrl);
        $this->assertSame('ctrl+c', $msgs[0]->string());
    }

    public function testTabEnterBackspaceSpace(): void
    {
        $msgs = (new InputReader())->parse("\t\r\x7f ");
        $this->assertSame(KeyType::Tab,       $msgs[0]->type);
        $this->assertSame(KeyType::Enter,     $msgs[1]->type);
        $this->assertSame(KeyType::Backspace, $msgs[2]->type);
        $this->assertSame(KeyType::Space,     $msgs[3]->type);
    }

    public function testArrowKeys(): void
    {
        $msgs = (new InputReader())->parse("\x1b[A\x1b[B\x1b[C\x1b[D");
        $this->assertCount(4, $msgs);
        $this->assertSame(KeyType::Up,    $msgs[0]->type);
        $this->assertSame(KeyType::Down,  $msgs[1]->type);
        $this->assertSame(KeyType::Right, $msgs[2]->type);
        $this->assertSame(KeyType::Left,  $msgs[3]->type);
    }

    public function testHomeEndDeletePageKeys(): void
    {
        $msgs = (new InputReader())->parse("\x1b[H\x1b[F\x1b[3~\x1b[5~\x1b[6~");
        $this->assertSame(KeyType::Home,     $msgs[0]->type);
        $this->assertSame(KeyType::End,      $msgs[1]->type);
        $this->assertSame(KeyType::Delete,   $msgs[2]->type);
        $this->assertSame(KeyType::PageUp,   $msgs[3]->type);
        $this->assertSame(KeyType::PageDown, $msgs[4]->type);
    }

    public function testAltPrefixedKey(): void
    {
        $msgs = (new InputReader())->parse("\x1ba");
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Char, $msgs[0]->type);
        $this->assertSame('a',           $msgs[0]->rune);
        $this->assertTrue($msgs[0]->alt);
        $this->assertFalse($msgs[0]->ctrl);
        $this->assertSame('alt+a', $msgs[0]->string());
    }

    public function testBareEscapeIsBufferedThenFlushed(): void
    {
        $r = new InputReader();
        // Single ESC alone is ambiguous (could be the start of a sequence),
        // so it's buffered.
        $this->assertSame([], $r->parse("\x1b"));

        $flushed = $r->flushPending();
        $this->assertInstanceOf(KeyMsg::class, $flushed);
        $this->assertSame(KeyType::Escape, $flushed->type);
    }

    public function testHasPendingEscape(): void
    {
        $r = new InputReader();
        $this->assertFalse($r->hasPendingEscape());
        $r->parse("\x1b");
        $this->assertTrue($r->hasPendingEscape());
        $r->flushPending();
        $this->assertFalse($r->hasPendingEscape());
    }

    public function testSplitCsiAcrossReads(): void
    {
        $r = new InputReader();
        $this->assertSame([], $r->parse("\x1b"));
        $this->assertSame([], $r->parse('['));
        $msgs = $r->parse('A');
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Up, $msgs[0]->type);
    }

    public function testMixedStream(): void
    {
        $r = new InputReader();
        $msgs = $r->parse("hi\x1b[Aq");
        $this->assertCount(4, $msgs);
        $this->assertSame('h', $msgs[0]->rune);
        $this->assertSame('i', $msgs[1]->rune);
        $this->assertSame(KeyType::Up, $msgs[2]->type);
        $this->assertSame('q', $msgs[3]->rune);
    }

    public function testKeyMsgString(): void
    {
        $this->assertSame('a',        (new KeyMsg(KeyType::Char, 'a'))->string());
        $this->assertSame('ctrl+a',   (new KeyMsg(KeyType::Char, 'a', ctrl: true))->string());
        $this->assertSame('alt+a',    (new KeyMsg(KeyType::Char, 'a', alt: true))->string());
        $this->assertSame('up',       (new KeyMsg(KeyType::Up))->string());
        $this->assertSame('ctrl+alt+a', (new KeyMsg(KeyType::Char, 'a', alt: true, ctrl: true))->string());
    }

    // ---- focus ------------------------------------------------------------

    public function testFocusIn(): void
    {
        $msgs = (new InputReader())->parse("\x1b[I");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(FocusMsg::class, $msgs[0]);
    }

    public function testFocusOut(): void
    {
        $msgs = (new InputReader())->parse("\x1b[O");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(BlurMsg::class, $msgs[0]);
    }

    // ---- mouse (SGR encoded) ---------------------------------------------

    public function testMouseLeftPress(): void
    {
        $msgs = (new InputReader())->parse("\x1b[<0;5;10M");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(MouseMsg::class, $msgs[0]);
        $this->assertSame(5,  $msgs[0]->x);
        $this->assertSame(10, $msgs[0]->y);
        $this->assertSame(MouseButton::Left,    $msgs[0]->button);
        $this->assertSame(MouseAction::Press,   $msgs[0]->action);
        $this->assertFalse($msgs[0]->shift);
        $this->assertFalse($msgs[0]->alt);
        $this->assertFalse($msgs[0]->ctrl);
    }

    public function testMouseLeftRelease(): void
    {
        $msgs = (new InputReader())->parse("\x1b[<0;5;10m");
        $this->assertSame(MouseAction::Release, $msgs[0]->action);
        $this->assertSame(MouseButton::Left,    $msgs[0]->button);
    }

    public function testMouseRightPress(): void
    {
        $msgs = (new InputReader())->parse("\x1b[<2;1;1M");
        $this->assertSame(MouseButton::Right, $msgs[0]->button);
    }

    public function testMouseModifiers(): void
    {
        // Button 0 (left) + shift(4) + alt(8) + ctrl(16) = 28
        $msgs = (new InputReader())->parse("\x1b[<28;3;4M");
        $this->assertTrue($msgs[0]->shift);
        $this->assertTrue($msgs[0]->alt);
        $this->assertTrue($msgs[0]->ctrl);
        $this->assertSame(MouseButton::Left, $msgs[0]->button);
    }

    public function testMouseMotionWithButton(): void
    {
        // Left + motion(32) = 32
        $msgs = (new InputReader())->parse("\x1b[<32;7;8M");
        $this->assertSame(MouseAction::Motion, $msgs[0]->action);
        $this->assertSame(MouseButton::Left,   $msgs[0]->button);
    }

    public function testMouseWheelUp(): void
    {
        // 64 = wheel up
        $msgs = (new InputReader())->parse("\x1b[<64;1;1M");
        $this->assertSame(MouseButton::WheelUp, $msgs[0]->button);
        $this->assertSame(MouseAction::Press,   $msgs[0]->action);
    }

    public function testMouseWheelDown(): void
    {
        $msgs = (new InputReader())->parse("\x1b[<65;1;1M");
        $this->assertSame(MouseButton::WheelDown, $msgs[0]->button);
    }

    public function testMouseExtraBackward(): void
    {
        // 128 = extra btn 0 (backward)
        $msgs = (new InputReader())->parse("\x1b[<128;1;1M");
        $this->assertSame(MouseButton::Backward, $msgs[0]->button);
        $this->assertSame(MouseAction::Press,    $msgs[0]->action);
    }

    public function testMouseSplitAcrossReads(): void
    {
        $r = new InputReader();
        $this->assertSame([], $r->parse("\x1b[<0;5"));
        $msgs = $r->parse(";10M");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(MouseMsg::class, $msgs[0]);
        $this->assertSame(5,  $msgs[0]->x);
        $this->assertSame(10, $msgs[0]->y);
    }

    // ---- function keys ----------------------------------------------------

    public function testFunctionKeysViaSs3(): void
    {
        $msgs = (new InputReader())->parse("\x1bOP\x1bOQ\x1bOR\x1bOS");
        $this->assertCount(4, $msgs);
        $this->assertSame(KeyType::F1, $msgs[0]->type);
        $this->assertSame(KeyType::F2, $msgs[1]->type);
        $this->assertSame(KeyType::F3, $msgs[2]->type);
        $this->assertSame(KeyType::F4, $msgs[3]->type);
    }

    public function testFunctionKeysViaCsiTilde(): void
    {
        $msgs = (new InputReader())->parse(
            "\x1b[15~\x1b[17~\x1b[18~\x1b[19~\x1b[20~\x1b[21~\x1b[23~\x1b[24~",
        );
        $expected = [
            KeyType::F5, KeyType::F6, KeyType::F7, KeyType::F8,
            KeyType::F9, KeyType::F10, KeyType::F11, KeyType::F12,
        ];
        $this->assertCount(count($expected), $msgs);
        foreach ($expected as $i => $type) {
            $this->assertSame($type, $msgs[$i]->type, "F-key #$i");
        }
    }

    public function testF1ThroughF4ViaCsiTildeAlsoWork(): void
    {
        // Some terminals send "ESC[11~" instead of "ESC OP".
        $msgs = (new InputReader())->parse("\x1b[11~\x1b[12~\x1b[13~\x1b[14~");
        $this->assertSame(KeyType::F1, $msgs[0]->type);
        $this->assertSame(KeyType::F4, $msgs[3]->type);
    }

    public function testSs3SplitAcrossReads(): void
    {
        $r = new InputReader();
        $this->assertSame([], $r->parse("\x1bO"));
        $msgs = $r->parse('P');
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::F1, $msgs[0]->type);
    }

    // ---- bracketed paste -------------------------------------------------

    public function testBracketedPasteSingleFrame(): void
    {
        $msgs = (new InputReader())->parse("\x1b[200~hello world\x1b[201~");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(PasteMsg::class, $msgs[0]);
        $this->assertSame('hello world', $msgs[0]->content);
    }

    public function testBracketedPastePreservesNewlinesAndControl(): void
    {
        // Pasted content with embedded newline + CSI sequence should NOT
        // be parsed as keys — the whole envelope is one PasteMsg.
        $payload = "line1\nline2\x1b[31mred\x1b[0m";
        $msgs    = (new InputReader())->parse("\x1b[200~" . $payload . "\x1b[201~");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(PasteMsg::class, $msgs[0]);
        $this->assertSame($payload, $msgs[0]->content);
    }

    public function testBracketedPasteSplitAcrossReads(): void
    {
        $r = new InputReader();
        $this->assertSame([], $r->parse("\x1b[200~hel"));
        $this->assertSame([], $r->parse('lo'));
        $msgs = $r->parse(" world\x1b[201~");
        $this->assertCount(1, $msgs);
        $this->assertSame('hello world', $msgs[0]->content);
    }

    public function testBracketedPasteFollowedByKey(): void
    {
        $msgs = (new InputReader())->parse("\x1b[200~paste\x1b[201~q");
        $this->assertCount(2, $msgs);
        $this->assertInstanceOf(PasteMsg::class, $msgs[0]);
        $this->assertSame('paste', $msgs[0]->content);
        $this->assertSame('q', $msgs[1]->rune);
    }

    public function testKeyMsgStringForFunctionKeys(): void
    {
        $this->assertSame('f1',  (new KeyMsg(KeyType::F1))->string());
        $this->assertSame('f12', (new KeyMsg(KeyType::F12))->string());
    }
}

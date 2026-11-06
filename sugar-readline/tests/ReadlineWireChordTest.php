<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextareaPrompt;

/**
 * Wire-level chord reality for the textarea break keys.
 *
 * The suite already pins the symbolic names ('shift_enter', 'ctrl_j'), but
 * symbolic pins cannot say whether the WIRE can deliver them. On a legacy
 * terminal there is no modifier channel: Shift+Enter and Ctrl+J both arrive
 * as bare 0x0A/0x0D, sugar-input decodes those bytes to a plain Enter
 * (Key::normalize maps 10/13 to Enter), and Readline::run negotiates no
 * modified-key protocol of its own — so on that wire the "break" chords
 * SUBMIT. Only a terminal reporting CSI-u frames carries them as chords.
 *
 * Same socket-pair pump as ReadlineChordDispatchTest: real raw bytes, real
 * decoder, real loop. Sequences end on a terminal key so the loop exits on
 * the final event.
 */
final class ReadlineWireChordTest extends TestCase
{
    /**
     * Feed raw bytes through the production pump into a textarea.
     *
     * A socket pair, not php://memory: the driver selects on the stream, and
     * stream_select() cannot poll memory streams.
     */
    private function driveTextarea(string $bytes, ?TextareaPrompt $prompt = null): TextareaPrompt
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        fwrite($pair[1], $bytes);
        fclose($pair[1]);

        $readline = new Readline(new StreamInputDriver($pair[0]));
        $final = $readline->run($prompt ?? TextareaPrompt::new('> '));
        fclose($pair[0]);

        $this->assertInstanceOf(TextareaPrompt::class, $final);
        /** @var TextareaPrompt $final */
        return $final;
    }

    // =========================================================================
    // Legacy wire — no modifier channel: both bytes are plain Enter
    // =========================================================================

    public function testLegacyNewlineByteSubmitsTheTextarea(): void
    {
        // 0x0A is what legacy terminals send for Enter — and for Ctrl+J.
        // The break chord cannot survive this wire: it submits.
        $final = $this->driveTextarea("ab\x0a");
        $this->assertTrue($final->isSubmitted());
        $this->assertSame('ab', $final->value());
    }

    public function testLegacyCarriageReturnByteSubmitsTheTextarea(): void
    {
        // 0x0D (Ctrl+M, the byte most terminals put on the Enter key)
        // normalizes to Enter the same way.
        $final = $this->driveTextarea("ab\x0d");
        $this->assertTrue($final->isSubmitted());
        $this->assertSame('ab', $final->value());
    }

    // =========================================================================
    // Reporting terminal — the chords exist only as CSI-u frames
    // =========================================================================

    public function testCsiuShiftEnterFrameBreaksALineThroughThePump(): void
    {
        // CSI-u encoding of Shift+Enter: codepoint 13, modifier 2. The
        // decoder reports Enter+SHIFT, the pump names it 'shift_enter',
        // and the textarea breaks — then a final Enter submits.
        $final = $this->driveTextarea("ab\x1b[13;2u\x0a");
        $this->assertTrue($final->isSubmitted());
        $this->assertSame("ab\n", $final->value());
    }

    public function testCsiuCtrlJFrameBreaksALineThroughThePump(): void
    {
        // kitty disambiguation reports Ctrl+J as codepoint 106 ('j') with
        // modifier 5 → symbolic 'ctrl_j' → break, NOT the legacy submit.
        $final = $this->driveTextarea("ab\x1b[106;5u\x0a");
        $this->assertTrue($final->isSubmitted());
        $this->assertSame("ab\n", $final->value());
    }
}

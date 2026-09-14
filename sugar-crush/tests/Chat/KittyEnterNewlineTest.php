<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;

/**
 * E705 behavior side: once the terminal honors the pushed DISAMBIGUATE flag,
 * the modified-Enter chords decode to Enter KeyMsgs carrying their modifier
 * and the existing Alt/Shift/Ctrl+Enter arm turns them into a newline AT THE
 * CARET — the same behavior Alt+Enter already had, no new machinery.
 *
 * Every case is driven through a real {@see InputReader} parse of the exact
 * bytes a Kitty-capable terminal emits (the house pattern from
 * {@see \SugarCraft\Crush\Tests\ChatInputCursorTest}), so a decoder-side
 * rename or flag-math slip reddens here rather than hiding behind
 * hand-synthesized KeyMsgs. The legacy polarity (plain CR submits, ESC+CR
 * newlines) is pinned here too as the same-stream pair.
 *
 * @see \SugarCraft\Crush\Tests\App\KittyKeyboardNegotiationTest the push/pop half
 */
final class KittyEnterNewlineTest extends TestCase
{
    private function chat(string $draft = ''): Chat
    {
        return new Chat(history: [], inputBuf: $draft, backend: new EchoBackend());
    }

    /** @return list<Msg> */
    private function parse(string $bytes): array
    {
        return (new InputReader())->parse($bytes);
    }

    private function drive(Chat $chat, Msg ...$msgs): Chat
    {
        foreach ($msgs as $msg) {
            [$chat] = $chat->update($msg);
        }

        return $chat;
    }

    /**
     * The wire fixtures the whole file leans on: exactly the bytes a
     * DISAMBIGUATE-enabled terminal sends for each chord.
     */
    public function testTheModifiedEnterChordsDecodeToEnterCarryingTheirFlag(): void
    {
        foreach (['ctrl' => "\x1b[13;5u", 'shift' => "\x1b[13;2u", 'alt' => "\x1b[13;3u"] as $flag => $bytes) {
            $decoded = $this->parse($bytes);
            $this->assertCount(1, $decoded, "fixture ({$flag}+Enter): one message per chord");
            $this->assertInstanceOf(KeyMsg::class, $decoded[0]);
            $this->assertSame(KeyType::Enter, $decoded[0]->type, "fixture ({$flag}+Enter)");
            $this->assertTrue($decoded[0]->{$flag}, "fixture ({$flag}+Enter): decoder sets {$flag}");
            $this->assertFalse($decoded[0]->{$flag === 'ctrl' ? 'shift' : 'ctrl'}, 'only its own flag');
        }

        // Unmodified Enter under the protocol is still Enter with NO flags —
        // pushing Kitty must not turn the submit key into a newline key.
        $plain = $this->parse("\x1b[13u");
        $this->assertSame(KeyType::Enter, $plain[0]->type);
        $this->assertFalse($plain[0]->ctrl);
        $this->assertFalse($plain[0]->shift);
        $this->assertFalse($plain[0]->alt);
    }

    public function testEachNegotiatedChordInsertsANewlineAtTheCaretWithoutSubmitting(): void
    {
        foreach (["\x1b[13;5u", "\x1b[13;2u", "\x1b[13;3u"] as $bytes) {
            $chord = $this->parse($bytes)[0];
            $this->assertInstanceOf(KeyMsg::class, $chord);

            $next = $this->chat('hello')->update($chord);
            $this->assertSame("hello\n", $next[0]->inputBuf, "{$bytes}: newline appended at end-of-draft caret");
            $this->assertNull($next[1], "{$bytes}: the chord must never submit");
        }

        // Mid-draft — caret motion is the whole point of the arm.
        $mid = $this->drive($this->chat('abcd'), new KeyMsg(KeyType::Left), new KeyMsg(KeyType::Left));
        $this->assertSame(2, $mid->inputCursorOffset(), 'fixture: caret between b and c');
        $after = $this->drive($mid, $this->parse("\x1b[13;5u")[0]);
        $this->assertSame("ab\ncd", $after->inputBuf, 'Ctrl+Enter splits the line where the caret sits');
        $this->assertSame(3, $after->inputCursorOffset());
    }

    public function testPlainEnterStillSubmitsOnBothSpellings(): void
    {
        foreach (["\r", "\n", "\x1b[13u"] as $bytes) {
            $decoded = $this->parse($bytes);
            $this->assertCount(1, $decoded, "fixture ({$bytes})");
            $this->assertInstanceOf(KeyMsg::class, $decoded[0]);
            $this->assertSame(KeyType::Enter, $decoded[0]->type, "fixture ({$bytes})");

            $next = $this->drive($this->chat('hello'), $decoded[0]);
            $this->assertSame('', $next->inputBuf, "{$bytes}: submit consumes the draft, no newline inserted");
        }
    }

    public function testLegacyByteStreamsBehaveExactlyAsBeforeTheNegotiation(): void
    {
        // The ESC+CR spelling of Alt+Enter — the chord that already worked on
        // every plain terminal — must keep working with identical results:
        // the push changes what CAPABLE terminals send, never what we do
        // with what arrived before.
        $decoded = $this->parse("\x1b\r");
        $this->assertCount(1, $decoded, 'fixture: ESC+CR is one Alt+Enter message');
        $this->assertInstanceOf(KeyMsg::class, $decoded[0]);
        $this->assertSame(KeyType::Enter, $decoded[0]->type);
        $this->assertTrue($decoded[0]->alt);

        $next = $this->drive($this->chat('hello'), $decoded[0]);
        $this->assertSame("hello\n", $next->inputBuf, 'legacy Alt+Enter path untouched by E705');
    }

    public function testKittyPasteAndModifiedEnterCooperate(): void
    {
        // A pasted multi-line block followed by Ctrl+Enter: the envelope
        // becomes ONE PasteMsg (E704) whose payload is inserted VERBATIM (the
        // paste trailing-Enter law is about the submit trim, not pre-trimming
        // content), and the negotiated chord then inserts a newline at the
        // caret rather than submitting the draft.
        foreach (["\x1b[200~one\ntwo\x1b[201~" => "one\ntwo\n", "\x1b[200~one\n\x1b[201~" => "one\n\n"] as $envelope => $expected) {
            $decoded = $this->parse($envelope . "\x1b[13;5u");
            $this->assertCount(4, $decoded, "fixture ({$envelope}): start, end, payload, chord");
            $this->assertInstanceOf(PasteMsg::class, $decoded[2]);
            $this->assertInstanceOf(KeyMsg::class, $decoded[3]);

            $next = $this->drive($this->chat(), ...$decoded);
            $this->assertSame($expected, $next->inputBuf, 'trailing-Enter trim law survives; chord adds the newline');
        }
    }
}

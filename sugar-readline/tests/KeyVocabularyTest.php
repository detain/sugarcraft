<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\KeyModifier;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

/**
 * Key vocabulary rigor (findings 074, 095, 096, 100): every chord name is
 * defined once (the complete CtrlA..CtrlZ constant set), the byte table in
 * Key::normalize() is pinned DIRECTLY (the symbolic layer never sees byte
 * edits), the Shift+letter canonical spelling maps legacy and kitty wire
 * forms to one name, and the CRLF framing boundary is honestly recorded:
 * sugar-input's coalescence is out of this library's control, so the pin
 * documents what the real driver actually does.
 */
final class KeyVocabularyTest extends TestCase
{
    public function testControlLetterVocabularyIsCompleteAndMatchesTheByteTable(): void
    {
        $letters = range('a', 'z');
        // GNU aliases from the byte table (documented in Key::normalize):
        // C-h erase, C-i tab, C-j/C-m newline — these names intentionally
        // replace the ctrl-letter spelling.
        $aliased = ['h' => Key::Backspace, 'i' => Key::Tab, 'j' => Key::Enter, 'm' => Key::Enter];
        foreach ($letters as $letter) {
            $const = 'Ctrl' . strtoupper($letter);
            self::assertTrue(
                \defined(Key::class . "::$const"),
                "Key::$const must exist (finding 074: one name per Ctrl byte)"
            );
            self::assertSame('ctrl_' . $letter, \constant(Key::class . "::$const"));
            // The byte table and the constants must agree on the byte's name:
            // alias bytes resolve to their GNU name, every other ctrl byte to
            // its constant.
            $emitted = Key::normalize(\chr(ord($letter) - 96));
            self::assertSame($aliased[$letter] ?? \constant(Key::class . "::$const"), $emitted);
        }
    }

    /**
     * Direct byte-table passthrough arms — the symbolic layer never catches
     * an edit here, so each passthrough is pinned as a dataset (finding 096).
     */
    public function testByteTablePassthroughArms(): void
    {
        self::assertSame("\0", Key::normalize("\0"), 'ord 0 has no ctrl name; passthrough');
        foreach (["\x1c", "\x1d", "\x1e", "\x1f"] as $byte) {
            self::assertSame($byte, Key::normalize($byte), 'the 0x1C-0x1F band stays raw');
        }
        self::assertSame("\x80", Key::normalize("\x80"), 'a >127 raw lead byte stays raw');
        self::assertSame('É', Key::normalize('É'), 'multibyte characters are never cut by a byte guard');
        self::assertSame(Key::Escape, Key::normalize('escape'), 'legacy name alias');
        self::assertSame(Key::Escape, Key::normalize("\x1b"));
        self::assertSame(Key::Backspace, Key::normalize("\x08"), 'Ctrl+H is GNU erase');
        self::assertSame(Key::Backspace, Key::normalize("\x7f"));
        self::assertSame(Key::Tab, Key::normalize("\x09"));
        self::assertSame(Key::Enter, Key::normalize("\x0a"));
        self::assertSame(Key::Enter, Key::normalize("\x0d"));
        self::assertSame(Key::Space, Key::normalize(' '));
        self::assertSame('z', Key::normalize('z'), 'symbolic/printable input is idempotent');
    }

    public function testCanonicalShiftSpellingUnifiesLegacyAndKittyForms(): void
    {
        // Legacy uppercase byte and kitty shift+letter report the SAME name.
        self::assertSame('shift_a', $this->symbolic(new KeyEvent('A', KeyModifier::shift(), 'A')));
        self::assertSame('shift_a', $this->symbolic(new KeyEvent('a', KeyModifier::shift(), 'A')));
        // Multibyte letters no longer fall through a byte-length guard
        // into a different road than they arrived from (finding 095).
        self::assertSame('shift_é', $this->symbolic(new KeyEvent('É', KeyModifier::shift(), 'É')));
        self::assertSame('alt_é', $this->symbolic(new KeyEvent('é', KeyModifier::alt(), 'é')));
        self::assertSame('alt_a', $this->symbolic(new KeyEvent('a', KeyModifier::alt(), 'a')));
        self::assertSame('alt_up', $this->symbolic(new KeyEvent('ArrowUp', KeyModifier::alt(), "\x1b\x1b[A")));
        self::assertSame('shift_up', $this->symbolic(new KeyEvent('ArrowUp', KeyModifier::shift(), "\x1b[1;2A")));
        // Shifted symbols arrive already folded by the decoder: the law
        // binds letters, and non-letter printables pass through untouched.
        self::assertSame('!', $this->symbolic(new KeyEvent('!', KeyModifier::none(), '!')));
    }

    public function testDoubleSpelledDisjunctsAreDeleted(): void
    {
        $source = \file_get_contents(__DIR__ . '/../src/Mode/EmacsMode.php');
        self::assertIsString($source);
        // CALIBER 2026-10-23: `$key === 'ctrl_x' || $key === Key::CtrlX` with
        // identical values is dead by construction — it must stay deleted.
        self::assertSame(0, \preg_match('/=== \x27ctrl_[a-z]\x27 \|\| \$key === Key::/', $source),
            'no arm may compare the same name twice through two spellings');
        self::assertStringContainsString('if ($key === Key::CtrlR) {', $source);
        // The lib speaks its own vocabulary: no 'ctrl_*' string literal
        // survives in code outside Key.php's byte table and docblocks.
        foreach (['src/Mode/EmacsMode.php', 'src/Mode/ViMode.php', 'src/TextPrompt.php', 'src/TextareaPrompt.php', 'src/Selection/SelectionEngine.php'] as $file) {
            $code = \file_get_contents(__DIR__ . '/../' . $file);
            self::assertIsString($code);
            // strip docblocks and comments before scanning executable code
            $stripped = \php_strip_whitespace(__DIR__ . '/../' . $file);
            self::assertIsString($stripped);
            self::assertSame(
                0,
                \preg_match_all("/'ctrl_[a-z]'/", $stripped),
                "$file must reference ctrl names only through Key constants"
            );
            unset($code);
        }
    }

    public function testRealDriverCrlfFramingIsDocumentedBoundary(): void
    {
        // The coalescence of \r\n into one Enter is sugar-input's decoder
        // business (out of S-50's target scope). This pin records the HONEST
        // disk truth: the shipped StreamInputDriver reports "\r\n" as TWO
        // Enters, so a strict-one-submit law would falsely promise what the
        // upstream layer does not provide. Readline::run()'s contract — one
        // submit per call — holds either way.
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$iw, $ir] = $pair;

        \fwrite($iw, "text\r\n");
        $readline = new Readline(new StreamInputDriver($ir));

        $first = $readline->run(TextPrompt::new('> '), null);
        self::assertTrue($first->submitted);
        self::assertSame('text', $first->value, 'the first run consumes through the Enter');

        \fwrite($iw, "x\r");
        $second = $readline->run(TextPrompt::new('> '), null);
        self::assertTrue($second->submitted);
        self::assertSame(
            '',
            $second->value,
            'documented boundary: the stray \n of a CRLF is its own Enter upstream-side; '
            . 'hosts that must ignore empty line-ends check submitted && value === "" and re-run'
        );

        $third = $readline->run(TextPrompt::new('> '), null);
        self::assertSame('x', $third->value, 'and the following line still lands correctly');

        \fclose($iw);
        \fclose($ir);
    }

    private function symbolic(KeyEvent $event): string
    {
        $readline = new Readline();
        $method = new \ReflectionMethod(Readline::class, 'symbolicKey');

        return $method->invoke($readline, $event);
    }
}

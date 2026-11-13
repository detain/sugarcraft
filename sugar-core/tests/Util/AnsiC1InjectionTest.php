<?php
declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;

/**
 * sugar-core-060 (sc-41) adversarial pins — kept as a sibling of
 * AnsiInjectionTest so the C1-era expectations never mix with the
 * historical C0-only bodies.
 */
final class AnsiC1InjectionTest extends TestCase
{
    public function testC1FamilyStrippedFromOscBodies(): void
    {
        // Raw 8-bit OSC introducer forging a clipboard directive, plus a
        // BEL: the C1 byte and the terminator are gone, the euro span
        // whose continuation sits in the C1 range survives whole.
        $body = substr(Ansi::setWindowTitle("caf\xe2\x82\xac\x9d52;c;pwn\x07x"), 4, -1);
        $this->assertSame("caf\xe2\x82\xac52;c;pwnx", $body);

        // UTF-8-encoded C1 (\xc2\x9d) stripped as a pair; DEL stripped.
        $body = substr(Ansi::setWindowTitle("ok\xc2\x9d\xe2\x82\xac\x7f"), 4, -1);
        $this->assertSame("ok\xe2\x82\xac", $body);

        // A DEL between lead and continuation must not splice a pair.
        $body = substr(Ansi::setWindowTitle("a\xc2\x7f\x9db"), 4, -1);
        $this->assertSame('ab', $body);

        // Multibyte continuations in the C1 range survive whole.
        $this->assertStringContainsString("\xe2\x80\x9d", Ansi::setWindowTitle("\xc3\xa9\xe2\x80\x9d"), 'right double quote U+201D kept');
        $this->assertStringContainsString("\xf0\x9f\x98\x80", Ansi::setWindowTitle("\xf0\x9f\x98\x80"), 'emoji kept');
        $this->assertStringContainsString("\xe2\x82\xac", Ansi::hyperlinkOpen("https://x/\xe2\x82\xac"), 'euro in URI kept');

        // Hyperlink id and cwd host ride the same extended filter.
        $this->assertStringNotContainsString("\x9d", Ansi::hyperlinkOpen('https://ok', "id\x9d52;c;pwn"));
        $this->assertStringNotContainsString("\x8b", Ansi::setWorkingDirectory('/tmp', "h\x8bost"));

        // The whole raw C1 range is gone from a title.
        $raw = '';
        for ($b = 0x80; $b <= 0x9f; $b++) {
            $raw .= chr($b);
        }
        $this->assertSame('', substr(Ansi::setWindowTitle($raw), 4, -1));
    }

    public function testClipboardSelectionForgeryRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not an OSC 52 selector');
        Ansi::setClipboard('x', "c;?\x1b]52;c;pwn;");
    }

    public function testClipboardSelectionAcceptsCanonicalSelectors(): void
    {
        $this->assertSame(
            "\x1b]52;c;" . base64_encode('hi') . "\x07",
            Ansi::setClipboard('hi')
        );
        foreach (['p', 's', '0', '7', 'c,p', 'c,0,1'] as $sel) {
            $this->assertStringContainsString("\x1b]52;{$sel};", Ansi::setClipboard('hi', $sel));
        }
        $this->assertStringStartsWith("\x1b]52;p;?", Ansi::readClipboard('p'));
    }

    public function testClipboardReadRejectsRawSelection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ansi::readClipboard("c;?");
    }

    public function testClipboardPayloadBoundIsNamedAndEnforced(): void
    {
        $limit = Ansi::MAX_CLIPBOARD_BYTES;
        $this->assertSame(1048576, $limit);
        $this->assertStringContainsString(
            base64_encode(str_repeat('a', $limit)),
            Ansi::setClipboard(str_repeat('a', $limit))
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('over the 1048576-byte OSC 52 bound');
        Ansi::setClipboard(str_repeat('a', $limit + 1));
    }

    public function testClipboardBase64ArmStructurallyClosesInjection(): void
    {
        // Whatever the text, the wire body between "52;c;" and the BEL is
        // pure base64 alphabet — ESC, BEL, ';' and C1 cannot reach the
        // terminal even as payload bytes.
        $hostile = "\x1b]52;c;pwn\x07\x9d\x00;";
        $seq = Ansi::setClipboard($hostile);
        $body = substr($seq, strlen("\x1b]52;c;"), -1);
        $this->assertSame(1, preg_match('#^[A-Za-z0-9+/=]*$#', $body));
        $this->assertSame($hostile, base64_decode($body, true), 'payload round-trips exactly');
    }
}

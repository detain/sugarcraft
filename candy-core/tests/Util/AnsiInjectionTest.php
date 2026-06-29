<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;

/**
 * Remediation tests for ANSI escape injection prevention in OSC commands.
 *
 * Verifies that C0 control bytes (\x00-\x1f) and \x9c are stripped from
 * OSC command bodies to prevent terminal-escape injection attacks.
 */
final class AnsiInjectionTest extends TestCase
{
    public function testSetWindowTitleStripsControlBytes(): void
    {
        // Clean title produces the expected OSC sequence.
        $result = Ansi::setWindowTitle('My Application');
        $this->assertSame("\x1b]2;My Application\x07", $result);

        // Embedded ESC byte (C0 control) in title should be stripped.
        $withEsc = "My\x1b[31m App"; // injection attempt with ESC followed by CSI
        $result = Ansi::setWindowTitle($withEsc);
        // The title portion (between OSC prefix ";2;" and BEL terminator) should not contain raw ESC.
        // Extract the body: after "\x1b]2;" and before "\x07".
        $titleBody = substr($result, 4, -1); // strip prefix "\x1b]2;" and suffix "\x07"
        $this->assertStringNotContainsString("\x1b", $titleBody, 'Raw ESC should be stripped from title body');
        // The residual "[31m App" is not a C0 control so stays (this is a known limitation of per-byte stripping).
        $this->assertStringContainsString('App', $titleBody);

        // BEL (\x07) in title should be stripped.
        $withBel = "My\x07App";
        $result = Ansi::setWindowTitle($withBel);
        $titleBody = substr($result, 4, -1);
        $this->assertStringNotContainsString("\x07", $titleBody, 'BEL should be stripped from title body');

        // All C0 controls (\x00-\x1f) should be stripped.
        $withC0 = "Test\x00\x01\x02\x1fControl";
        $result = Ansi::setWindowTitle($withC0);
        $titleBody = substr($result, 4, -1);
        $this->assertSame('TestControl', $titleBody);
    }

    public function testHyperlinkStripsControlBytesFromUri(): void
    {
        // Clean URI produces the expected OSC sequence.
        $clean = 'https://example.com/path';
        $result = Ansi::hyperlinkOpen($clean);
        $this->assertStringContainsString($clean, $result);

        // Embedded ESC byte in URI should be stripped.
        $withEsc = "https://evil\x1b[31m.com/evil";
        $result = Ansi::hyperlinkOpen($withEsc);
        // After the "8;;" prefix, the URI portion should not contain raw ESC.
        $uriStart = strpos($result, ';') + 1; // skip "8;"
        $uriStart = strpos($result, ';', $uriStart) + 1; // skip empty id param
        $uriBody = substr($result, $uriStart, -2); // strip ST suffix
        $this->assertStringNotContainsString("\x1b", $uriBody, 'Raw ESC should be stripped from URI');

        // BEL in URI should be stripped.
        $withBel = "https://example\x07.com/";
        $result = Ansi::hyperlinkOpen($withBel);
        $uriStart = strpos($result, ';') + 1;
        $uriStart = strpos($result, ';', $uriStart) + 1;
        $uriBody = substr($result, $uriStart, -2);
        $this->assertStringNotContainsString("\x07", $uriBody);

        // C0 controls stripped.
        $withC0 = "https://example\x00.com/";
        $result = Ansi::hyperlinkOpen($withC0);
        $uriStart = strpos($result, ';') + 1;
        $uriStart = strpos($result, ';', $uriStart) + 1;
        $uriBody = substr($result, $uriStart, -2);
        $this->assertStringNotContainsString("\x00", $uriBody);
    }

    public function testSetWorkingDirectoryStripsControlBytesFromHost(): void
    {
        // Clean path and host.
        $result = Ansi::setWorkingDirectory('/home/user', 'localhost');
        $this->assertStringContainsString('localhost', $result);
        $this->assertStringContainsString('/home/user', $result);

        // ESC in host should be stripped.
        $withEsc = "evil\x1b[31m.host";
        $result = Ansi::setWorkingDirectory('/tmp', $withEsc);
        // Extract host portion between "file://" and the path.
        $hostStart = strpos($result, 'file://') + 7;
        $pathStart = strpos($result, '/', $hostStart);
        $hostBody = substr($result, $hostStart, $pathStart - $hostStart);
        $this->assertStringNotContainsString("\x1b", $hostBody, 'Raw ESC should be stripped from host');

        // C0 controls in host should be stripped.
        $withC0 = "host\x00name";
        $result = Ansi::setWorkingDirectory('/tmp', $withC0);
        $hostStart = strpos($result, 'file://') + 7;
        $pathStart = strpos($result, '/', $hostStart);
        $hostBody = substr($result, $hostStart, $pathStart - $hostStart);
        $this->assertStringNotContainsString("\x00", $hostBody);
    }

    public function testCleanInputIsUnchanged(): void
    {
        // Regression guard: clean input produces the expected escape sequence.

        // setWindowTitle: the OSC wrapper is added but the title text is unchanged.
        $titles = ['Simple Title', 'Title with spaces', 'Title: v2.0'];
        foreach ($titles as $title) {
            $result = Ansi::setWindowTitle($title);
            $this->assertSame("\x1b]2;{$title}\x07", $result);
        }

        // hyperlinkOpen: the OSC wrapper is added but the URI text is unchanged.
        $uris = [
            'https://example.com/',
            'https://example.com/path?query=value',
            'mailto:test@example.com',
            'file:///tmp/test.txt',
        ];
        foreach ($uris as $uri) {
            $result = Ansi::hyperlinkOpen($uri);
            // Result is OSC 8; params; URI ST. Check URI is embedded unchanged.
            $this->assertStringContainsString($uri, $result);
        }

        // setWorkingDirectory: path is URL-encoded but visible.
        $paths = ['/home/user', '/tmp', '/var/log'];
        foreach ($paths as $path) {
            $result = Ansi::setWorkingDirectory($path);
            $this->assertStringContainsString($path, $result);
        }
    }
}

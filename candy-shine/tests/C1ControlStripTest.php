<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Renderer;

/**
 * Audit 15b-08: UTF-8-encoded C1 controls must not survive markdown rendering.
 *
 * `\xC2\x9B` is well-formed UTF-8 for U+009B, so a C0/ESC-only sweep lets it
 * through — and xterm decodes it to the codepoint and runs it as CSI, so
 * `U+009B 2 J` clears the screen with no ESC byte anywhere. Every text-bearing
 * node kind is driven through both the plain and the ANSI theme, since the
 * styled path wraps the literal in SGR and could hide a survivor.
 */
final class C1ControlStripTest extends TestCase
{
    private const CSI = "\u{9b}";
    private const OSC = "\u{9d}";
    private const DCS = "\u{90}";
    private const ST = "\u{9c}";

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function markdownNodes(): array
    {
        $csi = self::CSI . '2J';
        $osc = self::OSC . '0;pwn' . self::ST;
        $dcs = self::DCS . 'q' . self::ST;

        return [
            'paragraph' => ["before {$csi} {$osc} {$dcs} after", ['before', '2J', '0;pwn', 'after']],
            'heading' => ["# Title {$csi} end", ['Title', '2J', 'end']],
            'inline code' => ["use `x{$csi}y` here", ['x2Jy', 'here']],
            'fenced code, no lang' => ["```\nline {$csi} {$osc}\n```", ['line', '2J', '0;pwn']],
            'fenced code, php' => ["```php\n\$a = 1; // {$csi} {$dcs}\n```", ['$a', '2J']],
            'indented code' => ["    code {$csi} here", ['code', '2J', 'here']],
            'table cell' => ["| h1 | h2 |\n|----|----|\n| a {$csi} | b {$osc} |", ['h1', 'a', '2J', 'b', '0;pwn']],
            'list item' => ["- item {$csi} one\n- item {$dcs} two", ['item', '2J', 'one', 'two']],
            'block quote' => ["> quoted {$csi} text", ['quoted', '2J', 'text']],
            'emphasis' => ["**bold {$csi}** and *it {$osc}*", ['bold', '2J', 'it', '0;pwn']],
            'link text' => ["[click {$csi} me](https://example.com/a)", ['click', '2J', 'me']],
            'inline html' => ["a <span>{$csi}x</span> b", ['2Jx']],
            'html block' => ["<div>\n{$csi}2J {$osc}\n</div>", ['2J2J']],
        ];
    }

    #[DataProvider('markdownNodes')]
    public function testPlainThemeDropsC1IntroducersAndKeepsText(string $markdown, array $visible): void
    {
        $this->assertNoC1($markdown, Renderer::plain()->render($markdown), $visible);
    }

    #[DataProvider('markdownNodes')]
    public function testAnsiThemeDropsC1IntroducersAndKeepsText(string $markdown, array $visible): void
    {
        $this->assertNoC1($markdown, Renderer::ansi()->render($markdown), $visible);
    }

    public function testValidTextSharingC1ByteValuesSurvives(): void
    {
        // → (\xE2\x86\x92), NBSP (\xC2\xA0) and 😀 (\xF0\x9F\x98\x80) carry
        // bytes in 0x80-0x9F as continuations or a \xC2 lead with a non-C1
        // tail; none of them is a control and all must render.
        $out = Renderer::plain()->render("a \u{2192} b\u{a0}c \u{1F600}");
        $this->assertStringContainsString("\u{2192}", $out);
        $this->assertStringContainsString("\u{a0}", $out);
        $this->assertStringContainsString("\u{1F600}", $out);
    }

    public function testSweepSurvivesMalformedUtf8InTheInput(): void
    {
        // CommonMark rejects a malformed document before rendering, but
        // stripControls() also runs on pre-parse input (emoji expansion), so
        // it must stay a byte sweep: a /u regex would fail on the stray \xFF
        // and disable the whole strip.
        $method = (new \ReflectionClass(Renderer::class))->getMethod('stripControls');
        $out = $method->invoke(null, "bad \xFF byte " . self::CSI . "2J \xE2 end");
        $this->assertSame("bad \xFF byte 2J \xE2 end", $out);
    }

    public function testStripControlsHelperRemovesEveryC1Codepoint(): void
    {
        $method = (new \ReflectionClass(Renderer::class))->getMethod('stripControls');
        for ($cp = 0x80; $cp <= 0x9F; $cp++) {
            $this->assertSame('ab', $method->invoke(null, 'a' . mb_chr($cp, 'UTF-8') . 'b'), sprintf('U+%04X', $cp));
        }
        $this->assertSame("a\u{a0}b", $method->invoke(null, "a\u{a0}b"));
    }

    /**
     * @param list<string> $visible
     */
    private function assertNoC1(string $markdown, string $out, array $visible): void
    {
        $this->assertMatchesRegularExpression('/\xC2[\x80-\x9F]/', $markdown, 'fixture must carry C1');
        $this->assertDoesNotMatchRegularExpression(
            '/\xC2[\x80-\x9F]/',
            $out,
            'UTF-8 C1 control survived: ' . bin2hex($out),
        );
        foreach ($visible as $text) {
            $this->assertStringContainsString($text, $out);
        }
    }
}

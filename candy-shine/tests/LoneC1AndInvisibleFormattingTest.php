<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Renderer;

/**
 * Audits 15b-29 and 15b-28 for CandyShine.
 *
 * 15b-29: a lone raw 8-bit C1 byte (a bare `\x9B`, CSI) is malformed UTF-8,
 * so CommonMark refused the whole document with an exception, and
 * `stripControls()` — whose byte class only matched the UTF-8 spelling
 * `\xC2\x9B` — let it through on the pre-parse emoji path. It is now removed
 * wherever it is not part of a well-formed sequence, and the multi-byte
 * characters whose continuation bytes share its numeric range survive.
 *
 * 15b-28: a U+202E RIGHT-TO-LEFT OVERRIDE or a zero-width space in rendered
 * markdown shows as a `<U+XXXX>` marker instead of silently reversing or
 * hiding text.
 */
final class LoneC1AndInvisibleFormattingTest extends TestCase
{
    /** @return iterable<string, array{Renderer}> */
    public static function renderers(): iterable
    {
        yield 'plain' => [Renderer::plain()];
        yield 'ansi' => [Renderer::ansi()];
        yield 'plain + emoji' => [Renderer::plain()->withEmoji(true)];
    }

    #[DataProvider('renderers')]
    public function testALoneC1ByteIsRemovedAndValidTextSurvives(Renderer $r): void
    {
        $out = $r->render("a\x9B2Jb \u{2192} \u{1F44D} \x85end");

        $this->assertStringNotContainsString("\x9B2J", $out);
        $this->assertDoesNotMatchRegularExpression(
            '/(?:[\xC2-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF4][\x80-\xBF]{3})(*SKIP)(*FAIL)|[\x80-\x9F]/',
            $out,
            'a lone C1 byte survived: ' . bin2hex($out),
        );
        $this->assertStringContainsString('a2Jb', $out);
        $this->assertStringContainsString("\u{2192}", $out);
        $this->assertStringContainsString("\u{1F44D}", $out);
        $this->assertStringContainsString('end', $out);
    }

    public function testStreamAgreesWithRenderOnLoneC1Input(): void
    {
        $r = Renderer::plain();
        $text = "# T\x9B\n\npara \x9B2J \u{2192}\n\n```\ncode \x9Bx\n```\n";

        $this->assertSame($r->render($text), implode('', iterator_to_array($r->stream([$text]), false)));
    }

    public function testOtherMalformedUtf8IsRepairedRatherThanThrown(): void
    {
        $out = Renderer::plain()->render("caf\xE9 ok");

        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
        $this->assertStringContainsString("caf\u{FFFD} ok", $out);
    }

    public function testStripControlsRemovesEveryLoneC1Byte(): void
    {
        $method = (new \ReflectionClass(Renderer::class))->getMethod('stripControls');
        for ($b = 0x80; $b <= 0x9F; $b++) {
            $this->assertSame('ab', $method->invoke(null, 'a' . \chr($b) . 'b'), \sprintf('0x%02X', $b));
        }
        // A removal can never splice a fresh UTF-8 C1 pair together.
        $this->assertSame("x\xC2y", $method->invoke(null, "x\xC2\x00\x9By"));
    }

    #[DataProvider('renderers')]
    public function testARightToLeftOverrideIsShownNotObeyed(Renderer $r): void
    {
        foreach (["rm \u{202E}hs.txt", "`rm \u{202E}hs.txt`", "```\nrm \u{202E}hs.txt\n```"] as $md) {
            $out = $r->render($md);

            $this->assertStringNotContainsString("\u{202E}", $out, $md);
            $this->assertStringContainsString('rm <U+202E>hs.txt', $out, $md);
        }
    }

    public function testZeroWidthSpaceIsShownAndEmojiZwjSurvives(): void
    {
        $out = Renderer::plain()->render("pay\u{200B}pal and \u{1F469}\u{200D}\u{1F4BB}");

        $this->assertStringContainsString('pay<U+200B>pal', $out);
        $this->assertStringContainsString("\u{1F469}\u{200D}\u{1F4BB}", $out);
    }

    public function testSanitizeOffLeavesTheTextAlone(): void
    {
        $out = Renderer::plain()->withSanitize(false)->render("rm \u{202E}hs.txt");

        $this->assertStringContainsString("\u{202E}", $out);
    }
}

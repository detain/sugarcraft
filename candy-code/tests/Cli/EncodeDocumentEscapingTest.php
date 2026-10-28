<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\NonInteractive;

/**
 * C-TOOL-7.d pins for the invisibility post-pass in
 * {@see NonInteractive::encodeDocument()}.
 *
 * The encoder runs with `JSON_UNESCAPED_UNICODE` so CJK and emoji stay
 * grep-able on the consumer side — and that same flag lets DEL, the whole
 * C1 range and every Unicode Cf format character (bidi overrides,
 * zero-widths, soft hyphen, the BOM, the astral tag characters) through
 * raw, straight from provider answers and paths onto a terminal that will
 * obey them. These tests hold the three halves of the fix: nothing
 * invisible reaches the bytes any more, the escape the sweep writes still
 * DECODES to the exact original document, and everything that was already
 * visible — ASCII, CJK, emoji, the private-use image markers — rides
 * through with zero changed bytes.
 */
final class EncodeDocumentEscapingTest extends TestCase
{
    /** The four flags the shipped encoder passes to `json_encode()`. */
    private const PLAIN_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * One payload carrying every family the sweep must catch: DEL, a C1
     * character, the bidi/zero-width/soft-hyphen/mark/BOM Cf members and
     * one astral Cf code point.
     */
    private const INVISIBLE_PAYLOAD = "a\x7fb\u{85}c\u{AD}d\u{200B}e\u{200E}f\u{202A}g\u{202E}h\u{2066}i\u{061C}j\u{FEFF}k\u{1D173}l";

    public function testInvisibleCodePointsNeverReachTheEncodedDocumentRaw(): void
    {
        $encoded = NonInteractive::encodeDocument(['answer' => self::INVISIBLE_PAYLOAD]);

        self::assertSame(
            0,
            preg_match('/[\x{7F}-\x{9F}\p{Cf}]/u', $encoded),
            'a DEL, C1 or Cf code point reached the document as raw bytes',
        );

        // And each family actually arrived — escaped. A sweep whose pattern
        // silently matched NOTHING would pass the absence leg above just as
        // vacuously, so the presence legs are the non-vacuous half.
        self::assertStringContainsString('\u007f', $encoded, 'DEL');
        self::assertStringContainsString('\u0085', $encoded, 'C1 NEL');
        self::assertStringContainsString('\u00ad', $encoded, 'soft hyphen');
        self::assertStringContainsString('\u200b', $encoded, 'zero width space');
        self::assertStringContainsString('\u202e', $encoded, 'right-to-left override');
        self::assertStringContainsString('\u2066', $encoded, 'left-to-right isolate');
        self::assertStringContainsString('\u061c', $encoded, 'Arabic letter mark');
        self::assertStringContainsString('\ufeff', $encoded, 'byte order mark');
        // The astral tag character has no astral JSON escape; it must ride
        // as exactly the surrogate pair the encoder would have written.
        self::assertStringContainsString('\ud834\udd73', $encoded, 'astral Cf surrogate pair');
    }

    public function testDecodingTheSweptDocumentReturnsTheOriginalByteIdentically(): void
    {
        $document = [
            'answer' => self::INVISIBLE_PAYLOAD,
            "key\u{202E}with\u{FEFF}marks" => 'value with \backslash and /slash and 日本語',
            'nested' => ['deep' => "bidi\u{2069}tail"],
        ];

        $encoded = NonInteractive::encodeDocument($document);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            $document,
            $decoded,
            'the \uXXXX rewrite changed a decoded byte somewhere — the sweep is not lossless',
        );
        // Structural survivors: the escaped document is still exactly ONE
        // JSON object whose slashes and backslashes kept their raw
        // (JSON_UNESCAPED_SLASHES) spelling.
        self::assertStringContainsString('/slash', $encoded);
        self::assertStringContainsString('\\backslash', $encoded);
    }

    public function testVisibleContentRidesThroughWithZeroChangedBytes(): void
    {
        $document = [
            'ascii' => "plain \t text \n with escapes json already does",
            'cjk' => '日本語中文한글',
            'emoji' => 'ship it 🎉🚀',
            'marker' => "image\u{E002}zone\u{E003}tail",
            'ligature' => 'ﬁ printable ligature, not a format character',
        ];

        self::assertSame(
            json_encode($document, self::PLAIN_FLAGS),
            NonInteractive::encodeDocument($document),
            'the post-pass touched a byte that was never invisible — CJK, emoji, private-use markers or an already-escaped C0 changed shape',
        );
    }

    public function testInvalidUtf8SubstitutionSurvivesTheSweep(): void
    {
        // The whole reason the flags are what they are (see the encoder's
        // doc-block): a stray invalid byte must still produce a document.
        // Substitution lands on U+FFFD, which is neither C1 nor Cf, so the
        // swept document must decode back to exactly the substituted text —
        // the pin that the post-pass cannot turn the substitution path into
        // a second silent-empty bug — and the Cf member of the same payload
        // must still arrive escaped.
        $decoded = json_decode(
            NonInteractive::encodeDocument(['text' => "hexdump \xFF\xFE tail \u{202E}"]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ['text' => "hexdump \u{FFFD}\u{FFFD} tail \u{202E}"],
            $decoded,
            'the substituted replacement characters or the swept Cf member changed the decoded payload',
        );

        $swept = NonInteractive::encodeDocument(['text' => "hexdump \xFF\xFE tail \u{202E}"]);
        self::assertStringNotContainsString("\u{202E}", $swept);
        self::assertStringContainsString('\u202e', $swept);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;

/**
 * ImageSource::sniffMime() (magic-number label for the four vision wires) and
 * ImageSource::isFetchableUrl() (the pure URL predicate), plus the scheme
 * regression: RFC 3986 makes schemes case-insensitive, and the fetch path used
 * to refuse `HTTPS://`.
 *
 * @covers \SugarCraft\Image\ImageSource::sniffMime
 * @covers \SugarCraft\Image\ImageSource::isFetchableUrl
 */
final class ImageSourceSniffMimeTest extends TestCase
{
    protected function tearDown(): void
    {
        ImageSource::overrideHostResolver(null);
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function namedFormats(): iterable
    {
        yield 'png' => ["\x89PNG\r\n\x1a\n\0\0\0\rIHDR", 'image/png'];
        // The label keeps the four-byte rule detectImageFormat() always applied;
        // a damaged tail is refused by the decoders, not relabelled here.
        yield 'png with a damaged signature tail' => ["\x89PNG\r\nX\n\0\0\0\rIHDR", 'image/png'];
        yield 'jpeg' => ["\xFF\xD8\xFF\xE0\0\x10JFIF", 'image/jpeg'];
        yield 'gif87a' => ['GIF87a' . "\x01\x00\x01\x00", 'image/gif'];
        yield 'gif89a' => ['GIF89a' . "\x01\x00\x01\x00", 'image/gif'];
        yield 'webp' => ['RIFF' . pack('V', 12) . 'WEBPVP8 ', 'image/webp'];
    }

    #[DataProvider('namedFormats')]
    public function testSniffsEachVisionFormatByMagicNumber(string $bytes, string $mime): void
    {
        self::assertSame($mime, ImageSource::sniffMime($bytes));
    }

    /**
     * A label is not a refusal: an animated WebP is still reported as WebP here.
     * The refusal belongs to decoding, where fromString() still throws for it.
     */
    public function testAnimatedWebpIsLabelledWebpNotRefused(): void
    {
        $animated = 'RIFF' . pack('V', 22) . 'WEBP' . 'VP8X' . pack('V', 10)
            . chr(0x02) . "\0\0\0" . "\0\0\0" . "\0\0\0";

        self::assertSame('image/webp', ImageSource::sniffMime($animated));
    }

    /** @return iterable<string, array{string}> */
    public static function unnamedBytes(): iterable
    {
        yield 'empty' => [''];
        yield 'png prefix only' => ["\x89PNG"];
        yield 'jpeg prefix only' => ["\xFF\xD8"];
        yield 'riff but not webp' => ['RIFF' . pack('V', 4) . 'WEBX'];
        yield 'riff too short for webp' => ['RIFF' . "\0\0\0\0" . 'WEB'];
        yield 'bmp' => ['BM' . str_repeat("\0", 20)];
        yield 'pdf' => ['%PDF-1.4'];
        // TIFF is refused by fromString(), but it is outside the four wires'
        // label set, so the label is null.
        yield 'tiff' => ["II*\0" . str_repeat("\0", 8)];
        yield 'text' => ['hello world'];
    }

    #[DataProvider('unnamedBytes')]
    public function testReturnsNullForBytesOutsideTheFourLabels(string $bytes): void
    {
        self::assertNull(ImageSource::sniffMime($bytes));
    }

    public function testSniffMimeAgreesWithTheDecodedFormatForAPngFixture(): void
    {
        $bytes = (string) file_get_contents(__DIR__ . '/fixtures/100x100_blue.png');

        $image = ImageSource::fromString($bytes);

        self::assertSame(ImageSource::sniffMime($bytes), $image->format);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function fetchableUrls(): iterable
    {
        yield 'https' => ['https://example.com/a.png', true];
        yield 'http' => ['http://example.com/a.png', true];
        yield 'upper-case scheme' => ['HTTPS://example.com/a.png', true];
        yield 'ipv6 literal host' => ['http://[::1]/a.png', true];
        yield 'ftp scheme refused' => ['ftp://example.com/a.png', false];
        yield 'file scheme refused' => ['file:///etc/passwd', false];
        yield 'no host' => ['http:/a.png', false];
        yield 'empty host' => ['https://', false];
        yield 'no scheme' => ['example.com/a.png', false];
        yield 'empty string' => ['', false];
    }

    #[DataProvider('fetchableUrls')]
    public function testIsFetchableUrlRequiresAllowedSchemeAndHost(string $url, bool $expected): void
    {
        self::assertSame($expected, ImageSource::isFetchableUrl($url));
    }

    public function testIsFetchableUrlHonoursACustomAllowList(): void
    {
        self::assertTrue(ImageSource::isFetchableUrl('https://a.test/x', ['https']));
        self::assertFalse(ImageSource::isFetchableUrl('http://a.test/x', ['https']));
        self::assertTrue(ImageSource::isFetchableUrl('HTTPS://a.test/x', ['https']));
    }

    public function testNullAllowListAcceptsAnySchemeButStillNeedsAHost(): void
    {
        self::assertTrue(ImageSource::isFetchableUrl('ftp://a.test/x', null));
        self::assertFalse(ImageSource::isFetchableUrl('nohost', null));
    }

    /**
     * Regression: validateUrlScheme compared the raw scheme with a strict
     * in_array, so `HTTPS://` was refused as "not in the allowed list". The
     * scheme now matches case-insensitively, and the request gets as far as the
     * SSRF guard, which refuses this private resolution before any socket opens.
     */
    public function testUpperCaseHttpsSchemePassesTheSchemeCheck(): void
    {
        ImageSource::overrideHostResolver(static fn (string $h): array => ['10.0.0.5']);

        try {
            ImageSource::fromUrl('HTTPS://internal.test/a.png');
            self::fail('a private resolution must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('not in the allowed list', $e->getMessage());
        }
    }

    public function testADisallowedSchemeIsStillRefusedInAnyCase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not in the allowed list');

        ImageSource::fromUrl('FILE:///etc/passwd');
    }
}

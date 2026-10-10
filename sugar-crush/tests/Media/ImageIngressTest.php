<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\ImageIngress;
use SugarCraft\Mosaic\ImageSource;

/**
 * Plan W4.1 gates for the ingress boundary: every accepted fixture yields the
 * raw base64 of the EXACT source bytes (no re-encode), plus the geometry and
 * sha1 the source rows display; every refused shape (remote URL, data: URI,
 * over-cap bytes, corrupt file, missing file) throws InvalidArgumentException
 * naming the refusal before any state lands. The URL door is the SSRF ruling
 * on record — wider than http: ANY `[scheme]://` and the `data:` prefix are
 * refused, and a path that merely starts with a letter-plus-colon without the
 * `//` stays a candidate local path.
 */
final class ImageIngressTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/img';

    public function testFromPathAdmitsEverySupportedFixtureWithExactByteRoundTrip(): void
    {
        foreach (['tiny.png' => 'image/png', 'tiny.jpg' => 'image/jpeg', 'tiny.gif' => 'image/gif'] as $file => $mime) {
            $path = self::FIXTURES . '/' . $file;
            $ingress = ImageIngress::fromPath($path);

            self::assertSame($mime, $ingress->mime, $file);
            self::assertSame(4, $ingress->width, $file);
            self::assertSame(3, $ingress->height, $file);
            self::assertSame(file_get_contents($path), $ingress->bytes, $file);
            self::assertSame(
                base64_encode((string) file_get_contents($path)),
                $ingress->base64,
                $file . ' wire entry is raw base64 of the source bytes, never a data: URI',
            );
            self::assertSame(sha1((string) file_get_contents($path)), $ingress->sha1, $file);
            self::assertSame($file, $ingress->name(), $file);
            self::assertSame('4×3', $ingress->dimensions(), $file);
        }
    }

    public function testRemoteUrlShapesAreRefusedByTheSsrfDoor(): void
    {
        foreach ([
            'https://example.com/x.png',
            'http://example.com/x.png',
            'ftp://example.com/x.png',
            'file:///etc/passwd',
            'DATA:IMAGE/PNG;base64,AAAA',
            '  https://padded.example.com/x.png  ',
        ] as $candidate) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage(ImageIngress::URL_REFUSAL);

            ImageIngress::fromPath($candidate);
        }
    }

    public function testOversizedFileIsRefusedBeforeTheBytesAreReadIntoASlot(): void
    {
        $path = self::FIXTURES . '/tiny.png';
        $size = (int) filesize($path);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('over the ' . (string) ($size - 1) . '-byte ceiling');

        ImageIngress::fromPath($path, $size - 1);
    }

    public function testMissingFileIsRefusedByImageSource(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ImageIngress::fromPath(self::FIXTURES . '/no-such-file.png');
    }

    public function testCorruptFileWithRightMagicButBrokenStreamIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);

        ImageIngress::fromPath(self::FIXTURES . '/corrupt.png');
    }

    public function testFromBytesAdmitsInMemoryBytesWithOriginAsLabelOnly(): void
    {
        $bytes = (string) file_get_contents(self::FIXTURES . '/tiny.png');
        $ingress = ImageIngress::fromBytes($bytes, 'clipboard.png');

        self::assertSame('clipboard.png', $ingress->path);
        self::assertSame('clipboard.png', $ingress->name());
        self::assertSame(base64_encode($bytes), $ingress->base64);
        self::assertSame(4, $ingress->width);
        self::assertSame(3, $ingress->height);
    }

    public function testFromBytesRefusesNonImageAndOversizedInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('over the 8-byte ceiling');

        ImageIngress::fromBytes('not an image at all', 'clipboard.png', 8);
    }

    public function testFromBytesRefusesGarbageUnderTheCap(): void
    {
        // fromString's GD door is an InvalidArgumentException (ImageSource:235),
        // unlike fromFile's RuntimeException (:184) — the two entry shapes
        // fail through different classes and both polarities are pinned.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GD failed to load image');

        ImageIngress::fromBytes(str_repeat("\x00", 32), 'clipboard.png');
    }

    public function testTheExtensionPaletteMirrorsTheAcceptedMimes(): void
    {
        self::assertSame(['png', 'jpg', 'jpeg', 'gif'], ImageIngress::EXTENSIONS);
        // The ceiling claim in the docblock: webp is NOT admitted in v1.
        self::assertNotContains('webp', ImageIngress::EXTENSIONS);
    }

    public function testTheByteCeilingDefaultIsImageSources(): void
    {
        $reflection = new \ReflectionMethod(ImageIngress::class, 'fromPath');
        $parameters = $reflection->getParameters();

        self::assertSame(ImageSource::MAX_BYTES, $parameters[1]->getDefaultValue());
    }
}

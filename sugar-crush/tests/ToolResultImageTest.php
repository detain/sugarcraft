<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Mosaic\Mosaic;

/**
 * Covers ToolResult's image/attachment field + probe-once Mosaic::auto()
 * capture point (crush_feat.md section 9, E1/E2).
 *
 * @see ToolResult
 */
final class ToolResultImageTest extends TestCase
{
    // =========================================================================
    // Baseline: existing ok()/error()/constructor behaviour is untouched
    // =========================================================================

    public function testPlainConstructorDefaultsImageFieldsToNull(): void
    {
        $result = new ToolResult('grep', 'match found');

        $this->assertNull($result->imageBytes);
        $this->assertNull($result->imagePath);
        $this->assertNull($result->imageProtocol);
        $this->assertFalse($result->hasImage());
    }

    public function testOkFactoryHasNoImage(): void
    {
        $result = ToolResult::ok('grep', 'match found', 'call_1');

        $this->assertFalse($result->hasImage());
        $this->assertNull($result->imageProtocol);
    }

    public function testErrorFactoryHasNoImage(): void
    {
        $result = ToolResult::error('grep', 'boom', 'call_1');

        $this->assertFalse($result->hasImage());
        $this->assertTrue($result->isError());
    }

    // =========================================================================
    // okWithImage() -- this is the capability that did not exist before this
    // step: a tool producing a screenshot had zero way to attach bytes to
    // its ToolResult. Against the OLD ToolResult (4 ctor args, no
    // okWithImage/withImage/hasImage members) every assertion below is a
    // fatal "call to undefined method" / "undefined property", not merely a
    // false assertion.
    // =========================================================================

    public function testOkWithImageAttachesBytesAndSucceeds(): void
    {
        $bytes = "\x89PNG\r\n\x1a\nfake-bytes";
        $result = ToolResult::okWithImage('screenshot', 'captured', $bytes, 'call_42');

        $this->assertSame('screenshot', $result->name);
        $this->assertSame('captured', $result->result);
        $this->assertSame('call_42', $result->id);
        $this->assertNull($result->error);
        $this->assertFalse($result->isError());
        $this->assertTrue($result->hasImage());
        $this->assertSame($bytes, $result->imageBytes);
    }

    public function testOkWithImageNeverThrowsWithoutATty(): void
    {
        // The whole point of Mosaic::auto() (per candy-mosaic docs) is that
        // it NEVER throws, even with no controlling TTY (exactly this
        // sandboxed PHPUnit process). If probing were wired incorrectly
        // (e.g. calling Mosaic::probe() directly, which can throw) this
        // would fail under CI.
        $result = ToolResult::okWithImage('screenshot', 'ok', 'bytes', 'call_1');

        $this->assertNotNull($result->imageProtocol);
        $this->assertIsValidMosaicProtocol($result->imageProtocol);
    }

    public function testOkWithImageProbeIsCachedAcrossCalls(): void
    {
        // Probe-once: repeated captures must observe the SAME detected
        // protocol within one process, proving the static Mosaic::auto()
        // memoization (not a fresh probe per call) is actually in effect.
        $first = ToolResult::okWithImage('a', 'r', 'bytes-1');
        $second = ToolResult::okWithImage('b', 'r', 'bytes-2');

        $this->assertSame($first->imageProtocol, $second->imageProtocol);
    }

    // =========================================================================
    // withImage() -- fluent, immutable attach onto an existing result
    // =========================================================================

    public function testWithImageReturnsNewInstanceLeavingOriginalUntouched(): void
    {
        $original = ToolResult::ok('read', 'text output', 'call_9');
        $withImage = $original->withImage('raw-bytes', '/tmp/shot.png');

        $this->assertNotSame($original, $withImage);
        $this->assertFalse($original->hasImage());
        $this->assertNull($original->imageBytes);
        $this->assertTrue($withImage->hasImage());
        $this->assertSame('raw-bytes', $withImage->imageBytes);
        $this->assertSame('/tmp/shot.png', $withImage->imagePath);
    }

    public function testWithImagePreservesNameResultErrorAndId(): void
    {
        $original = ToolResult::error('read', 'file not found', 'call_5');
        $withImage = $original->withImage('bytes');

        $this->assertSame('read', $withImage->name);
        $this->assertSame('file not found', $withImage->error);
        $this->assertSame('call_5', $withImage->id);
        $this->assertTrue($withImage->isError());
    }

    public function testWithImagePathDefaultsToNullWhenOmitted(): void
    {
        $result = ToolResult::ok('shot', 'ok')->withImage('bytes-only');

        $this->assertSame('bytes-only', $result->imageBytes);
        $this->assertNull($result->imagePath);
    }

    public function testWithImageSetsProtocolFromMosaicAuto(): void
    {
        $result = ToolResult::ok('shot', 'ok')->withImage('bytes');

        $this->assertNotNull($result->imageProtocol);
        $this->assertIsValidMosaicProtocol($result->imageProtocol);
    }

    /**
     * Under $TMUX, Mosaic::auto() wraps the chosen renderer in
     * TmuxPassthroughDecorator, whose name() reports 'tmux(<inner>)' rather
     * than a bare protocol name -- accept either form.
     */
    private function assertIsValidMosaicProtocol(string $protocol): void
    {
        if (str_starts_with($protocol, 'tmux(') && str_ends_with($protocol, ')')) {
            $protocol = substr($protocol, 5, -1);
        }

        $this->assertContains($protocol, Mosaic::supportedProtocols());
    }

    // =========================================================================
    // hasImage()
    // =========================================================================

    public function testHasImageFalseWhenImageBytesIsEmptyStringIsStillTrue(): void
    {
        // Empty string is a set (if unusual) value, distinct from "not set" (null).
        $result = ToolResult::ok('shot', 'ok')->withImage('');

        $this->assertTrue($result->hasImage());
        $this->assertSame('', $result->imageBytes);
    }

    public function testHasImageTrueOnlyWhenBytesPresent(): void
    {
        $withoutImage = ToolResult::ok('grep', 'no image here');
        $withImage = ToolResult::okWithImage('shot', 'ok', 'data');

        $this->assertFalse($withoutImage->hasImage());
        $this->assertTrue($withImage->hasImage());
    }

    // =========================================================================
    // toWire() is unaffected by image fields -- binary bytes must never leak
    // into the LLM-facing wire payload.
    // =========================================================================

    public function testToWireDoesNotLeakImageBytes(): void
    {
        $result = ToolResult::okWithImage('shot', 'took a screenshot', "\x00binary\xffdata", 'call_7');
        $wire = $result->toWire();

        $this->assertSame('took a screenshot', $wire['content']);
        $this->assertArrayNotHasKey('imageBytes', $wire);
        $this->assertArrayNotHasKey('image', $wire);
    }
}

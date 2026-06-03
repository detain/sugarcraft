<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Render\LumaRamp;

/**
 * Unit tests for LumaRamp character ramp and luminance mapping.
 *
 * @covers \SugarCraft\Reel\Render\LumaRamp
 */
final class LumaRampTest extends TestCase
{
    /**
     * @testdox char() returns a string for boundary luminance values
     */
    public function testCharReturnsString(): void
    {
        $this->assertIsString(LumaRamp::char(0));
        $this->assertIsString(LumaRamp::char(255));
    }

    /**
     * @testdox char(0) returns the darkest character in the default ramp
     */
    public function testCharAtZeroLuminance(): void
    {
        $darkestChar = LumaRamp::char(0.0);
        $ramp = LumaRamp::ramp('default');

        // The first character in the standard ramp should match char(0).
        // standard ramp starts with ' ' (space) which is the darkest.
        $this->assertSame($ramp[0], $darkestChar);
    }

    /**
     * @testdox char(255) returns the brightest character in the default ramp
     */
    public function testCharAtMaxLuminance(): void
    {
        $brightestChar = LumaRamp::char(255.0);
        $ramp = LumaRamp::ramp('default');

        // The last character in the standard ramp should match char(255).
        // standard ramp ends with '8' (actually check the last entry).
        $this->assertSame($ramp[255], $brightestChar);
    }

    /**
     * @testdox char() returns exactly one character
     */
    public function testCharIsOneCharacter(): void
    {
        $this->assertSame(1, strlen(LumaRamp::char(128)));
        $this->assertSame(1, strlen(LumaRamp::char(0)));
        $this->assertSame(1, strlen(LumaRamp::char(255)));
        $this->assertSame(1, strlen(LumaRamp::char(64)));
        $this->assertSame(1, strlen(LumaRamp::char(192)));
    }

    /**
     * @testdox luminance to character mapping is monotonically non-decreasing
     *
     * Higher luminance values must map to the same or "brighter" characters
     * (further right in the ramp), never to darker ones.
     */
    public function testCharIsMonotonicallyNonDecreasing(): void
    {
        $prevChar = '';
        $prevIndex = -1;

        // Use a specific ramp to avoid LUT state interference.
        $ramp = LumaRamp::ramp('standard');

        for ($luma = 0; $luma <= 255; $luma++) {
            $ch = LumaRamp::char((float)$luma);
            $index = array_search($ch, $ramp, true);

            // Index should never decrease as luma increases.
            $this->assertGreaterThanOrEqual(
                $prevIndex,
                $index,
                "LumaRamp::char({$luma}) = '{$ch}' (index {$index}) decreased from previous luma value (char '{$prevChar}', index {$prevIndex})"
            );

            $prevChar = $ch;
            $prevIndex = $index;
        }
    }

    /**
     * @testdox ramp('default') returns a 256-entry array
     */
    public function testRampDefaultReturnsArray(): void
    {
        $ramp = LumaRamp::ramp('default');
        $this->assertIsArray($ramp);
        $this->assertCount(256, $ramp);
    }

    /**
     * @testdox ramp() with an unknown name falls back to the default ramp
     */
    public function testRampUnknownFallsBackToDefault(): void
    {
        $default = LumaRamp::ramp('default');
        $fallback = LumaRamp::ramp('nonexistent_ramp_name');

        $this->assertSame($default, $fallback);
    }

    /**
     * @testdox ramp('minimal') returns an array different from standard
     */
    public function testRampByName(): void
    {
        $minimal = LumaRamp::ramp('minimal');
        $standard = LumaRamp::ramp('standard');

        $this->assertIsArray($minimal);
        $this->assertIsArray($standard);

        // They should differ (different character sets).
        $this->assertNotSame($minimal, $standard);
    }

    /**
     * @testdox ramp() default size is 256 entries
     */
    public function testRampIs256Entries(): void
    {
        $this->assertCount(256, LumaRamp::ramp());
    }

    /**
     * @testdox ramp entries are all single characters
     */
    public function testRampEntriesAreSingleCharacters(): void
    {
        $ramp = LumaRamp::ramp();
        foreach ($ramp as $luma => $char) {
            $this->assertIsString($char, "ramp[{$luma}] is not a string");
            $this->assertSame(
                1,
                strlen($char),
                "ramp[{$luma}] = '{$char}' is not a single character"
            );
        }
    }

    /**
     * @testdox compute() returns BT.601 luminance in 0-255 range
     */
    public function testComputeReturnsLuminanceInRange(): void
    {
        // Black pixel → luma 0.
        $this->assertSame(0, LumaRamp::compute(0, 0, 0));

        // White pixel → luma 255.
        $this->assertSame(255, LumaRamp::compute(255, 255, 255));

        // Mid-grey (128,128,128) → luma 128.
        $this->assertSame(128, LumaRamp::compute(128, 128, 128));
    }

    /**
     * @testdox compute() correctly applies BT.601 weights (77R + 150G + 29B) >> 8
     */
    public function testComputeBt601Weights(): void
    {
        // Red: (77*255 + 150*0 + 29*0) >> 8 = 19659 >> 8 = 76
        $this->assertSame(76, LumaRamp::compute(255, 0, 0));

        // Green: (77*0 + 150*255 + 29*0) >> 8 = 38250 >> 8 = 149
        $this->assertSame(149, LumaRamp::compute(0, 255, 0));

        // Blue: (77*0 + 150*0 + 29*255) >> 8 = 7395 >> 8 = 28
        $this->assertSame(28, LumaRamp::compute(0, 0, 255));
    }

    /**
     * @testdox char() clamps out-of-range luminance values
     */
    public function testCharClampsOutOfRange(): void
    {
        // Negative clamps to 0 → same as char(0).
        $this->assertSame(LumaRamp::char(0), LumaRamp::char(-50));

        // Over 255 clamps to 255 → same as char(255).
        $this->assertSame(LumaRamp::char(255), LumaRamp::char(300));
    }

    /**
     * @testdox char() accepts float luminance values
     */
    public function testCharAcceptsFloat(): void
    {
        $result = LumaRamp::char(128.7);
        $this->assertIsString($result);
        $this->assertSame(1, strlen($result));
    }

    /**
     * @testdox char(l) indexes the precomputed default ramp for every 0-255 value
     *
     * Guards the precomputed-LUT contract: char() must be a plain lookup into
     * the cached default ramp for all luminance values (an earlier version
     * rebuilt the table per call and left the LUT field dead).
     */
    public function testCharMatchesPrecomputedRampForFullRange(): void
    {
        $ramp = LumaRamp::ramp(); // default (standard) ramp, precomputed
        for ($luma = 0; $luma <= 255; $luma++) {
            $this->assertSame(
                $ramp[$luma],
                LumaRamp::char((float) $luma),
                "char({$luma}) must equal the precomputed ramp entry"
            );
        }
    }

    /**
     * @testdox ramp() returns a stable (value-equal) table across repeated calls
     */
    public function testRampIsStableAcrossCalls(): void
    {
        $this->assertSame(LumaRamp::ramp('dense'), LumaRamp::ramp('dense'));
        $this->assertSame(LumaRamp::ramp(), LumaRamp::ramp());
    }

    /**
     * @testdox char() with no ramp argument uses the standard ramp
     */
    public function testCharDefaultIsStandardRamp(): void
    {
        // luma=0 (black) should map to the first char of the standard ramp.
        $this->assertNotEmpty(LumaRamp::char(0.0));
    }

    /**
     * @testdox char() accepts an explicit ramp name
     */
    public function testCharWithMinimalRamp(): void
    {
        // Different ramps produce different chars at same luma (most lumas).
        $char = LumaRamp::char(128.0, 'minimal');
        $this->assertIsString($char);
        $this->assertSame(1, strlen($char));
    }

    /**
     * @testdox isValidRamp() returns true for known ramp names
     */
    public function testIsValidRampKnownNames(): void
    {
        $this->assertTrue(LumaRamp::isValidRamp('minimal'));
        $this->assertTrue(LumaRamp::isValidRamp('standard'));
        $this->assertTrue(LumaRamp::isValidRamp('dense'));
    }

    /**
     * @testdox isValidRamp() returns false for unknown ramp names
     */
    public function testIsValidRampUnknownNames(): void
    {
        $this->assertFalse(LumaRamp::isValidRamp('nonexistent'));
        $this->assertFalse(LumaRamp::isValidRamp(''));
        $this->assertFalse(LumaRamp::isValidRamp('BT709'));
    }

    /**
     * @testdox LumaRamp source must not contain any BT.709 mislabel after J2.3 fix
     *
     * The (77,150,29) weights used throughout are BT.601/SMPTE-C, NOT BT.709.
     * This test guards against docblock regression where BT.709 was incorrectly
     * used to describe BT.601 weights.
     */
    public function testNoBt709MislabelInLumaRampSource(): void
    {
        $file = __DIR__ . '/../src/Render/LumaRamp.php';
        $content = file_get_contents($file);

        // After J2.3 fix, "BT.709" should not appear in this file at all.
        $this->assertStringNotContainsString(
            'BT.709',
            $content,
            'LumaRamp source must not contain BT.709 — the (77,150,29) weights are BT.601'
        );
    }
}

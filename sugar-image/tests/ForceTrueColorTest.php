<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Concerns\ForceTrueColor;

/**
 * The single checked truecolor door (finding 044). ext-gd's palette
 * conversion can only fail on allocation pressure this test cannot
 * honestly force, so the refusal leg is pinned structurally — the same
 * disclosure and method as the Iterm2 capture-reap pin: the census
 * routes every conversion here, and this file pins that HERE throws.
 */
final class ForceTrueColorTest extends TestCase
{
    public function testPaletteImageComesOutTruecolor(): void
    {
        $img = imagecreate(4, 2);
        $this->assertIsInt(imagecolorallocate($img, 10, 20, 30)); // pins a palette image
        $this->assertFalse(imageistruecolor($img));

        ForceTrueColor::convert($img);

        $this->assertTrue(imageistruecolor($img));
        imagedestroy($img);
    }

    public function testTruecolorImagePassesThroughUntouched(): void
    {
        $img = imagecreatetruecolor(3, 3);
        $this->assertTrue(imageistruecolor($img));

        ForceTrueColor::convert($img); // must not throw, must not churn

        $this->assertTrue(imageistruecolor($img));
        imagedestroy($img);
    }

    public function testTheDoorChecksTheConversionReturnValue(): void
    {
        $body = (string) file_get_contents(
            dirname(__DIR__) . '/src/Concerns/ForceTrueColor.php'
        );

        // The fifteen old hand-rolled blocks called the conversion as a
        // statement — return value dropped. The door must consume it.
        $this->assertStringContainsString('if (!@imagepalettetotruecolor($image)) {', $body);
        $this->assertMatchesRegularExpression('/imagepalettetotruecolor.*\{\s*throw new /s', $body);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Dither;

use PHPUnit\Framework\TestCase;

/**
 * C-06 arm-map replay for the 'noise' fixture, split out of DithererTest for
 * the reason PreRefactorSixelArms gives.
 */
final class DithererNoiseDigestTest extends TestCase
{
    use PreRefactorSixelArms;

    public function testEverySixelArmOfTheNoiseFixtureReRendersToItsPreRefactorDigest(): void
    {
        $this->assertFixtureArmsMatchTheirPreRefactorDigests('noise');
    }
}

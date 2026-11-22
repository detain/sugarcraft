<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Dither;

use PHPUnit\Framework\TestCase;

/**
 * C-06 arm-map replay for the 'grad' fixture, split out of DithererTest for
 * the reason PreRefactorSixelArms gives.
 */
final class DithererGradDigestTest extends TestCase
{
    use PreRefactorSixelArms;

    public function testEverySixelArmOfTheGradFixtureReRendersToItsPreRefactorDigest(): void
    {
        $this->assertFixtureArmsMatchTheirPreRefactorDigests('grad');
    }
}

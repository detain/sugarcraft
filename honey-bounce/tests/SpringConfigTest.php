<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests;

use SugarCraft\Bounce\SpringConfig;
use SugarCraft\Bounce\Spring;
use PHPUnit\Framework\TestCase;

final class SpringConfigTest extends TestCase
{
    private const EPS = 1e-9;

    public function testDerivesAngularFrequencyFromTensionAndMass(): void
    {
        $config = new SpringConfig(tension: 100.0, friction: 10.0, mass: 1.0);
        $this->assertEqualsWithDelta(10.0, $config->angularFrequency, self::EPS);
    }

    public function testDerivesDampingRatioFromTensionFrictionAndMass(): void
    {
        // ζ = friction / (2 * sqrt(tension * mass))
        // = 10 / (2 * sqrt(100)) = 10 / 20 = 0.5
        $config = new SpringConfig(tension: 100.0, friction: 10.0, mass: 1.0);
        $this->assertEqualsWithDelta(0.5, $config->dampingRatio, self::EPS);
    }

    public function testSpringFactoryReturnsSpringInstance(): void
    {
        $config = new SpringConfig(tension: 100.0, friction: 10.0, mass: 1.0);
        $spring = $config->spring();
        $this->assertInstanceOf(Spring::class, $spring);
    }

    public function testSpringAt60FpsUsesCorrectDeltaTime(): void
    {
        $config = new SpringConfig(tension: 100.0, friction: 10.0, mass: 1.0);
        $spring = $config->springAt60Fps();
        $this->assertInstanceOf(Spring::class, $spring);
    }

    public function testSpringWithCustomDeltaTime(): void
    {
        $config  = new SpringConfig(tension: 100.0, friction: 10.0, mass: 1.0);
        $spring  = $config->spring(1.0 / 120.0);
        $this->assertInstanceOf(Spring::class, $spring);
    }

    public function testZeroTensionYieldsZeroFrequency(): void
    {
        $config = new SpringConfig(tension: 0.0, friction: 5.0, mass: 1.0);
        $this->assertEqualsWithDelta(0.0, $config->angularFrequency, self::EPS);
    }

    public function testZeroFrictionYieldsZeroDamping(): void
    {
        $config = new SpringConfig(tension: 100.0, friction: 0.0, mass: 1.0);
        $this->assertEqualsWithDelta(0.0, $config->dampingRatio, self::EPS);
    }

    public function testNegativeTensionTreatedAsZero(): void
    {
        $config = new SpringConfig(tension: -50.0, friction: 10.0, mass: 1.0);
        $this->assertEqualsWithDelta(0.0, $config->angularFrequency, self::EPS);
    }

    public function testSpringFromConfigConverges(): void
    {
        $config = new SpringConfig(tension: 100.0, friction: 10.0, mass: 1.0);
        $spring  = $config->springAt60Fps();

        $pos = 0.0;
        $vel = 0.0;
        for ($i = 0; $i < 600; $i++) {
            [$pos, $vel] = $spring->update($pos, $vel, 100.0);
        }
        $this->assertEqualsWithDelta(100.0, $pos, 0.1);
    }
}

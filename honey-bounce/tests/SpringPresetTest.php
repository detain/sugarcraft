<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests;

use SugarCraft\Bounce\Spring;
use SugarCraft\Bounce\SpringPreset;
use SugarCraft\Bounce\SpringConfig;
use PHPUnit\Framework\TestCase;

final class SpringPresetTest extends TestCase
{
    private const EPS = 1e-9;

    /**
     * @dataProvider allPresetsProvider
     */
    public function testResolveReturnsSpringConfig(SpringPreset $preset): void
    {
        $config = $preset->resolve();
        $this->assertInstanceOf(SpringConfig::class, $config);
    }

    public static function allPresetsProvider(): iterable
    {
        foreach (SpringPreset::cases() as $case) {
            yield $case->name => [$case];
        }
    }

    public function testAllFivePresetsExist(): void
    {
        $names = array_map(fn($c) => $c->name, SpringPreset::cases());
        $this->assertContains('Gentle',   $names);
        $this->assertContains('Wobbly',    $names);
        $this->assertContains('Stiff',     $names);
        $this->assertContains('Slow',     $names);
        $this->assertContains('Molasses', $names);
        $this->assertCount(5, SpringPreset::cases());
    }

    public function testGentleHasPositiveAngularFrequency(): void
    {
        $config = SpringPreset::Gentle->resolve();
        $this->assertGreaterThan(0.0, $config->angularFrequency);
        $this->assertGreaterThan(0.0, $config->dampingRatio);
    }

    public function testStiffHasHigherFrequencyThanGentle(): void
    {
        $stiff  = SpringPreset::Stiff->resolve();
        $gentle = SpringPreset::Gentle->resolve();
        $this->assertGreaterThan($gentle->angularFrequency, $stiff->angularFrequency);
    }

    public function testSlowHasLowerFrequencyThanGentle(): void
    {
        $slow   = SpringPreset::Slow->resolve();
        $gentle = SpringPreset::Gentle->resolve();
        $this->assertLessThan($gentle->angularFrequency, $slow->angularFrequency);
    }

    public function testMolassesIsSlowest(): void
    {
        $molasses = SpringPreset::Molasses->resolve();
        $slow     = SpringPreset::Slow->resolve();
        $this->assertLessThan($slow->angularFrequency, $molasses->angularFrequency);
    }

    public function testFromPresetFactoryReturnsSpring(): void
    {
        foreach (SpringPreset::cases() as $preset) {
            $spring = Spring::fromPreset($preset);
            $this->assertInstanceOf(Spring::class, $spring);
        }
    }

    public function testFromPresetConvergesToTarget(): void
    {
        $spring = Spring::fromPreset(SpringPreset::Gentle);
        $pos = 0.0;
        $vel = 0.0;
        $target = 100.0;

        for ($i = 0; $i < 600; $i++) {
            [$pos, $vel] = $spring->update($pos, $vel, $target);
        }
        $this->assertEqualsWithDelta($target, $pos, 0.1);
        $this->assertEqualsWithDelta(0.0, $vel, 0.1);
    }

    public function testFromPresetWithCustomDeltaTime(): void
    {
        $spring = Spring::fromPreset(SpringPreset::Wobbly, 1.0 / 30.0);
        $this->assertInstanceOf(Spring::class, $spring);
    }
}

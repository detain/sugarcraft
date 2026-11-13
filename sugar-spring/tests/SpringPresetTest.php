<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use SugarCraft\Spring\Spring;
use SugarCraft\Spring\SpringPreset;
use SugarCraft\Spring\SpringConfig;
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

    /**
     * SPR-29 (finding 013): was instanceof-only — a fromPreset() that dropped
     * its $deltaTime argument stayed green. Frame-1 bytes for Wobbly now pin
     * 1/30 against the 1/60 default, so the argument is observed, not named.
     */
    public function testFromPresetWithCustomDeltaTime(): void
    {
        $custom  = Spring::fromPreset(SpringPreset::Wobbly, 1.0 / 30.0);
        $default = Spring::fromPreset(SpringPreset::Wobbly);

        [$pos30, $vel30] = $custom->update(0.0, 0.0, 100.0);
        [$pos60, $vel60] = $default->update(0.0, 0.0, 100.0);

        $this->assertSame(8.6484652418892978, $pos30, 'Wobbly frame 1 at 1/30 s');
        $this->assertSame(478.2431589911111, $vel30, 'Wobbly frame 1 velocity at 1/30 s');
        $this->assertSame(2.3317366043024634, $pos60, 'Wobbly frame 1 at the 1/60 default');
        $this->assertSame(269.6451664793027, $vel60, 'Wobbly frame 1 velocity at the 1/60 default');
        $this->assertGreaterThan($pos60, $pos30, 'a double-length frame must carry Wobbly further');
    }

    /**
     * SPR-21 (finding 080): resolve() rebuilt a fresh SpringConfig per call;
     * SpringConfig is `final readonly`, so the five presets memoize to one
     * shared instance each — callers resolving inside a loop stop
     * allocating. Identity pinned for all five cases; distinctness across
     * cases proves the table is keyed, not a single global. (Boundedness is
     * structural: the static-local memo keys on `$this->name`, so five
     * cases is the ceiling — enums cannot hold properties, hence no
     * reflection census here unlike CubicBezier's $presets.)
     */
    public function testResolveIsMemoizedPerPreset(): void
    {
        foreach (SpringPreset::cases() as $preset) {
            $this->assertSame(
                $preset->resolve(),
                $preset->resolve(),
                "{$preset->name} must hand back the same readonly config",
            );
        }

        $this->assertNotSame(
            SpringPreset::Gentle->resolve(),
            SpringPreset::Stiff->resolve(),
            'distinct presets must not alias to one instance',
        );
    }
}

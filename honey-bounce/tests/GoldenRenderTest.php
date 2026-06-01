<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bounce\Projectile;
use SugarCraft\Bounce\Spring;
use SugarCraft\Bounce\Point;
use SugarCraft\Bounce\Vector;
use SugarCraft\Testing\Snapshot\GoldenFile;

/**
 * Golden-file snapshot tests for honey-bounce spring/trajectory physics.
 *
 * honey-bounce outputs NUMERIC trajectories (JSON), not ANSI.
 * Uses assertGolden (file equality) on the JSON representation.
 *
 * @see Mirrors charmbracelet/harmonica spring physics
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    /**
     * Capture the first 10 steps of a spring trajectory as JSON.
     *
     * A spring starting at pos=0, vel=0, target=100 with Gentle preset
     * should produce a deterministic underdamped oscillation path.
     */
    public function testSpringTrajectoryJson(): void
    {
        $spring = Spring::fromPreset(\SugarCraft\Bounce\SpringPreset::Gentle);

        $trajectory = [];
        $pos = 0.0;
        $vel = 0.0;
        $target = 100.0;

        for ($i = 0; $i < 10; $i++) {
            $trajectory[] = [
                'step' => $i,
                'pos' => round($pos, 6),
                'vel' => round($vel, 6),
            ];
            [$pos, $vel] = $spring->update($pos, $vel, $target);
        }

        $json = json_encode($trajectory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // UPDATE_GOLDENS=1 auto-creates the fixture.
        if (getenv('UPDATE_GOLDENS') === '1') {
            GoldenFile::save($this->fixturesDir . '/spring-trajectory.golden', $json);
            $this->assertTrue(true);
            return;
        }

        $expected = GoldenFile::load($this->fixturesDir . '/spring-trajectory.golden');
        $this->assertNotNull($expected, 'Golden file not found. Run with UPDATE_GOLDENS=1 to create.');
        $this->assertEquals($expected, $json);
    }

    /**
     * Capture the first 10 steps of a projectile trajectory as JSON.
     *
     * A projectile launched upward should produce a deterministic arc.
     */
    public function testProjectileTrajectoryJson(): void
    {
        $projectile = Projectile::new(
            deltaTime: Spring::fps(60),
            position: Point::zero(),
            velocity: new Vector(5.0, 10.0),
            acceleration: Projectile::gravity(),
        );

        $trajectory = [];
        $p = $projectile;

        for ($i = 0; $i < 10; $i++) {
            $trajectory[] = [
                'step' => $i,
                'x' => round($p->position()->x, 6),
                'y' => round($p->position()->y, 6),
            ];
            $p = $p->update();
        }

        $json = json_encode($trajectory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (getenv('UPDATE_GOLDENS') === '1') {
            GoldenFile::save($this->fixturesDir . '/projectile-trajectory.golden', $json);
            $this->assertTrue(true);
            return;
        }

        $expected = GoldenFile::load($this->fixturesDir . '/projectile-trajectory.golden');
        $this->assertNotNull($expected, 'Golden file not found. Run with UPDATE_GOLDENS=1 to create.');
        $this->assertEquals($expected, $json);
    }
}

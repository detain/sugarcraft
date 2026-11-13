<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Spring\Projectile;
use SugarCraft\Spring\Spring;
use SugarCraft\Spring\Point;
use SugarCraft\Spring\Vector;
use SugarCraft\Testing\Snapshot\GoldenFile;

/**
 * Golden-file snapshot tests for sugar-spring spring/trajectory physics.
 *
 * sugar-spring outputs NUMERIC trajectories (JSON), not ANSI.
 * Uses sugar-testing's GoldenFile::save()/load() with plain assertEquals
 * file equality on the JSON representation — there is no single
 * `assertGolden` helper on `SugarCraft\Testing\Assertions`; the assertGolden*
 * family there is ANSI/cell-grid specific.
 *
 * M-G (review 1102): the fixtures are byte-comparisons, so the comparison
 * environment is pinned HERE, not inherited:
 *  - REDUCE_MOTION / PREFERS_REDUCED_MOTION are cleared for the duration —
 *    Spring::fromPreset() consults the ambient probe, so a host (or an a11y
 *    CI contributor) exporting REDUCE_MOTION=1 used to snap the spring golden
 *    to 100 from step 1 and redden the suite with no code change.
 *  - serialize_precision is forced to -1 (shortest round-trip): the same ini
 *    set to 17 makes json_encode(0.3) emit 0.29999999999999999 and BOTH
 *    goldens fail.
 * Remaining caveat (devops N1): last-ULP libm answers (sin/cos/pow) are
 * deterministic per platform; these goldens are recorded on Linux — re-record
 * per platform before the spring trajectory joins the Windows/macOS legs.
 *
 * Mirrors the spring physics of charmbracelet/harmonica.
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    /** @var array<string, string|false> env values to restore after each test */
    private array $savedEnv = [];

    private string|false $savedSerializePrecision = false;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';

        $this->savedSerializePrecision = ini_set('serialize_precision', '-1');

        foreach (['REDUCE_MOTION', 'PREFERS_REDUCED_MOTION'] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        // SPR-09: clearing the env only helps if the process-wide probe memo
        // is cleared with it — byte goldens must never inherit a snap verdict.
        \SugarCraft\Spring\Spring::resetReducedMotionProbe();
    }

    protected function tearDown(): void
    {
        if ($this->savedSerializePrecision !== false) {
            ini_set('serialize_precision', $this->savedSerializePrecision);
        }

        foreach ($this->savedEnv as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        $this->savedEnv = [];
        \SugarCraft\Spring\Spring::resetReducedMotionProbe();
    }

    /**
     * Capture the first 10 steps of a spring trajectory as JSON.
     *
     * A spring starting at pos=0, vel=0, target=100 with Gentle preset
     * should produce a deterministic underdamped oscillation path.
     */
    public function testSpringTrajectoryJson(): void
    {
        $spring = Spring::fromPreset(\SugarCraft\Spring\SpringPreset::Gentle);

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

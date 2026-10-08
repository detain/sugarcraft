<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\ProcRow;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Dash\Plot\ProcRow\ProcGraphTracker;

final class ProcGraphTrackerTest extends TestCase
{
    /** @return array<string, array{float, int}> */
    public static function sampleCases(): array
    {
        return [
            'zero' => [0.0, 0],
            'below threshold rounds to 0' => [0.05, 0],
            'threshold lifts to 5' => [0.1, 5],
            'just under 5 lifts' => [4.99, 5],
            'five' => [5.0, 5],
            'rounds down' => [5.4, 5],
            'rounds half up' => [5.5, 6],
            'near hundred' => [99.5, 100],
            'multi-core' => [250.2, 250],
            'negative' => [-1.0, 0],
        ];
    }

    #[DataProvider('sampleCases')]
    public function testSampleLaw(float $cpu, int $expected): void
    {
        $this->assertSame($expected, ProcGraphTracker::sample($cpu));
    }

    public function testNoGraphUntilCpuAboveZero(): void
    {
        $t = ProcGraphTracker::new();
        $same = $t->observe(7, 0.0);
        $this->assertSame($t, $same);
        $this->assertFalse($same->has(7));
        $this->assertNull($same->graph(7));
        $this->assertNull($same->idleCount(7));
    }

    public function testCreatedOnFirstActivityAndSampled(): void
    {
        $t = ProcGraphTracker::new()->observe(7, 12.4);
        $this->assertTrue($t->has(7));
        $this->assertSame(0, $t->idleCount(7));
        $this->assertSame([12], $t->graph(7)->samples());
        $this->assertSame(5, $t->graph(7)->width());
        $this->assertSame(1, $t->graph(7)->height());
    }

    public function testCpuJustAboveZeroCreatesWithZeroSample(): void
    {
        // btop creates on cpu_p > 0, and round(0.05) feeds a 0.
        $t = ProcGraphTracker::new()->observe(7, 0.05);
        $this->assertTrue($t->has(7));
        $this->assertSame([0], $t->graph(7)->samples());
    }

    public function testDestroyedAfterTenConsecutiveIdleSamples(): void
    {
        $t = ProcGraphTracker::new()->observe(7, 50.0);
        for ($i = 1; $i <= 9; $i++) {
            $t = $t->observe(7, 0.0);
            $this->assertSame($i, $t->idleCount(7));
        }
        $this->assertTrue($t->has(7));
        $this->assertCount(10, $t->graph(7)->samples());

        $t = $t->observe(7, 0.09);
        $this->assertFalse($t->has(7));
        $this->assertNull($t->idleCount(7));
        $this->assertSame([], $t->pids());
    }

    public function testActivityResetsTheIdleRun(): void
    {
        $t = ProcGraphTracker::new()->observe(7, 50.0);
        for ($i = 0; $i < 9; $i++) {
            $t = $t->observe(7, 0.0);
        }
        $t = $t->observe(7, 0.1);
        $this->assertSame(0, $t->idleCount(7));
        for ($i = 0; $i < 9; $i++) {
            $t = $t->observe(7, 0.0);
        }
        $this->assertTrue($t->has(7));
    }

    public function testRecreatedFreshAfterDestroy(): void
    {
        $t = ProcGraphTracker::new()->observe(7, 50.0);
        for ($i = 0; $i < 10; $i++) {
            $t = $t->observe(7, 0.0);
        }
        $t = $t->observe(7, 30.0);
        $this->assertSame([30], $t->graph(7)->samples());
    }

    public function testRetainSweepsDeadPids(): void
    {
        $t = ProcGraphTracker::new()->observe(1, 5.0)->observe(2, 5.0)->observe(3, 5.0);
        $kept = $t->retain([3, 1, 99]);
        $this->assertSame([1, 3], $kept->pids());
        $this->assertSame(2, $kept->count());
        $this->assertNull($kept->idleCount(2));
        $this->assertSame(3, $t->count(), 'original untouched');
    }

    public function testObserveIsImmutable(): void
    {
        $a = ProcGraphTracker::new()->observe(1, 20.0);
        $b = $a->observe(1, 40.0);
        $this->assertSame([20], $a->graph(1)->samples());
        $this->assertSame([20, 40], $b->graph(1)->samples());
    }

    public function testColdStartGraphGlyphs(): void
    {
        $t = ProcGraphTracker::new();
        foreach ([10.0, 40.0, 80.0, 100.0, 0.0] as $cpu) {
            $t = $t->observe(7, $cpu);
        }
        // bands (round(v*4/100 + 0.3)): 10→1, 40→2, 80→4, 100→4, 0→0;
        // five samples fill 3 cells pairing (0,1) (2,4) (4,0) after two
        // transparent pad cells.
        $this->assertSame('  ⢀⣼⡇', $t->graph(7)->render(ColorProfile::NoTty));
    }

    public function testFamilyAndWidthAreCarried(): void
    {
        $t = ProcGraphTracker::new(8, DualSampleGraph::FAMILY_TTY)->observe(1, 50.0);
        $this->assertSame(8, $t->width());
        $this->assertSame('tty', $t->family());
        $this->assertSame('tty', $t->graph(1)->family());
        $this->assertSame(8, $t->graph(1)->width());
    }

    public function testRejectsBadConfigAndNonFiniteCpu(): void
    {
        try {
            ProcGraphTracker::new(0);
            $this->fail('width 0 accepted');
        } catch (\InvalidArgumentException) {
        }
        try {
            ProcGraphTracker::new(5, 'dots');
            $this->fail('unknown family accepted');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        ProcGraphTracker::new()->observe(1, NAN);
    }
}

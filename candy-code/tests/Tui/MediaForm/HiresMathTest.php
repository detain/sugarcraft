<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\MediaForm\HiresMath;

/**
 * §2.4 hires target resolution, branch by branch (crush-media W3.5). The
 * figures below are the arithmetic of A1111's calculate_target_resolution —
 * int() truncation reproduced as floor on positive products — pinned so the
 * preview row and the server agree on the same odd numbers.
 */
final class HiresMathTest extends TestCase
{
    /** @return list<array{0:int,1:int,2:?float,3:?int,4:?int,5:int,6:int,7:string}> */
    public static function branchTable(): array
    {
        return [
            // Rule 1: both resize dims unset → pure scale, floored per axis.
            [512, 512, 2.0, 0, 0, 1024, 1024, 'scale x2'],
            [512, 1024, 1.7, null, null, 870, 1740, 'the 870.4 / 1740.8 floor oddity'],
            [512, 512, null, 0, 0, 512, 512, 'no scale, no resize = honest identity'],
            [768, 512, 1.25, 0, 0, 960, 640, 'exact quarters stay exact'],
            // Rule 2: one dim set → factor from that axis, BOTH floored
            // through it (the set dim itself can shift - upstream math kept).
            [512, 768, 2.0, 1024, 0, 1024, 1536, 'width set, height follows aspect'],
            [512, 768, 2.0, 0, 1536, 1024, 1536, 'height set, width follows'],
            [512, 512, 2.0, 700, 0, 700, 700, 'scale is ignored once a dim is set'],
            [512, 1000, 2.0, 768, 0, 768, 1500, 'non-dividing factor floors both axes (1500.0)'],
            // Rule 3: both set → max ratio covers the box, server crops.
            [512, 512, null, 870, 700, 870, 870, 'wider box wins, height crops'],
            [512, 1024, null, 900, 2048, 1024, 2048, 'taller box wins, width crops'],
            [512, 512, null, 512, 512, 512, 512, 'box equal to source is a no-op'],
        ];
    }

    /** @dataProvider branchTable */
    public function testTheTargetResolutionFollowsTheSectionTwentyPointFourRules(
        int $width,
        int $height,
        ?float $scale,
        ?int $resizeX,
        ?int $resizeY,
        int $expectedWidth,
        int $expectedHeight,
        string $case,
    ): void {
        $this->assertSame(
            [$expectedWidth, $expectedHeight],
            HiresMath::target($width, $height, $scale, $resizeX, $resizeY),
            $case,
        );
    }
}

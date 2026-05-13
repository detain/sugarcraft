<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Ladder;

final class LadderTest extends TestCase
{
    public function testNewCreatesLadder(): void
    {
        $ladder = Ladder::new([
            ['label' => 'Step 1', 'status' => Ladder::StatusComplete],
            ['label' => 'Step 2', 'status' => Ladder::StatusCurrent],
            ['label' => 'Step 3', 'status' => Ladder::StatusPending],
        ]);
        $this->assertNotNull($ladder);
    }

    public function testHorizontalCreatesHorizontalLadder(): void
    {
        $ladder = Ladder::horizontal([
            ['label' => 'A'],
            ['label' => 'B'],
        ]);
        $this->assertNotNull($ladder);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $ladder = Ladder::new([
            ['label' => 'Step 1'],
            ['label' => 'Step 2'],
        ]);
        $this->assertNotSame('', $ladder->render());
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $ladder = Ladder::new([
            ['label' => 'Step 1'],
        ]);
        [$width, $height] = $ladder->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithHorizontalReturnsNewInstance(): void
    {
        $ladder = Ladder::new([['label' => 'A']]);
        $newLadder = $ladder->withHorizontal(true);
        $this->assertNotSame($ladder, $newLadder);
    }

    public function testWithCompleteColorReturnsNewInstance(): void
    {
        $ladder = Ladder::new([['label' => 'A']]);
        $newLadder = $ladder->withCompleteColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertNotSame($ladder, $newLadder);
    }

    public function testEmptyStepsReturnsEmpty(): void
    {
        $ladder = Ladder::new([]);
        $this->assertSame('', $ladder->render());
    }

    public function testRenderContainsStepLabels(): void
    {
        $ladder = Ladder::new([
            ['label' => 'Start'],
            ['label' => 'Middle'],
            ['label' => 'End'],
        ]);
        $rendered = $ladder->render();
        $this->assertStringContainsString('Start', $rendered);
        $this->assertStringContainsString('Middle', $rendered);
        $this->assertStringContainsString('End', $rendered);
    }
}

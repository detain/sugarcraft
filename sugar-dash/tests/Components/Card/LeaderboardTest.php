<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Card\Leaderboard;

final class LeaderboardTest extends TestCase
{
    public function testNewCreatesLeaderboard(): void
    {
        $leaderboard = Leaderboard::new([
            ['label' => 'Alice', 'value' => 100],
            ['label' => 'Bob', 'value' => 200],
        ]);
        $this->assertNotNull($leaderboard);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $leaderboard = Leaderboard::new([
            ['label' => 'Alice', 'value' => 100],
        ]);
        $rendered = $leaderboard->render();
        $this->assertNotSame('', $rendered);
    }

    public function testSampleCreatesLeaderboard(): void
    {
        $leaderboard = Leaderboard::sample();
        $rendered = $leaderboard->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $leaderboard = Leaderboard::new([
            ['label' => 'Alice', 'value' => 100],
        ]);
        [$width, $height] = $leaderboard->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithRankStyleReturnsNewInstance(): void
    {
        $leaderboard = Leaderboard::new([['label' => 'A', 'value' => 1]]);
        $newLeaderboard = $leaderboard->withRankStyle('medal');
        $this->assertNotSame($leaderboard, $newLeaderboard);
    }

    public function testWithTopHighlightReturnsNewInstance(): void
    {
        $leaderboard = Leaderboard::new([['label' => 'A', 'value' => 1]]);
        $newLeaderboard = $leaderboard->withTopHighlight(5);
        $this->assertNotSame($leaderboard, $newLeaderboard);
    }

    public function testWithValueFormatReturnsNewInstance(): void
    {
        $leaderboard = Leaderboard::new([['label' => 'A', 'value' => 1]]);
        $newLeaderboard = $leaderboard->withValueFormat('currency');
        $this->assertNotSame($leaderboard, $newLeaderboard);
    }

    public function testWithShowValueReturnsNewInstance(): void
    {
        $leaderboard = Leaderboard::new([['label' => 'A', 'value' => 1]]);
        $newLeaderboard = $leaderboard->withShowValue(false);
        $this->assertNotSame($leaderboard, $newLeaderboard);
    }
}

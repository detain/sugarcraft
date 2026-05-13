<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\WordCloud;

final class WordCloudTest extends TestCase
{
    public function testNewCreatesWordCloud(): void
    {
        $cloud = WordCloud::new([
            ['word' => 'PHP', 'weight' => 10],
            ['word' => 'TUI', 'weight' => 8],
        ]);
        $this->assertNotNull($cloud);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $cloud = WordCloud::new([
            ['word' => 'PHP', 'weight' => 10],
            ['word' => 'TUI', 'weight' => 8],
        ]);
        $this->assertNotSame('', $cloud->render());
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $cloud = WordCloud::new([
            ['word' => 'PHP', 'weight' => 10],
        ]);
        [$width, $height] = $cloud->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithMaxWordsReturnsNewInstance(): void
    {
        $cloud = WordCloud::new([['word' => 'A', 'weight' => 1]]);
        $newCloud = $cloud->withMaxWords(5);
        $this->assertNotSame($cloud, $newCloud);
    }

    public function testWithShuffleReturnsNewInstance(): void
    {
        $cloud = WordCloud::new([['word' => 'A', 'weight' => 1]]);
        $newCloud = $cloud->withShuffle(false);
        $this->assertNotSame($cloud, $newCloud);
    }

    public function testWithShowWeightsReturnsNewInstance(): void
    {
        $cloud = WordCloud::new([['word' => 'A', 'weight' => 1]]);
        $newCloud = $cloud->withShowWeights(true);
        $this->assertNotSame($cloud, $newCloud);
    }

    public function testEmptyWordsReturnsEmpty(): void
    {
        $cloud = WordCloud::new([]);
        $this->assertSame('', $cloud->render());
    }

    public function testRenderContainsWords(): void
    {
        $cloud = WordCloud::new([
            ['word' => 'PHP', 'weight' => 10],
            ['word' => 'TUI', 'weight' => 8],
        ]);
        $rendered = $cloud->render();
        $this->assertStringContainsString('PHP', $rendered);
        $this->assertStringContainsString('TUI', $rendered);
    }

    public function testMaxWordsLimitsDisplay(): void
    {
        $words = [];
        for ($i = 0; $i < 30; $i++) {
            $words[] = ['word' => 'Word' . $i, 'weight' => $i];
        }
        $cloud = WordCloud::new($words)->withMaxWords(5);
        $rendered = $cloud->render();
        // Should contain some words
        $this->assertNotSame('', $rendered);
    }
}

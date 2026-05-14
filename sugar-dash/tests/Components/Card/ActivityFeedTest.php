<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Card\ActivityFeed;
use SugarCraft\Dash\Components\Card\ActivityEvent;

final class ActivityFeedTest extends TestCase
{
    public function testNewCreatesActivityFeed(): void
    {
        $feed = ActivityFeed::new([]);
        $this->assertNotNull($feed);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $feed = ActivityFeed::new([
            new ActivityEvent('Alice', 'pushed to', 'main', 'push', '2 min ago'),
        ]);
        $rendered = $feed->render();
        $this->assertNotSame('', $rendered);
    }

    public function testSampleCreatesActivityFeed(): void
    {
        $feed = ActivityFeed::sample();
        $rendered = $feed->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $feed = ActivityFeed::new([
            new ActivityEvent('Alice', 'pushed to', 'main', 'push', '2 min ago'),
        ]);
        [$width, $height] = $feed->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithEventsReturnsNewInstance(): void
    {
        $feed = ActivityFeed::new([]);
        $event = new ActivityEvent('Alice', 'pushed to', 'main', 'push');
        $newFeed = $feed->withEvents([$event]);
        $this->assertNotSame($feed, $newFeed);
    }

    public function testWithShowIconsReturnsNewInstance(): void
    {
        $feed = ActivityFeed::new([]);
        $newFeed = $feed->withShowIcons(false);
        $this->assertNotSame($feed, $newFeed);
    }

    public function testWithShowTimestampsReturnsNewInstance(): void
    {
        $feed = ActivityFeed::new([]);
        $newFeed = $feed->withShowTimestamps(false);
        $this->assertNotSame($feed, $newFeed);
    }

    public function testWithMaxItemsReturnsNewInstance(): void
    {
        $feed = ActivityFeed::new([]);
        $newFeed = $feed->withMaxItems(5);
        $this->assertNotSame($feed, $newFeed);
    }

    public function testEmptyEventsRendersEmpty(): void
    {
        $feed = ActivityFeed::new([]);
        $rendered = $feed->render();
        $this->assertSame('', $rendered);
    }
}

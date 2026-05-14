<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Tree\TimelineViz;
use SugarCraft\Dash\Components\Tree\TimelineEvent;

final class TimelineVizTest extends TestCase
{
    public function testNewCreatesTimeline(): void
    {
        $timeline = TimelineViz::new([]);
        $this->assertNotNull($timeline);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $timeline = TimelineViz::new([
            new TimelineEvent('09:00', 'Meeting', 'Team standup', 'meeting'),
        ]);
        $rendered = $timeline->render();
        $this->assertNotSame('', $rendered);
    }

    public function testSampleCreatesTimeline(): void
    {
        $timeline = TimelineViz::sample();
        $rendered = $timeline->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $timeline = TimelineViz::new([
            new TimelineEvent('09:00', 'Meeting', null, 'meeting'),
        ]);
        [$width, $height] = $timeline->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithEventsReturnsNewInstance(): void
    {
        $timeline = TimelineViz::new([]);
        $event = new TimelineEvent('09:00', 'Meeting', null, 'meeting');
        $newTimeline = $timeline->withEvents([$event]);
        $this->assertNotSame($timeline, $newTimeline);
    }

    public function testWithShowMarkersReturnsNewInstance(): void
    {
        $timeline = TimelineViz::new([]);
        $newTimeline = $timeline->withShowMarkers(false);
        $this->assertNotSame($timeline, $newTimeline);
    }

    public function testWithShowDescriptionsReturnsNewInstance(): void
    {
        $timeline = TimelineViz::new([]);
        $newTimeline = $timeline->withShowDescriptions(false);
        $this->assertNotSame($timeline, $newTimeline);
    }

    public function testEmptyEventsRendersEmpty(): void
    {
        $timeline = TimelineViz::new([]);
        $rendered = $timeline->render();
        $this->assertSame('', $rendered);
    }
}

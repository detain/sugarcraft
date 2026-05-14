<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Tree\Timeline;
use SugarCraft\Dash\Layout\HAlign;

final class TimelineTest extends TestCase
{
    public function testNewCreatesTimeline(): void
    {
        $timeline = Timeline::new([
            ['time' => '10:00', 'title' => 'Event 1'],
            ['time' => '11:00', 'title' => 'Event 2'],
        ]);

        $this->assertNotNull($timeline);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $timeline = Timeline::new([
            ['time' => '10:00', 'title' => 'Event 1'],
            ['time' => '11:00', 'title' => 'Event 2'],
        ]);

        $rendered = $timeline->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $timeline = Timeline::new([
            ['time' => '10:00', 'title' => 'Event 1'],
        ]);

        [$width, $height] = $timeline->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithLineStyleReturnsNewInstance(): void
    {
        $timeline = Timeline::new([
            ['time' => '10:00', 'title' => 'Event 1'],
        ]);

        $newTimeline = $timeline->withLineStyle('dashed');
        $this->assertNotSame($timeline, $newTimeline);
    }

    public function testWithAlignReturnsNewInstance(): void
    {
        $timeline = Timeline::new([
            ['time' => '10:00', 'title' => 'Event 1'],
        ]);

        $newTimeline = $timeline->withAlign(HAlign::Right);
        $this->assertNotSame($timeline, $newTimeline);
    }

    public function testWithShowDescriptionsReturnsNewInstance(): void
    {
        $timeline = Timeline::new([
            ['time' => '10:00', 'title' => 'Event 1', 'description' => 'Details'],
        ]);

        $newTimeline = $timeline->withShowDescriptions(false);
        $this->assertNotSame($timeline, $newTimeline);
    }

    public function testWithShowIconsReturnsNewInstance(): void
    {
        $timeline = Timeline::new([
            ['time' => '10:00', 'title' => 'Event 1'],
        ]);

        $newTimeline = $timeline->withShowIcons(false);
        $this->assertNotSame($timeline, $newTimeline);
    }

    public function testWithReverseReturnsNewInstance(): void
    {
        $timeline = Timeline::new([
            ['time' => '10:00', 'title' => 'Event 1'],
            ['time' => '11:00', 'title' => 'Event 2'],
        ]);

        $newTimeline = $timeline->withReverse(true);
        $this->assertNotSame($timeline, $newTimeline);
    }

    public function testEmptyEventsReturnsEmpty(): void
    {
        $timeline = Timeline::new([]);
        $this->assertSame('', $timeline->render());
    }

    public function testEventTypesProduceDifferentIcons(): void
    {
        $timeline = Timeline::new([
            ['time' => '10:00', 'title' => 'Info', 'type' => Timeline::TypeInfo],
            ['time' => '11:00', 'title' => 'Success', 'type' => Timeline::TypeSuccess],
            ['time' => '12:00', 'title' => 'Warning', 'type' => Timeline::TypeWarning],
            ['time' => '13:00', 'title' => 'Error', 'type' => Timeline::TypeError],
        ]);

        $rendered = $timeline->render();
        $this->assertNotSame('', $rendered);
        // Verify different icons appear
        $this->assertStringContainsString('●', $rendered);
        $this->assertStringContainsString('✓', $rendered);
        $this->assertStringContainsString('⚠', $rendered);
        $this->assertStringContainsString('✗', $rendered);
    }
}
<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Matcher;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Matcher\PassthroughMatcher;

final class PassthroughMatcherTest extends TestCase
{
    private PassthroughMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new PassthroughMatcher();
    }

    public function testSameKindMatchesRegardlessOfTimestamp(): void
    {
        $recorded = new Event(0.0, EventKind::Output, ['b' => 'hello']);
        $actual = new Event(10.5, EventKind::Output, ['b' => 'hello']);

        $this->assertTrue($this->matcher->matches($recorded, $actual));
    }

    public function testSameKindMatchesRegardlessOfPayload(): void
    {
        $recorded = new Event(0.0, EventKind::Resize, ['cols' => 80, 'rows' => 24]);
        $actual = new Event(0.0, EventKind::Resize, ['cols' => 200, 'rows' => 60]);

        $this->assertTrue($this->matcher->matches($recorded, $actual));
    }

    public function testDifferentKindDoesNotMatch(): void
    {
        $recorded = new Event(0.0, EventKind::Input, ['b' => 'a']);
        $actual = new Event(0.0, EventKind::Output, ['b' => 'a']);

        $this->assertFalse($this->matcher->matches($recorded, $actual));
    }

    public function testResizeVsQuitDoesNotMatch(): void
    {
        $recorded = new Event(0.0, EventKind::Resize, ['cols' => 80, 'rows' => 24]);
        $actual = new Event(0.0, EventKind::Quit, []);

        $this->assertFalse($this->matcher->matches($recorded, $actual));
    }

    public function testInputVsOutputDoesNotMatch(): void
    {
        $recorded = new Event(0.0, EventKind::Input, ['msg' => ['@type' => 'KeyMsg']]);
        $actual = new Event(0.0, EventKind::Output, ['b' => 'output']);

        $this->assertFalse($this->matcher->matches($recorded, $actual));
    }

    public function testAllFourKindsCanMatch(): void
    {
        foreach (EventKind::cases() as $kind) {
            $recorded = new Event(0.0, $kind, ['test' => 'data']);
            $actual = new Event(999.0, $kind, ['different' => 'payload']);

            $this->assertTrue($this->matcher->matches($recorded, $actual), "Kind {$kind->value} should match");
        }
    }

    public function testEmptyPayloadsMatch(): void
    {
        // Quit events have empty payloads
        $recorded = new Event(0.0, EventKind::Quit, []);
        $actual = new Event(0.0, EventKind::Quit, []);

        $this->assertTrue($this->matcher->matches($recorded, $actual));
    }
}

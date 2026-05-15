<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Matcher;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Matcher\ContentMatcher;

final class ContentMatcherTest extends TestCase
{
    private ContentMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new ContentMatcher();
    }

    public function testIdenticalEventsMatch(): void
    {
        $event = new Event(0.5, EventKind::Output, ['b' => 'hello']);

        $this->assertTrue($this->matcher->matches($event, $event));
    }

    public function testSameKindAndPayloadMatch(): void
    {
        $recorded = new Event(0.0, EventKind::Resize, ['cols' => 80, 'rows' => 24]);
        $actual = new Event(10.0, EventKind::Resize, ['cols' => 80, 'rows' => 24]);

        $this->assertTrue($this->matcher->matches($recorded, $actual));
    }

    public function testDifferentPayloadDoesNotMatch(): void
    {
        $recorded = new Event(0.0, EventKind::Resize, ['cols' => 80, 'rows' => 24]);
        $actual = new Event(0.0, EventKind::Resize, ['cols' => 120, 'rows' => 40]);

        $this->assertFalse($this->matcher->matches($recorded, $actual));
    }

    public function testDifferentKindDoesNotMatch(): void
    {
        $recorded = new Event(0.0, EventKind::Input, ['b' => 'a']);
        $actual = new Event(0.0, EventKind::Output, ['b' => 'a']);

        $this->assertFalse($this->matcher->matches($recorded, $actual));
    }

    public function testTimestampDifferenceIgnored(): void
    {
        $recorded = new Event(1.0, EventKind::Output, ['b' => 'hello']);
        $actual = new Event(999.0, EventKind::Output, ['b' => 'hello']);

        $this->assertTrue($this->matcher->matches($recorded, $actual));
    }

    public function testEmptyPayloadMatches(): void
    {
        // Quit events have empty payloads
        $recorded = new Event(0.0, EventKind::Quit, []);
        $actual = new Event(0.0, EventKind::Quit, []);

        $this->assertTrue($this->matcher->matches($recorded, $actual));
    }

    public function testEmptyPayloadDoesNotMatchNonEmpty(): void
    {
        $recorded = new Event(0.0, EventKind::Quit, []);
        $actual = new Event(0.0, EventKind::Quit, ['unexpected' => 'data']);

        $this->assertFalse($this->matcher->matches($recorded, $actual));
    }

    public function testInputMsgPayloadMustMatchExactly(): void
    {
        $recorded = new Event(0.0, EventKind::Input, [
            'msg' => ['@type' => 'KeyMsg', 'key' => 'j'],
        ]);
        $actual = new Event(0.0, EventKind::Input, [
            'msg' => ['@type' => 'KeyMsg', 'key' => 'k'],
        ]);

        $this->assertFalse($this->matcher->matches($recorded, $actual));
    }

    public function testInputBytePayloadMustMatchExactly(): void
    {
        $recorded = new Event(0.0, EventKind::Input, ['b' => 'abc']);
        $actual = new Event(0.0, EventKind::Input, ['b' => 'def']);

        $this->assertFalse($this->matcher->matches($recorded, $actual));
    }

    public function testOutputBytePayloadMustMatchExactly(): void
    {
        $recorded = new Event(0.0, EventKind::Output, ['b' => "\x1b[1mhelloworld\x1b[0m"]);
        $actual = new Event(0.0, EventKind::Output, ['b' => "\x1b[1mHELLOWORLD\x1b[0m"]);

        $this->assertFalse($this->matcher->matches($recorded, $actual));
    }

    public function testArrayOrderingMatters(): void
    {
        $recorded = new Event(0.0, EventKind::Resize, ['cols' => 80, 'rows' => 24]);
        $actual = new Event(0.0, EventKind::Resize, ['rows' => 24, 'cols' => 80]);

        // PHP associative arrays preserve insertion order
        $this->assertFalse($this->matcher->matches($recorded, $actual));
    }

    public function testAllKindsCanBeMatched(): void
    {
        foreach (EventKind::cases() as $kind) {
            $recorded = new Event(0.0, $kind, ['test' => 'data']);
            $actual = new Event(0.0, $kind, ['test' => 'data']);

            $this->assertTrue($this->matcher->matches($recorded, $actual), "Kind {$kind->value} should match identical payload");
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Matcher;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Matcher\EventMatcher;
use SugarCraft\Vcr\Matcher\PassthroughMatcher;

/**
 * @implements EventMatcher
 */
final class StubEventMatcher implements EventMatcher
{
    public function __construct(private readonly bool $result) {}

    public function matches(Event $recorded, Event $actual): bool
    {
        return $this->result;
    }
}

final class EventMatcherTest extends TestCase
{
    public function testPassthroughMatcherIsInstanceOfEventMatcher(): void
    {
        $matcher = new PassthroughMatcher();
        $this->assertInstanceOf(EventMatcher::class, $matcher);
    }

    public function testStubMatcherReturnsConfiguredResult(): void
    {
        $trueStub = new StubEventMatcher(true);
        $falseStub = new StubEventMatcher(false);

        $event = new Event(0.0, EventKind::Quit, []);

        $this->assertTrue($trueStub->matches($event, $event));
        $this->assertFalse($falseStub->matches($event, $event));
    }

    public function testEventMatcherInterfaceContract(): void
    {
        // Verify the interface contract: matches() takes two Events and returns bool
        $matcher = new PassthroughMatcher();

        $resizeA = new Event(0.1, EventKind::Resize, ['cols' => 80, 'rows' => 24]);
        $resizeB = new Event(0.2, EventKind::Resize, ['cols' => 120, 'rows' => 40]);

        // PassthroughMatcher only checks kind
        $this->assertTrue($matcher->matches($resizeA, $resizeB));
    }
}

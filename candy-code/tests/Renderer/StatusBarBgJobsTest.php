<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Message;

/**
 * The status bar's background-jobs segment (#16): "N bg" appears while jobs
 * run, is absent at zero, and is dropped rather than cut when the row is too
 * narrow to hold it.
 */
final class StatusBarBgJobsTest extends TestCase
{
    protected function tearDown(): void
    {
        Renderer::setBackgroundJobCount(0);
        parent::tearDown();
    }

    private function chat(int $cols): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
        ))->withSize($cols, 30);
    }

    private function bar(Chat $chat): string
    {
        $bar = (new \ReflectionMethod(Renderer::class, 'renderStatusBar'))
            ->invoke(null, $chat, Renderer::publishedFrame());

        return (string) (new \ReflectionMethod(Renderer::class, 'stripZoneMarkers'))->invoke(null, $bar);
    }

    public function testZeroJobsDrawsNoSegment(): void
    {
        Renderer::setBackgroundJobCount(0);

        $this->assertStringNotContainsString(' bg', $this->bar($this->chat(100)));
    }

    public function testTwoJobsDrawsTheSegmentWhenItFits(): void
    {
        Renderer::setBackgroundJobCount(2);

        $this->assertStringContainsString('2 bg', $this->bar($this->chat(100)));
    }

    public function testTheSegmentIsDroppedAtANarrowWidthAndTheBarStillFits(): void
    {
        Renderer::setBackgroundJobCount(2);

        $bar = $this->bar($this->chat(20));

        $this->assertStringNotContainsString('bg', $bar);
        $this->assertLessThanOrEqual(20, Width::of($bar));
    }
}

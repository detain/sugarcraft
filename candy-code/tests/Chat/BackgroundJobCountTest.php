<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Renderer;

/**
 * #16 wiring: each paint sets the status bar's background-job count from the
 * chat, and resets it after, so a count never outlives the frame that owned it.
 */
final class BackgroundJobCountTest extends TestCase
{
    protected function tearDown(): void
    {
        Renderer::setBackgroundJobCount(0);
        parent::tearDown();
    }

    public function testAChatWithNoBackgroundWorkCountsZero(): void
    {
        $chat = new Chat(history: [Message::user('hi')], backend: new EchoBackend());

        self::assertSame(0, $chat->backgroundJobCount());
    }

    public function testAPaintSetsTheCountAndLeavesNoneBehind(): void
    {
        $chat = new Chat(history: [Message::user('hi')], backend: new EchoBackend(), rows: 30, cols: 100);
        Renderer::setBackgroundJobCount(5);

        $chat->view();

        self::assertSame(0, Renderer::publishedFrame()->backgroundJobCount(), 'the paint overwrites the stale count and resets it');
    }
}

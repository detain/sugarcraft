<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Renderer;

/**
 * The status bar marks a context figure whose window the backend did not
 * report, and leaves a measured window unmarked. The mark is a trailing
 * segment on the bar's fit rule, so it never displaces the segments beside it.
 */
final class ContextAssumedLabelTest extends TestCase
{
    public function testAnAssumedWindowIsLabelledOnTheBar(): void
    {
        $bar = $this->bar($this->assumedChat(120));

        self::assertStringContainsString(Lang::t('tui.status.context_assumed'), $bar);
    }

    public function testAWindowTheBackendReportsIsNotLabelled(): void
    {
        $bar = $this->bar($this->assumedChat(120, new class implements \SugarCraft\Code\Backend, \SugarCraft\Code\Backend\ReportsContextWindow {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return (new EchoBackend())->complete($history, $onToken, $onEvent);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return (new EchoBackend())->completeAsync($history, $onToken, $cancellation, $onEvent);
            }

            public function contextWindow(): int
            {
                return 100_000;
            }
        }));

        self::assertStringNotContainsString(Lang::t('tui.status.context_assumed'), $bar);
    }

    private function assumedChat(int $cols, ?\SugarCraft\Code\Backend $backend = null): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: $backend ?? new EchoBackend(),
        ))->withSize($cols, 30);
    }

    private function bar(Chat $chat): string
    {
        $bar = (new \ReflectionMethod(Renderer::class, 'renderStatusBar'))
            ->invoke(null, $chat, Renderer::publishedFrame());

        return (string) (new \ReflectionMethod(Renderer::class, 'stripZoneMarkers'))->invoke(null, $bar);
    }
}

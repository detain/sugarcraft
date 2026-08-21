<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Role;

/**
 * F1's seam, at the Chat end: the launch warnings a session has to be able to
 * READ, rather than the ones it merely printed before the alternate screen
 * opened over them.
 *
 * WHY THE TRANSCRIPT AND NOT A BANNER. {@see Renderer} already wraps, scrolls
 * and paints {@see Role::System} rows at every width — the `/compact` report and
 * the background-session status notices are the same shape — so the rendered
 * assertion below is not testing a new surface, it is testing that the notice
 * was handed to the surface that already works. The evidence that a USER can see
 * it is a captured pty launch, not this file; see
 * {@see \SugarCraft\Crush\Tests\Cli\BootstrapToolAndPermissionSettingsTest}'s F1
 * block for what a unit test can and cannot claim here.
 */
final class LaunchNoticesTest extends TestCase
{
    public function testANoticeBecomesASystemRowAtTheEndOfTheTranscript(): void
    {
        $chat = (new Chat(history: [Message::user('hello')]))
            ->withLaunchNotices(['settings.json (disabledTools) left you Bash']);

        self::assertCount(2, $chat->history);
        self::assertSame(Role::User, $chat->history[0]->role);
        self::assertSame(Role::System, $chat->history[1]->role);
        self::assertSame('settings.json (disabledTools) left you Bash', $chat->history[1]->content);
    }

    /**
     * Several notices keep the order they were raised in, because that order is
     * the order the launch did things in and a reader reconstructing what
     * happened has nothing else to go on.
     */
    public function testEveryNoticeIsKeptInTheOrderItWasRaised(): void
    {
        $chat = (new Chat())->withLaunchNotices(['first', 'second', 'third']);

        self::assertSame(
            ['first', 'second', 'third'],
            array_map(static fn ($m): string => $m->content, $chat->history),
        );
    }

    /**
     * THE SAME INSTANCE for the common launch, which is the one with nothing to
     * report. A clone would be indistinguishable in behaviour and would make
     * `$chat` a different object for no reason — and it is the identity, not the
     * emptiness, that says the seam is inert when unused.
     */
    public function testALaunchWithNoNoticesGetsBackTheSameChat(): void
    {
        $chat = new Chat();

        self::assertSame($chat, $chat->withLaunchNotices([]));
    }

    /**
     * A blank notice is DROPPED rather than rendered, and it is worth pinning
     * because the failure it prevents is silent: an empty
     * {@see Message::system()} paints as a bare `system:` label with nothing
     * under it, which reads as a bug in the transcript rather than as a warning
     * nobody wrote.
     */
    public function testABlankNoticeIsDroppedRatherThanRenderedEmpty(): void
    {
        $chat = new Chat();

        self::assertSame($chat, $chat->withLaunchNotices(['', '   ', "\n"]));
    }

    /**
     * …and the end of it: the sentence reaches the RENDERED frame, not just the
     * history array. This is the closest a unit test gets to F1's requirement,
     * and it is still one step short of it — see the class doc-block.
     */
    public function testTheNoticeReachesTheRenderedFrame(): void
    {
        $chat = (new Chat())
            ->withLaunchNotices(['project settings disabled 10 of the 11 tools — leaving: Bash'])
            ->withSize(100, 30);

        self::assertStringContainsString('leaving: Bash', Renderer::renderView($chat)->body);
    }
}

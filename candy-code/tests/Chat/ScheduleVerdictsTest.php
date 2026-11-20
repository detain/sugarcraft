<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Automation\WebhookEvent;
use SugarCraft\Code\Chat\ScheduleVerdicts;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;

/**
 * The verdict lines a schedule fire earns (AUT-1, C-AGT-5.f): a launch and a
 * refused spawn are announced in the transcript and to the webhook; a skip is
 * quiet on both surfaces.
 */
final class ScheduleVerdictsTest extends TestCase
{
    public function testALaunchIsAnnouncedInTheTranscriptAndToTheWebhook(): void
    {
        $events = [];
        $notices = ScheduleVerdicts::announce(
            [['name' => 'nightly', 'action' => 'fired', 'sessionId' => 'sess_1']],
            static function (WebhookEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        self::assertCount(1, $notices);
        self::assertInstanceOf(Message::class, $notices[0]);
        self::assertSame(Lang::t('chat.schedule.fired', ['name' => 'nightly']), $notices[0]->content);
        self::assertCount(1, $events, 'the launch reaches the outbound webhook once');
    }

    public function testARefusedSpawnNamesItsErrorInTheTranscriptAndReachesTheWebhook(): void
    {
        $events = [];
        $notices = ScheduleVerdicts::announce(
            [['name' => 'nightly', 'action' => 'error', 'error' => 'the daemon never authenticated']],
            static function (WebhookEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        self::assertSame(
            Lang::t('chat.schedule.spawn_failed', ['name' => 'nightly', 'error' => 'the daemon never authenticated']),
            $notices[0]->content,
        );
        self::assertCount(1, $events);
    }

    public function testASkipIsQuietOnBothSurfaces(): void
    {
        $events = [];
        $notices = ScheduleVerdicts::announce(
            [['name' => 'busy', 'action' => 'skipped-busy', 'sessionId' => 'sess_0'], ['name' => 'foreign', 'action' => 'skipped-foreign']],
            static function (WebhookEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        self::assertSame([], $notices);
        self::assertSame([], $events);
    }
}

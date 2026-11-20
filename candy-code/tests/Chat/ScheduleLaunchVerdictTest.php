<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\BackgroundTickMsg;
use SugarCraft\Code\App\ScheduleFiredMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\Tests\Sessions\StoppableDaemonFixtureTrait;
use SugarCraft\Code\Tests\Support\SupervisorPump;

/**
 * P4-12 CC-1 row f on the TUI pump: a persisted row whose launch is still
 * waiting on its daemon leaves no verdict in the tick that fired it. The tick
 * returns the Cmd that will deliver the verdict as a {@see ScheduleFiredMsg},
 * and that message is what writes the transcript line.
 */
final class ScheduleLaunchVerdictTest extends TestCase
{
    use StoppableDaemonFixtureTrait;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['proc_open', 'pcntl_fork', 'posix_setsid', 'stream_socket_server'] as $fn) {
            if (!\function_exists($fn)) {
                self::markTestSkipped("{$fn}() unavailable");
            }
        }
        $this->setUpDaemonFixture();
        $this->root = (string) \realpath($this->fixtureHome);
        \mkdir($this->root . '/.candy-code', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->tearDownDaemonFixture();
        parent::tearDown();
    }

    public function testALaunchStillWaitingOnItsDaemonLeavesItsVerdictForALaterMessage(): void
    {
        \putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; exec sleep 30');
        $supervisor = new BackgroundSupervisor();
        $registry = ScheduleRegistry::atPath($this->root . '/.candy-code/schedules.json', $this->root);
        $registry->add(new Schedule(name: 'nightly', everySeconds: 3_600, prompt: 'run the sweep', nextDue: \time() - 1, root: $this->root));
        $chat = new Chat(backgroundSupervisor: $supervisor, scheduleRegistry: $registry);

        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        self::assertNotNull($cmd, 'a launch still pending delivers its verdict through a Cmd');
        self::assertNotContains(
            Lang::t('chat.schedule.fired', ['name' => 'nightly']),
            self::contents($next),
            'the tick writes no verdict it does not have',
        );

        self::assertTrue(SupervisorPump::until($supervisor, static fn (): bool => $registry->busySession('nightly') !== null));
        $sessionId = (string) $registry->busySession('nightly');
        $ipc = $this->ipcOf($supervisor, $sessionId);
        $this->fixtureDaemons[] = ['pid' => $ipc['pid'], 'startTime' => $ipc['startTime'] ?? null];

        [$landed] = $next->update(new ScheduleFiredMsg([['name' => 'nightly', 'action' => 'fired', 'sessionId' => $sessionId]]));

        self::assertContains(
            Lang::t('chat.schedule.fired', ['name' => 'nightly']),
            self::contents($landed),
            'the late verdict lands in the transcript',
        );
    }

    public function testAVerdictThatDecidesNothingLeavesTheTranscriptAlone(): void
    {
        $chat = new Chat();

        [$same] = $chat->update(new ScheduleFiredMsg([['name' => 'quiet', 'action' => 'skipped-busy']]));

        self::assertSame($chat, $same, 'a skip is not announced, so nothing is re-rendered');
    }

    /** @return list<string> */
    private static function contents(Chat $chat): array
    {
        return array_map(static fn (Message $m): string => $m->content, $chat->history);
    }
}

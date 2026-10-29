<?php

/**
 * C-AGT-4.e REVIEW-1 finding 4: the SERVED drain leg.
 *
 * SessionHost::tick() drains this session's cross-session mail (a
 * `SendMessage` to `session:<id>` lands it in the on-disk SessionMailbox) on
 * the same beat that fires due `/loop` rows: notice row, save, transcript
 * announcement, and a {@see QueueMode::Followup} submit — the brief's "a
 * served host drains it as a queued turn". Before this file neither drain leg
 * had a test: deleting the mail block in tick() left the whole filtered suite
 * green.
 *
 * Pins:
 * - an idle host's tick turns one planted message into a real submitted turn
 *   (the backend receives the SessionMailbox frame) plus its notice row, and
 *   the mailbox is consumed — a second tick fires nothing;
 * - a busy host queues the frame behind the running turn instead of cutting
 *   in;
 * - mail lands on a host with NO ScheduleRegistry (the drain runs before the
 *   registry lookup).
 *
 * Deleting the drain block in SessionHost::tick() reddens all three.
 */

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Agents\Live\AgentMessage;
use SugarCraft\Code\Agents\Live\MessageMode;
use SugarCraft\Code\Agents\Live\SessionMailbox;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use PHPUnit\Framework\TestCase;

final class SessionHostMailDrainTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $project = '';

    private string $session = '';

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->sandbox = \sys_get_temp_dir() . '/crush-mail-drain-' . \bin2hex(\random_bytes(6));
        $this->project = $this->sandbox . '/project';
        \mkdir($this->project, 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
        // tests/bootstrap.php pins the default mail root process-wide, so the
        // id must be unique: a shared 's' would hand this file's planted mail
        // to SessionHostLoopFireTest's host of the same name.
        $this->session = 'xd-' . \bin2hex(\random_bytes(5));
        $this->store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $this->store->createSession($this->session, 'p', 'm');

        if (SessionMailbox::forSession($this->session) === null) {
            self::markTestSkipped('no owned home for session mail');
        }
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $inbox = SessionMailbox::forSession($this->session);
        if ($inbox !== null) {
            $dir = $inbox->directory();
            \exec('rm -rf ' . \escapeshellarg($dir));
        }
        if (\is_dir($this->sandbox)) {
            \exec('rm -rf ' . \escapeshellarg($this->sandbox));
        }
    }

    public function testAServedHostDrainsPlantedMailAsAQueuedTurn(): void
    {
        $this->plant('rename the module first');
        $host = $this->host();

        self::assertSame(1, $host->tick(\time()), 'one planted message, one fire');

        $noticeRows = \array_values(\array_filter(
            $host->history(),
            static fn (Message $row): bool => \str_contains($row->content, "Mail from session 'xm-peer' arrived"),
        ));
        self::assertCount(1, $noticeRows, 'the mail leaves a notice row, as the loop fire does');

        // The Followup submit went through submit() itself: an idle host starts
        // the turn at once, and the turn's user row IS the framed message.
        $this->drain($host);
        $frames = \array_values(\array_filter(
            $host->history(),
            static fn (Message $row): bool => $row->role === Role::User
                && \str_starts_with($row->content, '<session-message from="xm-peer" mode="followup">'),
        ));
        self::assertCount(1, $frames, 'the frame became a real turn of THIS session');
        self::assertStringContainsString('rename the module first', $frames[0]->content);
        self::assertStringContainsString('never user approval', $frames[0]->content, 'the frame keeps its no-authority line');

        $again = SessionMailbox::forSession($this->session);
        self::assertNotNull($again);
        self::assertSame([], $again->take(), 'consuming is what releases: the message is gone');
        self::assertSame(0, $host->tick(\time()), 'the next beat drains to nothing');
    }

    public function testMailOnABusyHostQueuesBehindTheRunningTurn(): void
    {
        $backend = self::holding();
        $host = $this->host($backend);
        $host->submit('a human turn first');
        self::assertTrue($host->isBusy(), 'fixture: a turn runs');

        $this->plant('queue behind me');
        self::assertSame(1, $host->tick(\time()), 'the beat still advances while busy');

        $queued = $host->queued();
        self::assertCount(1, $queued, 'the frame waits behind the turn, not inside it');
        self::assertStringStartsWith('<session-message from="xm-peer" mode="followup">', $queued[0]);

        $backend->settle(Message::assistant('done'));
        $this->drain($host);
        self::assertSame([], $host->queued(), 'the settle takes the queued mail out');
    }

    public function testMailLandsOnAHostWithNoScheduleRegistry(): void
    {
        $this->plant('registry is none of mails business');
        // A workspace with NO ScheduleRegistry service: the drain runs BEFORE
        // the registry lookup in tick(), so mail must land regardless.
        $host = SessionHost::new($this->session, WorkspaceContext::new(
            root: $this->project,
            sessionStore: $this->store,
            backend: new EchoBackend(),
        ), history: [Message::user('hello'), Message::assistant('hi')]);

        self::assertSame(1, $host->tick(\time()), 'mail does not ride the registry door');
        $this->drain($host);
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** Land one signed followup message from a peer session in this host's inbox. */
    private function plant(string $text): void
    {
        $why = SessionMailbox::send($this->session, AgentMessage::new('agent:xm-peer', $text, MessageMode::Followup));
        self::assertNull($why, 'the send is accepted by the open inbox: ' . (string) $why);
    }

    private function host(?Backend $backend = null): SessionHost
    {
        return SessionHost::new($this->session, WorkspaceContext::new(
            root: $this->project,
            sessionStore: $this->store,
            backend: $backend ?? new EchoBackend(),
        ), history: [Message::user('hello'), Message::assistant('hi')]);
    }

    /** A backend whose turn settles when the test says so. */
    private static function holding(): object
    {
        return new class () implements Backend {
            private ?Deferred $deferred = null;

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->deferred = new Deferred();

                return $this->deferred->promise();
            }

            public function settle(Message $reply): void
            {
                $deferred = $this->deferred;
                $this->deferred = null;
                $deferred?->resolve($reply);
            }
        };
    }

    private function drain(SessionHost $host): void
    {
        if (!$host->isBusy()) {
            return;
        }

        $loop = Loop::get();
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($host, $loop): void {
            if (!$host->isBusy()) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(15.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * L-EXPIRY approval-expiry parity: an aged question is decided at consume time
 * by comparing its mint stamp, never by a timer, and never honored silently.
 * The serve ask-timeout is the best-effort surface (it dies with the loop);
 * this is the authoritative floor that survives restarts and reconnections —
 * the pairing-code ghost law, applied to approvals (code-chat.md §11.6).
 */
final class ApprovalExpiryParityTest extends TestCase
{
    private const EXPIRED_NOTE = 'the approval request aged out of its 30-minute window before it was answered';

    private ProtocolFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testAnAgedQuestionIsRefusedAtConsumeTimeAndReleasedLoudly(): void
    {
        [$a, $sessionId] = $this->runningTurn();
        $agedAt = \time() - PendingAsk::EXPIRY_SECONDS - 10;
        $ask = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'rm build.log'], askedAt: $agedAt);
        $this->fixture->run(0.08);

        $late = $a->request('permission.respond', ['sessionId' => $sessionId, 'askId' => $ask->askId, 'reply' => 'once']);
        self::assertSame(ErrorCode::NotFound->value, $late['error']['code']);
        self::assertSame('ask_expired', $late['error']['data']['kind']);
        self::assertSame($ask->askId, $late['error']['data']['askId']);
        self::assertSame($agedAt, $late['error']['data']['askedAt']);

        self::assertSame(PermissionReply::Reject, $ask->resolution()?->reply, 'the blocked turn is released with an honest reject');
        self::assertSame(self::EXPIRED_NOTE, $ask->resolution()?->note);
        self::assertFalse($ask->resolution()?->cancelled ?? true);
        self::assertSame([], $this->fixture->hub->get($sessionId)?->grants()->patterns(), 'a refused question never grants');

        $this->fixture->run(0.08);
        $resolved = $a->events('permission.resolved');
        self::assertCount(1, $resolved, 'every watcher hears the expiry settle');
        self::assertSame('reject', $resolved[0]['data']['reply']);
        self::assertSame(self::EXPIRED_NOTE, $resolved[0]['data']['note'] ?? null);
    }

    public function testAFreshQuestionIsStillAnsweredAndAnOlderOneNeverSqueaksThrough(): void
    {
        [$a, $sessionId] = $this->runningTurn();
        $fresh = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'ls']);
        $this->fixture->run(0.08);

        self::assertTrue($a->call('permission.respond', ['sessionId' => $sessionId, 'askId' => $fresh->askId, 'reply' => 'once'])['applied']);
        self::assertSame(PermissionReply::Once, $fresh->resolution()?->reply);
    }

    public function testPendingNeverPresentsAnAgedQuestionAndTheReadNeverMutates(): void
    {
        [$a, $sessionId] = $this->runningTurn();
        $fresh = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'ls']);
        $aged = $this->fixture->backend->ask('c2', 'Write', ['file_path' => 'notes.md'], askedAt: \time() - PendingAsk::EXPIRY_SECONDS - 60);
        $this->fixture->run(0.08);

        self::assertSame([$fresh->askId], \array_column($a->call('permission.pending', ['sessionId' => $sessionId])['items'], 'askId'), 'a ghost is never presented');
        self::assertCount(2, $this->fixture->hub->get($sessionId)?->pendingAsks(), 'the read stays side-effect free; the aged row waits for its loud refusal or the turn sweep');

        $again = $a->call('permission.pending', ['sessionId' => $sessionId]);
        self::assertSame([$fresh->askId], \array_column($again['items'], 'askId'), 'and it answers the same way twice');
    }

    public function testASecondAnswerAfterExpiryIsToldTheQuestionWasSettled(): void
    {
        [$a, $sessionId] = $this->runningTurn();
        $ask = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'du -sh'], askedAt: \time() - PendingAsk::EXPIRY_SECONDS - 60);
        $this->fixture->run(0.08);

        self::assertSame('ask_expired', $a->request('permission.respond', ['sessionId' => $sessionId, 'askId' => $ask->askId, 'reply' => 'once'])['error']['data']['kind']);

        $again = $a->request('permission.respond', ['sessionId' => $sessionId, 'askId' => $ask->askId, 'reply' => 'always']);
        self::assertSame(ErrorCode::Conflict->value, $again['error']['code']);
        self::assertSame('already_resolved', $again['error']['data']['kind']);
        self::assertSame('reject', $again['error']['data']['resolved']['reply'], 'the expiry settle is resolution-visible, not a ghost');
    }

    public function testAlwaysNeverCascadesIntoAnAgedSibling(): void
    {
        [$a, $sessionId] = $this->runningTurn();
        $fresh = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'git status']);
        $aged = $this->fixture->backend->ask('c2', 'Bash', ['command' => 'git status --short'], askedAt: \time() - PendingAsk::EXPIRY_SECONDS - 60);
        $this->fixture->run(0.08);

        $answer = $a->call('permission.respond', ['sessionId' => $sessionId, 'askId' => $fresh->askId, 'reply' => 'always']);
        self::assertSame([], $answer['cascaded'], 'the grant covers its pattern, but an aged question is honored by nobody');
        self::assertFalse($aged->isSettled());
        self::assertNotSame([], $this->fixture->hub->get($sessionId)?->grants()->patterns(), 'the fresh always itself was remembered');
        self::assertSame([], $a->call('permission.pending', ['sessionId' => $sessionId])['items']);
    }

    /**
     * One client on a live session with a turn in flight. Deliberately a
     * different shape from the FirstWins twin's two-client launcher: expiry
     * is a property of the question, so only one witness is needed here.
     *
     * @return array{0: WireClient, 1: string}
     */
    private function runningTurn(): array
    {
        $a = $this->fixture->client('only');
        $sessionId = $this->fixture->session($a);
        $a->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();
        $a->call('session.send', ['sessionId' => $sessionId, 'text' => 'check the log before anyone rotates it']);

        return [$a, $sessionId];
    }
}

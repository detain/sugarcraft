<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tests\Server\Support\WireClient;

/**
 * Roadmap O-3b, Appendix O §6.7: a permission question is a durable event
 * every client sees, and an answer is a request any of them may send — the
 * first valid one wins, a late one is told who won, the answer reaches only
 * the call that was asked about, `always` is remembered for the session, a
 * cascading reject stops the turn, open questions survive a reconnect, and a
 * question nobody can answer any more is settled `cancelled`.
 */
final class PermissionFirstWinsTest extends TestCase
{
    private ProtocolFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testTheFirstAnswerWinsAndALateOneIsToldWhoWon(): void
    {
        [$a, $b, $sessionId] = $this->twoClientsOnARunningTurn();
        $ask = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'rm build.log']);
        $this->fixture->run(0.08);

        foreach ([$a, $b] as $client) {
            $requested = $client->events('permission.requested');
            self::assertCount(1, $requested);
            self::assertSame($ask->askId, $requested[0]['data']['askId']);
            self::assertSame(['command' => 'rm build.log'], $requested[0]['data']['arguments']);
            self::assertTrue($requested[0]['durable']);
        }

        $won = $b->call('permission.respond', ['sessionId' => $sessionId, 'askId' => $ask->askId, 'reply' => 'once']);
        self::assertTrue($won['applied']);

        $late = $a->request('permission.respond', ['sessionId' => $sessionId, 'askId' => $ask->askId, 'reply' => 'reject']);
        self::assertSame(ErrorCode::Conflict->value, $late['error']['code']);
        self::assertSame('already_resolved', $late['error']['data']['kind']);
        self::assertSame('once', $late['error']['data']['resolved']['reply']);

        self::assertCount(1, $this->fixture->backend->settled, 'the child heard exactly one answer');
        self::assertSame(PermissionReply::Once, $this->fixture->backend->settled[0]->reply);

        $this->fixture->run(0.08);
        foreach ([$a, $b] as $client) {
            self::assertSame('once', $client->events('permission.resolved')[0]['data']['reply'] ?? null);
            self::assertSame(['busy', 'waiting_permission', 'busy'], \array_column(\array_column($client->events('session.status'), 'data'), 'status'));
        }
    }

    public function testAnAnswerNamesOneQuestionAndAnUnknownOneIsNotFound(): void
    {
        [$a, , $sessionId] = $this->twoClientsOnARunningTurn();

        self::assertSame('ask_not_found', $a->request('permission.respond', ['sessionId' => $sessionId, 'askId' => '0123456789abcdef', 'reply' => 'once'])['error']['data']['kind']);
        self::assertSame(-32602, $a->request('permission.respond', ['sessionId' => $sessionId, 'askId' => '0123456789abcdef', 'reply' => 'maybe'])['error']['code']);
    }

    public function testAlwaysIsRememberedForTheSessionAndCoversTheSameToolsOpenQuestions(): void
    {
        [$a, , $sessionId] = $this->twoClientsOnARunningTurn();
        $first = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'git status']);
        $second = $this->fixture->backend->ask('c2', 'Bash', ['command' => 'git log']);
        $other = $this->fixture->backend->ask('c3', 'Write', ['file_path' => 'a.txt']);
        $this->fixture->run(0.08);

        $answer = $a->call('permission.respond', ['sessionId' => $sessionId, 'askId' => $first->askId, 'reply' => 'always']);

        self::assertSame([$second->askId], $answer['cascaded']);
        self::assertFalse($other->isSettled(), 'another tool still asks');
        self::assertNotSame([], $this->fixture->hub->get($sessionId)?->grants()->patterns(), 'every later turn of the session is told');
        self::assertSame([$other->askId], \array_column($a->call('permission.pending', ['sessionId' => $sessionId])['items'], 'askId'));
    }

    public function testARejectThatCascadesRejectsTheRestAndStopsTheTurn(): void
    {
        [$a, , $sessionId] = $this->twoClientsOnARunningTurn();
        $first = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'make deploy']);
        $second = $this->fixture->backend->ask('c2', 'Write', ['file_path' => 'prod.env']);
        $this->fixture->run(0.08);

        $answer = $a->call('permission.respond', ['sessionId' => $sessionId, 'askId' => $first->askId, 'reply' => 'reject', 'note' => 'not today', 'cascade' => true]);

        self::assertSame([$second->askId], $answer['cascaded']);
        self::assertSame(PermissionReply::Reject, $second->resolution()?->reply);
        self::assertSame('not today', $first->resolution()?->note);
        self::assertTrue($this->fixture->backend->cancellation()?->isSoftCancelled());
    }

    public function testRememberingBeyondTheSessionIsRefused(): void
    {
        [$a, , $sessionId] = $this->twoClientsOnARunningTurn();
        $ask = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'ls']);
        $this->fixture->run(0.08);

        self::assertSame('remember_refused', $a->request('permission.respond', ['sessionId' => $sessionId, 'askId' => $ask->askId, 'reply' => 'always', 'remember' => 'project'])['error']['data']['kind']);
        self::assertSame('remember_user_unavailable', $a->request('permission.respond', ['sessionId' => $sessionId, 'askId' => $ask->askId, 'reply' => 'always', 'remember' => 'user'])['error']['data']['kind']);
        self::assertFalse($ask->isSettled());
        self::assertSame('always', $a->call('permission.respond', ['sessionId' => $sessionId, 'askId' => $ask->askId, 'reply' => 'once', 'remember' => 'session'])['reply']);
    }

    public function testAnOpenQuestionIsHandedToAClientThatReconnects(): void
    {
        [$a, , $sessionId] = $this->twoClientsOnARunningTurn();
        $ask = $this->fixture->backend->ask('c1', 'Edit', ['file_path' => 'src/a.php']);
        $this->fixture->run(0.08);
        $cursor = \max(\array_column($a->events(), 'seq'));
        $a->close();

        $back = WireClient::open($this->fixture->dispatcher, 'a2');
        $hello = $back->hello(['resume' => [$sessionId => $cursor]]);

        $resumed = $hello['resumed'][$sessionId];
        self::assertSame($cursor + 1, $resumed['fromSeq']);
        self::assertSame([$ask->askId], \array_column($resumed['pendingAsks'], 'askId'), 'whatever the cursor, the open question rides along');
        self::assertSame([$ask->askId], \array_column($back->call('permission.pending')['items'], 'askId'));
        self::assertTrue($back->call('permission.respond', ['sessionId' => $sessionId, 'askId' => $ask->askId, 'reply' => 'once'])['applied']);
    }

    public function testCancellingTheTurnSettlesItsOpenQuestionsAsCancelled(): void
    {
        [$a, , $sessionId] = $this->twoClientsOnARunningTurn();
        $ask = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'sleep 99']);
        $this->fixture->run(0.08);

        $a->call('session.cancel', ['sessionId' => $sessionId]);
        $this->fixture->run();

        self::assertTrue($ask->resolution()?->cancelled);
        $resolved = $a->events('permission.resolved');
        self::assertCount(1, $resolved);
        self::assertTrue($resolved[0]['data']['cancelled']);
        self::assertSame([], $a->call('permission.pending')['items']);
    }

    public function testAnUnansweredQuestionTimesOutWhenTheServerSaysSo(): void
    {
        $this->fixture->tearDown();
        $this->fixture = ProtocolFixture::new(ServerConfig::new('/nonexistent')->withAskTimeoutSeconds(0.05));
        [$a, , $sessionId] = $this->twoClientsOnARunningTurn();
        $ask = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'ls']);

        $this->fixture->run(0.25);

        self::assertSame(PermissionReply::Reject, $ask->resolution()?->reply);
        self::assertSame('timed out after 0.05 s', $ask->resolution()?->note);
        self::assertSame('reject', $a->events('permission.resolved')[0]['data']['reply'] ?? null);
    }

    /**
     * Two clients following one session whose turn is running.
     *
     * @return array{0: WireClient, 1: WireClient, 2: string}
     */
    private function twoClientsOnARunningTurn(): array
    {
        $a = $this->fixture->client('a');
        $b = $this->fixture->client('b');
        $sessionId = $this->fixture->session($a);
        $a->call('session.subscribe', ['sessionId' => $sessionId]);
        $b->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();
        $a->call('session.send', ['sessionId' => $sessionId, 'text' => 'tidy up']);

        return [$a, $b, $sessionId];
    }
}

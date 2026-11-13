<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\Support\Keys;
use CandyCode\Bundles\MsTeams\Support\SessionMap;
use CandyCode\Bundles\MsTeams\Tests\Fixture\FaceHarness;
use PHPUnit\Framework\TestCase;

/**
 * `streamReplies`: a "working…" placeholder edited in place by the final reply.
 * The throttle is driven under an injected clock so the 2-second gap is
 * asserted exactly, not by sleeping. The full-flow cases run the real router
 * over the FakeServe bridge; the clock is pinned there so the refresh tick
 * never fires on its own.
 */
final class StreamRepliesTest extends TestCase
{
    use FaceHarness;

    private float $now = 1000.0;

    protected function setUp(): void
    {
        $this->bootFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixtures();
    }

    public function testTheFinalReplyEditsThePlaceholderInPlace(): void
    {
        $this->startServe();
        $this->serveFace(['dmPolicy' => 'pairing', 'streamReplies' => true], $this->frozenClock());
        $this->face->handleActivity($this->activity('u1', 'hello there'));
        $this->awaitPostText('working…');

        $this->served->pushEvent(['sessionId' => 'sess_fixture_1', 'type' => 'assistant.completed', 'seq' => 12, 'durable' => true, 'data' => ['content' => 'ANSWER text']]);
        $this->awaitCall('PUT', '/activities/act_out_1');

        $puts = $this->callsTo('PUT', '/activities/act_out_1');
        self::assertCount(1, $puts, 'the placeholder is edited exactly once');
        self::assertSame('ANSWER text', ((array) \json_decode($puts[0]['body'], true))['text']);
        self::assertSame(['working…'], $this->sentTexts('POST'), 'the placeholder is the only POST; no second message carries the answer');
    }

    public function testALongReplyReplacesThePlaceholderThenPostsTheRest(): void
    {
        $this->startServe();
        $this->serveFace(['dmPolicy' => 'pairing', 'streamReplies' => true, 'outbound' => ['textChunkLimit' => 100]], $this->frozenClock());
        $this->face->handleActivity($this->activity('u1', 'hello there'));
        $this->awaitPostText('working…');

        $words = [];
        for ($i = 1; $i <= 40; $i++) {
            $words[] = "word{$i}";
        }
        $this->served->pushEvent(['sessionId' => 'sess_fixture_1', 'type' => 'assistant.completed', 'seq' => 12, 'durable' => true, 'data' => ['content' => \implode(' ', $words)]]);
        $this->awaitCall('PUT', '/activities/act_out_1');
        $this->settle(300);

        $puts = $this->sentTexts('PUT');
        $posts = \array_values(\array_filter($this->sentTexts('POST'), static fn (string $t): bool => $t !== 'working…'));
        self::assertCount(1, $puts);
        self::assertNotSame([], $posts, 'the rest of the reply rides as new messages');
        self::assertStringStartsWith('word1 ', $puts[0]);
        $rejoined = \implode(' ', \array_merge($puts, $posts));
        foreach ($words as $word) {
            self::assertStringContainsString($word . ' ', $rejoined . ' ', "{$word} arrives in order somewhere");
        }
    }

    public function testWhenStreamingIsOffTheReplyIsOnePostAsBefore(): void
    {
        $this->startServe();
        $this->serveFace(['dmPolicy' => 'pairing'], $this->frozenClock());
        $this->face->handleActivity($this->activity('u1', 'hello there'));
        $this->awaitSession();

        $this->served->pushEvent(['sessionId' => 'sess_fixture_1', 'type' => 'assistant.completed', 'seq' => 12, 'durable' => true, 'data' => ['content' => 'ANSWER text']]);
        $this->awaitPostText('ANSWER text');
        $this->settle(200);

        self::assertSame(['ANSWER text'], $this->sentTexts('POST'), 'no placeholder, one message');
        self::assertSame([], $this->callsTo('PUT', '/activities'));
    }

    public function testAFailedEditFallsBackToPostingTheAnswer(): void
    {
        $this->startServe();
        $this->serveFace(['dmPolicy' => 'pairing', 'streamReplies' => true], $this->frozenClock());
        $this->api->failNextPutWith = 403;
        $this->face->handleActivity($this->activity('u1', 'hello there'));
        $this->awaitPostText('working…');

        $this->served->pushEvent(['sessionId' => 'sess_fixture_1', 'type' => 'assistant.completed', 'seq' => 12, 'durable' => true, 'data' => ['content' => 'ANSWER text']]);
        $this->awaitPostText('ANSWER text');
        $this->settle(200);

        self::assertCount(1, $this->callsTo('PUT', '/activities/act_out_1'), 'the edit was attempted');
        self::assertContains('ANSWER text', $this->sentTexts('POST'), 'the refused edit posts the answer as a new message');
    }

    public function testTheRefreshIsThrottledToOneEditEveryTwoSeconds(): void
    {
        $this->buildFace(['streamReplies' => true], fn (): float => $this->now);
        $ref = ['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-1'];
        $this->face->beginReply($ref);
        $this->awaitPostText('working…');
        $this->settle(50);

        $this->now = 1000.0;
        $this->face->refreshReply($ref);
        $this->settle(50);
        self::assertSame([], $this->callsTo('PUT', '/activities'), 'nothing inside the first two seconds');

        $this->now = 1001.9;
        $this->face->refreshReply($ref);
        $this->settle(50);
        self::assertSame([], $this->callsTo('PUT', '/activities'), 'still inside the gap');

        $this->now = 1002.0;
        $this->face->refreshReply($ref);
        $this->settle(50);
        self::assertSame(['working… 2s'], $this->sentTexts('PUT'));

        $this->now = 1003.0;
        $this->face->refreshReply($ref);
        $this->settle(50);
        self::assertCount(1, $this->callsTo('PUT', '/activities'), 'one second later is still too soon');

        $this->now = 1004.0;
        $this->face->refreshReply($ref);
        $this->settle(50);
        self::assertSame(['working… 2s', 'working… 4s'], $this->sentTexts('PUT'));
    }

    public function testAnAbandonedTurnSaysSoInThePlaceholder(): void
    {
        $this->buildFace(['streamReplies' => true], $this->frozenClock());
        $ref = ['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-1'];
        $this->face->beginReply($ref);
        $this->awaitPostText('working…');
        $this->settle(50);

        $this->face->abandonReply($ref);
        $this->settle(100);

        self::assertFalse($this->face->hasOpenReply($ref));
        self::assertSame(['No reply was produced.'], $this->sentTexts('PUT'));
    }

    public function testAPromptTheServeNeverAdmitsClosesItsPlaceholder(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'pairing', 'streamReplies' => true], $this->frozenClock());
        // A session is bound, but the serve is not dialled: the send itself fails.
        (new SessionMap($this->tempDir . '/sessions.db'))->bind(
            Keys::dmKey('msteams', 'default', 'u1', 'conv-1', Keys::DM_PER_PEER),
            'sess_unreachable',
        );
        $this->face->handleActivity($this->activity('u1', 'hello there'));
        $this->awaitPostText('working…');
        $this->settle(200);

        self::assertSame(['No reply was produced.'], $this->sentTexts('PUT'), 'the placeholder is closed, not left ticking');
        self::assertFalse($this->face->hasOpenReply(['conversationId' => 'conv-1']));
    }

    public function testStreamingDefaultsOff(): void
    {
        $this->buildFace([], $this->frozenClock());
        $ref = ['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-1'];
        $this->face->beginReply($ref);
        $this->settle(100);

        self::assertFalse($this->face->hasOpenReply($ref));
        self::assertSame([], $this->callsTo('POST', '/activities'), 'no placeholder with the default');
    }

    /** @return \Closure(): float */
    private function frozenClock(): \Closure
    {
        return fn (): float => $this->now;
    }

    /** Spin until a request with this method and path fragment has landed, or fail. */
    private function awaitCall(string $method, string $pathNeedle): void
    {
        for ($tick = 0; $tick < 80; $tick++) {
            if ($this->callsTo($method, $pathNeedle) !== []) {
                $this->settle(50);

                return;
            }
            $this->settle(50);
        }
        self::fail("no {$method} to {$pathNeedle} arrived");
    }

    /** Spin until a POST whose text contains $text has landed, or fail. */
    private function awaitPostText(string $text): void
    {
        for ($tick = 0; $tick < 80; $tick++) {
            foreach ($this->sentTexts('POST') as $sent) {
                if (\str_contains($sent, $text)) {
                    $this->settle(50);

                    return;
                }
            }
            $this->settle(50);
        }
        self::fail("no POST containing '{$text}' arrived");
    }

    private function awaitSession(): void
    {
        for ($tick = 0; $tick < 60 && $this->served->sendSeen < 1; $tick++) {
            $this->settle(50);
        }
        $this->settle(100);
    }
}

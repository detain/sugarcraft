<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\MsTeamsChannel;
use CandyCode\Bundles\MsTeams\Support\SessionMap;
use CandyCode\Bundles\MsTeams\Tests\Fixture\FaceHarness;
use PHPUnit\Framework\TestCase;

/**
 * Proactive notify: a post into a conversation the bot has served before.
 * The reply address comes from the store the bot filled on its own inbound,
 * never from the caller; unknown conversations are refused; the text takes
 * the same redaction and chunking as a reply.
 */
final class NotifyConversationTest extends TestCase
{
    use FaceHarness;

    protected function setUp(): void
    {
        $this->bootFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixtures();
    }

    public function testAKnownConversationReceivesTheNotice(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->activity('u1', 'hi there'));
        $this->settle(200);

        $pending = $this->face->notifyConversation(['conversationId' => 'conv-1'], 'Background job done.');
        self::assertNotNull($pending, 'the conversation was seen, so the door is open');
        $outcome = $this->awaitPromise($pending);

        self::assertSame('fulfilled', $outcome['state']);
        self::assertContains('Background job done.', $this->sentTexts());
    }

    public function testAConversationTheBotHasNeverSeenIsRefused(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing']);

        self::assertNull($this->face->notifyConversation(['conversationId' => 'never-seen'], 'hello?'));
        self::assertSame([], $this->sentTexts(), 'nothing is posted');

        $channel = new MsTeamsChannel();
        $outcome = $this->awaitPromise($channel->notifyConversation(['conversationId' => 'never-seen'], 'hello?'));
        self::assertSame('rejected', $outcome['state'], 'the channel refuses an unseen conversation too');
    }

    public function testTheCallerCannotAimTheNoticeAtAnotherHost(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->activity('u1', 'hi there'));
        $this->settle(200);

        $pending = $this->face->notifyConversation(['conversationId' => 'conv-1', 'serviceUrl' => 'https://attacker.example.com'], 'ping');
        self::assertNotNull($pending);
        $this->awaitPromise($pending);

        self::assertContains('ping', $this->sentTexts(), 'the post went to the stored address');
        $posted = $this->callsTo('POST', '/v3/conversations/conv-1/activities');
        self::assertNotSame([], $posted, 'the post reached the stored reply address, not the caller-named host');
        self::assertStringStartsWith('/v3/conversations/conv-1/activities', $posted[array_key_last($posted)]['path']);
    }

    public function testTheNoticeIsRedactedLikeAReply(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->activity('u1', 'hi there'));
        $this->settle(200);
        $this->safety->addSecret('boot-secret-9');

        $pending = $this->face->notifyConversation(['conversationId' => 'conv-1'], 'token boot-secret-9 is done');
        self::assertNotNull($pending);
        $this->awaitPromise($pending);

        self::assertContains('token [redacted] is done', $this->sentTexts());
        foreach ($this->sentTexts() as $sent) {
            self::assertStringNotContainsString('boot-secret-9', $sent);
        }
    }

    public function testAGroupThatIsNoLongerServedRefusesTheNotice(): void
    {
        $this->buildFace(['groups' => ['conv-1' => ['policy' => 'allowlist']]]);
        $this->face->handleActivity($this->activity('u1', '<at>candy</at> hi', 'groupChat', [
            ['type' => 'mention', 'mentioned' => ['user' => ['id' => '28:' . self::HARNESS_APP_ID]]],
        ]));
        $this->settle(200);

        // Same stored conversation, but the row is switched off in the policy this face now holds.
        $this->face->cancelAllTimers();
        $this->buildFace(['groups' => ['conv-1' => ['policy' => 'disabled']]]);
        $pending = $this->face->notifyConversation(['conversationId' => 'conv-1'], 'late news');
        self::assertNotNull($pending, 'the conversation is still in the store');
        self::assertSame('rejected', $this->awaitPromise($pending)['state']);
    }

    public function testTheReplyAddressSurvivesARestart(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->activity('u1', 'hi there'));
        $this->settle(200);

        $reopened = new SessionMap($this->tempDir . '/sessions.db');
        $stored = $reopened->teamsConversation('default', 'conv-1');
        self::assertIsArray($stored);
        self::assertSame($this->serviceUrl(), $stored['serviceUrl']);
        self::assertNull($reopened->teamsConversation('other-account', 'conv-1'), 'the store is per account');
    }
}

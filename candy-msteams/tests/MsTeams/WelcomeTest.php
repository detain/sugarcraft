<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\Tests\Fixture\FaceHarness;
use PHPUnit\Framework\TestCase;

/**
 * The welcome on conversationUpdate: fires when the bot itself is added,
 * stays silent on removal and on other members joining, and absorbs a
 * redelivered activity through the same inbound claim as every other POST.
 */
final class WelcomeTest extends TestCase
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

    public function testAddingTheBotWelcomesExactlyOnce(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->update([['id' => '28:' . self::HARNESS_APP_ID]], [], 'personal', 'upd-add-1'));
        $this->settle(300);

        $texts = $this->sentTexts();
        self::assertCount(1, $texts, 'one welcome per add');
        self::assertStringStartsWith('candy-code is connected to this conversation.', $texts[0]);
        self::assertStringContainsString('/help', $texts[0]);
        self::assertStringNotContainsString('Pairing is required', $texts[0], 'an already-paired peer is not asked to pair');
    }

    public function testAnUnpairedPeerIsToldPairingIsRequired(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->update([['id' => '28:' . self::HARNESS_APP_ID]], [], 'personal', 'upd-add-2'));
        $this->settle(300);

        $texts = $this->sentTexts();
        self::assertCount(1, $texts);
        self::assertStringContainsString('Pairing is required before the bot answers', $texts[0]);
    }

    public function testRemovingTheBotNeverWelcomes(): void
    {
        $this->buildFace(['dmPolicy' => 'open']);
        $this->face->handleActivity($this->update([], [['id' => '28:' . self::HARNESS_APP_ID]], 'personal', 'upd-rm-1'));
        $this->settle(300);

        self::assertSame([], $this->sentTexts());
    }

    public function testAddedAndRemovedInOneActivityStaysSilent(): void
    {
        $this->buildFace(['dmPolicy' => 'open']);
        $bot = ['id' => '28:' . self::HARNESS_APP_ID];
        $this->face->handleActivity($this->update([$bot], [$bot], 'personal', 'upd-both-1'));
        $this->settle(300);

        self::assertSame([], $this->sentTexts());
    }

    public function testAHumanJoiningIsNotTheBotBeingAdded(): void
    {
        $this->buildFace(['dmPolicy' => 'open']);
        $this->face->handleActivity($this->update([['id' => 'u7']], [], 'groupChat', 'upd-human-1'));
        $this->settle(300);

        self::assertSame([], $this->sentTexts());
    }

    public function testADuplicateDeliveryWelcomesOnce(): void
    {
        $this->buildFace(['dmPolicy' => 'open']);
        $added = $this->update([['id' => '28:' . self::HARNESS_APP_ID]], [], 'personal', 'upd-dupe-1');
        $this->face->handleActivity($added);
        $this->face->handleActivity($added);
        $this->settle(300);

        self::assertCount(1, $this->sentTexts(), 'Teams retries a slow POST; the claim keeps it to one welcome');
    }

    public function testAGroupThatIsNotAllowlistedIsTold(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->update([['id' => '28:' . self::HARNESS_APP_ID]], [], 'groupChat', 'upd-grp-1'));
        $this->settle(300);

        $texts = $this->sentTexts();
        self::assertCount(1, $texts);
        self::assertStringContainsString('not on the group allowlist yet', $texts[0]);
        self::assertStringContainsString('channels.msteams.groups', $texts[0]);
    }

    public function testAServedGroupInvitesAMention(): void
    {
        $this->buildFace(['groups' => ['conv-1' => ['policy' => 'allowlist']]]);
        $this->face->handleActivity($this->update([['id' => '28:' . self::HARNESS_APP_ID]], [], 'groupChat', 'upd-grp-2'));
        $this->settle(300);

        $texts = $this->sentTexts();
        self::assertCount(1, $texts);
        self::assertStringContainsString('Mention me to ask something.', $texts[0]);
    }

    public function testADisabledDirectMessageDoorWelcomesNobody(): void
    {
        $this->buildFace(['dmPolicy' => 'disabled']);
        $this->face->handleActivity($this->update([['id' => '28:' . self::HARNESS_APP_ID]], [], 'personal', 'upd-off-1'));
        $this->settle(300);

        self::assertSame([], $this->sentTexts());
    }

    /**
     * @param list<array<string,string>> $added
     * @param list<array<string,string>> $removed
     */
    private function update(array $added, array $removed, string $conversationType, string $id): array
    {
        return [
            'type' => 'conversationUpdate',
            'id' => $id,
            'serviceUrl' => $this->serviceUrl(),
            'from' => ['id' => 'u1', 'aadObjectId' => 'u1', 'name' => 'Peer u1'],
            'conversation' => ['id' => 'conv-1', 'conversationType' => $conversationType],
            'membersAdded' => $added,
            'membersRemoved' => $removed,
        ];
    }
}

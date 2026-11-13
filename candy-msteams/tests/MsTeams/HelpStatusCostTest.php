<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\MsTeams\Commands;
use CandyCode\Bundles\MsTeams\Tests\Fixture\FaceHarness;
use PHPUnit\Framework\TestCase;

/**
 * /help, /status and /cost: the commands the bundle answers, and the session
 * facts it reports. Status and spend come from the host's own session
 * snapshot; the tests pin that nothing is estimated when the host is silent.
 */
final class HelpStatusCostTest extends TestCase
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

    public function testTheTeamsWordsParseAndTheSharedRosterIsUntouched(): void
    {
        self::assertSame(['new', 'stop', 'approve', 'queue', 'model', 'pair'], Commands::ROSTER, 'the shared §7.6 roster stays identical to discord');
        self::assertSame(['name' => 'help', 'args' => []], Commands::parseText('/help'));
        self::assertSame(['name' => 'status', 'args' => []], Commands::parseText('<at>candy</at> /STATUS'));
        self::assertSame(['name' => 'cost', 'args' => []], Commands::parseText('/cost'));
        self::assertNull(Commands::parseText('/whoami'));
    }

    public function testHelpListsEveryCommandTheBundleAnswers(): void
    {
        $help = Commands::helpText();
        foreach (\array_merge(Commands::ROSTER, Commands::TEAMS_EXTRA) as $name) {
            self::assertStringContainsString($name, $help, "/help must name {$name}");
        }
        $shipped = \array_merge(Commands::ROSTER, Commands::TEAMS_EXTRA);
        $listed = \array_keys(Commands::HELP);
        \sort($shipped);
        \sort($listed);
        self::assertSame($shipped, $listed, '/help names exactly the shipped roster, no more and no less');
    }

    public function testHelpAnswersAPairedDirectMessage(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->activity('u1', '/help'));
        $this->settle(300);

        $texts = $this->sentTexts();
        self::assertCount(1, $texts);
        self::assertStringStartsWith('**candy-code commands**', $texts[0]);
        self::assertStringContainsString('`/status`', $texts[0]);
        self::assertStringContainsString('`/cost`', $texts[0]);
    }

    public function testAnUnpairedPeerGetsThePairingNoticeNotTheHelp(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->activity('u9', '/help'));
        $this->settle(300);

        foreach ($this->sentTexts() as $text) {
            self::assertStringNotContainsString('candy-code commands', $text, 'the access floor answers first');
        }
    }

    public function testStatusAndCostWithNoSessionSayThereIsNone(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->activity('u1', '/status'));
        $this->face->handleActivity($this->activity('u1', '/cost'));
        $this->settle(300);

        $texts = $this->sentTexts();
        self::assertCount(2, $texts);
        self::assertStringContainsString('No session is attached', $texts[0]);
        self::assertStringContainsString('No session is attached', $texts[1]);
    }

    public function testStatusReportsTheHostSnapshotAndOmitsAModelTheHostDoesNotSend(): void
    {
        $this->startServe();
        $this->serveFace(['dmPolicy' => 'pairing']);
        $this->primeSession();

        $this->face->handleActivity($this->activity('u1', '/status'));
        $text = $this->awaitText('Session status');

        self::assertStringContainsString('- Session: `sess_fixture_1`', $text);
        self::assertStringContainsString('- State: idle', $text);
        self::assertStringContainsString('- Queue mode: steer', $text);
        self::assertStringContainsString('- Queued prompts: 0', $text);
        self::assertStringContainsString('- Approvals awaiting an answer: 0', $text);
        self::assertStringNotContainsString('Model:', $text, 'the snapshot carries no model, so none is invented');
    }

    public function testStatusShowsAModelWhenTheHostReportsOne(): void
    {
        $this->startServe();
        $this->served->sessionSnapshot = ['model' => 'claude-test-model', 'status' => 'busy', 'queued' => ['next']];
        $this->serveFace(['dmPolicy' => 'pairing']);
        $this->primeSession();

        $this->face->handleActivity($this->activity('u1', '/status'));
        $text = $this->awaitText('Session status');

        self::assertStringContainsString('- Model: claude-test-model', $text);
        self::assertStringContainsString('- State: busy', $text);
        self::assertStringContainsString('- Queued prompts: 1', $text);
    }

    public function testCostShowsTheHostSpendToFourPlaces(): void
    {
        $this->startServe();
        $this->served->sessionSnapshot = ['spentUsd' => 0.0123456];
        $this->serveFace(['dmPolicy' => 'pairing']);
        $this->primeSession();

        $this->face->handleActivity($this->activity('u1', '/cost'));
        self::assertSame('Spend this session: $0.0123', $this->awaitText('Spend this session'));
    }

    public function testCostSaysSoWhenTheSpendIsALowerBound(): void
    {
        $this->startServe();
        $this->served->sessionSnapshot = ['spentUsd' => 0.5, 'spendUnpriced' => true];
        $this->serveFace(['dmPolicy' => 'pairing']);
        $this->primeSession();

        $this->face->handleActivity($this->activity('u1', '/cost'));
        $text = $this->awaitText('Spend this session');

        self::assertStringContainsString('$0.5000', $text);
        self::assertStringContainsString('a lower bound', $text);
    }

    public function testCostSaysNotReportedWhenTheHostSendsNoFigure(): void
    {
        $this->startServe();
        $this->served->sessionSnapshot = ['spentUsd' => null];
        $this->serveFace(['dmPolicy' => 'pairing']);
        $this->primeSession();

        $this->face->handleActivity($this->activity('u1', '/cost'));
        self::assertSame('Cost: not reported.', $this->awaitText('Cost: not reported'));
    }

    /** One prompt through the live serve, so the conversation is bound to a session. */
    private function primeSession(): void
    {
        $this->face->handleActivity($this->activity('u1', 'hello there'));
        for ($tick = 0; $tick < 60 && $this->served->sendSeen < 1; $tick++) {
            $this->settle(50);
        }
        $this->settle(100);
    }

    /** Wait (bounded) for a posted message containing $needle; returns it. */
    private function awaitText(string $needle): string
    {
        for ($tick = 0; $tick < 60; $tick++) {
            foreach ($this->sentTexts() as $text) {
                if (\str_contains($text, $needle)) {
                    return $text;
                }
            }
            $this->settle(50);
        }
        self::fail("no posted message contained '{$needle}'");
    }
}

<?php

/**
 * C-1 (cc-review-1111 CC-TEAM-B), account layer: approval-answer-bearing
 * interactions must pass the SAME access floor the message path applies
 * (dmPolicy ladder on DMs — without the pairing-code notice; guild policy
 * row + channel allowlist on guilds — without the mention arms, which do
 * not exist for a targeted interaction). Before this lane the whole
 * interaction path was ungated: an unpaired DM peer or a member of a
 * non-served guild could answer approval buttons and slash /approve.
 * Refusal is loud: warn log + an ephemeral deny message on the
 * interaction itself. Other slash verbs are deliberately NOT touched
 * here (surface-hijack work is the tracked C-1 remainder).
 */

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Discord;

use CandyCode\Bundles\Discord\Bridge\ConversationRouter;
use CandyCode\Bundles\Discord\Candy\ServeBridge;
use CandyCode\Bundles\Discord\Discord\DiscordAccount;
use CandyCode\Bundles\Discord\Discord\Gateway;
use CandyCode\Bundles\Discord\Discord\Rest;
use CandyCode\Bundles\Discord\Support\CredentialStore;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Support\PairingCodes;
use CandyCode\Bundles\Discord\Support\SessionMap;
use CandyCode\Bundles\Discord\Support\TextSafety;
use CandyCode\Bundles\Discord\Tests\Fixture\FakeDiscordApi;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

final class ApprovalInteractionGateTest extends TestCase
{
    private LoopInterface $loop;

    private FakeDiscordApi $api;

    private DiscordAccount $face;

    private ?Log $faceLog = null;

    private CredentialStore $store;

    private PairingCodes $pairing;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->loop = Loop::get();
        $this->tempDir = \sys_get_temp_dir() . '/f-c1-floor-' . \getmypid() . '-' . \uniqid('', true);
        \mkdir($this->tempDir, 0o700, true);
        $this->api = new FakeDiscordApi($this->loop);
        $this->api->listen();
        $this->store = new CredentialStore($this->tempDir . '/channels.json');
        $this->pairing = new PairingCodes($this->store, new TextSafety());
    }

    protected function tearDown(): void
    {
        $this->face?->cancelAllTimers();
        $this->faceLog = null;
        $this->api->stop();
        @\unlink($this->tempDir . '/sessions.db');
        @\rmdir($this->tempDir);
    }

    public function testAnUnpairedDmButtonClickIsRefusedEphemeral(): void
    {
        $this->buildAccountFace(['dmPolicy' => 'pairing']);
        $this->drive($this->buttonInteraction('u1', 'ap:ask_9:once:cafebabe01'));
        $this->settle();

        self::assertRefusedOnFakeApi();
    }

    public function testADisabledDmPolicyRefusesEvenAPairedPeer(): void
    {
        $this->store->writeDiscordPairing('default', [], ['u1']);
        $this->buildAccountFace(['dmPolicy' => 'disabled']);
        $this->drive($this->buttonInteraction('u1', 'ap:ask_9:once:cafebabe01'));
        $this->settle();

        self::assertRefusedOnFakeApi();
    }

    public function testAnAllowlistPolicyWithAnEmptyRosterFailsClosed(): void
    {
        $this->buildAccountFace(['dmPolicy' => 'allowlist']);
        $this->drive($this->buttonInteraction('u1', 'ap:ask_9:once:cafebabe01'));
        $this->settle();

        self::assertRefusedOnFakeApi();
    }

    public function testAPairedPeerButtonClickPassesTheFloorAndIsAcked(): void
    {
        $this->store->writeDiscordPairing('default', [], ['u1']);
        $this->buildAccountFace(['dmPolicy' => 'pairing']);
        $this->drive($this->buttonInteraction('u1', 'ap:ask_9:once:cafebabe01'));
        $this->settle();

        self::assertAckedOnFakeApi('button click of a floor-passing peer must reach the answer path (ACK)');
    }

    public function testAButtonInAnUnguildLessConfiguredGuildIsRefused(): void
    {
        $this->buildAccountFace(['groupPolicy' => 'allowlist']);
        $this->drive($this->buttonInteraction('u1', 'ap:ask_9:once:cafebabe01', 'g1'));
        $this->settle();

        self::assertRefusedOnFakeApi();
    }

    public function testAGuildRowWithDisabledPolicyRefusesItsButtons(): void
    {
        $this->buildAccountFace(['guilds' => ['g1' => ['policy' => 'disabled']]]);
        $this->drive($this->buttonInteraction('u1', 'ap:ask_9:once:cafebabe01', 'g1'));
        $this->settle();

        self::assertRefusedOnFakeApi();
    }

    public function testAGuildChannelAllowlistRefusesOtherChannelsAndAdmitsItsOwn(): void
    {
        $this->buildAccountFace(['guilds' => ['g1' => ['allowChannels' => ['cX']]]]);
        $this->drive($this->buttonInteraction('u1', 'ap:ask_9:once:cafebabe01', 'g1', 'c1'));
        $this->settle();
        self::assertRefusedOnFakeApi();

        $this->drive($this->buttonInteraction('u1', 'ap:ask_9:once:cafebabe01', 'g1', 'cX'));
        $this->settle();
        self::assertAckedOnFakeApi('allowlisted channel must reach the answer path');
    }

    public function testSlashApproveFromAnUnpairedDmIsRefused(): void
    {
        $this->buildAccountFace(['dmPolicy' => 'pairing']);
        $this->drive($this->slashInteraction('u1', 'approve', ['ask_9', 'once']));
        $this->settle();

        $call = $this->api->lastCallFor('/callback');
        self::assertNotNull($call);
        self::assertSame(4, (int) ($call['body']['type'] ?? 0));
        self::assertStringContainsString('not permitted', (string) ($call['body']['data']['content'] ?? ''));
    }

    public function testOtherSlashVerbsAreNotTouchedByThisGate(): void
    {
        // Scope lock (F-C1): only approval answers take the floor here.
        // /new from an unpaired DM still gets the ordinary ack (the model
        // door itself is the C-1 surface-hijack remainder, disclosed).
        $this->buildAccountFace(['dmPolicy' => 'pairing']);
        $this->drive($this->slashInteraction('u1', 'new', []));
        $this->settle();

        $call = $this->api->lastCallFor('/callback');
        self::assertNotNull($call);
        self::assertSame(4, (int) ($call['body']['type'] ?? 0));
        self::assertStringNotContainsString('not permitted', (string) ($call['body']['data']['content'] ?? ''));
    }

    public function testThePairingCodeNoticeIsNotReemittedOnApprovalRefusal(): void
    {
        // Floor refusal must NOT mint a pairing request — an approval
        // answer is not a DM asking to be served (§7.3 gate discipline).
        $this->buildAccountFace(['dmPolicy' => 'pairing']);
        $this->drive($this->buttonInteraction('ghost', 'ap:ask_9:once:cafebabe01'));
        $this->settle();

        self::assertRefusedOnFakeApi();
        self::assertCount(0, $this->pairing->pending('default'), 'a refused button click must not consume a pairing slot');
    }

    // ── harness ──

    /** @param array<string,mixed> $interaction */
    private function drive(array $interaction): void
    {
        $on = new \ReflectionMethod(DiscordAccount::class, 'onInteraction');
        $on->setAccessible(true);
        $on->invoke($this->face, $interaction);
    }

    /** @param array<string,mixed> $cfg */
    private function buildAccountFace(array $cfg): void
    {
        $rest = new Rest('tok', Log::silent(), 'http://127.0.0.1:' . $this->apiPort());
        $gateway = new Gateway('tok', Log::silent(), $this->loop, 'ws://127.0.0.1:1/never');
        $router = new ConversationRouter(
            new ServeBridge('ws://127.0.0.1:1/never', '', 'subprotocol', Log::silent(), $this->loop),
            new SessionMap($this->tempDir . '/sessions.db'),
            Log::silent(),
            new TextSafety(),
            $this->loop,
        );
        $this->faceLog = Log::silent();
        $this->face = new DiscordAccount('default', $rest, $gateway, $router, $cfg, $this->faceLog,
            $this->pairing, new TextSafety(), $this->loop);
        $router->addChannel($this->face, 'default');
        $own = new \ReflectionProperty(DiscordAccount::class, 'ownUserId');
        $own->setAccessible(true);
        $own->setValue($this->face, 'bot-9');
    }

    /** @return array<string,mixed> */
    private function buttonInteraction(string $userId, string $customId, string $guildId = '', string $channelId = 'c1'): array
    {
        $interaction = [
            'id' => 'i-' . \substr(\md5($userId . $customId . $channelId), 0, 6),
            'token' => 'tk-1',
            'type' => 3,
            'channel_id' => $channelId,
            'member' => ['user' => ['id' => $userId, 'username' => 'U']],
            'data' => ['custom_id' => $customId],
        ];
        if ($guildId !== '') {
            $interaction['guild_id'] = $guildId;
        }

        return $interaction;
    }

    /** @param list<string> $args */
    private function slashInteraction(string $userId, string $name, array $args, string $guildId = ''): array
    {
        $interaction = [
            'id' => 'i-' . \substr(\md5($userId . $name . \implode(',', $args)), 0, 6),
            'token' => 'tk-2',
            'type' => 2,
            'channel_id' => 'c1',
            'member' => ['user' => ['id' => $userId, 'username' => 'U']],
            'data' => ['name' => $name, 'options' => \array_map(static fn (string $v): array => ['value' => $v], $args)],
        ];
        if ($guildId !== '') {
            $interaction['guild_id'] = $guildId;
        }

        return $interaction;
    }

    private function assertRefusedOnFakeApi(): void
    {
        $call = $this->callbackCall();
        self::assertSame(4, (int) ($call['body']['type'] ?? 0), 'an ungated actor must get an ephemeral deny, never the answer-path ACK');
        self::assertStringContainsString('not permitted', (string) ($call['body']['data']['content'] ?? ''));
    }

    private function assertAckedOnFakeApi(string $message): void
    {
        $call = $this->callbackCall();
        self::assertSame(6, (int) ($call['body']['type'] ?? 0), $message);
    }

    /** @return array{method:string,path:string,body:array<string,mixed>} */
    private function callbackCall(): array
    {
        $call = $this->api->lastCallFor('/callback');
        self::assertNotNull($call, 'expected an interaction callback on the fake api');

        return $call;
    }

    private function apiPort(): int
    {
        $port = new \ReflectionProperty(FakeDiscordApi::class, 'server');
        $port->setAccessible(true);
        $parts = \parse_url((string) $port->getValue($this->api)?->getAddress());

        return (int) ($parts['port'] ?? 0);
    }

    private function settle(int $ms = 300): void
    {
        $this->loop->addTimer($ms / 1000.0, function (): void {
            $this->loop->stop();
        });
        $this->loop->run();
    }
}

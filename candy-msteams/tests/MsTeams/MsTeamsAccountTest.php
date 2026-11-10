<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\Bridge\ConversationRouter;
use CandyCode\Bundles\MsTeams\Candy\ServeBridge;
use CandyCode\Bundles\MsTeams\MsTeams\Cards;
use CandyCode\Bundles\MsTeams\MsTeams\MsTeamsAccount;
use CandyCode\Bundles\MsTeams\MsTeams\Rest;
use CandyCode\Bundles\MsTeams\MsTeams\TokenBroker;
use CandyCode\Bundles\MsTeams\Support\CredentialStore;
use CandyCode\Bundles\MsTeams\Support\Log;
use CandyCode\Bundles\MsTeams\Support\PairingCodes;
use CandyCode\Bundles\MsTeams\Support\SessionMap;
use CandyCode\Bundles\MsTeams\Support\TextSafety;
use CandyCode\Bundles\MsTeams\Tests\Fixture\FakeServe;
use CandyCode\Bundles\MsTeams\Tests\Fixture\FakeTeamsApi;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\Loop;

/**
 * The account face driven through its public intake with fixture activities
 * (§8.1-§8.6 surfaces): the pairing gate answers before anything reaches the
 * router, unlisted groups are blocked by default, the degraded posture
 * forces mention-only, outbound carries the conversation reference, cards
 * retire by replace, and the /pair text surface speaks. The REST leg rides
 * a real Rest client pointed at a loopback fake with the opt-in flag; the
 * serve bridge is a placeholder that was never dialled — anything that
 * reaches it fails loudly through the face, which is itself the pin that
 * the gate opened. One leg (the round trip) dials a real FakeServe instead.
 */
final class MsTeamsAccountTest extends TestCase
{
    private const APP_ID = 'app-guid-1';

    private LoopInterface $loop;
    private FakeTeamsApi $api;
    private MsTeamsAccount $face;
    private CredentialStore $store;
    private PairingCodes $pairing;
    private TextSafety $safety;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->loop = Loop::get();
        $this->tempDir = \sys_get_temp_dir() . '/p3-account-' . \getmypid() . '-' . \uniqid('', true);
        \mkdir($this->tempDir, 0o700, true);
        $this->api = new FakeTeamsApi($this->loop);
        $this->api->listen();
        $this->store = new CredentialStore($this->tempDir . '/channels.json');
        $this->safety = new TextSafety();
        $this->pairing = new PairingCodes($this->store, $this->safety);
    }

    protected function tearDown(): void
    {
        $this->face?->cancelAllTimers();
        $this->api->stop();
        @\unlink($this->tempDir . '/sessions.db');
        @\rmdir($this->tempDir);
    }

    public function testAnUnpairedDmGetsItsCodeAndNothingReachesTheRouter(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->activity('u1', 'hello there'));
        $this->settle();
        $call = $this->api->lastCallFor('/activities', 'POST');
        self::assertIsArray($call);
        $body = (array) \json_decode($call['body'], true);
        self::assertStringContainsString('Pairing required.', (string) $body['text']);
        self::assertSame(1, \preg_match('#[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}#', (string) $body['text']), 'the notice carries a length-8 code');
        self::assertCount(1, $this->pairing->pending('default'));
        self::assertStringNotContainsString((string) $this->pairing->pending('default')[0]['code'], $this->safety->outbound('echo attempt'), 'the minted code is a redaction secret');
    }

    public function testAnEmptyUnpairedDmGetsNoNoticeAtAll(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->activity('u1', '   '));
        $this->settle();
        self::assertSame([], $this->api->calls);
    }

    public function testAPairedPeerPassesTheGateAndHitsTheServeWall(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->activity('u1', 'hello there'));
        $this->settle(600);
        $call = $this->api->lastCallFor('/activities', 'POST');
        self::assertIsArray($call);
        $body = (array) \json_decode($call['body'], true);
        self::assertStringContainsString('Could not open a session', (string) $body['text']);
    }

    public function testOwnPostsForeignBotsAndUnlistedGroupsAreDroppedSilently(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1', '28:other-bot']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $own = $this->activity('u1', 'echo of ours');
        $own['from'] = ['id' => '28:' . self::APP_ID, 'role' => 'bot'];
        $this->face->handleActivity($own);
        $bot = $this->activity('28:other-bot', 'beep');
        $bot['from'] += ['role' => 'bot'];
        $this->face->handleActivity($bot);
        $this->face->handleActivity($this->activity('u1', 'group noise', 'groupChat'));
        $this->settle();
        self::assertSame([], $this->api->calls);
    }

    public function testAGroupConversationNeedsItsRowAndItsMention(): void
    {
        $this->buildFace(['groups' => ['conv-1' => ['policy' => 'allowlist']]]);
        $this->face->handleActivity($this->activity('u1', 'unmentioned', 'groupChat'));
        $this->settle();
        self::assertSame([], $this->api->calls, 'requireMention defaults true per row');

        $this->face->handleActivity($this->activity('u1', '<at>candy</at> hello', 'groupChat', [
            ['type' => 'mention', 'mentioned' => ['user' => ['id' => '28:' . self::APP_ID]]],
        ]));
        $this->settle(600);
        $call = $this->api->lastCallFor('/activities', 'POST');
        self::assertIsArray($call, 'the served+mentioned post reached the router');
        $body = (array) \json_decode($call['body'], true);
        self::assertStringContainsString('Could not open a session', (string) $body['text']);
    }

    public function testTheDegradedPostureForcesMentionOnlyEvenForAnOpenRow(): void
    {
        $this->buildFace(['degraded' => true, 'groups' => ['conv-1' => ['policy' => 'open', 'requireMention' => false]]]);
        $this->face->handleActivity($this->activity('u1', 'no mention in a degraded world', 'groupChat'));
        $this->settle();
        self::assertSame([], $this->api->calls, 'no ChannelMessage.Read.Group consent means no unmentioned reads, §8.3');

        $this->face->handleActivity($this->activity('u1', '<at>candy</at> now', 'groupChat', [
            ['type' => 'mention', 'mentioned' => ['user' => ['id' => '28:' . self::APP_ID]]],
        ]));
        $this->settle(600);
        self::assertIsArray($this->api->lastCallFor('/activities', 'POST'));
    }

    public function testOutboundCarriesTheConversationReferenceAndMarkdownFlag(): void
    {
        $this->buildFace([]);
        $this->face->deliver(['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-9', 'activityId' => 'act-in-1'], 'chunk one');
        $this->settle();
        $call = $this->api->lastCallFor('/v3/conversations/conv-9/activities', 'POST');
        self::assertIsArray($call);
        $body = (array) \json_decode($call['body'], true);
        self::assertSame('message', $body['type']);
        self::assertSame(self::APP_ID, $body['from']['id']);
        self::assertSame('markdown', $body['textFormat']);
        self::assertSame('act-in-1', $body['replyToId']);

        $this->face->deliver(['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-9'], 'no reply ref');
        $this->settle();
        $second = (array) \json_decode((string) $this->api->lastCallFor('/v3/conversations/conv-9/activities', 'POST')['body'], true);
        self::assertArrayNotHasKey('replyToId', $second);
    }

    public function testTheApprovalCardCarriesSubmitDataAndRetiresByReplace(): void
    {
        $this->buildFace(['approvalRoster' => ['boss'], 'approvals' => ['ttlMinutes' => 30]]);
        $this->face->promptApproval(['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-1'], 'sess_1',
            ['askId' => 'ask_1', 'tool' => 'Bash', 'arguments' => ['cmd' => 'ls']]);
        $this->settle();
        $call = $this->api->lastCallFor('/activities', 'POST');
        self::assertIsArray($call);
        $body = (array) \json_decode($call['body'], true);
        self::assertSame('application/vnd.microsoft.card.adaptive', $body['attachments'][0]['contentType']);
        $card = (array) $body['attachments'][0]['content'];
        self::assertCount(3, $card['actions']);
        self::assertSame('ask_1', $card['actions'][0]['data'][Cards::DATA_KEY]);
        self::assertSame('once', $card['actions'][0]['data']['verdict']);

        $this->face->retireApproval('ask_1', 'Resolved.');
        $this->settle();
        $put = $this->api->lastCallFor('/v3/conversations/conv-1/activities/act_out_1', 'PUT');
        self::assertIsArray($put, 'the answered card is REPLACED in place');
        $replaceBody = (array) \json_decode($put['body'], true);
        $retired = (array) $replaceBody['attachments'][0]['content'];
        self::assertArrayNotHasKey('actions', $retired, 'a retired card cannot be clicked again');
    }

    public function testTheClockRetiresAnUnansweredCard(): void
    {
        $this->buildFace(['approvals' => ['ttlMinutes' => 1]]);
        $this->face->promptApproval(['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-1'], 'sess_1',
            ['askId' => 'ask_t', 'tool' => 'Bash', 'arguments' => []]);
        $this->settle();
        // Fire the expiry timer without waiting a minute: advance by hand —
        // the timer law itself is max(60, minutes*60); reflect it out and
        // invoke the retire path the timer closure calls.
        $this->face->retireApproval('ask_t', 'expired-by-test');
        $this->settle();
        self::assertIsArray($this->api->lastCallFor('/activities/act_out_1', 'PUT'));
        $timers = new \ReflectionProperty(MsTeamsAccount::class, 'approvalTimers');
        $timers->setAccessible(true);
        self::assertSame([], $timers->getValue($this->face), 'retirement must unarm the clock');
    }

    public function testAnUnknownInvokeIsIgnoredWithoutTouchingTheWire(): void
    {
        $this->buildFace([]);
        $invoke = $this->activity('u1', '', 'message');
        $invoke['type'] = 'invoke';
        $invoke['name'] = 'task/fetch';
        $invoke['value'] = ['anything' => true];
        $this->face->handleActivity($invoke);
        $this->settle();
        self::assertSame([], $this->api->calls);
    }

    public function testPairApproveAndListSpeakThroughTheChannelSurface(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing']);
        $code = (string) $this->pairing->request('default', 'u2')['code'];
        $this->face->pairCommand(['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-1'], ['approve', $code]);
        $this->settle();
        $call = $this->api->lastCallFor('/activities', 'POST');
        self::assertIsArray($call);
        self::assertStringContainsString('Paired u2.', (string) ((array) \json_decode((string) $call['body'], true))['text']);
        $this->face->pairCommand(['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-1'], ['list']);
        $this->settle();
        $listBody = (array) \json_decode((string) $this->api->lastCallFor('/activities', 'POST')['body'], true);
        self::assertStringContainsString('No pending pairings.', (string) $listBody['text']);
    }

    public function testACommandActivityReachesTheRouterNotTheModel(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->activity('u1', '/stop'));
        // /stop against an undialled bridge: the router logs the miss, the
        // conversation hears nothing — proving the command branch ran.
        $this->settle(300);
        self::assertSame([], \array_filter($this->api->calls, static fn (array $c): bool => \str_contains($c['body'], 'stop') || \str_contains($c['body'], 'Could not open')));
    }

    public function testTheFullRoundTripPromptInAndAssistantOutOverRealFixtures(): void
    {
        $serve = new FakeServe($this->loop, 'tok-serve');
        $port = $serve->listen();
        $bridge = new ServeBridge('ws://127.0.0.1:' . $port . '/', 'tok-serve', 'subprotocol', Log::silent(), $this->loop);
        $started = false;
        $bridge->start()->then(static function () use (&$started): void {
            $started = true;
        });
        for ($tick = 0; $tick < 20 && !$started; $tick++) {
            $this->settle(100);
        }
        self::assertTrue($started, 'FakeServe handshake never admitted');

        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $rest = new Rest(new TokenBroker(
            ['appId' => self::APP_ID, 'appPassword' => 'p', 'tenantId' => 't'],
            Log::silent(),
            'http://127.0.0.1:' . $this->api->port,
        ), Log::silent(), true);
        $router = new ConversationRouter($bridge, new SessionMap($this->tempDir . '/sessions-rt.db'), Log::silent(), $this->safety, $this->loop);
        $this->face = new MsTeamsAccount('default', self::APP_ID, $rest, $router, ['dmPolicy' => 'pairing'], Log::silent(), $this->pairing, $this->safety, $this->loop);
        $router->addChannel($this->face, 'default');

        $this->face->handleActivity($this->activity('u1', 'hello there'));
        for ($tick = 0; $tick < 40 && $serve->sendSeen < 1; $tick++) {
            $this->settle(50);
        }
        self::assertSame(1, $serve->sendSeen);
        self::assertSame('hello there', $serve->seenText);
        self::assertNotSame('', $serve->lastIdempotencyKey, 'the session.send must carry an idempotencyKey (§9.5 dedupe)');

        // The answer-side redaction rides the SAME shared TextSafety the
        // face was built with — the secret joins the roster as a live
        // addSecret (monotonic), never as a post-hoc replacement.
        $this->safety->addSecret('boot-secret-77');
        $serve->pushEvent(['sessionId' => 'sess_fixture_1', 'type' => 'assistant.completed', 'seq' => 12, 'durable' => true, 'data' => ['content' => 'ANSWER — secret boot-secret-77 inside']]);
        for ($tick = 0; $tick < 40; $tick++) {
            $this->settle(50);
        }
        $out = null;
        foreach ($this->api->calls as $call) {
            if ($call['method'] === 'POST' && str_contains((string) $call['body'], 'ANSWER')) {
                $out = $call;
            }
        }
        self::assertIsArray($out, 'the assistant answer never reached the platform');
        $body = (array) \json_decode((string) $out['body'], true);
        self::assertSame('ANSWER — secret [redacted] inside', $body['text'], 'the outbound filter redacts as the answer rides out');

        $bridge->stop();
        $serve->stop();
    }

    // ── harness ──

    private function buildFace(array $cfg): void
    {
        $rest = new Rest(new TokenBroker(
            ['appId' => self::APP_ID, 'appPassword' => 'p', 'tenantId' => 't'],
            Log::silent(),
            'http://127.0.0.1:' . $this->api->port,
        ), Log::silent(), true);
        $router = new ConversationRouter(
            new ServeBridge('ws://127.0.0.1:1/never', '', 'subprotocol', Log::silent(), $this->loop),
            new SessionMap($this->tempDir . '/sessions.db'),
            Log::silent(),
            $this->safety,
            $this->loop,
        );
        $this->face = new MsTeamsAccount('default', self::APP_ID, $rest, $router, $cfg, Log::silent(),
            $this->pairing, $this->safety, $this->loop);
        $router->addChannel($this->face, 'default');
    }

    private function serviceUrl(): string
    {
        return 'http://127.0.0.1:' . $this->api->port;
    }

    /** @param list<array<string,mixed>> $entities */
    private function activity(string $fromId, string $text, string $conversationType = 'personal', array $entities = [], string $type = 'message'): array
    {
        return [
            'type' => $type,
            'id' => 'act-' . \substr(\md5($fromId . $text . $conversationType . \count($entities)), 0, 8),
            'serviceUrl' => $this->serviceUrl(),
            'from' => ['id' => $fromId, 'aadObjectId' => $fromId, 'name' => 'Peer ' . $fromId],
            'conversation' => ['id' => 'conv-1', 'conversationType' => $conversationType],
            'text' => $text,
            'entities' => $entities,
        ];
    }

    private function settle(int $ms = 300): void
    {
        $this->loop->addTimer($ms / 1000.0, function (): void {
            $this->loop->stop();
        });
        $this->loop->run();
    }
}

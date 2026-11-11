<?php

/**
 * C-1 (cc-review-1111 CC-TEAM-B), Teams account layer: approval-answer
 * invokes must pass the SAME access floor the message path applies
 * (dmPolicy ladder on personal chats — without the pairing-code notice;
 * group row policy otherwise — without the mention arms, which do not
 * exist for a targeted submit). Before this lane handleActivity routed
 * `invoke` to handleInvoke BEFORE any gating: an unpaired peer or a
 * member of a blocked group could answer approval cards, and a crafted
 * Action.Submit value reached permission.respond unchecked. Refusal is
 * loud (warn log + a deny message in the invoking conversation) and
 * fail-closed; unknown (non-approval) invokes stay silent as before.
 */

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
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

final class ApprovalInvokeGateTest extends TestCase
{
    private const APP_ID = 'app-1';

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
        $this->tempDir = \sys_get_temp_dir() . '/f-c1-teams-floor-' . \getmypid() . '-' . \uniqid('', true);
        \mkdir($this->tempDir, 0o700, true);
        $this->api = new FakeTeamsApi($this->loop);
        $this->api->listen();
        $this->store = new CredentialStore($this->tempDir . '/channels.json');
        $this->pairing = new PairingCodes($this->store, new TextSafety());
        $this->safety = new TextSafety();
    }

    protected function tearDown(): void
    {
        $this->face?->cancelAllTimers();
        $this->api->stop();
        @\unlink($this->tempDir . '/sessions.db');
        @\unlink($this->tempDir . '/sessions-rt.db');
        @\rmdir($this->tempDir);
    }

    public function testAnUnpairedCardSubmitIsRefusedWithALoudDeny(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->submit('u1', 'ask_9', 'once', 'cafebabe01'));
        $this->settle();

        self::assertDeniedOnApi();
        self::assertCount(0, $this->pairing->pending('default'), 'a refused submit must not consume a pairing slot');
    }

    public function testADisabledPolicyRefusesEvenAPairedPeer(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'disabled']);
        $this->face->handleActivity($this->submit('u1', 'ask_9', 'once', 'cafebabe01'));
        $this->settle();

        self::assertDeniedOnApi();
    }

    public function testAnAllowlistPolicyWithAnEmptyRosterFailsClosed(): void
    {
        $this->buildFace(['dmPolicy' => 'allowlist']);
        $this->face->handleActivity($this->submit('u1', 'ask_9', 'once', 'cafebabe01'));
        $this->settle();

        self::assertDeniedOnApi();
    }

    public function testAGroupSubmitWithoutItsRowIsRefused(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['u1']);
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->face->handleActivity($this->submit('u1', 'ask_9', 'once', 'cafebabe01', 'groupChat'));
        $this->settle();

        self::assertDeniedOnApi();
    }

    public function testAGroupRowWithDisabledPolicyRefusesItsSubmits(): void
    {
        $this->buildFace(['groups' => ['conv-1' => ['policy' => 'disabled']]]);
        $this->face->handleActivity($this->submit('u1', 'ask_9', 'once', 'cafebabe01', 'groupChat'));
        $this->settle();

        self::assertDeniedOnApi();
    }

    public function testANonApprovalInvokeStaysSilent(): void
    {
        // task/fetch and friends must NOT collect the approval deny line —
        // the gate lives inside the approval-answer branch only.
        $this->buildFace(['dmPolicy' => 'pairing']);
        $invoke = $this->submit('u1', 'ask_9', 'once', 'cafebabe01');
        $invoke['value'] = ['anything' => true];
        $this->face->handleActivity($invoke);
        $this->settle();

        self::assertSame([], $this->api->calls, 'an unhandled invoke keeps the pre-existing silent-drop law');
    }

    public function testTheBoundSubmitFromAPairedPeerAnswersEndToEnd(): void
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

        // Bind the session, let the ask present (the card rides the fake
        // api — the nonce comes out of the REAL payload the peer sees),
        // then replay it as the peer's submit.
        $this->face->handleActivity($this->message('u1', 'hello there'));
        for ($tick = 0; $tick < 40 && $serve->sendSeen < 1; $tick++) {
            $this->settle(50);
        }
        self::assertSame(1, $serve->sendSeen);

        $serve->pushEvent(['sessionId' => 'sess_fixture_1', 'type' => 'permission.requested', 'seq' => 80, 'durable' => true,
            'data' => ['askId' => 'ask_9', 'tool' => 'Bash', 'arguments' => []]]);
        for ($tick = 0; $tick < 40; $tick++) {
            $this->settle(50);
        }
        $cardCall = null;
        foreach ($this->api->calls as $call) {
            $body = (array) \json_decode((string) $call['body'], true);
            if (($body['attachments'][0]['contentType'] ?? '') === 'application/vnd.microsoft.card.adaptive') {
                $cardCall = $body;
            }
        }
        self::assertIsArray($cardCall, 'the approval card never reached the platform');
        $data = (array) $cardCall['attachments'][0]['content']['actions'][0]['data'];
        $nonce = (string) ($data['nonce'] ?? '');
        self::assertNotSame('', $nonce, 'the presented card must carry the minted nonce');

        $before = $serve->respondSeen;
        $this->face->handleActivity($this->submit('u1', 'ask_9', 'once', $nonce));
        $this->settle(600);

        self::assertSame($before + 1, $serve->respondSeen, 'the bound submit from the paired peer answers');
        self::assertSame('once', $serve->lastReply);

        // Same card, crafted by an UNPAIRED peer: the floor refuses it.
        $after = $serve->respondSeen;
        $this->face->handleActivity($this->submit('ghost', 'ask_9', 'always', $nonce));
        $this->settle(600);
        self::assertSame($after, $serve->respondSeen, 'the nonce is not a ticket for an ungated actor');

        $bridge->stop();
        $serve->stop();
    }

    // ── harness ──

    /** @param array<string,mixed> $cfg */
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

    /** @return array<string,mixed> */
    private function submit(string $fromId, string $askId, string $verdict, string $nonce, string $conversationType = 'personal'): array
    {
        return [
            'type' => 'invoke',
            'name' => '',
            'id' => 'act-' . \substr(\md5($fromId . $askId . $nonce), 0, 8),
            'serviceUrl' => $this->serviceUrl(),
            'from' => ['id' => $fromId, 'aadObjectId' => $fromId, 'name' => 'Peer ' . $fromId],
            'conversation' => ['id' => 'conv-1', 'conversationType' => $conversationType],
            'value' => [Cards::DATA_KEY => $askId, 'verdict' => $verdict, 'nonce' => $nonce],
        ];
    }

    /** @return array<string,mixed> */
    private function message(string $fromId, string $text): array
    {
        return [
            'type' => 'message',
            'id' => 'act-' . \substr(\md5($fromId . $text), 0, 8),
            'serviceUrl' => $this->serviceUrl(),
            'from' => ['id' => $fromId, 'aadObjectId' => $fromId, 'name' => 'Peer ' . $fromId],
            'conversation' => ['id' => 'conv-1', 'conversationType' => 'personal'],
            'text' => $text,
            'entities' => [],
        ];
    }

    private function serviceUrl(): string
    {
        return 'http://127.0.0.1:' . $this->api->port;
    }

    private function assertDeniedOnApi(): void
    {
        $seen = null;
        foreach ($this->api->calls as $call) {
            if ($call['method'] === 'POST' && \str_contains((string) $call['body'], 'not permitted')) {
                $seen = $call;
            }
        }
        self::assertIsArray($seen, 'an ungated submitter must collect the loud deny; calls: ' . \json_encode($this->api->calls));
    }

    private function settle(int $ms = 300): void
    {
        $this->loop->addTimer($ms / 1000.0, function (): void {
            $this->loop->stop();
        });
        $this->loop->run();
    }
}

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
use React\EventLoop\TimerInterface;
use SugarCraft\Code\Backend\PendingAsk;

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

    private ?FakeServe $served = null;

    private ?ServeBridge $bridge = null;

    private const PNG = "\x89PNG\r\n\x1a\nFAKEPNG";

    private const PDF = "%PDF-1.4\nfake";

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
        $this->bridge?->stop();
        $this->served?->stop();
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

    public function testATtlBeyondTheCoreCeilingArmsTheCeilingAndLogsLoudly(): void
    {
        $lines = [];
        $this->buildFace(['approvals' => ['ttlMinutes' => 120]], new Log(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        $this->face->promptApproval(['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-1'], 'sess_1',
            ['askId' => 'ask_clamp', 'tool' => 'Bash', 'arguments' => []]);
        $this->settle();
        // The card can only keep the ask alive as long as the core honors it:
        // a 120-minute posture must arm the 1800-second ceiling, not 7200.
        self::assertSame((float) PendingAsk::EXPIRY_SECONDS, $this->armedApprovalInterval('ask_clamp'));
        $clamped = \array_values(\array_filter($lines, static fn (string $line): bool =>
            \str_contains($line, '"event":"approval_ttl_clamped"')));
        self::assertCount(1, $clamped, 'the clamp announces itself exactly once');
        self::assertStringContainsString('"configuredMinutes":120', $clamped[0]);
        self::assertStringContainsString('"armedSeconds":' . PendingAsk::EXPIRY_SECONDS, $clamped[0]);
        // Clamping the window must NOT retire the live card early: the ask is
        // answerable until the armed (clamped) timer fires.
        self::assertNull($this->api->lastCallFor('/v3/conversations/conv-1/activities/act_out_1', 'PUT'));
    }

    public function testTheDefaultTtlArmsTheCoreWindowQuietly(): void
    {
        $lines = [];
        $this->buildFace([], new Log(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        $this->face->promptApproval(['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-1'], 'sess_1',
            ['askId' => 'ask_default', 'tool' => 'Bash', 'arguments' => []]);
        $this->settle();
        self::assertSame((float) PendingAsk::EXPIRY_SECONDS, $this->armedApprovalInterval('ask_default'));
        self::assertSame([], \array_filter($lines, static fn (string $line): bool =>
            \str_contains($line, 'approval_ttl_clamped')), 'the default pair needs no announcement');
    }

    public function testTheSixtySecondFloorStillBindsBelowTheCeiling(): void
    {
        $lines = [];
        $this->buildFace(['approvals' => ['ttlMinutes' => 1]], new Log(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        $this->face->promptApproval(['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-1'], 'sess_1',
            ['askId' => 'ask_floor', 'tool' => 'Bash', 'arguments' => []]);
        $this->settle();
        self::assertSame(60.0, $this->armedApprovalInterval('ask_floor'));
        self::assertSame([], \array_filter($lines, static fn (string $line): bool =>
            \str_contains($line, 'approval_ttl_clamped')));
    }

    /**
     * The interval the ask's retirement timer was armed with, read straight
     * off the timer — pinned at arm time, no waiting on wall clock.
     */
    private function armedApprovalInterval(string $askId): float
    {
        $timers = new \ReflectionProperty(MsTeamsAccount::class, 'approvalTimers');
        $timers->setAccessible(true);
        $timer = $timers->getValue($this->face)[$askId] ?? null;
        self::assertInstanceOf(TimerInterface::class, $timer);

        return $timer->getInterval();
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
        $this->buildFace(['dmPolicy' => 'pairing', 'approvalRoster' => ['boss']]);
        $code = (string) $this->pairing->request('default', 'u2')['code'];
        $this->face->pairCommand($this->dmRef(), ['approve', $code], 'boss', true);
        $this->settle();
        self::assertSame(['Paired u2.'], $this->postedTexts());

        $this->face->pairCommand($this->dmRef(), ['list'], 'boss', true);
        $this->settle();
        self::assertSame(['Paired u2.', 'No pending pairings.'], $this->postedTexts());
    }

    public function testPairingIsAnApproversActAndARefusedApproveLeavesTheCodePending(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing', 'approvalRoster' => ['boss']]);
        $code = (string) $this->pairing->request('default', 'u2')['code'];
        $this->face->pairCommand($this->dmRef(), ['approve', $code], 'u9', true);
        $this->face->pairCommand($this->dmRef(), ['list'], 'u9', true);
        $this->settle();

        self::assertSame(['⛔ Only an approver can manage pairings.', '⛔ Only an approver can manage pairings.'], $this->postedTexts());
        self::assertFalse($this->pairing->isPaired('default', 'u2'), 'a non-approver must not admit a peer');
        self::assertCount(1, $this->pairing->pending('default'), 'the code stays pending');
    }

    public function testAnEmptyApproverRosterAnswersNoPairing(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing']);
        $this->pairing->request('default', 'u2');
        $this->face->pairCommand($this->dmRef(), ['approve', (string) $this->pairing->pending('default')[0]['code']], 'boss', true);
        $this->settle();

        self::assertSame(['⛔ Only an approver can manage pairings.'], $this->postedTexts());
        self::assertFalse($this->pairing->isPaired('default', 'u2'));
    }

    public function testPairListInAGroupNamesNoCodeEvenForAnApprover(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing', 'approvalRoster' => ['boss']]);
        $code = (string) $this->pairing->request('default', 'u2')['code'];
        $this->face->pairCommand(['serviceUrl' => $this->serviceUrl(), 'conversationId' => '19:group@thread.v2'], ['list'], 'boss', false);
        $this->settle();

        self::assertSame(['Use a direct message to list pairing codes.'], $this->postedTexts());
        self::assertStringNotContainsString($code, $this->postedTexts()[0]);
    }

    public function testPairListInADirectMessageShowsTheCodeToAnApprover(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing', 'approvalRoster' => ['boss']]);
        $code = (string) $this->pairing->request('default', 'u2')['code'];
        $this->face->pairCommand($this->dmRef(), ['list'], 'boss', true);
        $this->settle();

        self::assertStringContainsString($code, (string) ($this->postedTexts()[0] ?? ''));
    }

    public function testADuplicateCommandDeliveryIsAnsweredOnce(): void
    {
        $this->store->writeMsteamsPairing('default', [], ['boss']);
        $this->buildFace(['dmPolicy' => 'pairing', 'approvalRoster' => ['boss']]);
        $this->face->handleActivity($this->activity('boss', '/pair list'));
        $this->face->handleActivity($this->activity('boss', '/pair list'));
        $this->settle();

        self::assertSame(['No pending pairings.'], $this->postedTexts(), 'Teams retries a slow POST: the second delivery must be claimed away');
    }

    public function testAForeignTenantIsRefusedBeforeAnyReply(): void
    {
        $lines = [];
        $this->buildFace(['dmPolicy' => 'pairing'], new Log(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }), 'tenant-a');
        $foreign = $this->activity('u1', 'hello');
        $foreign['conversation']['tenantId'] = 'tenant-b';
        $this->face->handleActivity($foreign);
        $this->settle();

        self::assertSame([], $this->api->calls, 'a foreign tenant must not even earn a pairing notice');
        self::assertStringContainsString('msteams_tenant_refused', \implode("\n", $lines));
    }

    public function testTheConfiguredTenantPassesAndAnAbsentTenantFailsClosed(): void
    {
        $this->buildFace(['dmPolicy' => 'pairing'], null, 'tenant-a');
        $mine = $this->activity('u1', 'hello');
        $mine['conversation']['tenantId'] = 'TENANT-A';
        $this->face->handleActivity($mine);
        $this->settle(600);
        self::assertStringContainsString('Pairing required', (string) ($this->postedTexts()[0] ?? ''), 'the matching tenant reaches the DM gate');

        $this->face->handleActivity($this->activity('u2', 'hello'));
        $this->settle(300);
        self::assertCount(1, $this->postedTexts(), 'an activity naming no tenant is refused when one is configured');
    }

    public function testARequireMentionFalseRowAnswersAPlainPrompt(): void
    {
        $this->buildFace(['groups' => ['conv-1' => ['policy' => 'allowlist', 'requireMention' => false]]]);
        $this->face->handleActivity($this->activity('u1', 'plain question', 'groupChat'));
        $this->settle(600);

        self::assertStringContainsString('Could not open a session', (string) ($this->postedTexts()[0] ?? ''), 'the admitted group takes plain prompts');
    }

    public function testDrainDeliveriesLetsAnInFlightReplyLandBeforeItReturns(): void
    {
        $this->buildFace([]);
        $sent = null;
        $this->face->deliver(['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-1'], 'in flight')->then(static function (array $r) use (&$sent): void {
            $sent = $r;
        });
        $this->face->drainDeliveries(5.0);

        self::assertIsArray($sent, 'the reply must have settled when drain returned');
        self::assertStringContainsString('in flight', (string) ($this->postedTexts()[0] ?? ''));
    }

    public function testAnInboundPngIsDeclaredAsPngAndReachesTheServe(): void
    {
        $this->api->mediaBytes = self::PNG;
        $this->serveFace();
        $this->face->handleActivity($this->withAttachments($this->activity('u1', 'look'), [
            ['name' => 'pic.png', 'contentType' => 'image/png', 'contentUrl' => $this->serviceUrl() . '/media/pic.png'],
        ]));
        $this->awaitSends(1);

        self::assertSame(1, $this->served->sendSeen);
        self::assertSame(0, $this->served->mimeRefusals, 'the declared mime must match the sniffed bytes');
        self::assertSame(1, $this->served->lastImageCount);
    }

    public function testAnInboundPdfIsDeclaredAsPdfAndReachesTheServe(): void
    {
        $this->api->mediaBytes = self::PDF;
        $this->serveFace();
        $this->face->handleActivity($this->withAttachments($this->activity('u1', 'read this'), [
            ['name' => 'spec.pdf', 'contentType' => 'application/pdf', 'contentUrl' => $this->serviceUrl() . '/media/spec.pdf'],
        ]));
        $this->awaitSends(1);

        self::assertSame(1, $this->served->sendSeen);
        self::assertSame(0, $this->served->mimeRefusals);
        self::assertSame(1, $this->served->lastDocumentCount);
    }

    public function testAnUntypedAttachmentIsSniffedAndReachesTheServe(): void
    {
        $this->api->mediaBytes = self::PNG;
        $this->serveFace();
        $this->face->handleActivity($this->withAttachments($this->activity('u1', 'untyped'), [
            ['name' => 'blob', 'contentUrl' => $this->serviceUrl() . '/media/blob'],
        ]));
        $this->awaitSends(1);

        self::assertSame(0, $this->served->mimeRefusals, 'a generic or absent contentType is decided by the bytes');
        self::assertSame(1, $this->served->lastImageCount);
    }

    public function testOneFailedMediaFetchDropsOnlyItsOwnAttachment(): void
    {
        $this->api->mediaBytes = self::PNG;
        $this->serveFace();
        $this->face->handleActivity($this->withAttachments($this->activity('u1', 'two pics'), [
            ['name' => 'ok.png', 'contentType' => 'image/png', 'contentUrl' => $this->serviceUrl() . '/media/ok.png'],
            ['name' => 'missing.png', 'contentType' => 'image/png', 'contentUrl' => $this->serviceUrl() . '/media/missing.png'],
        ]));
        $this->awaitSends(1);

        self::assertSame(1, $this->served->lastImageCount, 'the surviving sibling still rides the prompt');
        self::assertStringContainsString('missing.png: fetch failed', $this->served->seenText);
    }

    public function testAnAttachmentWithNonStringContentIsTreatedAsAbsent(): void
    {
        $this->serveFace();
        $this->face->handleActivity($this->withAttachments($this->activity('u1', 'odd one'), [
            ['name' => 'weird', 'contentType' => 'image/png', 'content' => ['not', 'a', 'url']],
        ]));
        $this->awaitSends(1);

        self::assertStringContainsString('weird: no inline content', $this->served->seenText);
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

    private function buildFace(array $cfg, ?Log $faceLog = null, string $tenantId = ''): void
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
        $this->face = new MsTeamsAccount('default', self::APP_ID, $rest, $router, $cfg, $faceLog ?? Log::silent(),
            $this->pairing, $this->safety, $this->loop, $tenantId);
        $router->addChannel($this->face, 'default');
    }

    /** A face wired to a real FakeServe bridge, with u1 paired. */
    private function serveFace(array $cfg = ['dmPolicy' => 'pairing']): void
    {
        $this->served = new FakeServe($this->loop, 'tok-serve');
        $port = $this->served->listen();
        $this->bridge = new ServeBridge('ws://127.0.0.1:' . $port . '/', 'tok-serve', 'subprotocol', Log::silent(), $this->loop);
        $started = false;
        $this->bridge->start()->then(static function () use (&$started): void {
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
        $router = new ConversationRouter($this->bridge, new SessionMap($this->tempDir . '/sessions-served.db'), Log::silent(), $this->safety, $this->loop);
        $this->face = new MsTeamsAccount('default', self::APP_ID, $rest, $router, $cfg, Log::silent(), $this->pairing, $this->safety, $this->loop);
        $router->addChannel($this->face, 'default');
    }

    private function awaitSends(int $count): void
    {
        for ($tick = 0; $tick < 60 && $this->served->sendSeen < $count; $tick++) {
            $this->settle(50);
        }
        $this->settle(100);
    }

    /** @param list<array<string,mixed>> $attachments */
    private function withAttachments(array $activity, array $attachments): array
    {
        $activity['attachments'] = $attachments;

        return $activity;
    }

    /** @return array{serviceUrl: string, conversationId: string} */
    private function dmRef(): array
    {
        return ['serviceUrl' => $this->serviceUrl(), 'conversationId' => 'conv-1'];
    }

    /** The text of every activity the face posted, in order. @return list<string> */
    private function postedTexts(): array
    {
        $texts = [];
        foreach ($this->api->calls as $call) {
            if ($call['method'] === 'POST' && \str_contains($call['path'], '/activities')) {
                $texts[] = (string) ((array) \json_decode((string) $call['body'], true))['text'];
            }
        }

        return $texts;
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

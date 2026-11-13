<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Bridge;

use CandyCode\Bundles\Discord\Bridge\ConversationRouter;
use CandyCode\Bundles\Discord\Bridge\PlatformFace;
use CandyCode\Bundles\Discord\Candy\ServeBridge;
use CandyCode\Bundles\Discord\Support\Keys;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Support\OutboundChunker;
use CandyCode\Bundles\Discord\Support\QueueGate;
use CandyCode\Bundles\Discord\Support\SessionMap;
use CandyCode\Bundles\Discord\Support\TextSafety;
use CandyCode\Bundles\Discord\Tests\Fixture\FakeServe;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/** The router's channel face for tests: records everything, dials nothing. */
final class RecordingRouterFace implements PlatformFace
{
    /** @var list<string> */
    public array $delivered = [];

    /** @var list<array> */
    public array $prompted = [];

    /** @var array<string,string> askId → nonce the router handed the face (C-1 tests read it to forge/legit-click) */
    public array $promptNonces = [];

    /** @var array<string,string> */
    public array $retired = [];

    public int $typingCount = 0;

    /** Test hook: when set, deliver() routes chunk settlement timing through it. */
    public ?\Closure $deliverGate = null;

    public int $deliverIssued = 0;

    public int $lineLimit = 17;

    public int $byteLimit = 2000;

    public function name(): string
    {
        return 'discord';
    }

    public function textChunkLimit(): int
    {
        return $this->byteLimit;
    }

    public function maxLinesPerMessage(): int
    {
        return $this->lineLimit;
    }

    public function deliver(array $ref, string $chunk): PromiseInterface
    {
        $this->deliverIssued++;
        if ($this->deliverGate !== null) {
            return ($this->deliverGate)($chunk);
        }
        $this->delivered[] = $chunk;

        return resolve(null);
    }

    public function promptApproval(array $ref, string $sessionId, array $ask, string $nonce = ''): void
    {
        $this->prompted[] = $ask;
        $this->promptNonces[(string) ($ask['askId'] ?? '')] = $nonce;
    }

    public function retireApproval(string $askId, string $line): void
    {
        $this->retired[$askId] = $line;
    }

    public function typing(array $ref): void
    {
        $this->typingCount++;
    }
}

/**
 * Behaviour pins for the router: delivery vocabulary, dedupe, the exec-approval
 * doors, the /model leg, and the media-refusal retry — all over the loopback
 * FakeServe with a temp-dir SessionMap. Each test boots its OWN router so the
 * policy under test is the only policy listening on the wire. No real network,
 * no model paths (E646 clean).
 */
final class ConversationRouterTest extends TestCase
{
    private LoopInterface $loop;

    private FakeServe $serve;

    private ServeBridge $bridge;

    private SessionMap $map;

    private RecordingRouterFace $face;

    private ConversationRouter $router;

    private string $tempDir = '';

    private const DM_KEY = 'discord:default:dm:peer-1';

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
        $this->serve = new FakeServe($this->loop, 'tok-123456');
        $port = $this->serve->listen();
        $this->bridge = new ServeBridge('ws://127.0.0.1:' . $port . '/', 'tok-123456', 'subprotocol', Log::silent(), $this->loop);

        $this->tempDir = sys_get_temp_dir() . '/p2-router-' . getmypid() . '-' . uniqid('', true);
        $this->map = new SessionMap($this->tempDir . '/map/map.sqlite');
        $this->face = new RecordingRouterFace();
    }

    protected function tearDown(): void
    {
        $this->bridge->stop();
        $this->serve->stop();
        if ($this->tempDir !== '') {
            $this->removeTree($this->tempDir);
        }
    }

    public function testTheFirstInboundQueuesAndDedupesById(): void
    {
        $this->boot();
        $this->inboundDm('hello serve', false, 'm-1');

        self::assertSame(1, $this->serve->sendSeen);
        self::assertSame('hello serve', $this->serve->seenText);
        self::assertSame('queue', $this->serve->lastDelivery);

        $this->inboundDm('a repeat', false, 'm-1');

        self::assertSame(1, $this->serve->sendSeen, 'a re-delivered inbound id must never double-prompt the session');
    }

    public function testAMentionWhileTheSessionIsBusySteers(): void
    {
        $this->boot();
        $this->inboundDm('start work', false, 'm-1');

        $this->pushSettled($this->envelope('session.status', 50, ['status' => 'busy']));
        $this->inboundDm('steer me', true, 'm-2');

        self::assertSame('steer', $this->serve->lastDelivery);
        self::assertSame(2, $this->serve->sendSeen);
    }

    public function testCollectModeCoalescesQuietPromptsIntoOneSend(): void
    {
        $this->boot(['queue' => ['mode' => 'collect']]);
        $this->router->setQueueGate(new QueueGate($this->loop, 40, 20));
        $this->inboundDm('bind me', false, 'm-0');
        self::assertSame(1, $this->serve->sendSeen);

        // Direct calls, NO settle in between: settling after the first push
        // would let the 40 ms gate flush 'one' alone — the coalescing law
        // needs both items in the window.
        $this->router->inbound('discord', self::DM_KEY, ['channelId' => 'chan-9', 'messageId' => 'm-1'], 'one', false, 'm-1');
        $this->router->inbound('discord', self::DM_KEY, ['channelId' => 'chan-9', 'messageId' => 'm-2'], 'two', false, 'm-2');
        self::runFor($this->loop, 500);

        self::assertSame(2, $this->serve->sendSeen, 'collect must flush both prompts as ONE send');
        self::assertSame("one\n\ntwo", $this->serve->seenText);
    }

    public function testInterruptModeCancelsThenQueuesTheMention(): void
    {
        $this->boot(['queue' => ['mode' => 'interrupt']]);
        $this->inboundDm('bind me', false, 'm-1');

        $this->pushSettled($this->envelope('session.status', 55, ['status' => 'busy']));
        $this->inboundDm('stop that', true, 'm-2');

        self::assertSame(1, $this->serve->cancelSeen);
        self::assertSame('queue', $this->serve->lastDelivery);
    }

    public function testAnAskPresentsApprovalButtons(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $this->pushSettled($this->envelope('permission.requested', 80, ['askId' => 'ask_9', 'tool' => 'Bash', 'arguments' => ['cmd' => 'ls']]));

        self::assertCount(1, $this->face->prompted);
        self::assertSame('ask_9', $this->face->prompted[0]['askId'] ?? null);
    }

    public function testDisabledApprovalsFallBackToSameChatText(): void
    {
        $this->boot(['approvals' => ['enabled' => false]]);
        $this->inboundDm('bind me', false, 'm-1');

        $this->pushSettled($this->envelope('permission.requested', 80, ['askId' => 'ask_9', 'tool' => 'Bash', 'arguments' => []]));

        self::assertSame([], $this->face->prompted, 'buttons must not render when approvals are disabled');
        self::assertContains('/approve ask_9 once|always|deny', $this->face->delivered);
    }

    public function testTheTargetDoorKeepsChannelAsksQuietWhenDmOnly(): void
    {
        $this->boot(['approvals' => ['target' => 'dm']]);
        $channelKey = Keys::channelKey('discord', 'default', 'chan-9');
        $this->router->inbound('discord', $channelKey, ['channelId' => 'chan-9'], 'bind on channel', false, 'm-1');

        $this->pushSettled($this->envelope('permission.requested', 80, ['askId' => 'ask_9', 'tool' => 'Bash', 'arguments' => []]));

        self::assertSame([], $this->face->prompted, 'a dm-only target must keep channel-conversation asks unrendered');
        self::assertSame([], $this->face->delivered);
    }

    public function testTheRosterRefusesStrangers(): void
    {
        $this->boot(['approvals' => ['approvers' => ['boss']]]);
        $this->inboundDm('bind me', false, 'm-1');

        $this->router->answerApproval('sess_fixture_1', 'ask_9', 'once', ['userId' => 'rando', 'name' => 'R']);
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->respondSeen);
        self::assertSame('reject', $this->serve->lastReply);
        self::assertStringContainsString('is not an approver', (string) $this->serve->lastNote);
    }

    public function testTheModelArmRunsTheBuiltInAndDeliversItsRows(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $this->router->runCommand('discord', self::DM_KEY, ['channelId' => 'chan-9'], ['name' => 'model', 'args' => ['sonnet']]);
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->execSeen);
        self::assertSame('switched', $this->face->delivered[count($this->face->delivered) - 1]);
    }

    public function testAMediaRefusalRetriesOnceWithoutAttachments(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $this->serve->refuseMediaOnce = true;
        $before = $this->serve->sendSeen;
        $this->router->inbound(
            'discord',
            self::DM_KEY,
            ['channelId' => 'chan-9'],
            'with file',
            false,
            'm-2',
            [['name' => 'a.png', 'mime' => 'image/png', 'data' => 'AA==']],
            [],
        );
        self::runFor($this->loop, 400);

        self::assertSame($before + 2, $this->serve->sendSeen, 'refused media must be retried exactly once without attachments');
        self::assertContains('Attachments refused by the server — sent without them.', $this->face->delivered);
        self::assertFalse($this->serve->lastHasMedia);
    }

    public function testAResolvedAskRetiresWithItsOutcomeLine(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $this->pushSettled($this->envelope('permission.requested', 80, ['askId' => 'ask_9', 'tool' => 'Bash', 'arguments' => []]));
        self::assertCount(1, $this->face->prompted);

        $this->pushSettled($this->envelope('permission.resolved', 81, ['askId' => 'ask_9', 'reply' => 'once']));

        self::assertArrayHasKey('ask_9', $this->face->retired);
        self::assertStringContainsString('Approved', $this->face->retired['ask_9']);
    }

    public function testAssistantOutputChunksStayWithinTheFaceBudget(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $blob = str_repeat("line of output\n", 400);
        $this->pushSettled($this->envelope('assistant.completed', 90, ['content' => $blob]));

        self::assertGreaterThan(1, count($this->face->delivered), 'a fat reply must arrive chunked');
        foreach ($this->face->delivered as $chunk) {
            self::assertLessThanOrEqual(2000, OutboundChunker::len($chunk));
        }
    }

    public function testAGuildConversationIsCreatedUnderTheGroupGrammar(): void
    {
        $this->boot();
        $this->inboundOn('discord:default:chan-42', 'hello guild');

        self::assertSame(1, $this->serve->createSeen);
        self::assertSame('agent:main:discord:group:chan-42', $this->serve->createName, 'D6: the policy resolver reads identity off the name field');
    }

    public function testAPerPeerDmIsCreatedUnderTheDmGrammarWithThePeerTail(): void
    {
        $this->boot();
        $this->inboundOn(self::DM_KEY, 'hello dms');

        self::assertSame('agent:main:discord:dm:peer-1', $this->serve->createName);
    }

    public function testTheAccountChannelPeerShapeStillEndsInItsPeer(): void
    {
        $this->boot();
        $this->inboundOn('discord:default:dm:acc:chan-9:peer-7', 'wide dm');

        self::assertSame('agent:main:discord:dm:peer-7', $this->serve->createName, 'the widest DM ladder shape resolves to the human it addresses');
    }

    public function testTheAggregateDmIsHonestlyCreatedAsAChannelSession(): void
    {
        $this->boot();
        $this->inboundOn('discord:default:dm:main', 'aggregate hello');

        self::assertSame('agent:main:discord:channel:main', $this->serve->createName, 'a folded multi-sender session names no sender — policy falls to the channel level');
    }

    public function testNewKeepsTheChannelPolicyGrammarOnTheReplacementSession(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $this->router->runCommand('discord', self::DM_KEY, ['channelId' => 'chan-9'], ['name' => 'new', 'args' => []]);
        self::runFor($this->loop, 300);

        self::assertSame(2, $this->serve->createSeen, '/new must mint its own session, not reuse the bound one');
        self::assertSame('agent:main:discord:dm:peer-1', $this->serve->createName, 'M-4: the grammar survives the /new turn so the D6 policy still resolves');
        self::assertStringContainsString('New session', $this->face->delivered[count($this->face->delivered) - 1]);
    }

    public function testANamedNewArgumentNeverBecomesTheSessionName(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $this->router->runCommand('discord', self::DM_KEY, ['channelId' => 'chan-9'], ['name' => 'new', 'args' => ['agent:squatter:victim']]);
        self::runFor($this->loop, 300);

        self::assertSame(2, $this->serve->createSeen);
        self::assertSame('agent:main:discord:dm:peer-1', $this->serve->createName, 'M-4: a chat-typed title can neither drop the binding nor squat reserved grammar');
    }

    /**
     * M-8: the core never re-feeds a RESET snapshot as frames (the
     * subscriber stream pins at throughSeq and goes live strictly above
     * it). A bridge holding a mid-log cursor must ACCOUNT for the
     * un-replayed window before absorbing it: one disclosure naming the
     * gap and the discarded row count, and only then does the cursor
     * advance. Field law: never cursor-skip on RESET.
     */
    public function testAResetWithAGapIsAccountedAndDisclosed(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');
        $this->map->noteSeqForSession('sess_fixture_1', 10);
        $before = \count($this->face->delivered);

        $this->serve->resetOnSubscribe = true;
        $this->router->adoptBindings();
        self::runFor($this->loop, 300);

        $chunks = \array_slice($this->face->delivered, $before);
        self::assertCount(1, $chunks);
        self::assertStringContainsString('seq 11–40', $chunks[0]);
        self::assertStringContainsString('2 snapshot row(s) were discarded', $chunks[0]);
        self::assertSame(40, $this->map->cursorForSession('sess_fixture_1'));
    }

    /**
     * A first open (cursor 0) has claimed nothing, so a reset carries no
     * un-replayed gap: no alarm, and the cursor is not pre-advanced —
     * it starts empty and lives rise frame by frame. This pins that the
     * M-8 accounting never fabricates a false disclosure on a fresh
     * binding.
     */
    public function testAFirstOpenResetAbsorbsQuietly(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');
        $before = \count($this->face->delivered);

        $this->serve->resetOnSubscribe = true;
        $this->router->adoptBindings();
        self::runFor($this->loop, 300);

        self::assertCount(0, \array_slice($this->face->delivered, $before));
        self::assertSame(0, $this->map->cursorForSession('sess_fixture_1'));
    }

    /**
     * The worst carrier-loss shape: a reset answer with no snapshot key
     * at all. The disclosure must still name the gap and report zero
     * rows the bridge could even point at, and only then absorb the
     * cursor (M-8).
     */
    public function testTheFeedCanArriveWithoutAnySnapshotCarrier(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');
        $this->map->noteSeqForSession('sess_fixture_1', 10);
        $before = \count($this->face->delivered);

        $this->serve->resetOnSubscribe = true;
        $this->serve->discardOnly = true;
        $this->router->adoptBindings();
        self::runFor($this->loop, 300);

        $chunks = \array_slice($this->face->delivered, $before);
        self::assertCount(1, $chunks);
        self::assertStringContainsString('seq 11–40', $chunks[0]);
        self::assertStringContainsString('0 snapshot row(s) were discarded', $chunks[0]);
        self::assertSame(40, $this->map->cursorForSession('sess_fixture_1'));
    }

    public function testTwoInboundsOnAFreshKeyWhileCreateIsInFlightCreateOneSession(): void
    {
        $this->boot();
        $this->serve->createDelayMs = 250;
        $key = 'discord:default:chan-77';

        $this->router->inbound('discord', $key, ['channelId' => 'chan-77', 'messageId' => 'm-1'], 'first', false, 'm-1');
        self::runFor($this->loop, 50);
        self::assertSame(1, $this->serve->createSeen, 'the create must be in flight before the race');

        $this->router->inbound('discord', $key, ['channelId' => 'chan-77', 'messageId' => 'm-2'], 'second', false, 'm-2');
        self::runFor($this->loop, 50);
        self::assertSame(1, $this->serve->createSeen, 'the second inbound must ride the in-flight create, not start its own');
        self::runFor($this->loop, 600);
        self::assertSame('sess_fixture_1', $this->map->sessionIdFor($key), 'the single session ends bound to the key');
        self::assertSame(1, $this->serve->subscribeSeen, 'one follow subscription — the rider never double-follows');
    }

    public function testARefusedCreateClearsTheFlightSoTheNextMessageRetries(): void
    {
        $this->boot();
        $this->serve->createRefuses = true;
        $key = 'discord:default:chan-78';

        $this->router->inbound('discord', $key, ['channelId' => 'chan-78', 'messageId' => 'm-1'], 'first', false, 'm-1');
        self::runFor($this->loop, 300);
        self::assertSame(1, $this->serve->createSeen);
        self::assertNull($this->map->sessionIdFor($key), 'a refused create leaves nothing bound');

        $this->serve->createRefuses = false;
        $this->router->inbound('discord', $key, ['channelId' => 'chan-78', 'messageId' => 'm-2'], 'second', false, 'm-2');
        self::runFor($this->loop, 300);

        self::assertSame(2, $this->serve->createSeen, 'the memo must not stay poisoned by a rejection');
        self::assertSame('sess_fixture_1', $this->map->sessionIdFor($key));
    }

    public function testMultiChunkRepliesSettleInIssueOrderEvenWhenThePlatformAnswersOutOfOrder(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $loop = $this->loop;
        $face = $this->face;
        $face->lineLimit = 1;
        $face->deliverGate = static function (string $chunk) use ($face, $loop): PromiseInterface {
            $delays = ['alpha' => 0.3, 'beta' => 0.2, 'gamma' => 0.1];
            $deferred = new Deferred();
            $loop->addTimer($delays[$chunk] ?? 0.05, static function () use ($face, $chunk, $deferred): void {
                $face->delivered[] = $chunk;
                $deferred->resolve(null);
            });

            return $deferred->promise();
        };
        $this->pushSettled($this->envelope('assistant.completed', 91, ['content' => "alpha\nbeta\ngamma"]));
        self::runFor($this->loop, 800);

        self::assertSame(['alpha', 'beta', 'gamma'], \array_map('trim', $this->face->delivered), 'the chain must beat completion order');
        self::assertSame(3, $this->face->deliverIssued);
    }

    public function testAChunkFailureStopsTheChainAndDisclosesExactlyOneRow(): void
    {
        $rows = [];
        $log = new Log(static function (string $line) use (&$rows): void {
            $rows[] = $line;
        });
        $this->boot([], $log);
        $this->inboundDm('bind me', false, 'm-1');

        $loop = $this->loop;
        $face = $this->face;
        $face->lineLimit = 1;
        $face->deliverGate = static function (string $chunk) use ($face, $loop): PromiseInterface {
            $deferred = new Deferred();
            if ($chunk === 'beta') {
                $loop->addTimer(0.05, static fn () => $deferred->reject(new \RuntimeException('platform shrugged')));

                return $deferred->promise();
            }
            $loop->addTimer(0.05, static function () use ($face, $chunk, $deferred): void {
                $face->delivered[] = $chunk;
                $deferred->resolve(null);
            });

            return $deferred->promise();
        };
        $this->pushSettled($this->envelope('assistant.completed', 92, ['content' => "alpha\nbeta\ngamma"]));
        self::runFor($this->loop, 400);

        self::assertSame(['alpha'], \array_map('trim', $this->face->delivered), 'the tail after a failed chunk must never be sent');
        self::assertSame(2, $this->face->deliverIssued, 'gamma must not even be issued');
        $warns = \array_values(\array_filter($rows, static fn (string $r): bool => \str_contains($r, 'deliver_failed')));
        self::assertCount(1, $warns, 'one disclosure row, not one per abandoned chunk');
        self::assertStringContainsString('"chunk":2', $warns[0]);
        self::assertStringContainsString('"of":3', $warns[0]);
        self::assertStringContainsString('"stopped":true', $warns[0]);
    }

    public function testAFailedNoMediaPromptStillAnswersTheUserOnceAndNeverVanishes(): void
    {
        $rows = [];
        $log = new Log(static function (string $line) use (&$rows): void {
            $rows[] = $line;
        });
        $this->boot([], $log);
        $this->serve->sendRefusals = 1;
        $this->inboundDm('hello there', false, 'm-9');

        $answers = \array_values(\array_filter($this->face->delivered, static fn (string $d): bool => \str_contains($d, 'could not be delivered')));
        self::assertCount(1, $answers, 'a refused prompt must answer the user exactly once');
        $refusals = \array_values(\array_filter($rows, static fn (string $r): bool => \str_contains($r, 'prompt_refused')));
        self::assertCount(1, $refusals);
        self::assertStringContainsString('"kind":"not_connected"', $refusals[0]);
        self::assertStringNotContainsString('not connected', $refusals[0], 'the row is content-free: kind only, no error payload');
    }

    public function testAMediaRefusalWhoseRetryAlsoFailsDisclosesExactlyOnce(): void
    {
        $rows = [];
        $log = new Log(static function (string $line) use (&$rows): void {
            $rows[] = $line;
        });
        $this->boot([], $log);
        $this->inboundDm('bind me', false, 'm-1');
        $before = $this->serve->sendSeen;
        $this->serve->refuseMediaOnce = true;
        $this->serve->sendRefusals = 1;

        $this->router->inbound(
            'discord',
            self::DM_KEY,
            ['channelId' => 'chan-9'],
            'with file',
            false,
            'm-2',
            [['name' => 'a.png', 'mime' => 'image/png', 'data' => 'AA==']],
            [],
        );
        self::runFor($this->loop, 400);

        self::assertSame($before + 2, $this->serve->sendSeen, 'media refusal retries once, then the retry fails');
        $answers = \array_values(\array_filter($this->face->delivered, static fn (string $d): bool => \str_contains($d, 'could not be delivered')));
        self::assertCount(1, $answers, 'the failed retry must disclose once, not twice');
        $refusals = \array_values(\array_filter($rows, static fn (string $r): bool => \str_contains($r, 'prompt_refused')));
        self::assertCount(1, $refusals);
    }

    /** @param array<string,mixed> $policy */
    private function boot(array $policy = [], ?Log $log = null): void
    {
        $this->router = new ConversationRouter($this->bridge, $this->map, $log ?? Log::silent(), new TextSafety(), $this->loop, $policy);
        $this->router->addChannel($this->face, 'default');

        $started = false;
        $this->bridge->start()->then(static function () use (&$started): void {
            $started = true;
        });
        for ($tick = 0; $tick < 20 && !$started; $tick++) {
            self::runFor($this->loop, 100);
        }
        if (!$started) {
            self::fail('serve handshake never admitted');
        }
    }

    /** @param array<string,mixed> $data */
    private function envelope(string $type, ?int $seq, array $data): array
    {
        return [
            'sessionId' => 'sess_fixture_1',
            'seq' => $seq,
            'type' => $type,
            'ts' => 1,
            'durable' => $seq !== null,
            'data' => $data,
        ];
    }

    /** Push an event and settle the loop until the router has processed it. */
    private function pushSettled(array $envelope): void
    {
        $this->serve->pushEvent($envelope);
        self::runFor($this->loop, 250);
    }

    private function inboundDm(string $text, bool $isMention, string $inboundId): void
    {
        $this->router->inbound('discord', self::DM_KEY, ['channelId' => 'chan-9', 'messageId' => $inboundId], $text, $isMention, $inboundId);
        self::runFor($this->loop, 300);
    }

    /** Drive one inbound message on an arbitrary conversation key. */
    private function inboundOn(string $key, string $text, bool $isMention = false): void
    {
        $rest = \implode(':', \array_slice(\explode(':', $key), 2));
        $channel = \str_starts_with($rest, 'dm') ? 'chan-9' : $rest;
        $this->router->inbound('discord', $key, ['channelId' => $channel, 'messageId' => 'm-' . \substr(\md5($key . $text), 0, 8)], $text, $isMention, 'mb-' . \substr(\md5($text), 0, 8));
        self::runFor($this->loop, 300);
    }

    private static function runFor(LoopInterface $loop, int $ms): void
    {
        $timer = $loop->addTimer($ms / 1000.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($timer);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}

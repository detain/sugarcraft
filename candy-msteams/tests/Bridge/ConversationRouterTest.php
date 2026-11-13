<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Bridge;

use CandyCode\Bundles\MsTeams\Bridge\ConversationRouter;
use CandyCode\Bundles\MsTeams\Bridge\PlatformFace;
use CandyCode\Bundles\MsTeams\Candy\ServeBridge;
use CandyCode\Bundles\MsTeams\Support\Keys;
use CandyCode\Bundles\MsTeams\Support\Log;
use CandyCode\Bundles\MsTeams\Support\OutboundChunker;
use CandyCode\Bundles\MsTeams\Support\QueueGate;
use CandyCode\Bundles\MsTeams\Support\SessionMap;
use CandyCode\Bundles\MsTeams\Support\TextSafety;
use CandyCode\Bundles\MsTeams\Tests\Fixture\FakeServe;
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

    /** @var array<string,string> askId → nonce the router minted (C-1 tests read it to forge/legit-submit) */
    public array $promptNonces = [];

    /** @var array<string,string> */
    public array $retired = [];

    public int $typingCount = 0;

    public function name(): string
    {
        return 'msteams';
    }

    public function textChunkLimit(): int
    {
        return 2000;
    }

    public function maxLinesPerMessage(): int
    {
        return 17;
    }

    public function deliver(array $ref, string $chunk): PromiseInterface
    {
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
 * no model paths (E646 clean). The refs below are Teams conversationReferences
 * because the Bridge never reads them — that opaqueness IS the port's contract.
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

    private const DM_KEY = 'msteams:default:dm:peer-1';

    private const REF = ['conversationId' => '19:dm-9@thread.v2', 'serviceUrl' => 'https://s.test', 'activityId' => 'a-1'];

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
        $this->serve = new FakeServe($this->loop, 'tok-123456');
        $port = $this->serve->listen();
        $this->bridge = new ServeBridge('ws://127.0.0.1:' . $port . '/', 'tok-123456', 'subprotocol', Log::silent(), $this->loop);

        $this->tempDir = sys_get_temp_dir() . '/p3-router-' . getmypid() . '-' . uniqid('', true);
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
        $this->router->inbound('msteams', self::DM_KEY, self::REF, 'one', false, 'm-1');
        $this->router->inbound('msteams', self::DM_KEY, self::REF, 'two', false, 'm-2');
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

        self::assertSame([], $this->face->prompted, 'cards must not render when approvals are disabled');
        self::assertContains('/approve ask_9 once|always|deny', $this->face->delivered);
    }

    public function testTheTargetDoorKeepsChannelAsksQuietWhenDmOnly(): void
    {
        $this->boot(['approvals' => ['target' => 'dm']]);
        $channelKey = Keys::channelKey('msteams', 'default', '19:team-9@thread.tacv2');
        $this->router->inbound('msteams', $channelKey, ['conversationId' => '19:team-9@thread.tacv2', 'serviceUrl' => 'https://s.test'], 'bind on channel', false, 'm-1');

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

    public function testAlwaysNeedsANamedApproverWhenTheRosterIsEmpty(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $this->router->answerApproval('sess_fixture_1', 'ask_9', 'always', ['userId' => 'rando', 'name' => 'R'], '', self::DM_KEY, 'text');
        self::runFor($this->loop, 300);

        self::assertSame(0, $this->serve->respondSeen, 'a persistent grant must not be answered by an open roster');
    }

    public function testAnEmptyRosterStillAnswersOnceAndDeny(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $this->router->answerApproval('sess_fixture_1', 'ask_9', 'once', ['userId' => 'rando', 'name' => 'R'], '', self::DM_KEY, 'text');
        self::runFor($this->loop, 300);
        self::assertSame(1, $this->serve->respondSeen);
        self::assertSame('once', $this->serve->lastReply);

        $this->router->answerApproval('sess_fixture_1', 'ask_10', 'reject', ['userId' => 'rando', 'name' => 'R'], '', self::DM_KEY, 'text');
        self::runFor($this->loop, 300);
        self::assertSame(2, $this->serve->respondSeen);
        self::assertSame('reject', $this->serve->lastReply);
    }

    public function testAReplysChunksGoOutInOrderOneAtATime(): void
    {
        $face = $this->deferredFace();
        $this->boot([], $face);
        $this->inboundDm('bind me', false, 'm-1');

        $content = \str_repeat('A', 150) . "\n\n" . \str_repeat('B', 150) . "\n\n" . \str_repeat('C', 150);
        $total = \count(OutboundChunker::split($content, 200, 100));
        self::assertGreaterThanOrEqual(2, $total, 'the reply must split into several chunks for the order to mean anything');
        $this->pushSettled($this->envelope('assistant.completed', 90, ['content' => $content]));
        self::assertCount(1, $face->started, 'the second chunk must wait for the first to settle');

        for ($i = 0; $i < $total - 1; $i++) {
            $face->pending[$i]->resolve(null);
            self::runFor($this->loop, 50);
            self::assertCount($i + 2, $face->started, 'chunk ' . ($i + 2) . ' starts only after chunk ' . ($i + 1) . ' settled');
        }
        $joined = \implode('|', $face->started);
        self::assertLessThan(\strpos($joined, 'B'), \strpos($joined, 'A'), 'chunks go out in reply order');
        self::assertLessThan(\strrpos($joined, 'C'), \strrpos($joined, 'B'), 'chunks go out in reply order');
    }

    public function testAFailedChunkDoesNotStopTheRest(): void
    {
        $face = $this->deferredFace();
        $this->boot([], $face);
        $this->inboundDm('bind me', false, 'm-1');

        $this->pushSettled($this->envelope('assistant.completed', 90, ['content' => \str_repeat('A', 150) . "\n\n" . \str_repeat('B', 150)]));
        $face->pending[0]->reject(new \RuntimeException('429 after retries'));
        self::runFor($this->loop, 50);

        self::assertCount(2, $face->started, 'a refused chunk is logged, and the next chunk still goes out');
    }

    public function testTheApproveCommandMapsTheVerdictVocabulary(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $this->router->runCommand('msteams', self::DM_KEY, self::REF + ['__actor' => ['userId' => 'boss', 'name' => 'B']], ['name' => 'approve', 'args' => ['ask_9', 'deny']]);
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->respondSeen);
        self::assertSame('reject', $this->serve->lastReply, '/approve deny must answer the serve side with reject');
    }

    public function testTheModelArmRunsTheBuiltInAndDeliversItsRows(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $this->router->runCommand('msteams', self::DM_KEY, self::REF, ['name' => 'model', 'args' => ['sonnet']]);
        self::runFor($this->loop, 300);

        self::assertSame(1, $this->serve->execSeen);
        self::assertSame('switched', $this->face->delivered[count($this->face->delivered) - 1]);
    }

    public function testAChannelConversationIsCreatedUnderTheGroupGrammar(): void
    {
        $this->boot();
        $this->inboundOn('msteams:default:19:team-42@thread.v2', 'hello channel');

        self::assertSame(1, $this->serve->createSeen);
        self::assertSame('agent:main:msteams:group:19%3Ateam-42@thread.v2', $this->serve->createName, 'M-5: the Teams conversation id rides colon-encoded so the name stays inside the five-segment policy grammar (an embedded colon would push it into refusingAll)');
    }

    public function testAPerPeerDmIsCreatedUnderTheDmGrammarWithThePeerTail(): void
    {
        $this->boot();
        $this->inboundOn('msteams:default:dm:peer-1', 'hello dms');

        self::assertSame('agent:main:msteams:dm:peer-1', $this->serve->createName);
    }

    public function testTheAccountChannelPeerShapeStillEndsInItsPeer(): void
    {
        $this->boot();
        $this->inboundOn('msteams:default:dm:acc:chan-9:peer-7', 'wide dm');

        self::assertSame('agent:main:msteams:dm:peer-7', $this->serve->createName, 'the widest DM ladder shape resolves to the human it addresses');
    }

    public function testTheAggregateDmIsHonestlyCreatedAsAChannelSession(): void
    {
        $this->boot();
        $this->inboundOn('msteams:default:dm:main', 'aggregate hello');

        self::assertSame('agent:main:msteams:channel:main', $this->serve->createName, 'a folded multi-sender session names no sender — policy falls to the channel level');
    }

    public function testNewKeepsTheChannelPolicyGrammarOnTheReplacementSession(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $this->router->runCommand('msteams', self::DM_KEY, self::REF, ['name' => 'new', 'args' => ['agent:squatter:victim']]);
        self::runFor($this->loop, 300);

        self::assertSame(2, $this->serve->createSeen, '/new must mint its own session, not reuse the bound one');
        self::assertSame('agent:main:msteams:dm:peer-1', $this->serve->createName, 'M-4 twin: the grammar survives the /new turn, and a chat-typed title can never become the name');
        self::assertStringContainsString('New session', $this->face->delivered[count($this->face->delivered) - 1]);
    }

    public function testAMediaRefusalRetriesOnceWithoutAttachments(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        $this->serve->refuseMediaOnce = true;
        $before = $this->serve->sendSeen;
        $this->router->inbound(
            'msteams',
            self::DM_KEY,
            self::REF,
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

    /**
     * M-8: the core never re-feeds a RESET snapshot as frames (the subscriber
     * stream pins at throughSeq and goes live strictly above it). A bridge
     * holding a mid-log cursor must ACCOUNT for the un-replayed window
     * before absorbing it: one disclosure naming the gap and the discarded
     * row count, and only then does the cursor advance. Field law: never
     * cursor-skip on RESET.
     */
    public function testAResetWithAGapIsAccountedAndDisclosed(): void
    {
        $this->boot();
        $this->inboundDm('bind me', false, 'm-1');

        // The bridge believes the log through seq 10; the snapshot only
        // reaches seq 40 and the frames in between are gone for good.
        $this->map->noteSeqForSession('sess_fixture_1', 10);
        $before = \count($this->face->delivered);
        $this->serve->resetOnSubscribe = true;

        $this->router->adoptBindings();
        self::runFor($this->loop, 300);

        $chunks = \array_slice($this->face->delivered, $before);
        self::assertCount(1, $chunks, 'the skipped window is disclosed exactly once');
        self::assertStringContainsString('seq 11–40', $chunks[0]);
        self::assertStringContainsString('2 snapshot row(s) were discarded', $chunks[0]);
        self::assertSame(40, $this->map->cursorForSession('sess_fixture_1'), 'accounted, THEN absorbed');
    }

    /**
     * A first open (cursor 0) has claimed nothing, so a reset carries no
     * un-replayed gap: no alarm, and the cursor is not pre-advanced — it
     * starts empty and lives rise frame by frame. This pins that the M-8
     * accounting never fabricates a false disclosure on a fresh binding.
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
     * The worst carrier-loss shape: a reset answer with no snapshot key at
     * all. The disclosure must still name the gap and report zero rows the
     * bridge could even point at, and only then absorb the cursor (M-8).
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

    /** @param array<string,mixed> $policy */
    private function boot(array $policy = [], ?PlatformFace $face = null): void
    {
        $this->router = new ConversationRouter($this->bridge, $this->map, Log::silent(), new TextSafety(), $this->loop, $policy);
        $this->router->addChannel($face ?? $this->face, 'default');

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

    /** A face whose deliveries stay open until the test settles them. */
    private function deferredFace(): PlatformFace
    {
        return new class implements PlatformFace {
            /** @var list<string> */
            public array $started = [];

            /** @var list<Deferred> */
            public array $pending = [];

            public function name(): string
            {
                return 'msteams';
            }

            public function textChunkLimit(): int
            {
                return 200;
            }

            public function maxLinesPerMessage(): int
            {
                return 100;
            }

            public function deliver(array $ref, string $chunk): PromiseInterface
            {
                $this->started[] = $chunk;
                $deferred = new Deferred();
                $this->pending[] = $deferred;

                return $deferred->promise();
            }

            public function promptApproval(array $ref, string $sessionId, array $ask, string $nonce = ''): void
            {
            }

            public function retireApproval(string $askId, string $line): void
            {
            }

            public function typing(array $ref): void
            {
            }
        };
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
        $this->router->inbound('msteams', self::DM_KEY, ['conversationId' => '19:dm-9@thread.v2', 'serviceUrl' => 'https://s.test', 'activityId' => $inboundId], $text, $isMention, $inboundId);
        self::runFor($this->loop, 300);
    }

    /** Drive one inbound message on an arbitrary §9.1 conversation key. */
    private function inboundOn(string $key, string $text, bool $isMention = false): void
    {
        $conversationId = '19:' . \substr(\md5($key), 0, 8) . '@thread.v2';
        $inboundId = 'm-' . \substr(\md5($key . $text), 0, 8);
        $this->router->inbound('msteams', $key, ['conversationId' => $conversationId, 'serviceUrl' => 'https://s.test', 'activityId' => $inboundId], $text, $isMention, $inboundId);
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

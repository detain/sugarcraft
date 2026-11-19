<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Bridge;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Plugins\Bridge\ConversationRouterCore;
use SugarCraft\Plugins\Tests\Bridge\Support\FakeFace;
use SugarCraft\Plugins\Tests\Bridge\Support\FakeServeClient;
use SugarCraft\Plugins\Tests\Bridge\Support\FakeTextGuard;
use SugarCraft\Plugins\Tests\Bridge\Support\FixedChunkSplitter;
use SugarCraft\Plugins\Tests\Bridge\Support\MemorySessionStore;
use SugarCraft\Plugins\Tests\Bridge\Support\PlainRouter;
use SugarCraft\Plugins\Tests\Bridge\Support\RecordingLog;
use SugarCraft\Plugins\Tests\Bridge\Support\RecordingQueueGate;

/**
 * The platform-neutral router, driven through its contracts only: a scripted
 * serve client, a fake face, an in-memory store, a recording log, and a
 * translator that echoes its key so assertions name the catalog key used.
 */
final class ConversationRouterCoreTest extends TestCase
{
    private FakeServeClient $serve;

    private MemorySessionStore $store;

    private RecordingLog $log;

    private FakeFace $face;

    private ConversationRouterCore $router;

    private const KEY = 'fake:acc:group-1';

    private const REF = ['conversationId' => 'c1'];

    /** @param array<string,mixed> $policy */
    private function build(int $limit = 1000, array $policy = []): void
    {
        $this->face = new FakeFace('fake', $limit);
        $this->router = new PlainRouter(...$this->ctorArgs($policy));
        $this->router->addChannel($this->face, 'acc');
    }

    /**
     * The constructor argument list, shared by the tests that subclass the core.
     *
     * @param array<string,mixed> $policy
     * @return list<mixed>
     */
    private function ctorArgs(array $policy = []): array
    {
        $this->serve = new FakeServeClient();
        $this->store = new MemorySessionStore();
        $this->log = new RecordingLog();
        $translate = static fn (string $key, array $params = []): string => '[' . $key . ($params === [] ? '' : ' ' . \json_encode($params, \JSON_UNESCAPED_SLASHES)) . ']';

        return [$this->serve, $this->store, $this->log, new FakeTextGuard(), new FixedChunkSplitter(), Loop::get(), $translate, $policy];
    }

    private function emitDurable(string $type, int $seq, array $data = [], string $sessionId = 's1'): void
    {
        $this->serve->emit(['sessionId' => $sessionId, 'type' => $type, 'durable' => true, 'seq' => $seq, 'data' => $data]);
    }

    /** Bind KEY to s1 with one idle text prompt, so later events have a conversation to ride. */
    private function bindWithPrompt(string $text = 'hello', ?string $inboundId = null): void
    {
        $this->router->inbound('fake', self::KEY, self::REF, $text, false, $inboundId);
    }

    public function testChannelForPrefersTheAccountFaceThenThePlatformAlias(): void
    {
        $this->build();
        $second = new FakeFace('fake');
        $this->router->addChannel($second, 'acc2');

        $this->assertSame($this->face, $this->router->channelFor('fake:acc:x'));
        $this->assertSame($second, $this->router->channelFor('fake:acc2:x'));
        // An account with no face of its own falls back to the first face registered for the platform.
        $this->assertSame($this->face, $this->router->channelFor('fake:gone:x'));
        $this->assertNull($this->router->channelFor('other:acc:x'));
    }

    public function testIdleInboundMintsASessionNamedByTheGrammarAndSendsAFencedQueuedPrompt(): void
    {
        $this->build();
        $this->bindWithPrompt('hello', 'm1');

        $this->assertSame(['agent:main:fake:group:group-1'], $this->serve->createdNames);
        $this->assertSame('s1', $this->store->bindings[self::KEY]);
        $this->assertSame('s1', $this->serve->sent[0]['sessionId']);
        $this->assertSame('<fenced>hello</fenced>', $this->serve->sent[0]['text']);
        $this->assertSame('queue', $this->serve->sent[0]['delivery']);
        $this->assertSame(1, $this->face->typingCalls);
        $this->assertContains('follow', \array_column($this->serve->calls, 'method'));
    }

    public function testDirectMessageSessionNamesFollowTheDmLadder(): void
    {
        $this->build();
        $this->router->inbound('fake', 'fake:acc:dm:main', self::REF, 'a', false);
        $this->router->inbound('fake', 'fake:acc:dm:chan:peer9', self::REF, 'b', false);

        $this->assertSame(
            ['agent:main:fake:channel:main', 'agent:main:fake:dm:peer9'],
            $this->serve->createdNames,
        );
    }

    public function testADuplicateInboundIdIsSuppressed(): void
    {
        $this->build();
        $this->bindWithPrompt('hello', 'm1');
        $this->bindWithPrompt('hello', 'm1');

        $this->assertCount(1, $this->serve->sent);
        $this->assertContains('inbound_duplicate', $this->log->events());
    }

    public function testABusyMentionSteersTheRunningTurn(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->emitDurable('session.status', 1, ['status' => 'running']);
        $this->router->inbound('fake', self::KEY, self::REF, 'more', true);

        $this->assertSame('steer', $this->serve->sent[1]['delivery']);
    }

    public function testInterruptModeCancelsTheTurnThenSendsAQueuedPrompt(): void
    {
        $this->build(1000, ['queue' => ['mode' => 'interrupt']]);
        $this->bindWithPrompt();
        $this->emitDurable('session.status', 1, ['status' => 'running']);
        $this->router->inbound('fake', self::KEY, self::REF, 'stop that', true);

        $this->assertSame(['s1'], $this->serve->cancelled);
        $this->assertSame('queue', \end($this->serve->sent)['delivery']);
    }

    public function testCollectModeCoalescesPlainTextThroughTheGate(): void
    {
        $this->build(1000, ['queue' => ['mode' => 'collect']]);
        $gate = new RecordingQueueGate(dropped: 2);
        $this->router->setQueueGate($gate);
        $this->bindWithPrompt('first');
        $this->router->inbound('fake', self::KEY, self::REF, 'second', false);

        $this->assertSame(['<fenced>first</fenced>', '<fenced>second</fenced>'], $gate->pushedText);
        $this->assertSame([], $this->serve->sent);
        $this->assertContains('[router.dropped_queued {"count":"2"}]', $this->face->delivered);

        $gate->flush();
        $this->assertCount(1, $this->serve->sent);
        $this->assertSame("<fenced>first</fenced>\n\n<fenced>second</fenced>", $this->serve->sent[0]['text']);
    }

    public function testStopCancelsTheBoundSessionAndSaysSo(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->router->runCommand('fake', self::KEY, self::REF, ['name' => 'stop', 'args' => []]);

        $this->assertSame(['s1'], $this->serve->cancelled);
        $this->assertContains('[turn.cancelled]', $this->face->delivered);
    }

    public function testStopWithNoBindingSaysNothingIsRunning(): void
    {
        $this->build();
        $this->router->runCommand('fake', 'fake:acc:group-9', self::REF, ['name' => 'stop', 'args' => []]);

        $this->assertSame([], $this->serve->cancelled);
        $this->assertContains('[turn.nothing_running]', $this->face->delivered);
    }

    public function testNewRebindsTheConversationToAFreshSession(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->serve->nextSessionId = 's2';
        $this->router->runCommand('fake', self::KEY, self::REF, ['name' => 'new', 'args' => []]);

        $this->assertSame('s2', $this->store->bindings[self::KEY]);
        $this->assertSame('agent:main:fake:group:group-1', \end($this->serve->createdNames));
        $this->assertContains('[session.new {"sessionId":"s2"}]', $this->face->delivered);
    }

    public function testAnUnlistedCommandIsLoggedAndNotAnswered(): void
    {
        $this->build();
        $this->router->runCommand('fake', self::KEY, self::REF, ['name' => 'bogus', 'args' => []]);

        $this->assertSame([], $this->face->delivered);
        $this->assertContains('command_unhandled', $this->log->events());
    }

    public function testPairWithoutAHandlerSaysItIsNotWiredAndWithOneHandsOver(): void
    {
        $this->build();
        $this->router->runCommand('fake', self::KEY, self::REF, ['name' => 'pair', 'args' => ['x']]);
        $this->assertContains('[pair.not_wired]', $this->face->delivered);

        $seen = [];
        $this->router->setPairHandler(static function (string $key, array $ref, array $args) use (&$seen): void {
            $seen = [$key, $args];
        });
        $this->router->runCommand('fake', self::KEY, self::REF, ['name' => 'pair', 'args' => ['code']]);
        $this->assertSame([self::KEY, ['code']], $seen);
    }

    public function testApproveWithoutAnAskAndVerdictSaysTheUsage(): void
    {
        $this->build();
        $this->router->runCommand('fake', self::KEY, self::REF, ['name' => 'approve', 'args' => []]);

        $this->assertContains('[approve.usage]', $this->face->delivered);
    }

    public function testAssistantReplyIsChunkedInOrder(): void
    {
        $this->build(4);
        $this->bindWithPrompt();
        $this->emitDurable('assistant.completed', 2, ['content' => 'abcdefghij']);

        $this->assertSame(['abcd', 'efgh', 'ij'], $this->face->delivered);
    }

    public function testTheFirstFailedChunkStopsTheWalkWithOneDeliverFailedRow(): void
    {
        $this->build(4);
        $this->bindWithPrompt();
        $this->face->failAt = [1];
        $this->emitDurable('assistant.completed', 2, ['content' => 'abcdefghij']);

        $this->assertSame(['abcd'], $this->face->delivered);
        $fields = $this->log->fieldsOf('deliver_failed');
        $this->assertNotNull($fields);
        $this->assertSame(2, $fields['chunk']);
        $this->assertSame(3, $fields['of']);
        $this->assertTrue($fields['stopped']);
    }

    public function testADurableEventAtOrBelowTheCursorIsNotReapplied(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->emitDurable('assistant.completed', 2, ['content' => 'once']);
        $this->emitDurable('assistant.completed', 2, ['content' => 'once']);
        $this->emitDurable('assistant.completed', 1, ['content' => 'older']);

        $this->assertSame(['once'], $this->face->delivered);
    }

    public function testAnEphemeralEventWithoutASeqIsIgnored(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->serve->emit(['sessionId' => 's1', 'type' => 'assistant.completed', 'durable' => false, 'data' => ['content' => 'ghost']]);

        $this->assertSame([], $this->face->delivered);
    }

    public function testAMediaRefusalWithoutAMediaRuleFailsTheTurnWithoutARetry(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->serve->sendOutcomes = [new \RuntimeException('images_not_a_list')];
        $this->router->inbound('fake', self::KEY, self::REF, 'look', false, null, [['mime' => 'image/png', 'data' => 'x']]);

        $this->assertCount(2, $this->serve->sent);
        $this->assertContains('[prompt.failed {"error":"images_not_a_list"}]', $this->face->delivered);
    }

    public function testAClassifiedMediaRefusalRetriesOnceWithoutAttachments(): void
    {
        $args = $this->ctorArgs();
        $this->face = new FakeFace('fake', 1000);
        $this->router = new class(...$args) extends PlainRouter {
            protected function isMediaRefusal(\Throwable $error): bool
            {
                return $error->getMessage() === 'images_not_a_list';
            }
        };
        $this->router->addChannel($this->face, 'acc');
        $this->bindWithPrompt();
        $this->serve->sendOutcomes = [new \RuntimeException('images_not_a_list')];
        $this->router->inbound('fake', self::KEY, self::REF, 'look', false, null, [['mime' => 'image/png', 'data' => 'x']]);

        $this->assertCount(3, $this->serve->sent);
        $this->assertSame([], $this->serve->sent[2]['images']);
        $this->assertSame('<fenced>look</fenced>', $this->serve->sent[2]['text']);
        $this->assertContains('[media.refused]', $this->face->delivered);
    }

    public function testAnApprovalIsPresentedAndAnsweredWithItsBoundNonce(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->emitDurable('permission.requested', 2, ['askId' => 'a1', 'tool' => 'bash']);
        $nonce = $this->face->approvals[0]['nonce'];
        $this->assertNotSame('', $nonce);

        $this->router->answerApproval('s1', 'a1', 'once', ['userId' => 'u1'], $nonce, self::KEY, 'button');

        $this->assertSame([['sessionId' => 's1', 'askId' => 'a1', 'reply' => 'once', 'note' => 'u1']], $this->serve->permissionAnswers);
    }

    public function testAButtonAnswerWithTheWrongNonceIsRefusedBeforeTheWire(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->emitDurable('permission.requested', 2, ['askId' => 'a1']);

        $this->router->answerApproval('s1', 'a1', 'once', ['userId' => 'u1'], 'forged', self::KEY, 'button');

        $this->assertSame([], $this->serve->permissionAnswers);
        $this->assertContains('[approval.refused]', $this->face->delivered);
        $this->assertContains('approval_answer_unbound', $this->log->events());
    }

    public function testAnAnswerFromAnotherConversationIsRefusedEvenWithTheRightNonce(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->emitDurable('permission.requested', 2, ['askId' => 'a1']);
        $nonce = $this->face->approvals[0]['nonce'];

        $this->router->answerApproval('s1', 'a1', 'once', ['userId' => 'u1'], $nonce, 'fake:acc:group-2', 'button');

        $this->assertSame([], $this->serve->permissionAnswers);
    }

    public function testAnAnswerFromOutsideTheRosterIsRejectedOnTheWire(): void
    {
        $this->build(1000, ['approvals' => ['approvers' => ['u1']]]);
        $this->bindWithPrompt();
        $this->emitDurable('permission.requested', 2, ['askId' => 'a1']);
        $nonce = $this->face->approvals[0]['nonce'];

        $this->router->answerApproval('s1', 'a1', 'once', ['userId' => 'u2'], $nonce, self::KEY, 'button');

        $this->assertSame('reject', $this->serve->permissionAnswers[0]['reply']);
        $this->assertSame('refused: u2 is not an approver', $this->serve->permissionAnswers[0]['note']);
        $this->assertContains('[approval.not_yours]', $this->face->delivered);
    }

    public function testAlwaysWithAnOpenRosterStaysOpenAndSaysWhyItIsRefused(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->emitDurable('permission.requested', 2, ['askId' => 'a1']);
        $nonce = $this->face->approvals[0]['nonce'];

        $this->router->answerApproval('s1', 'a1', 'always', ['userId' => 'u1'], $nonce, self::KEY, 'button');

        $this->assertSame([], $this->serve->permissionAnswers);
        $this->assertContains('[approval.always_needs_approver]', $this->face->delivered);
    }

    public function testAResolvedApprovalIsRetiredOnEveryFace(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->emitDurable('permission.requested', 2, ['askId' => 'a1']);
        $this->emitDurable('permission.resolved', 3, ['askId' => 'a1', 'reply' => 'once']);

        $this->assertCount(1, $this->face->retired);
        $this->assertSame('a1', $this->face->retired[0]['askId']);
        $this->assertSame('[approve.resolved_once {"who":""}]', $this->face->retired[0]['line']);
    }

    public function testATextAnswerForAnAskTheProcessNeverPresentedAsksTheServeForIt(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->serve->callReplies['permission.pending'] = ['pending' => [['askId' => 'a9', 'sessionId' => 's1']]];

        $this->router->answerByAskId('a9', 'once', ['userId' => 'u1'], '', self::KEY, 'text');

        $this->assertContains('permission.pending', \array_column($this->serve->calls, 'method'));
        $this->assertSame([['sessionId' => 's1', 'askId' => 'a9', 'reply' => 'once', 'note' => 'u1']], $this->serve->permissionAnswers);
    }

    public function testAReplayGapIsDisclosedAndTheCursorAdvancesToThroughSeq(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->store->noteSeqForSession('s1', 1);
        $this->serve->followResult('s1', [
            'reset' => true,
            'throughSeq' => 4,
            'snapshot' => ['messages' => [['role' => 'user'], ['role' => 'assistant']]],
        ]);

        $fields = $this->log->fieldsOf('reset_snapshot_discarded');
        $this->assertNotNull($fields);
        $this->assertSame(2, $fields['snapshotDropped']);
        $this->assertContains('[replay.gap {"from":"2","to":"4","count":"2"}]', $this->face->delivered);
        $this->assertSame(4, $this->store->cursorForSession('s1'));
    }

    public function testTheDefaultConfirmsAnInboundAfterItsDispatch(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->store->ops = [];
        $this->bindWithPrompt('hello', 'm1');

        $this->assertSame(['remember', 'confirm'], $this->store->ops);
    }

    public function testAnOverrideCanConfirmAnInboundBeforeItsDispatch(): void
    {
        $args = $this->ctorArgs();
        $this->router = new class(...$args) extends PlainRouter {
            protected function confirmsInboundFirst(): bool
            {
                return true;
            }
        };
        $this->face = new FakeFace('fake', 1000);
        $this->router->addChannel($this->face, 'acc');
        $this->bindWithPrompt();
        $this->store->ops = [];
        $this->bindWithPrompt('hello', 'm1');

        $this->assertSame(['confirm', 'remember'], $this->store->ops);
    }

    public function testAnOverrideSuppliesTheSessionGrammar(): void
    {
        $args = $this->ctorArgs();
        $this->router = new class(...$args) extends PlainRouter {
            protected function sessionName(string $key): string
            {
                return 'custom:' . $key;
            }
        };
        $this->face = new FakeFace('fake', 1000);
        $this->router->addChannel($this->face, 'acc');
        $this->bindWithPrompt();

        $this->assertSame(['custom:fake:acc:group-1'], $this->serve->createdNames);
    }

    public function testChannelLooksUpByExactNameAndTheFirstFaceServesThePlatformAlias(): void
    {
        $this->build();
        $second = new FakeFace('fake');
        $this->router->addChannel($second, 'acc2');

        $this->assertSame($this->face, $this->router->channel('fake:acc'));
        $this->assertSame($second, $this->router->channel('fake:acc2'));
        // The bare platform name is the alias kept for the first face registered.
        $this->assertSame($this->face, $this->router->channel('fake'));
        $this->assertNull($this->router->channel('fake:gone'));
        $this->assertNull($this->router->channel('other'));
    }

    public function testAllChannelsListsEveryRegisteredFace(): void
    {
        $this->build();
        $this->assertSame([$this->face], $this->distinctFaces($this->router->allChannels()));

        $second = new FakeFace('fake');
        $this->router->addChannel($second, 'acc2');
        $faces = $this->distinctFaces($this->router->allChannels());

        $this->assertSame([$this->face, $second], $faces);
    }

    /**
     * Identity dedupe: two FakeFace instances can compare equal by value, so
     * array_unique(SORT_REGULAR) would merge distinct faces.
     *
     * @param list<\SugarCraft\Plugins\Bridge\PlatformFace> $faces
     * @return list<\SugarCraft\Plugins\Bridge\PlatformFace>
     */
    private function distinctFaces(array $faces): array
    {
        $seen = [];
        foreach ($faces as $face) {
            $seen[\spl_object_id($face)] = $face;
        }

        return \array_values($seen);
    }

    public function testClaimInboundAcceptsTheFirstDeliveryOnly(): void
    {
        $this->build();

        $this->assertTrue($this->router->claimInbound('fake', 'm1'));
        $this->assertFalse($this->router->claimInbound('fake', 'm1'));
        $this->assertTrue($this->router->claimInbound('fake', 'm2'));
    }

    public function testConfirmInboundForwardsToTheStore(): void
    {
        $this->build();
        $this->store->ops = [];

        $this->router->confirmInbound('fake', 'm1');

        $this->assertSame(['confirm'], $this->store->ops);
    }

    public function testReleaseBindingForgetsTheConversationBinding(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->assertSame('s1', $this->store->bindings[self::KEY]);

        $this->router->releaseBinding(self::KEY);

        $this->assertArrayNotHasKey(self::KEY, $this->store->bindings);
    }

    public function testAdoptBindingsResubscribesEveryBoundConversationFromItsCursor(): void
    {
        $this->build();
        $this->bindWithPrompt();
        $this->store->bind('fake:acc:other', 's2');
        $this->store->noteSeqForSession('s2', 5);
        $this->serve->calls = [];

        $this->router->adoptBindings();

        $follows = \array_values(\array_filter($this->serve->calls, static fn (array $c): bool => $c['method'] === 'follow'));
        $this->assertSame(
            [
                ['sessionId' => 's1', 'afterSeq' => null],
                ['sessionId' => 's2', 'afterSeq' => 5],
            ],
            \array_column($follows, 'params'),
        );
    }

    public function testAdoptBindingsWithNoBindingsSubscribesNothing(): void
    {
        $this->build();
        $this->serve->calls = [];

        $this->router->adoptBindings();

        $this->assertSame([], $this->serve->calls);
    }

    public function testTheCoreHasNoImportOfAnyBundleNamespace(): void
    {
        $dir = \dirname(__DIR__, 2) . '/src/Bridge';
        $files = \glob($dir . '/*.php');
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertStringNotContainsString('CandyCode\\Bundles\\', (string) \file_get_contents($file), $file);
        }
    }
}

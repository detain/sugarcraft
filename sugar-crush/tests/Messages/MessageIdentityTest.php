<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Messages;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Support\MessageIdAllocator;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 1.B-1: a transcript row has an identity - a storage id, a
 * model-facing ref, the engine step it came from - and a user-visibility flag
 * beside the agent-side `uiOnly`. Identity is only useful if it is never lost,
 * so every rebuild route and the persisted shape are pinned here, together
 * with the allocator that hands refs out.
 */
final class MessageIdentityTest extends TestCase
{
    public function testTheFactoriesStartWithNoIdentityAndUserVisible(): void
    {
        foreach ([Message::user('u'), Message::assistant('a'), Message::system('s'), Message::notice('n')] as $m) {
            $this->assertSame([null, null, null, true], [$m->id, $m->ref, $m->stepId, $m->userVisible]);
        }
    }

    public function testWithIdentitySetsAndClearsBothParts(): void
    {
        $message = Message::user('hi', 5);
        $named = $message->withIdentity('m_s_3', 3);

        $this->assertSame(['m_s_3', 3], [$named->id, $named->ref]);
        $this->assertNull($message->id, 'immutable: the original is untouched');
        $this->assertSame([Role::User, 'hi', 5], [$named->role, $named->content, $named->createdAt]);
        $this->assertSame([null, null], [$named->withIdentity(null, null)->id, $named->withIdentity(null, null)->ref]);
        $this->assertSame([null, null], [$named->withIdentity('', 0)->id, $named->withIdentity('', 0)->ref], 'empty id and a ref below 1 are not identities');
    }

    public function testWithStepIdAndWithUserVisible(): void
    {
        $stepped = Message::assistant('a')->withStepId('step-2');
        $this->assertSame('step-2', $stepped->stepId);
        $this->assertNull($stepped->withStepId('')->stepId);
        $this->assertNull($stepped->withStepId(null)->stepId);

        $hidden = Message::assistant('a')->withUserVisible(false);
        $this->assertFalse($hidden->userVisible);
        $this->assertTrue($hidden->withUserVisible()->userVisible);
        $this->assertFalse($hidden->uiOnly, 'hidden from the user is not hidden from the model');
    }

    public function testEveryWitherPreservesTheIdentityFields(): void
    {
        $marked = Message::assistant('a')
            ->withIdentity('m_s_9', 9)
            ->withStepId('step-1')
            ->withUserVisible(false);

        $rebuilt = [
            'attachFile' => $marked->attachFile('/tmp/x'),
            'attachImage' => $marked->attachImage('/tmp/x.png'),
            'withToolCalls' => $marked->withToolCalls([new ToolCall('bash', [], 'c1')]),
            'withToolResults' => $marked->withToolResults([ToolResult::ok('bash', 'ok', 'c1')]),
            'withReasoning' => $marked->withReasoning('r'),
            'withImage' => $marked->withImage('bytes', 'kitty'),
            'withUsage' => $marked->withUsage(Usage::reported(1, 0.0, 1, 0)),
            'withLengthStopped' => $marked->withLengthStopped(true),
            'withStepsTruncated' => $marked->withStepsTruncated(true),
            'withUiOnly' => $marked->withUiOnly(),
            'withLoopGuardStoppedBy' => $marked->withLoopGuardStoppedBy('Read'),
            'withAttachmentNotice' => $marked->withAttachmentNotice('n'),
            'withStepId' => $marked->withStepId('step-1'),
            'withUserVisible' => $marked->withUserVisible(false),
            'withIdentity' => $marked->withIdentity('m_s_9', 9),
        ];

        foreach ($rebuilt as $wither => $message) {
            $this->assertSame(
                ['m_s_9', 9, 'step-1', false],
                [$message->id, $message->ref, $message->stepId, $message->userVisible],
                "{$wither}() dropped an identity field",
            );
        }
    }

    public function testWithToolResultsStillClearsThePlaceholderFields(): void
    {
        $placeholder = Message::toolRunning(new ToolCall('Bash', ['command' => 'ls'], 'c1'))->withIdentity('m_s_1', 1);

        $done = $placeholder->withToolResults([ToolResult::ok('Bash', 'a', 'c1')]);

        $this->assertSame([null, [], null], [$done->pendingToolCallId, $done->pendingToolArguments, $done->pendingToolName]);
        $this->assertSame('m_s_1', $done->id, 'the row is the same row, finished');
    }

    public function testTheIdentityRoundTripsThroughThePersistedShape(): void
    {
        $row = Message::assistant('a', 7)->withIdentity('m_s_4', 4)->withStepId('step-3')->withUserVisible(false)->jsonSerialize();

        $this->assertSame(['m_s_4', 4, 'step-3', false], [$row['id'], $row['ref'], $row['stepId'], $row['userVisible']]);
        $back = Message::fromArray(json_decode((string) json_encode($row), true));
        $this->assertSame(['m_s_4', 4, 'step-3', false], [$back->id, $back->ref, $back->stepId, $back->userVisible]);
    }

    public function testARowWithoutIdentitySerialisesExactlyAsBefore(): void
    {
        $row = Message::assistant('legacy')->jsonSerialize();

        foreach (['id', 'ref', 'stepId', 'userVisible'] as $key) {
            $this->assertArrayNotHasKey($key, $row, "{$key}: an id-less row keeps the bytes its checkpoint blobs were hashed from");
        }
        $back = Message::fromArray($row);
        $this->assertSame([null, null, null, true], [$back->id, $back->ref, $back->stepId, $back->userVisible]);
    }

    public function testMalformedIdentityValuesFallBackToTheDefaults(): void
    {
        $back = Message::fromArray([
            'role' => 'user',
            'content' => 'x',
            'id' => '',
            'ref' => '3',
            'stepId' => 12,
            'userVisible' => 'no',
        ]);

        $this->assertSame([null, null, null, true], [$back->id, $back->ref, $back->stepId, $back->userVisible]);
        $this->assertNull(Message::fromArray(['role' => 'user', 'ref' => 0])->ref, 'a ref below 1 is not a ref');
    }

    public function testTheIdentityNeverReachesTheWireShape(): void
    {
        $message = Message::user('hi')->withIdentity('m_s_1', 1)->withStepId('s')->withUserVisible(false);

        $this->assertSame(['role' => 'user', 'content' => 'hi'], $message->toWire());
        $this->assertSame([$message], Message::agentVisible([$message]), 'user-hidden rows still reach the model');
    }

    public function testTheAllocatorHandsOutMonotonicRefsAndDerivedIds(): void
    {
        $allocator = MessageIdAllocator::new('abc123');

        $this->assertSame('abc123', $allocator->sessionId());
        $this->assertSame(1, $allocator->nextRef());
        $this->assertSame(1, $allocator->allocate());
        $this->assertSame(2, $allocator->allocate());
        $this->assertSame(3, $allocator->nextRef());
        $this->assertSame('m_abc123_7', $allocator->idFor(7));
        $this->assertSame(1, MessageIdAllocator::new('s', -4)->nextRef(), 'the mark is clamped to 1');
    }

    public function testObserveOnlyEverRaisesTheMark(): void
    {
        $allocator = MessageIdAllocator::new('s', 5);

        $allocator->observe(2);
        $allocator->observe(null);
        $this->assertSame(5, $allocator->nextRef(), 'a lower ref is already behind the mark');

        $allocator->observe(9);
        $this->assertSame(10, $allocator->nextRef(), 'a held ref is never handed out again');
    }

    public function testIdentityForCompletesWhateverPartIsMissing(): void
    {
        $allocator = MessageIdAllocator::new('s');

        $this->assertSame(['m_s_1', 1], $allocator->identityFor(null, null));
        $this->assertSame(['custom', 2], $allocator->identityFor('custom', null), 'an id-only row keeps its id');
        $this->assertSame(['m_s_8', 8], $allocator->identityFor(null, 8), 'a ref-only row gets its derived id');
        $this->assertSame(['kept', 4], $allocator->identityFor('kept', 4), 'a complete identity is kept as it is');
        $this->assertSame(9, $allocator->nextRef(), 'every ref seen pushed the mark');
        $this->assertSame(['m_s_9', 9], $allocator->identityFor('', 0), 'empty parts are absent parts');
    }

    public function testTheSessionPartOfAnIdIsReducedToASafeCharset(): void
    {
        $this->assertSame('m_my-session--x_1', MessageIdAllocator::new('my session/"x')->idFor(1));
        $this->assertSame('m_session_1', MessageIdAllocator::new('')->idFor(1));
    }
}

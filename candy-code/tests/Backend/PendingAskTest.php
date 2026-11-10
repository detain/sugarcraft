<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\ChildChannel;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Events\PermissionResolved;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Tools\ToolCall;

/**
 * The parent-side reply handle of roadmap 1.C-1: rebuilt from an untrusted
 * `ask` frame, settled exactly once.
 */
final class PendingAskTest extends TestCase
{
    public function testTheAskIdIsAStableHashOfTheCallToolAndArguments(): void
    {
        $id = PendingAsk::askId('call_1', 'Bash', ['command' => 'ls']);

        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $id);
        self::assertSame($id, PendingAsk::askId('call_1', 'Bash', ['command' => 'ls']));
        self::assertNotSame($id, PendingAsk::askId('call_2', 'Bash', ['command' => 'ls']));
        self::assertNotSame($id, PendingAsk::askId('call_1', 'Grep', ['command' => 'ls']));
        self::assertNotSame($id, PendingAsk::askId('call_1', 'Bash', ['command' => 'rm']), 'a rewrite must change the id');
    }

    public function testItRoundTripsTheFrameDescribeWrites(): void
    {
        $frame = PendingAsk::describe(
            new ToolCall('call_1', 'Edit', ['path' => 'x']),
            ForkChannelEofIsUnansweredTest::gateAsk(),
            'default',
        );
        $ask = PendingAsk::fromFrame(\unserialize(\serialize($frame), ['allowed_classes' => false]), static function (): void {});

        self::assertInstanceOf(PendingAsk::class, $ask);
        self::assertSame($frame['askId'], $ask->askId);
        self::assertSame(['path' => 'x'], $ask->arguments);
        self::assertSame(['once', 'always', 'reject'], $ask->suggestions);
        self::assertTrue($ask->offers(PermissionReply::Always));
        // The expiry stamp is part of the round trip: serialize preserves
        // the int, fromFrame keeps a positive one, and the handle still
        // reads fresh.
        self::assertSame($frame['askedAt'], $ask->askedAt());
        self::assertFalse($ask->hasExpired());
    }

    public function testExpiryLandsAtEqualityAndAnUnframedHandleAgesClosed(): void
    {
        $ask = PendingAsk::fromFrame(
            ['askId' => '0123456789abcdef', 'toolCallId' => 'c', 'tool' => 'Edit', 'arguments' => [], 'askedAt' => 1_000_000],
            static function (): void {},
        );
        self::assertInstanceOf(PendingAsk::class, $ask);
        $horizon = 1_000_000 + PendingAsk::EXPIRY_SECONDS;

        self::assertFalse($ask->hasExpired($horizon - 1), 'one second short of the horizon is still answerable');
        self::assertTrue($ask->hasExpired($horizon), 'expiry lands AT equality, the PairingCodes law');
        self::assertTrue($ask->hasExpired($horizon + 1));

        // A forged or absent stamp is never trusted as fresh: the handle
        // reads aged (fail-closed toward refusal), and a non-int stamp
        // falls to null at parse.
        $unframed = PendingAsk::fromFrame(
            ['askId' => '0123456789abcdef', 'toolCallId' => 'c', 'tool' => 'Edit', 'arguments' => []],
            static function (): void {},
        );
        self::assertInstanceOf(PendingAsk::class, $unframed);
        self::assertNull($unframed->askedAt());
        self::assertTrue($unframed->hasExpired());

        $forged = PendingAsk::fromFrame(
            ['askId' => '0123456789abcdef', 'toolCallId' => 'c', 'tool' => 'Edit', 'arguments' => [], 'askedAt' => 'soon'],
            static function (): void {},
        );
        self::assertTrue($forged?->hasExpired(), 'a non-int stamp parses to aged, never to fresh');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedFrames(): iterable
    {
        $ok = ['kind' => 'ask', 'askId' => '0123456789abcdef', 'toolCallId' => 'c', 'tool' => 'Edit', 'arguments' => []];
        yield 'no askId' => [\array_diff_key($ok, ['askId' => 1])];
        yield 'askId not 16 hex' => [['askId' => 'XYZ'] + $ok];
        yield 'askId with a trailing newline' => [['askId' => "0123456789abcdef\n"] + $ok];
        yield 'no tool' => [['tool' => ''] + $ok];
        yield 'arguments not a map' => [['arguments' => 'rm -rf /'] + $ok];
        yield 'toolCallId not a string' => [['toolCallId' => 7] + $ok];
    }

    /**
     * @dataProvider malformedFrames
     * @param array<string, mixed> $frame
     */
    public function testAnOutOfShapeFrameIsNotAQuestion(array $frame): void
    {
        self::assertNull(PendingAsk::fromFrame($frame, static function (): void {}));
    }

    public function testRejectIsAlwaysOnOfferAndUnknownSuggestionsAreDropped(): void
    {
        $ask = PendingAsk::fromFrame(
            ['askId' => '0123456789abcdef', 'toolCallId' => 'c', 'tool' => 'Edit', 'arguments' => [], 'suggestions' => ['once', 'sure', 'once', 3]],
            static function (): void {},
        );

        self::assertSame(['once', 'reject'], $ask?->suggestions);
    }

    public function testItSettlesExactlyOnce(): void
    {
        $settled = [];
        $ask = $this->ask(['once', 'always', 'reject'], $settled);

        self::assertFalse($ask->isSettled());
        self::assertNull($ask->resolution());
        self::assertTrue($ask->reply(PermissionReply::Once));
        self::assertFalse($ask->reply(PermissionReply::Reject));
        self::assertFalse($ask->cancel('late'));

        self::assertCount(1, $settled);
        self::assertSame(PermissionReply::Once, $settled[0]->reply);
        self::assertSame($settled[0], $ask->resolution());
        self::assertTrue($ask->isSettled());
    }

    public function testAlwaysWhereItIsNotOfferedSettlesAsOnce(): void
    {
        $settled = [];
        $ask = $this->ask(['once', 'reject'], $settled);

        $ask->reply(PermissionReply::Always);

        self::assertSame(PermissionReply::Once, $settled[0]->reply);
    }

    public function testCancelIsUnansweredAndNeverPermits(): void
    {
        $settled = [];
        $ask = $this->ask(['once', 'reject'], $settled);

        self::assertTrue($ask->cancel('Request cancelled'));

        self::assertTrue($settled[0]->cancelled);
        self::assertNull($settled[0]->reply);
        self::assertFalse($settled[0]->permits());
        self::assertSame('Request cancelled', $settled[0]->note);
    }

    public function testTheNoteIsClipped(): void
    {
        $settled = [];
        $this->ask(['once', 'reject'], $settled)->reply(PermissionReply::Reject, \str_repeat('x', 5000));

        self::assertSame(ChildChannel::MAX_NOTE_BYTES, \strlen($settled[0]->note));
    }

    /**
     * @param list<string>             $suggestions
     * @param list<PermissionResolved> $settled
     */
    private function ask(array $suggestions, array &$settled): PendingAsk
    {
        return new PendingAsk(
            '0123456789abcdef',
            'c',
            'Edit',
            [],
            'why',
            'gate',
            'default',
            $suggestions,
            [],
            static function (PermissionResolved $r) use (&$settled): void {
                $settled[] = $r;
            },
        );
    }
}

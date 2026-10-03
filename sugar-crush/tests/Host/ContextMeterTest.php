<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\ContextWindow;
use SugarCraft\Crush\Context\IdleCompactionPolicy;
use SugarCraft\Crush\Host\ContextMeter;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * O-2c: {@see ContextMeter} is the context arithmetic `Chat` used to own —
 * the raw proxy, the calibrated estimate, the window and the E17 calibration —
 * lifted out so a headless host measures a session exactly as the TUI does.
 * These cases pin the arithmetic itself and that `Chat` reads the meter its
 * workspace registered rather than a private copy.
 */
final class ContextMeterTest extends TestCase
{
    public function testTheRawProxyIsScriptWeightedTextPlusTenPerSentMessage(): void
    {
        $history = [Message::user('hello world'), Message::assistant('a reply of some length')];

        $expected = TokenEstimate::ofText('hello world') + 10 + TokenEstimate::ofText('a reply of some length') + 10;

        self::assertSame($expected, ContextMeter::new()->rawTokens($history));
    }

    public function testAUiOnlyRowOccupiesNoneOfTheWindow(): void
    {
        $meter = ContextMeter::new();
        $sent = [Message::user('hello world')];

        self::assertSame(
            $meter->rawTokens($sent),
            $meter->rawTokens([...$sent, Message::assistant(str_repeat('x', 4000))->withUiOnly()]),
        );
    }

    public function testAttachmentsCountFilesByTextAndImagesAtTheFlatEstimate(): void
    {
        $meter = ContextMeter::new();
        $bare = Message::user('see attached');
        $file = $bare->attachFile('a.txt', str_repeat('word ', 200));
        $image = $bare->attachImage('a.png', 'PNGBYTES', 'image/png');
        $uncaptured = $bare->attachImage('b.png');

        self::assertSame($meter->rawTokens([$bare]) + TokenEstimate::ofText(str_repeat('word ', 200)), $meter->rawTokens([$file]));
        self::assertSame($meter->rawTokens([$bare]) + ContextMeter::IMAGE_ATTACHMENT_TOKEN_ESTIMATE, $meter->rawTokens([$image]));
        self::assertSame($meter->rawTokens([$bare]), $meter->rawTokens([$uncaptured]), 'an attachment whose bytes were not captured is not inlined');
    }

    public function testTheEstimateIsTheRawProxyUntilACalibrationScalesIt(): void
    {
        $meter = ContextMeter::new();
        $history = [Message::user(str_repeat('abcd', 100))];
        $raw = $meter->rawTokens($history);

        self::assertSame($raw, $meter->estimate($history, null));
        self::assertSame((int) round($raw * 1.5), $meter->estimate($history, 1.5));
        self::assertSame(1, $meter->estimate([], 2.0), 'a calibrated estimate never reads zero');
    }

    public function testTheLimitIsTheBackendsWindowOrTheFallback(): void
    {
        self::assertSame(ContextWindow::FALLBACK_TOKENS, ContextMeter::new()->limit(new EchoBackend()));
    }

    public function testTheIdleQuestionIsThePolicysAnswer(): void
    {
        $meter = ContextMeter::new();
        $idle = new \DateTimeImmutable('-' . (IdleCompactionPolicy::IDLE_SECONDS + 60) . ' seconds');

        self::assertSame(
            IdleCompactionPolicy::shouldPrompt(5000, $idle, 1000),
            $meter->shouldPromptIdleCompaction(5000, $idle, 1000),
        );
        self::assertFalse($meter->shouldPromptIdleCompaction(5000, null, 1000), 'idleness unknown never prompts');
    }

    public function testACalibrationIsTheObservedOverRawRatioClampedToItsBounds(): void
    {
        $meter = ContextMeter::new();

        self::assertEqualsWithDelta(2.0, $meter->calibrationFrom(1000, Usage::new(2000)), 1e-9);
        self::assertSame(ContextMeter::CALIBRATION_MAX, $meter->calibrationFrom(1000, Usage::new(9000)), 'the ceiling bounds how early a tier may fire');
        self::assertSame(ContextMeter::CALIBRATION_MIN, $meter->calibrationFrom(1000, Usage::new(200)), 'no pairing loosens the proxy below itself');
    }

    public function testThePromptSplitIsPreferredAndDelegatedTokensAreNeverObserved(): void
    {
        $meter = ContextMeter::new();

        $split = Usage::new(9000, inputTokens: 1200, outputTokens: 7800, cacheReadTokens: 300, cacheCreationTokens: 0);
        self::assertEqualsWithDelta(1.5, $meter->calibrationFrom(1000, $split), 1e-9, 'prompt tokens, not the billed total');

        self::assertEqualsWithDelta(
            1.5,
            $meter->calibrationFrom(1000, Usage::new(5000, delegatedTokens: 3500)),
            1e-9,
            'a Task sub-agent\'s share is no part of this conversation\'s prompt',
        );
        self::assertNull($meter->calibrationFrom(1000, Usage::new(5000, delegatedTokens: 5000)), 'an all-delegated turn observes nothing');
    }

    public function testATurnThatObservedNothingKeepsTheExistingFactor(): void
    {
        $meter = ContextMeter::new();

        self::assertNull($meter->calibrationFrom(null, Usage::new(2000)), 'no estimate was taken at dispatch');
        self::assertNull($meter->calibrationFrom(0, Usage::new(2000)));
        self::assertNull($meter->calibrationFrom(1000, null), 'the provider reported nothing');
        self::assertNull($meter->calibrationFrom(1000, Usage::new(0)));
    }

    /**
     * The constructor docblock still cites Chat's own pair of bounds (CS is
     * owned elsewhere this wave); until they alias the meter's, this keeps the
     * two spellings from drifting apart.
     */
    public function testChatsCalibrationBoundsAgreeWithTheMeters(): void
    {
        self::assertSame(ContextMeter::CALIBRATION_MIN, (new \ReflectionClassConstant(Chat::class, 'TOKEN_CALIBRATION_MIN'))->getValue());
        self::assertSame(ContextMeter::CALIBRATION_MAX, (new \ReflectionClassConstant(Chat::class, 'TOKEN_CALIBRATION_MAX'))->getValue());
    }

    public function testChatReadsTheMeterItsWorkspaceRegistered(): void
    {
        $meter = ContextMeter::new();
        $chat = new Chat(workspace: WorkspaceContext::new()->withService(ContextMeter::class, $meter));

        self::assertSame($meter, (new \ReflectionMethod(Chat::class, 'contextMeter'))->invoke($chat));
    }

    public function testChatWithoutAWorkspaceMeasuresExactlyAsTheMeterDoes(): void
    {
        $history = [
            Message::user(str_repeat('数据', 50)),
            Message::user('fine')->attachImage('a.png', 'PNG', 'image/png'),
        ];
        $chat = new Chat(history: $history, backend: new EchoBackend());
        $meter = ContextMeter::new();

        self::assertSame($meter->estimate($history, null), $chat->contextTokens());
        self::assertSame($meter->limit(new EchoBackend()), $chat->contextTokenLimit());
        self::assertSame($meter->estimate($history, null) / $meter->limit(new EchoBackend()), $chat->contextUsagePercent());
    }
}

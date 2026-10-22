<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Agents\Live\AgentLiveRegistry;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Context\ContextPressure;
use SugarCraft\Code\Events\StepStarted;
use SugarCraft\Code\Events\UsageUpdated;
use SugarCraft\Code\Message;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\App\ToolEventPumpMsg;
use SugarCraft\Code\Usage;

/**
 * Roadmap 1.C-4: on an engine turn that reports its steps, the first Escape
 * asks for a soft stop (the token's `cancelSoft()`, which the fork turns into
 * a `cancel_soft` frame) and the next Escape still cancels hard. The status
 * bar names the step, the step's over-budget context figure, the stop in
 * progress and the turn's spend so far.
 */
final class EscapeSoftCancelTest extends TestCase
{
    public function testTheFirstEscapeOfASteppingTurnAsksForASoftStop(): void
    {
        $token = new CancellationToken();
        [$asked] = self::steppingChat($token)->update(new KeyMsg(KeyType::Escape));

        $this->assertTrue($token->isSoftCancelled());
        $this->assertFalse($token->isCancelled(), 'the first Escape kills nothing');
        $this->assertTrue($asked->inFlight, 'the turn runs on to its step boundary');
        $this->assertTrue($asked->stopRequested());
        $this->assertStringContainsString('stopping after step 2 · Esc to cancel now', self::statusBar($asked));
    }

    public function testAnyLaterEscapeCancelsHardHoweverLongAfter(): void
    {
        $token = new CancellationToken();
        $token->cancelSoft();
        // No lastEscapeAt: the double-press window is long gone.
        [$cancelled] = self::steppingChat($token)->update(new KeyMsg(KeyType::Escape));

        $this->assertTrue($token->isCancelled());
        $this->assertFalse($cancelled->inFlight);
        $this->assertNull($cancelled->liveStep(), 'the step belongs to the turn that just ended');
    }

    public function testATurnThatReportsNoStepsKeepsTheDoublePressRule(): void
    {
        $token = new CancellationToken();
        $chat = (new Chat(history: [Message::user('go')], backend: new EchoBackend(), inFlight: true, inFlightCancellation: $token))
            ->withSize(120, 20);

        [$armed] = $chat->update(new KeyMsg(KeyType::Escape));

        $this->assertFalse($token->isSoftCancelled(), 'nothing to stop softly');
        $this->assertTrue($armed->inFlight);
        // ISS-103: the glyph is the registry's stored frame (wall clock here,
        // never advanced because no pump runs), so the needle derives it.
        $glyph = AgentLiveRegistry::SPINNER[$armed->agentLive()->spinnerFrame()];
        $this->assertStringContainsString($glyph . ' thinking… · Esc Esc to cancel', self::statusBar($armed));
    }

    public function testAnEarlierTurnsStepIsNeverShownOrObeyed(): void
    {
        $token = new CancellationToken();
        $chat = self::steppingChat($token, stepGeneration: 3);

        $this->assertNull($chat->liveStep());
        [$armed] = $chat->update(new KeyMsg(KeyType::Escape));
        $this->assertFalse($token->isSoftCancelled());
        $this->assertTrue($armed->inFlight);
    }

    public function testTheBarNamesTheStepItsPressureAndTheSpendSoFar(): void
    {
        $bar = self::statusBar(self::steppingChat(new CancellationToken(), overBudget: true));

        $this->assertStringContainsString('step 2 · ctx 85% · thinking… · Esc to stop, Esc Esc to cancel', $bar);
        $this->assertStringContainsString('$0.0300', $bar, 'the steps billed so far count before the turn settles');
    }

    public function testAnUnpressuredStepShowsNoContextFigure(): void
    {
        $chat = self::steppingChat(new CancellationToken());
        $bar = self::statusBar($chat);
        $glyph = AgentLiveRegistry::SPINNER[$chat->agentLive()->spinnerFrame()];

        $this->assertStringContainsString($glyph . ' step 2 · thinking…', $bar);
        $this->assertStringNotContainsString('ctx ', $bar);
    }

    /**
     * ISS-103: the in-chat thinking row counts the turn's seconds off the
     * registry's stored clock — the pump moves it, rendering never reads a
     * wall clock of its own.
     */
    public function testTheThinkingLineCountsTheTurnsElapsedSeconds(): void
    {
        $clock = 1000.0;
        [$chat] = self::inFlightChat($clock);

        self::assertStringContainsString(
            'assistant is thinking…',
            Renderer::render($chat),
            'the first frame seeds the memo and shows no seconds yet',
        );

        $clock = 1005.0;
        [$pumped] = $chat->update(new ToolEventPumpMsg());

        $frame = Renderer::render($pumped);
        self::assertStringContainsString('assistant is thinking… · 0:05', $frame);
        self::assertStringNotContainsString('no tokens for', $frame, 'five seconds of silence is not a stall');
    }

    /**
     * ISS-103: after two minutes of silence the row says so in words — and a
     * single arriving token retires the notice while the elapsed figure,
     * belonging to the whole turn, stands.
     */
    public function testAStalledTurnNamesTheSilenceAndTheFirstTokenEndsIt(): void
    {
        $clock = 1000.0;
        [$chat, $inbox] = self::inFlightChat($clock);
        Renderer::render($chat);

        $clock = 1061.0;
        [$pumped] = $chat->update(new ToolEventPumpMsg());

        self::assertStringContainsString(
            'assistant is thinking… · 1:01 · no tokens for 1:01 · Esc Esc to cancel',
            Renderer::render($pumped),
        );

        $grew = (new Chat(
            history: [Message::user('go')],
            backend: new EchoBackend(),
            inFlight: true,
            liveToolEvents: $inbox,
            streamingText: 'Here you go:',
        ))->withSize(120, 30);

        $frame = Renderer::render($grew);
        self::assertStringNotContainsString('no tokens for', $frame, 'the silence clock restarts at the first token');
        self::assertStringContainsString('assistant is thinking… · 1:01', $frame, 'the turn itself is still 1:01 old');
    }

    /**
     * ISS-103 + rv-a11y-4: the reduced-motion switch freezes the glyph
     * process-wide without freezing the seconds.
     */
    public function testTheReducedMotionSwitchFreezesTheGlyphButNotTheClock(): void
    {
        AgentLiveRegistry::setStaticSpinner(true);

        try {
            $clock = 1000.0;
            [$chat] = self::inFlightChat($clock);
            Renderer::render($chat);

            $clock = 1005.0;
            [$pumped] = $chat->update(new ToolEventPumpMsg());

            self::assertStringContainsString(
                '⠋ assistant is thinking… · 0:05',
                Renderer::render($pumped),
                'the spinner rests on the first frame while the seconds move',
            );
        } finally {
            AgentLiveRegistry::setStaticSpinner(false);
        }
    }

    /**
     * A Chat mid-turn on its own inbox, whose registry runs on the caller's
     * clock variable; the inbox is returned so a second Chat can share the
     * same registry — the memo's owner — across frames of one turn.
     *
     * @return array{0: Chat, 1: \ArrayObject<int, array{0: int, 1: object}>}
     */
    private static function inFlightChat(float &$clock, string $streamingText = ''): array
    {
        $inbox = new \ArrayObject();
        AgentLiveRegistry::of($inbox, static function () use (&$clock): float {
            return $clock;
        });

        $chat = (new Chat(
            history: [Message::user('go')],
            backend: new EchoBackend(),
            inFlight: true,
            liveToolEvents: $inbox,
            streamingText: $streamingText,
        ))->withSize(120, 30);

        return [$chat, $inbox];
    }

    private static function steppingChat(CancellationToken $token, int $stepGeneration = 4, bool $overBudget = false): Chat
    {
        $pressure = new ContextPressure($overBudget ? 85_000 : 10_000, 9_000, 1_000, 10_500, 2_000, 500, 80_000, 100_000);

        return (new Chat(
            history: [Message::user('go')],
            backend: new EchoBackend(),
            inFlight: true,
            generation: 4,
            inFlightCancellation: $token,
            liveStep: new StepStarted(2, 1000, $pressure),
            liveUsage: new UsageUpdated(2, Usage::new(100, 0.02), Usage::new(150, 0.03)),
            liveStepGeneration: $stepGeneration,
        ))->withSize(160, 20);
    }

    private static function statusBar(Chat $chat): string
    {
        $rows = explode("\n", Renderer::render($chat));

        return (string) end($rows);
    }
}

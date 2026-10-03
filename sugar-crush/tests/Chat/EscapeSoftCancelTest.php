<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\ContextPressure;
use SugarCraft\Crush\Events\StepStarted;
use SugarCraft\Crush\Events\UsageUpdated;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Usage;

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
        $this->assertStringContainsString('⠴ thinking… · Esc Esc to cancel', self::statusBar($armed));
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
        $bar = self::statusBar(self::steppingChat(new CancellationToken()));

        $this->assertStringContainsString('⠴ step 2 · thinking…', $bar);
        $this->assertStringNotContainsString('ctx ', $bar);
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

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Usage;

/**
 * E17: the context tiers compared a chars/4 + 10-per-message ESTIMATE against
 * a provider-counted window — two units that "must never be summed, compared,
 * or shown as one figure" (the {@see Usage} docblock's own words). This suite
 * pins the calibration that answers the comparison's side of that mismatch:
 * the last settled turn's real measurement scales the estimator
 * ({@see Chat::turnEstimateObservation()}), bounded to [1.0, 3.0] and
 * fail-open to the raw proxy whenever nothing was measured.
 *
 * Everything is driven the way a user drives it — submit a draft, settle the
 * reply — because the pairing lives in the DISPATCH record, not in the
 * tracker: a test that poked the calibration by hand could not see the
 * submit-to-settle seam break. Fixture arithmetic, stated once because every
 * expected value below reads off it: the seeded `hello` history estimates
 * ceil(5/4)+10 = 12, which is the dispatch-side figure; settling GROWS the
 * history by the submitted prompt (`x`: 1+10 = 11) and the reply
 * (`done`: 1+10 = 11), so a post-settle `contextTokens()` reads over
 * 12+11+11 = 34 raw. A reported 24 against the 12 estimate is exactly a 2.0
 * factor — 34 × 2 = 68 is the number several tests expect.
 */
final class TokenEstimateCalibrationTest extends TestCase
{
    /** @param list<Message> $history */
    private function calibratableChat(array $history): Chat
    {
        return new Chat(history: $history, backend: new EchoBackend());
    }

    /**
     * Type a draft and submit it through the real dispatch path, leaving the
     * Chat inFlight with its estimate-for-dispatch recorded.
     */
    private function dispatchDraft(Chat $chat, string $draft): Chat
    {
        foreach (mb_str_split($draft) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        return $next;
    }

    /** Settle the inFlight turn with a Message carrying $usage. */
    private function settle(Chat $chat, ?Usage $usage): Chat
    {
        [$next] = $chat->update(new AssistantMsg(
            Message::assistant('done')->withUsage($usage),
        ));

        return $next;
    }

    public function testTheEstimatorIsTheRawProxyUntilSomethingIsMeasured(): void
    {
        $chat = $this->calibratableChat([Message::user('hello')]);

        $this->assertSame(12, $chat->contextTokens(), 'chars/4 + 10 with no factor — the pre-E17 answer, preserved for every unmeasured session');
    }

    public function testASettledTurnCalibratesTheEstimatorToTheProvidersFigure(): void
    {
        $chat = $this->calibratableChat([Message::user('hello')]);
        $chat = $this->settle($this->dispatchDraft($chat, 'x'), Usage::new(24, 0.001));

        $this->assertSame(
            68,
            $chat->contextTokens(),
            'the provider counted 24 against a 12 estimate (factor 2.0), now scaled over the 34-token settled history',
        );
    }

    public function testAnInflatedObservationIsClampedToTheCeiling(): void
    {
        $chat = $this->calibratableChat([Message::user('hello')]);
        $chat = $this->settle($this->dispatchDraft($chat, 'x'), Usage::new(1200, 0.5));

        $this->assertSame(
            102,
            $chat->contextTokens(),
            'a 100x pairing may move the tier, but only to the 3.0 ceiling — 34 raw × 3',
        );
    }

    public function testAnObservationBelowTheProxyDoesNotLoosenTheTier(): void
    {
        $chat = $this->calibratableChat([Message::user('hello')]);
        $chat = $this->settle($this->dispatchDraft($chat, 'x'), Usage::new(3, 0.0));

        $this->assertSame(
            34,
            $chat->contextTokens(),
            'the floor is the raw proxy: a short-turn pairing must not make the 95% tier believe there is MORE room than chars/4 already claimed',
        );
    }

    public function testThePromptHalfIsPreferredOverTheTotalWhereverBothExist(): void
    {
        $chat = $this->calibratableChat([Message::user('hello')]);
        // total 36, but the cache buckets resolve promptTokens() to
        // 4 + 20 + 0 = 24 — the moment CompleteResponse::$usage crosses the
        // fold, this is the shape that arrives. 24 pairs with the 12-token
        // dispatch estimate for a 2.0 factor (68 over the settled 34); had the
        // total been preferred the factor would clamp at 3.0 and the answer
        // would be 102 — the two are far apart on purpose.
        $usage = Usage::new(36, 0.002, inputTokens: 4, outputTokens: 12, cacheReadTokens: 20, cacheCreationTokens: 0);
        $chat = $this->settle($this->dispatchDraft($chat, 'x'), $usage);

        $this->assertSame(
            68,
            $chat->contextTokens(),
            'calibration pairs the tier estimate against the PROMPT side when the carrier reports it — not the total that includes completion tokens',
        );
    }

    public function testASilentSettlementKeepsTheLastMeasurement(): void
    {
        $chat = $this->calibratableChat([Message::user('hello')]);
        $chat = $this->settle($this->dispatchDraft($chat, 'x'), Usage::new(24, 0.001));
        $this->assertSame(68, $chat->contextTokens(), 'fixture arithmetic: factor 2.0 over the settled 34');

        // Second turn, provider reports NOTHING (the ordinary streamed answer):
        // one silent turn must not erase what a measured turn already said.
        // History grows by `y` (11) and the reply (11) → 56 raw, still × 2.0.
        $chat = $this->settle($this->dispatchDraft($chat, 'y'), null);

        $this->assertSame(112, $chat->contextTokens(), 'the factor survives an unreported settlement');
    }

    public function testThePairingIsOneShotAndNeverReused(): void
    {
        $chat = $this->calibratableChat([Message::user('hello')]);
        $chat = $this->settle($this->dispatchDraft($chat, 'x'), Usage::new(24, 0.001));
        $this->assertSame(68, $chat->contextTokens());

        // A stray settled AssistantMsg with NO dispatch behind it — a replay,
        // an out-of-band embedder send — must find the estimate consumed and
        // pair with nothing: the factor stays at the one real observation.
        // (Were the stale estimate reused, 1200/12 would clamp to 3.0 and the
        // 45-token history would answer 135.)
        $chat = $this->settle($chat, Usage::new(1200, 0.5));

        $this->assertSame(
            90,
            $chat->contextTokens(),
            'the estimate is cleared on consumption — an undispatched settlement cannot calibrate the session twice',
        );
    }

    public function testTheFactorSurvivesOrdinaryKeystrokes(): void
    {
        $chat = $this->calibratableChat([Message::user('hello')]);
        $chat = $this->settle($this->dispatchDraft($chat, 'x'), Usage::new(24, 0.001));

        [$idle] = $chat->update(new KeyMsg(KeyType::Char, 'a'));

        $this->assertSame(
            68,
            $idle->contextTokens(),
            'a calibration that evaporated on the next keystroke would be no calibration at all — the factor is mutate-carried model state',
        );
    }
}

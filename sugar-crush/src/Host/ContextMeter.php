<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Context\ContextWindow;
use SugarCraft\Crush\Context\IdleCompactionPolicy;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * How full a session's context window is: the token estimate every context
 * tier reads, its E17 calibration against what the provider really counted,
 * the window it is measured against, and the idle-compaction question asked
 * of the pair (roadmap O-2c, Appendix O §4.2 "token estimate calibration").
 *
 * WHY IT LEFT {@see Chat}. The arithmetic never needed a screen — it reads a
 * history, a backend and one float — but it lived as Chat privates, so a host
 * running sessions without a TUI (the server Appendix O designs) would have
 * had to re-spell it, and two spellings of a tier's numerator is how the 70%
 * reminder and the 85% compaction drift into measuring different things.
 * Chat now delegates every one of these figures here and keeps its methods'
 * names as thin delegates, because tests and docs cite them.
 *
 * STATELESS BY DESIGN. One meter serves every session of a workspace — it is
 * registered once on {@see WorkspaceContext} and read through
 * {@see WorkspaceContext::service()} — so the per-session state (the history,
 * the calibration factor, the estimate taken at dispatch) stays on the session
 * and is handed in. Nothing here mutates, which is what lets a forked turn
 * child and the parent agree without coordinating.
 */
final class ContextMeter
{
    /**
     * Floor of the E17 calibration factor — 1.0, ON PURPOSE: an observation
     * implying the provider counts FEWER tokens than the raw proxy (possible:
     * the +10-per-message overhead dominates a history of short messages,
     * and an unreported-usage streak paired wrongly could produce anything)
     * must not make the blocking tier LOUDER about fitting than the raw
     * proxy already was. Fail-open keeps the pre-E17 behaviour as the floor.
     */
    public const CALIBRATION_MIN = 1.0;

    /**
     * Ceiling of the E17 calibration factor. The raw proxy runs LOW ("code
     * and CJK tokenize worse than chars/4"), so the legitimate span above 1.0
     * is real but bounded; the observation meanwhile carries the whole turn's
     * BILLED TOTAL against a PROMPT-only estimate whenever the provider has
     * not crossed the split onto {@see \SugarCraft\Crush\Providers\CompleteResponse::$usage},
     * which inflates it by every completion's worth of tokens. 3.0 caps that
     * bundled error: the tier may fire up to a third early — the safe
     * direction against an overflow — and no single chatty turn can triple
     * the session's estimate. Widening this bound is a real decision, not a
     * tuning knob.
     */
    public const CALIBRATION_MAX = 3.0;

    /**
     * Audit 15b-15: what one attached image is counted as by
     * {@see rawTokens()}. Anthropic bills an image at roughly
     * width×height/750 tokens and downscales anything past ~1.15 megapixels,
     * which caps one image near 1,600; OpenAI's high-detail tiling lands in the
     * same range for a typical screenshot. The cap is the honest flat figure:
     * an estimate that errs high fires a tier a little early, one that errs
     * low lets a session of screenshots overrun the window.
     */
    public const IMAGE_ATTACHMENT_TOKEN_ESTIMATE = 1600;

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * The PRE-calibration proxy — {@see TokenEstimate::ofText()} (chars/4
     * for ASCII/Latin, heavier per character for CJK, other scripts and
     * emoji) + 10 per message, plus every inlined attachment.
     *
     * Its own concept because E17's observation must be paired against THIS,
     * never against the calibrated output: recording raw×f at dispatch and
     * folding real/(raw×f) at settle carries the previous factor back into
     * every later observation, and the factor then cycles period-2 around the
     * square root of the true ratio (a real 4× ratio oscillates 1.33↔3.0
     * forever, under-estimating every other turn — the direction that fires
     * the tier LATE). Paired against the raw proxy, the stored factor is the
     * true correction itself and repeated settles converge to it.
     *
     * The same figure as {@see ContextCompactor}'s countTokens() since
     * 15b-13-rem, over the same rows: UI-only rows are skipped here, and the
     * compactor is never handed them.
     *
     * @param list<Message> $history
     */
    public function rawTokens(array $history): int
    {
        $total = 0;
        foreach ($history as $msg) {
            // A UI-only row is never sent (audit 15b-03), so it occupies none
            // of the window this estimates.
            if ($msg->uiOnly) {
                continue;
            }
            // Script-weighted, not codepoints/4 (audit 15b-13): CJK/emoji ran
            // 3-6x under, past what the [1.0, 3.0] calibration can correct.
            $total += TokenEstimate::ofText($msg->content);
            $total += 10; // role overhead
            // Audit 15b-15: an attached file is inlined into the request and
            // an image billed as image tokens, so both occupy the window - a
            // 256 KiB `@file` must reach the tiers, not hide behind a short
            // prompt.
            foreach ($msg->attachments as $attachment) {
                if (!$attachment instanceof Attachment || $attachment->data === null) {
                    continue;
                }
                $total += $attachment->type === AttachmentType::Image
                    ? self::IMAGE_ATTACHMENT_TOKEN_ESTIMATE
                    : TokenEstimate::ofText($attachment->data);
            }
        }

        return $total;
    }

    /**
     * The estimate every context tier reads: {@see rawTokens()} scaled by the
     * session's last estimate-vs-real observation when one exists.
     *
     * The scale is EMPIRICAL, bounded to [{@see CALIBRATION_MIN},
     * {@see CALIBRATION_MAX}], and until the provider reports a prompt split it
     * carries the whole turn's billed total over a prompt-only estimate (HIGH,
     * the safe direction). With a calibration in force this agrees with the
     * provider's counter MORE than {@see ContextCompactor}'s raw count does,
     * so the 70% nudge can arrive a little before a `/compact` would
     * self-report — a nudge deciding to fire, not a tier refusing.
     *
     * @param list<Message> $history
     */
    public function estimate(array $history, ?float $calibration): int
    {
        $total = $this->rawTokens($history);

        if ($calibration === null) {
            return $total;
        }

        return max(1, (int) round($total * $calibration));
    }

    /**
     * The window every context tier is a percentage of: the backend's
     * provider-reported context window when it implements
     * {@see \SugarCraft\Crush\Backend\ReportsContextWindow} and reports a
     * positive one, else {@see ContextWindow::FALLBACK_TOKENS}. The figure is
     * MODEL-AWARE and provider-reported; no literal here is a contract.
     */
    public function limit(Backend $backend): int
    {
        return ContextWindow::ofBackend($backend);
    }

    /**
     * Whether the idle-compaction prompt is due: idle longer than
     * $idleSeconds AND the estimate past the whole window. The caller supplies
     * the limit because the backend is what it can see, and the idle bound
     * because the session's settings are.
     *
     * @param int $idleSeconds the session's `compaction.idleOfferSeconds`
     *        ({@see \SugarCraft\Crush\Context\CompactorConfig::$idleOfferSeconds}),
     *        else {@see IdleCompactionPolicy::IDLE_SECONDS}; `0` never offers
     */
    public function shouldPromptIdleCompaction(
        int $tokenCount,
        ?\DateTimeImmutable $lastActivityAt,
        int $tokenLimit,
        int $idleSeconds = IdleCompactionPolicy::IDLE_SECONDS,
    ): bool {
        return IdleCompactionPolicy::shouldPrompt($tokenCount, $lastActivityAt, $tokenLimit, idleSeconds: $idleSeconds);
    }

    /**
     * The calibration factor one settled turn implies, or null when the turn
     * observed nothing (and the session's existing factor must stand).
     *
     * `$estimateAtDispatch` is the RAW proxy ({@see rawTokens()}) taken when
     * the turn was sent — see that method for why pairing against the
     * calibrated figure never converges. The observed side prefers the
     * provider's prompt count and otherwise takes the conversation's OWN total
     * ({@see Usage::ownTokens()}), never the share Task sub-agents billed into
     * the turn, which measures their prompts rather than this one; a turn
     * whose every token was delegated therefore observes nothing. The result
     * is clamped to [{@see CALIBRATION_MIN}, {@see CALIBRATION_MAX}].
     *
     * A null answer is deliberately "keep the old factor", not "clear it": one
     * silent turn must not erase a measurement a loud turn already made.
     */
    public function calibrationFrom(?int $estimateAtDispatch, ?Usage $usage): ?float
    {
        // ownTokens(), not totalTokens: since B4 a turn's total also carries
        // the tokens its Task sub-agents billed, and a sub-agent's fifty steps
        // are no part of THIS conversation's prompt (audit B4-rem(iii)).
        $observed = $usage?->promptTokens() ?? $usage?->ownTokens();

        if ($estimateAtDispatch === null || $estimateAtDispatch <= 0 || $observed === null || $observed <= 0) {
            return null;
        }

        $ratio = $observed / $estimateAtDispatch;

        return min(self::CALIBRATION_MAX, max(self::CALIBRATION_MIN, $ratio));
    }
}

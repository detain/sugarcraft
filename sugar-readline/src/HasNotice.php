<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * The transient notice row (findings 033/084): a dim one-line announce for
 * refusals and silent policy events — the FIFO un-mark at the selection
 * cap, Ctrl+R over an empty history, a newline the line limit refuses —
 * that would otherwise happen with zero feedback. The notice lives exactly
 * one input beat: every public key funnel clears it before dispatch, so
 * the refusing arm may set a fresh one on the same clone chain and the
 * next key erases it.
 *
 * Mirrors TextPrompt's error-channel precedent: state rides the cloned
 * instance, the row is painted through RowFit under the body, and the
 * message text is already sanitized at paint time (never at set time —
 * the set sites hold translated text or user data per the LOCALES.md law).
 *
 * A using class must carry a `private ?int $displayWidth` field; the
 * trait adds the `notice` field and the funnel helpers only.
 */
trait HasNotice
{
    private string $notice = '';

    /** The painted notice line, or null when nothing is on display. */
    private function noticeRowIfAny(): ?string
    {
        if ($this->notice === '') {
            return null;
        }

        return RowFit::row([[Ansi::sanitize($this->notice), Styles::COMPLETION]], $this->displayWidth);
    }

    /**
     * Copy of $this with any pending notice cleared; identity when there
     * is nothing to clear (funnels must not churn clones for silence).
     */
    private function withoutNotice(): static
    {
        if ($this->notice === '') {
            return $this;
        }

        $clone         = clone $this;
        $clone->notice = '';
        return $clone;
    }

    /** Copy of $this announcing $message until the next key. */
    private function withNotice(string $message): static
    {
        $clone         = clone $this;
        $clone->notice = $message;
        return $clone;
    }
}

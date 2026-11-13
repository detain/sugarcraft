<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Support;

use SugarCraft\Code\Context\PromptFence;

/**
 * The trust floor for inbound platform text (§6.4 trust law, §9.5):
 * channel bytes are UNTRUSTED input spliced into a prompt, and the repo's
 * own authority on that splice is {@see PromptFence} — the same escape the
 * inbound-webhook door applies before splicing `{{payload}}`
 * (`Server/Http/HookWebhooks.php`, the class docblock's `<` → `&lt;`
 * rewrite over the fence-tag roster plus the chat-template control-token
 * pipes, and Unicode tag characters deleted). The bridge REUSES the class
 * rather than copying the pattern: one roster, one head.
 *
 * Redaction (§2.3, §9.4) rides the same door: configured secrets (the serve
 * token, bot tokens, app passwords — anything the operator named) never
 * echo back into a transcript or channel, in either direction.
 */
final class TextSafety
{
    /** @var list<string> */
    private array $secrets = [];

    public function withSecrets(array $secrets): self
    {
        $clean = \array_values(\array_filter(
            $secrets,
            static fn (mixed $s): bool => \is_string($s) && \strlen($s) >= 6,
        ));
        $clone = clone $this;
        $clone->secrets = $clean;

        return $clone;
    }

    /** Untrusted channel text → prompt-safe text, byte-for-byte inert against the roster. */
    public function fence(string $untrusted): string
    {
        return $this->redact(PromptFence::escape($untrusted));
    }

    /**
     * Runtime-issued secrets (pairing codes, §7.3) join the roster as they
     * are minted. This is the ONE deliberate mutation on this value object
     * — a pairing code cannot be pre-registered at boot, and the additive
     * rule keeps the floor monotonic: secrets only ever enter, never leave,
     * for the lifetime of the channel.
     */
    public function addSecret(string $secret): void
    {
        $secret = \trim($secret);
        if (\strlen($secret) < 6 || \in_array($secret, $this->secrets, true)) {
            return;
        }
        $this->secrets[] = $secret;
    }

    /** Model/server output about to echo into a channel: redact, do NOT fence. */
    public function outbound(string $text): string
    {
        return $this->redact($text);
    }

    /**
     * Exact-byte sweep, best-effort by design: str_replace removes only a
     * contiguous, case-exact occurrence of a registered secret. A homoglyph
     * or interleaved-formatting or case-shifted variant survives — this is
     * a floor against casual leakage, not a normalization adversary, and
     * every one-way fold added to match more would silently rewrite
     * legitimate text too. The compensating bounds carry the rest: pairing
     * codes sit behind TTL 3600 + the 3-pending cap + DM/approver-only
     * delivery, and the serve token's exposure is host-side.
     */
    private function redact(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = \str_replace($secret, '[redacted]', $text);
        }

        return $text;
    }
}

<?php

declare(strict_types=1);

namespace ChannelBridge;

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

    /** Model/server output about to echo into a channel: redact, do NOT fence. */
    public function outbound(string $text): string
    {
        return $this->redact($text);
    }

    private function redact(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = \str_replace($secret, '[redacted]', $text);
        }

        return $text;
    }
}

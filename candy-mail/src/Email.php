<?php

declare(strict_types=1);

namespace SugarCraft\Mail;

use SugarCraft\Core\Concerns\Mutable;

/**
 * Immutable email message value object.
 */
final class Email
{
    use Mutable;

    /** @var list<string> Sender addresses (usually one). */
    public readonly array $from;
    /** @var list<string> Primary recipients. */
    public readonly array $to;
    /** @var list<string> Carbon-copy recipients. */
    public readonly array $cc;
    /** @var list<string> Blind carbon-copy recipients. */
    public readonly array $bcc;
    /** @var string|null Email subject line. */
    public readonly ?string $subject;
    /** @var string|null Plain-text body. */
    public readonly ?string $body;
    /** @var string|null HTML body (alternative to plain body). */
    public readonly ?string $htmlBody;
    /** @var string|null Reply-to address. */
    public readonly ?string $replyTo;
    /** @var list<Attachment> File attachments. */
    public readonly array $attachments;
    /** @var string|null Signature appended to body. */
    public readonly ?string $signature;

    /**
     * @param list<string>      $from
     * @param list<string>      $to
     * @param list<string>      $cc
     * @param list<string>      $bcc
     * @param list<Attachment>  $attachments
     */
    public function __construct(
        array $from,
        array $to,
        ?string $subject = null,
        ?string $body = null,
        array $cc = [],
        array $bcc = [],
        ?string $htmlBody = null,
        ?string $replyTo = null,
        array $attachments = [],
        ?string $signature = null,
    ) {
        $this->from         = $this->sanitizeAddressList($from);
        $this->to           = $this->sanitizeAddressList($to);
        $this->subject      = $this->sanitizeHeader($subject, 'subject');
        $this->body         = $body;
        $this->cc           = $this->sanitizeAddressList($cc);
        $this->bcc          = $this->sanitizeAddressList($bcc);
        $this->htmlBody     = $htmlBody;
        $this->replyTo      = $replyTo !== null ? $this->sanitizeAddr($replyTo) : null;
        $this->attachments  = $attachments;
        $this->signature    = $signature;
    }

    /**
     * Sanitize a list of addresses (strip CRLF, validate format).
     *
     * @param list<string> $addrs
     * @return list<string>
     */
    /**
     * Merge an existing list with incoming addresses, dropping duplicates.
     *
     * RFC 5321 treats the domain part as case-insensitive while the local part
     * is (technically) case-sensitive, so the dedupe key lowercases only the
     * domain — and the FIRST occurrence keeps its exact casing. Returns a
     * proper list (array_merge of two lists already does; re-indexing is
     * asserted, not assumed).
     *
     * @param list<string> $existing
     * @param array<array-key, string> $incoming
     * @return list<string>
     */
    private static function mergeAddressList(array $existing, array $incoming): array
    {
        $seen   = [];
        $merged = [];
        foreach ([...$existing, ...$incoming] as $addr) {
            $at  = \strrpos($addr, '@');
            $key = $at === false
                ? \strtolower($addr)
                : \substr($addr, 0, $at) . '@' . \strtolower(\substr($addr, $at + 1));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[]   = $addr;
        }
        \assert(\array_is_list($merged));
        return $merged;
    }

    private function sanitizeAddressList(array $addrs): array
    {
        // Empty arrays are allowed; filter out empty strings first
        $filtered = [];
        foreach ($addrs as $addr) {
            if ($addr !== '') {
                $filtered[] = $this->sanitizeAddr($addr);
            }
        }
        return \array_values($filtered);
    }

    /**
     * Strip CRLF from an address and validate it as a bare email.
     * Accepts display-name format "Name <addr@host>" and validates just the address part.
     *
     * Mirrors charmbracelet/pop address sanitization.
     * Uses structural validation (has exactly one @, non-empty local part)
     * rather than FILTER_VALIDATE_EMAIL which rejects short/informal TLDs.
     */
    private function sanitizeAddr(string $addr): string
    {
        if ($addr === '') {
            return $addr;
        }
        // Reject any CRLF in the raw token
        if (\preg_match('/[\r\n]/', $addr)) {
            throw new \InvalidArgumentException(Lang::t('email.crlf_in_address'));
        }
        $trimmed = \trim($addr);

        // Extract bare address if in "Name <addr@host>" format
        $bareAddr = $trimmed;
        if (\preg_match('/<([^>]+)>$/', $trimmed, $matches)) {
            $bareAddr = $matches[1];
        }

        // Structural validation: must have exactly one @ and non-empty local part
        if (\substr_count($bareAddr, '@') !== 1 || \str_starts_with($bareAddr, '@') || \str_ends_with($bareAddr, '@')) {
            throw new \InvalidArgumentException(Lang::t('email.invalid_address', ['addr' => $bareAddr]));
        }
        return $trimmed;
    }

    /**
     * Reject CRLF in a header field value.
     */
    private function sanitizeHeader(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (\preg_match('/[\r\n]/', $value)) {
            throw new \InvalidArgumentException(Lang::t('email.crlf_in_header', ['field' => $field]));
        }
        return $value;
    }

    /**
     * Create from a single from/to pair with optional subject/body.
     *
     * Mirrors charmbracelet/pop Email::new() factory.
     */
    public static function new(
        string $from,
        string $to,
        ?string $subject = null,
        ?string $body = null,
    ): self {
        return new self(
            from:    [$from],
            to:      [$to],
            subject: $subject,
            body:    $body,
        );
    }

    // -------------------------------------------------------------------------
    // With* builders (return new immutable instances)
    // -------------------------------------------------------------------------

    public function withFrom(string $from): self
    {
        return $this->mutate(['from' => [$from]]);
    }

    public function withTo(string ...$to): self
    {
        return $this->mutate(['to' => self::mergeAddressList($this->to, $to)]);
    }

    public function withSubject(string $subject): self
    {
        return $this->mutate(['subject' => $subject]);
    }

    public function withBody(string $body): self
    {
        return $this->mutate(['body' => $body]);
    }

    public function withHtmlBody(string $htmlBody): self
    {
        return $this->mutate(['htmlBody' => $htmlBody]);
    }

    public function withCc(string ...$cc): self
    {
        return $this->mutate(['cc' => self::mergeAddressList($this->cc, $cc)]);
    }

    public function withBcc(string ...$bcc): self
    {
        return $this->mutate(['bcc' => self::mergeAddressList($this->bcc, $bcc)]);
    }

    public function withReplyTo(string $replyTo): self
    {
        return $this->mutate(['replyTo' => $replyTo]);
    }

    /**
     * Add an attachment from a file path.
     *
     * @throws \InvalidArgumentException if path is null (use Attachment::fromContent for empty content)
     */
    public function withAttachment(string $filename, ?string $path = null): self
    {
        if ($path === null) {
            throw new \InvalidArgumentException(Lang::t('attachment.no_path'));
        }
        $att = Attachment::fromPath($path, $filename);

        return $this->mutate(['attachments' => \array_merge($this->attachments, [$att])]);
    }

    public function withInlineAttachment(string $path, string $cid, ?string $filename = null): self
    {
        $att = Attachment::inline($path, $cid, $filename);

        return $this->mutate(['attachments' => \array_merge($this->attachments, [$att])]);
    }

    public function withSignature(string $signature): self
    {
        return $this->mutate(['signature' => $signature]);
    }

    // -------------------------------------------------------------------------
    // Derived
    // -------------------------------------------------------------------------

    /**
     * Body text with signature appended (if set).
     */
    public function bodyWithSignature(): ?string
    {
        if ($this->body === null) {
            return null;
        }
        if ($this->signature === null || $this->signature === '') {
            return $this->body;
        }
        return $this->body . "\n\n" . $this->signature;
    }

    /**
     * All recipients combined (to + cc + bcc) for SMTP "RCPT TO" routing.
     *
     * @return list<string>
     */
    public function allRecipients(): array
    {
        return \array_values(\array_unique(\array_merge(
            $this->to,
            $this->cc,
            $this->bcc,
        )));
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------
}

<?php

declare(strict_types=1);

namespace SugarCraft\Post;

use SugarCraft\Core\Concerns\Mutable;

/**
 * Immutable email message value object.
 *
 * @property list<string>       $from      Sender addresses (usually one).
 * @property list<string>       $to        Primary recipients.
 * @property list<string>       $cc        Carbon-copy recipients.
 * @property list<string>       $bcc       Blind carbon-copy recipients.
 * @property string|null        $subject   Email subject line.
 * @property string|null        $body      Plain-text body.
 * @property string|null        $htmlBody  HTML body (alternative to plain body).
 * @property string|null        $replyTo   Reply-to address.
 * @property list<Attachment>   $attachments File attachments.
 * @property string|null        $signature Signature appended to body.
 */
final class Email
{
    use Mutable;

    public readonly array $from;
    public readonly array $to;
    public readonly array $cc;
    public readonly array $bcc;
    public readonly ?string $subject;
    public readonly ?string $body;
    public readonly ?string $htmlBody;
    public readonly ?string $replyTo;
    public readonly array $attachments;
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
        return $this->mutate(['to' => \array_merge($this->to, $to)]);
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
        return $this->mutate(['cc' => \array_merge($this->cc, $cc)]);
    }

    public function withBcc(string ...$bcc): self
    {
        return $this->mutate(['bcc' => \array_merge($this->bcc, $bcc)]);
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

    public function withInlineAttachment(string $path, string $cid, string $filename = null): self
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

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
    /** @var string|null Signature appended to the plain-text body (HTML-only mails ignore it — see bodyWithSignature()). */
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
        // Bodies ride the MIME document verbatim except for stray control
        // bytes: NUL/BEL/etc. inside text survive into wire bytes that
        // downstream filters and terminals choke on.
        // Tab and newlines are kept; CR exists only to form CRLF, which the
        // renderer normalizes anyway.
        $this->body         = self::stripStrayControl($body);
        $this->cc           = $this->sanitizeAddressList($cc);
        $this->bcc          = $this->sanitizeAddressList($bcc);
        $this->htmlBody     = self::stripStrayControl($htmlBody);
        $this->replyTo      = $replyTo !== null ? $this->sanitizeAddr($replyTo) : null;
        $this->attachments  = $attachments;
        $this->signature    = self::stripStrayControl($signature);
    }

    /**
     * Remove C0 control bytes (RFC 2046 §2 text bodies are printable plus
     * tab/newline) and DEL from a body-like string, keeping \t \n \r.
     */
    private static function stripStrayControl(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }

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

    /**
     * Sanitize a list of addresses (refuse injection bytes, validate format).
     *
     * @param array<array-key, string> $addrs
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
     * Refuse any address containing a C0 control byte or DEL (reject, never
     * fold it away) and validate it as a bare email.
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
        // ...and refuse the rest of the C0 + DEL class. CR/LF are the classic
        // header-splitting pair, but NUL, VT, FF, ESC, BEL, raw TAB and DEL
        // have no legal place in an envelope RCPT or header word either —
        // the residual risk they carry is upstream log corruption.
        if (\preg_match('/[\x00-\x1F\x7F]/', $addr)) {
            throw new \InvalidArgumentException(Lang::t('email.invalid_address', ['addr' => $addr]));
        }
        $trimmed = \trim($addr);

        // Angle brackets may only appear as ONE well-formed trailing
        // route-addr (RFC 5322 §3.4: display-name [CFWS] "<" addr-spec ">").
        // The old code extracted the LAST "<…>" and validated only its
        // contents, so 'a@b <c@d> </x>' or 'Name <a@b> junk' rode on with
        // stray brackets that break the envelope/header agreement downstream.
        if (\str_contains($trimmed, '<') && \preg_match('/^(?:[^<>]* )?<[^<>]+>$/', $trimmed) !== 1) {
            throw new \InvalidArgumentException(Lang::t('email.invalid_address', ['addr' => $trimmed]));
        }

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
     * Reject CRLF and the C0/DEL control-byte class in a header field value.
     *
     * Refused class (candy-mail-003, parity with sanitizeAddr's C0+DEL
     * refusal and the stripStrayControl body doctrine — refusal, not
     * stripping): 0x00-0x08, 0x0B-0x0C, 0x0E-0x1F, 0x7F. TAB stays legal
     * (header WSP); CR/LF are caught by the dedicated CRLF refusal above.
     */
    private function sanitizeHeader(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (\preg_match('/[\r\n]/', $value)) {
            throw new \InvalidArgumentException(Lang::t('email.crlf_in_header', ['field' => $field]));
        }
        if (\preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException(Lang::t('email.invalid_header_char', ['field' => $field]));
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
     *
     * The signature joins the PLAIN-TEXT body only.
     * An HTML-only mail returns null here and the renderer emits the HTML
     * part verbatim — appending raw text to markup would corrupt it, and
     * auto-wrapping the signature in <p> + escaping is a product decision
     * this library deliberately does not make silently. If you send
     * HTML-only with a signature, include the signature in $htmlBody.
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
     * Dedupe uses the SAME key as the address-list
     * merge (local part verbatim, domain lower-cased — RFC 5321 §2.3.3 makes
     * the domain case-insensitive). The old raw array_unique let a to:
     * A@Example.com plus cc: a@example.com pair deliver twice.
     *
     * @return list<string>
     */
    public function allRecipients(): array
    {
        return self::mergeAddressList(self::mergeAddressList($this->to, $this->cc), $this->bcc);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------
}

<?php

declare(strict_types=1);

namespace SugarCraft\Post;

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
        $this->from         = \array_values(\array_map('trim', $from));
        $this->to           = \array_values(\array_map('trim', $to));
        $this->subject      = $subject;
        $this->body         = $body;
        $this->cc           = \array_values(\array_map('trim', $cc));
        $this->bcc          = \array_values(\array_map('trim', $bcc));
        $this->htmlBody     = $htmlBody;
        $this->replyTo      = $replyTo;
        $this->attachments  = $attachments;
        $this->signature    = $signature;
    }

    /**
     * Create from named positional args (variadic convenience).
     */
    public static function make(
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
        return new self(
            from:         [$from],
            to:           $this->to,
            subject:      $this->subject,
            body:         $this->body,
            cc:           $this->cc,
            bcc:          $this->bcc,
            htmlBody:     $this->htmlBody,
            replyTo:      $this->replyTo,
            attachments:  $this->attachments,
            signature:    $this->signature,
        );
    }

    public function withTo(string ...$to): self
    {
        return new self(
            from:         $this->from,
            to:           \array_merge($this->to, $to),
            subject:      $this->subject,
            body:         $this->body,
            cc:           $this->cc,
            bcc:          $this->bcc,
            htmlBody:     $this->htmlBody,
            replyTo:      $this->replyTo,
            attachments:  $this->attachments,
            signature:    $this->signature,
        );
    }

    public function withSubject(string $subject): self
    {
        return $this->with('subject', $subject);
    }

    public function withBody(string $body): self
    {
        return $this->with('body', $body);
    }

    public function withHtmlBody(string $htmlBody): self
    {
        return $this->with('htmlBody', $htmlBody);
    }

    public function withCc(string ...$cc): self
    {
        return new self(
            from:         $this->from,
            to:           $this->to,
            subject:      $this->subject,
            body:         $this->body,
            cc:           \array_merge($this->cc, $cc),
            bcc:          $this->bcc,
            htmlBody:     $this->htmlBody,
            replyTo:      $this->replyTo,
            attachments:  $this->attachments,
            signature:    $this->signature,
        );
    }

    public function withBcc(string ...$bcc): self
    {
        return new self(
            from:         $this->from,
            to:           $this->to,
            subject:      $this->subject,
            body:         $this->body,
            cc:           $this->cc,
            bcc:          \array_merge($this->bcc, $bcc),
            htmlBody:     $this->htmlBody,
            replyTo:      $this->replyTo,
            attachments:  $this->attachments,
            signature:    $this->signature,
        );
    }

    public function withReplyTo(string $replyTo): self
    {
        return $this->with('replyTo', $replyTo);
    }

    /**
     * Add an attachment from a file path.
     */
    public function withAttachment(string $filename, string $path = null): self
    {
        $att = $path !== null
            ? Attachment::fromPath($path, $filename)
            : Attachment::fromContent('', $filename);

        return new self(
            from:         $this->from,
            to:           $this->to,
            subject:      $this->subject,
            body:         $this->body,
            cc:           $this->cc,
            bcc:          $this->bcc,
            htmlBody:     $this->htmlBody,
            replyTo:      $this->replyTo,
            attachments:  \array_merge($this->attachments, [$att]),
            signature:    $this->signature,
        );
    }

    public function withInlineAttachment(string $path, string $cid, string $filename = null): self
    {
        $att = Attachment::inline($path, $cid, $filename);

        return new self(
            from:         $this->from,
            to:           $this->to,
            subject:      $this->subject,
            body:         $this->body,
            cc:           $this->cc,
            bcc:          $this->bcc,
            htmlBody:     $this->htmlBody,
            replyTo:      $this->replyTo,
            attachments:  \array_merge($this->attachments, [$att]),
            signature:    $this->signature,
        );
    }

    public function withSignature(string $signature): self
    {
        return $this->with('signature', $signature);
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

    /** Helper for simple field replacements. */
    private function with(string $prop, mixed $value): self
    {
        $args = [
            'from'        => $this->from,
            'to'          => $this->to,
            'subject'     => $this->subject,
            'body'        => $this->body,
            'cc'          => $this->cc,
            'bcc'         => $this->bcc,
            'htmlBody'    => $this->htmlBody,
            'replyTo'     => $this->replyTo,
            'attachments' => $this->attachments,
            'signature'   => $this->signature,
        ];
        $args[$prop] = $value;

        return new self(...$args);
    }
}

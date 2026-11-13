<?php

declare(strict_types=1);

namespace SugarCraft\Mail;

/**
 * Represents a file attachment on an email.
 */
final class Attachment
{
    /** @var string Display name of the attachment. */
    public readonly string $filename;
    /** @var string|null Path to the file (if from disk). */
    public readonly ?string $path;
    /** @var string|null Raw content bytes (if from memory). */
    public readonly ?string $content;
    /** @var string Detected or specified MIME type. */
    public readonly string $mimeType;
    /** @var string Content-transfer encoding (base64 default). */
    public readonly string $encoding;
    /** @var string|null Content-ID for inline (embed) attachments. */
    public readonly ?string $cid;

    /**
     * Hard local ceiling for attachment bytes (25 MiB), enforced before the
     * eager read and again in the constructor for fromContent(). Submission
     * servers cap messages around 25-50 MiB (RFC 1870 SIZE is advisory and
     * this client does not read it), and the base64 MIME transfer encoding
     * amplifies the wire by ~4/3 — an unbounded read here is memory
     * exhaustion at the sender before the server ever says no.
     */
    public const MAX_BYTES = 26_214_400;

    /**
     * Create from a file path.
     *
     * The file is read eagerly here: an unreadable or missing path fails
     * immediately with a {@see \RuntimeException} rather than producing an
     * attachment whose content silently reads back as 0 bytes at send time.
     *
     * @throws \RuntimeException if the file cannot be read
     */
    public static function fromPath(string $path, ?string $filename = null): self
    {
        $name = $filename ?? \basename($path);
        $mime = self::detectMimeType($path);
        $content = self::readFileOrThrow($path);

        return new self(
            filename:  $name,
            path:      $path,
            content:   $content,
            mimeType:  $mime,
            encoding:  'base64',
            cid:       null,
        );
    }

    /**
     * Create from raw content bytes.
     */
    public static function fromContent(string $content, string $filename, string $mimeType = 'application/octet-stream'): self
    {
        return new self(
            filename:  $filename,
            path:      null,
            content:   $content,
            mimeType:  $mimeType,
            encoding:  'base64',
            cid:       null,
        );
    }

    /**
     * Create an inline (embedded) image attachment.
     *
     * @throws \RuntimeException if the file cannot be read
     * @throws \InvalidArgumentException if the CID is not a valid RFC 2392 format
     */
    public static function inline(string $path, string $cid, ?string $filename = null): self
    {
        $name = $filename ?? \basename($path);

        // The ctor gate below is the choke for every path, but inline()
        // additionally checks BEFORE reading the file so a forged cid is
        // diagnosed even when the path would fail first (the earlier order is kept).
        self::refuseMalformedCid($cid);

        $mime = self::detectMimeType($path);
        $content = self::readFileOrThrow($path);

        return new self(
            filename:  $name,
            path:      $path,
            content:   $content,
            mimeType:  $mime,
            encoding:  'base64',
            cid:       $cid,
        );
    }

    /**
     * Get the raw content bytes.
     *
     * Distinct from the public readonly `$content` property: the property
     * is the raw (nullable) backing field, while this method resolves the
     * bytes — including the defense-in-depth path re-read below.
     *
     * Every public factory reads eagerly, so `$content` is normally already
     * set; the path re-read below is defense-in-depth for a future factory
     * that defers the read, not a behavior reachable through today's API.
     *
     * @throws \RuntimeException if the path is set but the file cannot be read
     */
    public function content(): string
    {
        if ($this->content !== null) {
            return $this->content;
        }
        if ($this->path !== null) {
            // Suppress warning when file doesn't exist
            $prev = \error_reporting(E_ALL & ~\E_WARNING);
            $c = @\file_get_contents($this->path);
            \error_reporting($prev);
            if ($c === false) {
                throw new \RuntimeException(Lang::t('attachment.unreadable', ['path' => $this->path]));
            }
            return $c;
        }
        return '';
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::content()} instead. REMOVED-IN: 2.0.0.
     */
    public function getContent(): string { return $this->content(); }

    /**
     * Create a copy with an updated cid (for inline embedding).
     *
     * @throws \InvalidArgumentException if the CID is not a valid RFC 2392
     *     format — the ctor choke validates every assignment, so the
     *     forged-Content-ID path this method used to leave open is closed.
     */
    public function withCid(string $cid): self
    {
        return new self(
            filename:  $this->filename,
            path:      $this->path,
            content:   $this->content,
            mimeType:  $this->mimeType,
            encoding:  $this->encoding,
            cid:       $cid,
        );
    }

    /**
     * Debug-output shape: var_dump()/print_r()
     * of an Attachment used to spill up to MAX_BYTES raw payload bytes onto
     * the terminal. Identity stays inspectable; the bytes do not.
     *
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        $info             = \get_object_vars($this);
        $info['content']  = $this->content === null
            ? null
            : \sprintf('[%d bytes hidden — read via content()]', \strlen($this->content));
        return $info;
    }

    private function __construct(
        string $filename,
        ?string $path,
        ?string $content,
        string $mimeType,
        string $encoding,
        ?string $cid,
    ) {
        // Filename and mimeType are interpolated into MIME
        // part headers at send time. The package already refuses CR/LF in
        // subjects and addresses (Email::sanitizeHeader); attachments rode the
        // same interpolation channel unguarded, letting an archive/web-upload
        // filename forge arbitrary headers — or whole MIME sub-parts — into
        // outgoing mail. Fail loud at construction, not silent-strip at send.
        self::refuseHeaderInjection($filename, 'attachment filename');
        self::refuseHeaderInjection($mimeType, 'attachment MIME type');
        // The CR/LF gate stops line breaks, but a
        // parameter suffix smuggles onto the SAME wire line without one.
        self::refuseMalformedMimeType($mimeType);

        // The cid is interpolated into
        // `Content-ID: <cid>` verbatim, so it needs the SAME construction-time
        // gate — but a stronger one than CR/LF: an unquoted `>` or space lets
        // a crafted value close the angle bracket early and append headers on
        // the SAME wire line without any CRLF. The RFC 2392 grammar inline()
        // has always enforced is the allowlist; applying it in the ctor means
        // withCid() (the open door the security probe walked through) can no
        // longer mint an unguarded value.
        self::refuseMalformedCid($cid);

        // Content arriving from memory (fromContent/with* re-constructs)
        // never passes readFileOrThrow's gate, so the cap is enforced here
        // too — every constructed attachment obeys MAX_BYTES.
        if ($content !== null && \strlen($content) > self::MAX_BYTES) {
            // The Transport taxonomy (CM-45): a payload that is simply too
            // big is a caller-supplied input defect, not transport I/O —
            // InvalidArgumentException, like every other ctor refusal.
            throw new \InvalidArgumentException(Lang::t('attachment.too_large', ['limit' => (string) self::MAX_BYTES]));
        }

        $this->filename  = $filename;
        $this->path      = $path;
        $this->content   = $content;
        $this->mimeType  = $mimeType;
        $this->encoding  = $encoding;
        $this->cid       = $cid;
    }

    /**
     * Refuse CR/LF in a value that will be interpolated into a MIME header.
     *
     * @throws \InvalidArgumentException on any CR or LF byte
     */
    private static function refuseHeaderInjection(string $value, string $field): void
    {
        if (\preg_match('/[\r\n]/', $value)) {
            throw new \InvalidArgumentException(Lang::t('email.crlf_in_header', ['field' => $field]));
        }
    }

    /**
     * Refuse any MIME type outside the bare type/subtype token grammar.
     *
     * No internal producer (guesses, defaults, examples) ever emits anything
     * but `token "/" token` (RFC 9110 §8.8.1 token set), so parameters,
     * whitespace, angle brackets and control bytes alike are refused at
     * construction instead of riding the part Content-Type line verbatim.
     */
    private static function refuseMalformedMimeType(string $mimeType): void
    {
        $token = "[!#$%&'*+.^_`|~0-9A-Za-z-]+";

        if (\preg_match("@^{$token}/{$token}$@", $mimeType) !== 1) {
            throw new \InvalidArgumentException(Lang::t('email.invalid_mime_type', ['mime' => $mimeType]));
        }
    }

    /**
     * Refuse a Content-ID outside the RFC 2392 addr-spec / token grammar.
     *
     * The grammar (the one inline() has always documented) admits only the
     * characters a msg-id may carry: no CR/LF (header injection), no `>`
     * (early close of the Content-ID angle bracket), no space (parameter
     * smuggling on the same line).
     *
     * RFC 2392 §3 / RFC 2822 msg-id: `dot-atom-text` or `cid:` + that.
     *
     * @throws \InvalidArgumentException when $cid is non-null and outside the grammar
     */
    private static function refuseMalformedCid(?string $cid): void
    {
        if ($cid === null) {
            return;
        }

        // Valid formats: "cid:user@host", "user@host", or simple identifiers like "cid-1", "logo"
        // Simple identifiers (no @) are widely supported by email clients despite being non-standard
        if (!\preg_match('/^(cid:[a-zA-Z0-9\.\+\-]+@[a-zA-Z0-9\.\-]+|[a-zA-Z0-9\.\+\-]+@[a-zA-Z0-9\.\-]+|[a-zA-Z0-9\.\+\-]+)$/', $cid)) {
            throw new \InvalidArgumentException(Lang::t('attachment.invalid_cid', ['cid' => $cid]));
        }
    }

    /**
     * Read a file eagerly, throwing on failure.
     *
     * A swallowed read failure would let a missing or unreadable path become
     * a silent 0-byte attachment that only surfaces (or ships empty) later at
     * send time; failing here keeps the error at the point the caller can
     * still handle it. The warning is suppressed so the thrown exception is
     * the single, clean failure signal.
     *
     * @throws \RuntimeException if the file cannot be read
     */
    private static function readFileOrThrow(string $path): string
    {
        // is_file() is true only for REGULAR files, which rejects in one
        // gate the three probes that hurt: /dev/zero and friends (character
        // devices whose read never yields a short count — the old code
        // exhausted memory past the exception path), FIFOs (a permanent hang
        // with no writer), and directories (a bare warning then false).
        // The pre-read size check caps amplification before allocation.
        if (!\file_exists($path)) {
            throw new \RuntimeException(Lang::t('attachment.unreadable', ['path' => $path]));
        }
        if (!\is_file($path)) {
            throw new \RuntimeException(Lang::t('attachment.not_a_file', ['path' => $path]));
        }
        $size = \filesize($path);
        if ($size === false) {
            throw new \RuntimeException(Lang::t('attachment.unreadable', ['path' => $path]));
        }
        if ($size > self::MAX_BYTES) {
            // Same CM-45 taxonomy as the ctor arm: the caller supplied an
            // oversized input; the read was never attempted.
            throw new \InvalidArgumentException(Lang::t('attachment.too_large', ['limit' => (string) self::MAX_BYTES]));
        }

        $prev = \error_reporting(E_ALL & ~\E_WARNING);
        $content = @\file_get_contents($path);
        \error_reporting($prev);
        if ($content === false) {
            throw new \RuntimeException(Lang::t('attachment.unreadable', ['path' => $path]));
        }
        return $content;
    }

    private static function detectMimeType(string $path): string
    {
        if (\function_exists('mime_content_type')) {
            // Suppress warning when file doesn't exist - we'll fall back to extension-based detection
            $prev = \error_reporting(E_ALL & ~\E_WARNING);
            $m = @\mime_content_type($path);
            \error_reporting($prev);
            if ($m !== false && $m !== 'application/octet-stream') {
                return $m;
            }
        }
        $ext = \strtolower(\pathinfo($path, \PATHINFO_EXTENSION));
        return self::EXT_TO_MIME[$ext] ?? 'application/octet-stream';
    }

    private const EXT_TO_MIME = [
        'txt'  => 'text/plain',
        'html' => 'text/html',
        'htm'  => 'text/html',
        'css'  => 'text/css',
        'csv'  => 'text/csv',
        'md'   => 'text/markdown',
        'json' => 'application/json',
        'xml'  => 'application/xml',
        'pdf'  => 'application/pdf',
        'zip'  => 'application/zip',
        'tar'  => 'application/x-tar',
        'gz'   => 'application/gzip',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
}

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

        // RFC 2392 Content-ID format: accepts msg-id (addr-spec) or cid:uri
        // Valid formats: "cid:user@host", "user@host", or simple identifiers like "cid-1", "logo"
        // Simple identifiers (no @) are widely supported by email clients despite being non-standard
        if (!\preg_match('/^(cid:[a-zA-Z0-9\.\+\-]+@[a-zA-Z0-9\.\-]+|[a-zA-Z0-9\.\+\-]+@[a-zA-Z0-9\.\-]+|[a-zA-Z0-9\.\+\-]+)$/', $cid)) {
            throw new \InvalidArgumentException(Lang::t('attachment.invalid_cid', ['cid' => $cid]));
        }

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
     * Every public factory reads eagerly, so `$content` is normally already
     * set; the path re-read below is defense-in-depth for a future factory
     * that defers the read, not a behavior reachable through today's API.
     *
     * @throws \RuntimeException if the path is set but the file cannot be read
     */
    public function getContent(): string
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
     * Create a copy with an updated cid (for inline embedding).
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

    private function __construct(
        string $filename,
        ?string $path,
        ?string $content,
        string $mimeType,
        string $encoding,
        ?string $cid,
    ) {
        // docs-fix-1023 C-2: filename and mimeType are interpolated into MIME
        // part headers at send time. The package already refuses CR/LF in
        // subjects and addresses (Email::sanitizeHeader); attachments rode the
        // same interpolation channel unguarded, letting an archive/web-upload
        // filename forge arbitrary headers — or whole MIME sub-parts — into
        // outgoing mail. Fail loud at construction, not silent-strip at send.
        self::refuseHeaderInjection($filename, 'attachment filename');
        self::refuseHeaderInjection($mimeType, 'attachment MIME type');

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

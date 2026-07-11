<?php

declare(strict_types=1);

namespace SugarCraft\Post;

/**
 * Represents a file attachment on an email.
 *
 * @property string      $filename   Display name of the attachment.
 * @property string      $path       Path to the file (if from disk).
 * @property string|null $content    Raw content bytes (if from memory).
 * @property string      $mimeType   Detected or specified MIME type.
 * @property string      $encoding   Content-transfer encoding (base64 default).
 * @property string|null $cid        Content-ID for inline (embed) attachments.
 */
final class Attachment
{
    public readonly string $filename;
    public readonly ?string $path;
    public readonly ?string $content;
    public readonly string $mimeType;
    public readonly string $encoding;
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
    public static function fromPath(string $path, string $filename = null): self
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
    public static function inline(string $path, string $cid, string $filename = null): self
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
     * Get the raw content bytes (reads from path if needed).
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
        $this->filename  = $filename;
        $this->path      = $path;
        $this->content   = $content;
        $this->mimeType  = $mimeType;
        $this->encoding  = $encoding;
        $this->cid       = $cid;
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

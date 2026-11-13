<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Support;

/**
 * Magic-number sniffing for inbound attachment bytes. Mirrors the host's
 * image/document door (candy-code FileMentions::sniffImage/sniffDocument):
 * the serve re-sniffs every image and document and refuses the whole turn
 * when a declared mime differs from the sniffed one, so the bundle declares
 * the type the bytes actually carry.
 */
final class MediaSniff
{
    /** @return 'image/png'|'image/jpeg'|'image/gif'|'image/webp'|'application/pdf'|null */
    public static function sniff(string $bytes): ?string
    {
        return self::image($bytes) ?? self::document($bytes);
    }

    /** @return 'image/png'|'image/jpeg'|'image/gif'|'image/webp'|null */
    public static function image(string $bytes): ?string
    {
        return match (true) {
            \str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            \str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            \str_starts_with($bytes, 'GIF87a'), \str_starts_with($bytes, 'GIF89a') => 'image/gif',
            \strlen($bytes) >= 12 && \str_starts_with($bytes, 'RIFF') && \substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /** @return 'application/pdf'|null */
    public static function document(string $bytes): ?string
    {
        return \str_starts_with($bytes, '%PDF-') ? 'application/pdf' : null;
    }
}

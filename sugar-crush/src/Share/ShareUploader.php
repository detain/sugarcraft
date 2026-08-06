<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Share;

use DateTimeImmutable;

/**
 * Handles uploading shared session content and generating signed URLs.
 *
 * This is a thin hosting-and-auth layer over data the system already has.
 * For now, generates a signed URL from a secret + expiry without a real
 * upload backend.
 *
 * @mirrors charmbracelet/<repo>.ShareUploader
 */
final class ShareUploader
{
    private const SIGNING_SECRET = 'sugar-crush-share-secret-2026';

    /**
     * Upload content and return a signed URL with expiry.
     *
     * @param string $content The serialized session content
     * @param string $format The export format (markdown, json, text)
     * @param int $expirySeconds Seconds until URL expires
     * @param string $uploadBaseUrl Base URL for the share endpoint
     * @return array{url:string,expiresAt:DateTimeImmutable}
     */
    public static function upload(
        string $content,
        string $format,
        int $expirySeconds,
        string $uploadBaseUrl = 'https://share.sugarcraft.dev',
    ): array {
        $expiresAt = new DateTimeImmutable("+{$expirySeconds} seconds");

        // Generate a short ID for this share
        $shareId = self::generateShareId($content, $expiresAt);

        // Build the signed URL
        $url = self::buildSignedUrl($uploadBaseUrl, $shareId, $expiresAt, $format);

        return [
            'url' => $url,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Generate a compact share ID from content + expiry.
     *
     * Uses a deterministic ID based on content hash and expiry time,
     * so the same session with same expiry produces the same ID (useful for caching).
     */
    private static function generateShareId(string $content, DateTimeImmutable $expiresAt): string
    {
        $payload = $content . '|' . $expiresAt->getTimestamp();
        $hash = substr(hash('sha256', $payload), 0, 16);

        // Base64url encode for URL safety
        return rtrim(strtr(base64_encode(pack('H*', $hash)), '+/', '-_'), '=');
    }

    /**
     * Build a signed URL with expiry embedded in it.
     */
    private static function buildSignedUrl(
        string $baseUrl,
        string $shareId,
        DateTimeImmutable $expiresAt,
        string $format,
    ): string {
        $expiryTs = $expiresAt->getTimestamp();
        $data = "{$shareId}|{$expiryTs}|{$format}";

        // Create a signature using HMAC
        $signature = hash_hmac('sha256', $data, self::SIGNING_SECRET);
        $sigShort = substr($signature, 0, 8);

        // Build URL with all components
        $encodedData = rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

        return "{$baseUrl}/s/{$encodedData}.{$sigShort}";
    }
}

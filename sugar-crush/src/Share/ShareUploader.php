<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Share;

use RuntimeException;

/**
 * Handles uploading shared session content to a real storage backend.
 *
 * No such backend is wired up yet. Previously this fabricated a
 * signed-looking URL + hash without ever uploading anything, which
 * lied to the user about what /share had actually done. Until a real
 * backend is configured, this reports the honest failure instead.
 *
 * DORMANT, OPT-IN (roadmap X-35a). `/share` writes a local file by default;
 * it reaches this class only when `SUGARCRUSH_SHARE_UPLOAD_URL` (or the
 * deprecated `SUGAR_CRUSH_SHARE_UPLOAD_URL`) names a host, and then still
 * writes the local file and says the upload did not happen. Kept, not
 * deleted, so a real backend has one seam to land in.
 *
 * @mirrors charmbracelet/<repo>.ShareUploader
 */
final class ShareUploader
{
    /**
     * Attempt to upload content and obtain a shareable URL.
     *
     * @param string $content The serialized session content
     * @param string $format The export format (markdown, json, text)
     * @param int $expirySeconds Seconds until URL expires
     * @param string $uploadBaseUrl Base URL for the share endpoint
     *
     * @throws RuntimeException Always — no upload backend is configured yet,
     *     so no data is uploaded and no URL is ever fabricated.
     */
    public static function upload(
        string $content,
        string $format,
        int $expirySeconds,
        string $uploadBaseUrl = 'https://share.sugarcraft.dev',
    ): never {
        throw new RuntimeException('Share upload is not yet implemented. No data was uploaded.');
    }
}

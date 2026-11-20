<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Removal of the temp file a Windows child reads as its stdin (see
 * {@see \SugarCraft\Core\Util\Proc\WindowsPipes::stageStdin()}). On Windows a file a live child holds
 * open cannot be unlinked, so a removal attempted before the child is reaped can
 * fail with the payload still on disk. The retry decision lives here, apart from
 * the platform, so every platform can test it.
 */
final class StagedStdin
{
    /**
     * Unlinks $path. A failure while the child is still alive is retry-worthy:
     * the caller keeps the path and removes it again on the reap path. A failure
     * after the reap is final, because the child no longer holds the file.
     *
     * @param null|callable(string): bool $unlink  Removal seam; the default is a silent unlink()
     * @return bool true when the file is still owed a removal once the child is reaped
     */
    public static function removeOrRetry(string $path, bool $childReaped, ?callable $unlink = null): bool
    {
        $removed = $unlink === null ? @\unlink($path) : $unlink($path);
        if ($removed) {
            return false;
        }

        return !$childReaped;
    }
}

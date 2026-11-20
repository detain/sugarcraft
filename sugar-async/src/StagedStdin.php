<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;

/**
 * Removal of the temp file a Windows child reads as its stdin (see
 * {@see \SugarCraft\Core\Util\Proc\WindowsPipes::stageStdin()}). On Windows a file a live child holds
 * open cannot be unlinked, so a removal attempted before the child is reaped can
 * fail with the payload still on disk. The retry decision lives here, apart from
 * the platform, so every platform can test it.
 */
final class StagedStdin
{
    /** Removals tried on the exited path, the first included: a handle the OS releases a moment after the exit. */
    public const EXITED_UNLINK_ATTEMPTS = 3;

    /** Seconds between exited-path removal attempts. */
    public const EXITED_UNLINK_RETRY_SECONDS = 0.05;

    /**
     * Removes the payload once the child has been reaped, so the child no longer
     * holds it. A refused removal is retried on the loop a bounded number of times,
     * the first attempt included, and then left behind: the retries never block.
     *
     * @param null|callable(string): bool $unlink  Removal seam; the default is a silent unlink()
     */
    public static function removeAfterExit(
        LoopInterface $loop,
        string $path,
        ?callable $unlink = null,
        int $attempts = self::EXITED_UNLINK_ATTEMPTS,
        float $retrySeconds = self::EXITED_UNLINK_RETRY_SECONDS,
    ): void {
        if (self::unlinkOnce($path, $unlink)) {
            return;
        }

        self::retryAfterExit($loop, $path, $unlink, $attempts - 1, $retrySeconds);
    }

    /** @param null|callable(string): bool $unlink */
    private static function retryAfterExit(
        LoopInterface $loop,
        string $path,
        ?callable $unlink,
        int $retriesLeft,
        float $retrySeconds,
    ): void {
        if ($retriesLeft <= 0) {
            return;
        }

        $loop->addTimer($retrySeconds, static function () use ($loop, $path, $unlink, $retriesLeft, $retrySeconds): void {
            if (self::unlinkOnce($path, $unlink)) {
                return;
            }

            self::retryAfterExit($loop, $path, $unlink, $retriesLeft - 1, $retrySeconds);
        });
    }

    /** @param null|callable(string): bool $unlink */
    private static function unlinkOnce(string $path, ?callable $unlink): bool
    {
        return $unlink === null ? (bool) @\unlink($path) : (bool) $unlink($path);
    }

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

    /**
     * The callback a reap runs to remove a payload the live child refused. Null when
     * nothing is owed: no path, or a removal that was already final.
     *
     * @param null|callable(string): bool $unlink  Removal seam, passed through to {@see removeOrRetry()}
     * @return null|\Closure(?int): void
     */
    public static function reapHook(?string $path, bool $retry, ?callable $unlink = null): ?\Closure
    {
        if ($path === null || !$retry) {
            return null;
        }

        return static function (?int $status) use ($path, $unlink): void {
            self::removeOrRetry($path, true, $unlink);
        };
    }
}

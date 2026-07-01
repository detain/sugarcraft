<?php

declare(strict_types=1);

namespace SugarCraft\Palette;

use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Asynchronous terminal color profile probe using ReactPHP ChildProcess.
 *
 * Runs `infocmp` in a subprocess to detect TrueColor (Tc/RGB) support,
 * avoiding blocking the event loop during terminal capability detection.
 *
 * Falls back to the synchronous {@see Probe::colorProfile()} if:
 * - The event loop is not available
 * - The ChildProcess fails to spawn
 * - The promise is cancelled before resolution
 *
 * @see Probe::colorProfile() for the synchronous baseline detection
 */
final class AsyncProbe
{
    private static ?string $infocmpPath = null;

    /**
     * Detect the terminal color profile asynchronously.
     *
     * @return PromiseInterface<ColorProfile> Resolves with the detected color profile
     */
    public static function colorProfile(): PromiseInterface
    {
        $loop = self::getLoop();
        if ($loop === null) {
            // No event loop available — fall back to sync detection
            return \React\Promise\resolve(Probe::colorProfile());
        }

        $deferred = new Deferred();

        $infocmpPath = self::findInfocmpPath();
        if ($infocmpPath === '') {
            $deferred->resolve(Probe::colorProfile());

            return $deferred->promise();
        }

        $term = self::getTerm();
        $command = $infocmpPath . ' -1 ' . escapeshellarg($term ?? 'xterm');

        $process = new Process($command);
        $process->start($loop);

        $stdout = '';

        $process->on('stdout', function ($chunk) use (&$stdout): void {
            $stdout .= $chunk;
        });

        $process->on('exit', function (int $exitCode) use ($deferred, $stdout): void {
            if ($exitCode === 0 && (preg_match('/\bTc\b/', $stdout) || preg_match('/\bRGB\b/', $stdout))) {
                $deferred->resolve(ColorProfile::TrueColor);
            } else {
                // Fall back to sync detection (includes env-based detection + existing infocmp logic)
                $deferred->resolve(Probe::colorProfile());
            }
        });

        // Ensure we don't leave dangling processes
        $process->on('error', function () use ($deferred): void {
            $deferred->resolve(Probe::colorProfile());
        });

        return $deferred->promise();
    }

    /**
     * Get the current event loop or null if not available.
     */
    private static function getLoop(): ?LoopInterface
    {
        if (!class_exists(Loop::class)) {
            return null;
        }

        try {
            return Loop::get();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get the TERM environment variable.
     */
    private static function getTerm(): ?string
    {
        $term = $_ENV['TERM'] ?? (getenv('TERM') ?: null);
        return $term === false ? null : $term;
    }

    /**
     * Find the infocmp binary path, with caching.
     */
    private static function findInfocmpPath(): string
    {
        if (self::$infocmpPath !== null) {
            return self::$infocmpPath;
        }

        self::$infocmpPath = is_file('/usr/bin/infocmp') ? '/usr/bin/infocmp'
            : (is_file('/bin/infocmp') ? '/bin/infocmp' : '');

        return self::$infocmpPath;
    }
}

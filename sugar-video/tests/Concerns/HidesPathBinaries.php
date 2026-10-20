<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests\Concerns;

use SugarCraft\Video\Source\Probe;

/**
 * Simulates a host with no toolchain binaries (ffmpeg/ffprobe/ffplay/mpv)
 * by pointing PATH at an empty directory for the duration of a callback.
 *
 * Probe::which() shells out to `command -v <cmd>`, which resolves against
 * the PATH inherited from this process — so an empty PATH makes every
 * binary lookup fail, exercising the binary-absent fallback paths even on
 * hosts where ffmpeg is installed.
 */
trait HidesPathBinaries
{
    /**
     * Run $fn with PATH pointing at an empty directory; always restore PATH.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withoutPathBinaries(callable $fn): mixed
    {
        $emptyDir = sys_get_temp_dir() . '/reel-empty-path-' . bin2hex(random_bytes(8));
        if (!mkdir($emptyDir, 0700) && !is_dir($emptyDir)) {
            $this->fail("could not create empty PATH dir {$emptyDir}");
        }

        $originalPath = getenv('PATH');
        putenv('PATH=' . $emptyDir);
        // F-17 memo must not answer across a PATH flip (see StubbedPathBinaries).
        Probe::clearBinaryCacheForTesting();

        try {
            return $fn();
        } finally {
            putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);
            Probe::clearBinaryCacheForTesting();
            @rmdir($emptyDir);
        }
    }
}

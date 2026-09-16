<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests\Concerns;

/**
 * Makes named binaries (ffplay/mpv/…) resolvable on a host that may not have
 * them, by pointing PATH at a private directory of inert executable stubs for
 * the duration of a callback.
 *
 * Counterpart to {@see HidesPathBinaries}: that trait forces the binary-ABSENT
 * path, this one forces the binary-PRESENT path deterministically. Nothing is
 * ever executed — `Probe::which()` shells out to `command -v <cmd>`, a pure PATH
 * search — so the suite keeps its "no real ffmpeg/ffplay/mpv required" promise
 * while pinning the CALL SITES that resolve binaries by name.
 *
 * The stub contents (`#!/bin/sh; exit 0`) only need to exist and be executable;
 * a test that accidentally spawned one would get an instant no-op, never audio.
 */
trait StubbedPathBinaries
{
    /**
     * Run $fn with PATH set to a directory containing executable stubs named
     * `$names`; the callback receives the stub directory path (each binary
     * resolves to "$dir/$name"). Always restores PATH and removes the stubs.
     *
     * @param list<string> $names
     */
    private function withStubbedBinaries(array $names, callable $fn): void
    {
        $dir = sys_get_temp_dir() . '/reel-stub-bin-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700) && !is_dir($dir)) {
            $this->fail("could not create stub PATH dir {$dir}");
        }

        try {
            foreach ($names as $name) {
                $path = $dir . '/' . $name;
                if (file_put_contents($path, "#!/bin/sh\nexit 0\n") === false) {
                    $this->fail("could not write stub binary {$path}");
                }
                chmod($path, 0755);
            }

            $originalPath = getenv('PATH');
            putenv('PATH=' . $dir);

            try {
                $fn($dir);
            } finally {
                putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);
            }
        } finally {
            foreach ($names as $name) {
                @unlink($dir . '/' . $name);
            }
            @rmdir($dir);
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

/**
 * DST-1: one helper that mints a REAL minimal phar stamped with build
 * metadata, shared by the `Version` reader pin and the non-injected
 * `self-update` pins.
 *
 * The archive is minted by a CHILD process run with
 * `-d phar.readonly=0` — the same door `PharCompatTest::buildScratchPhar()`
 * walks: the suite process never mutates its own ini, the child builds
 * where the parent could not, and no `phar.readonly` runner can tell the
 * difference, so these pins need NO body-level skip and NO roster entry.
 *
 * Reading the minted archive in-process is always legal: `phar.readonly`
 * forbids WRITING to an archive, never opening one, so
 * `Version::fromPharMetadata()` reads back exactly what the stamp wrote.
 *
 * WHY A TRAIT (the E174 discipline): two suites need a stamped fixture and
 * a byte-copied second helper is the duplication guards' favorite snack.
 */
trait MintsStampedPharTrait
{
    /**
     * Write $stamp into metadata['version'] of a fresh single-file archive
     * under the system temp dir and return its absolute path. The child
     * takes both values through ENVIRONMENT, never argv — no quoting layer
     * sits between the stamp and the phar metadata it must land in.
     */
    private static function mintStampedPhar(string $stamp): string
    {
        $path = \sys_get_temp_dir() . '/dst1-mint-' . \getmypid() . '-' . \bin2hex(\random_bytes(4)) . '.phar';

        $script = '$p = getenv("DST1_PHAR_PATH"); $s = getenv("DST1_PHAR_STAMP");'
            . 'if (!is_string($p) || $p === "" || !is_string($s)) { exit(2); }'
            . '$phar = new Phar($p, 0, "dst1-mint-" . getmypid() . "-" . bin2hex(random_bytes(4)));'
            . '$phar->setStub("<?php __HALT_COMPILER();");'
            . '$phar->addFromString("src/stub.txt", "DST-1 stamp fixture");'
            . '$phar->setMetadata(["version" => $s, "built_at" => "1970-01-01T00:00:00+00:00", "commit" => "0000000"]);'
            . '$phar->stopBuffering();';

        $env = ['DST1_PHAR_PATH' => $path, 'DST1_PHAR_STAMP' => $stamp];
        foreach (\getenv() as $key => $value) {
            if (!isset($env[$key])) {
                $env[$key] = $value;
            }
        }

        $pipes = [];
        $process = \proc_open(
            [\PHP_BINARY, '-d', 'phar.readonly=0', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($process);
        // Drain both pipes to EOF BEFORE proc_close (pipe-EOF law: a
        // single-shot status read after a half-drained child is a flake).
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $rc = \proc_close($process);

        self::assertSame(0, $rc, 'child phar mint failed rc=' . $rc . ' out=' . $out . ' err=' . $err);
        self::assertTrue(\is_file($path), 'the minted archive is not on disk at ' . $path);

        return $path;
    }

    /**
     * Drop a fixture minted by {@see self::mintStampedPhar()}. The Phar
     * object is long gone by the time cleanup runs; @ keeps a Windows
     * handle-lag from failing the test the cleanup follows.
     */
    private static function discardStampedPhar(string $path): void
    {
        @\unlink($path);
    }
}

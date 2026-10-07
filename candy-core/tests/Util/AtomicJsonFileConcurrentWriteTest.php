<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\AtomicJsonFile;

/**
 * crush_libs.md candy-core C2: the old write() took flock() on its own
 * per-write UNIQUE temp inode — a lock nobody else ever requested, so two
 * processes happily interleaved and the later rename silently clobbered the
 * earlier one's publish. The fix serialises writers on a STABLE sidecar
 * (`.lock` beside the target); these pins go red the moment that sidecar
 * lock disappears.
 *
 * Children are bounded `php` processes reaped in finally with a poll-until-
 * exited loop (never a one-shot proc_get_status after pipe EOF — the kernel
 * closes stdio before the task is waitable, so a single-shot read races).
 */
final class AtomicJsonFileConcurrentWriteTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . \DIRECTORY_SEPARATOR
            . 'candy-core-atomiclock-' . bin2hex(random_bytes(8));
        if (mkdir($this->tmpDir, 0700, true) === false && is_dir($this->tmpDir) === false) {
            $this->fail("Could not create temp dir: {$this->tmpDir}");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($this->tmpDir);
    }

    /** Resolve the private sidecar name through the class itself, never a guess. */
    private function lockPathFor(AtomicJsonFile $store): string
    {
        $method = new \ReflectionMethod($store, 'lockPath');

        return $method->invoke($store);
    }

    private function childScriptPath(): string
    {
        return $this->tmpDir . '/writer-child.php';
    }

    private function writeChildScript(): void
    {
        // Flexible heredoc: the closing label's indent strips itself from every line.
        $code = <<<'PHP'
        <?php
        declare(strict_types=1);
        require $argv[1];
        $store = SugarCraft\Core\Util\AtomicJsonFile::new($argv[2]);
        $id = $argv[3];
        $loops = (int) $argv[4];
        if ($loops === 0) {
            $store->write(['child' => $id]);
            fwrite(STDOUT, "wrote\n");
            exit(0);
        }
        for ($n = 1; $n <= $loops; $n++) {
            $store->write(['writer' => $id, 'seq' => $n, 'fill' => str_repeat('x', 2000)]);
            fwrite(STDOUT, "done {$id} {$n}\n");
        }
        exit(0);
        PHP;
        // Flexible heredoc already strips the shared 8-space indent, so the
        // child source lands on disk ready to parse.
        file_put_contents($this->childScriptPath(), $code . "\n");
    }

    /**
     * @param array<int, resource> $pipes
     *
     * @return array{0: int, 1: string} exit code + drained stdout
     */
    private function drainAndFinish($proc, array $pipes, float $budgetSeconds): array
    {
        $stdout = '';
        $deadline = microtime(true) + $budgetSeconds;
        while (microtime(true) < $deadline) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') {
                $stdout .= $chunk;
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            usleep(20000);
        }
        if ((proc_get_status($proc)['running'] ?? false) === true) {
            $this->terminate($proc);
            $this->fail('child writer exceeded its bounded runtime and was SIGKILLed');
        }
        // Bounded final drain: the child has exited, so the pipe only holds
        // whatever already flushed into it.
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $stdout .= $chunk;
        }
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, "child exited {$code}; stderr: {$stderr}");

        return [$code, $stdout];
    }

    /** @return array{0: resource, 1: array<int, resource>} */
    private function launchChild(int $loops, string $id, string $target): array
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $this->assertFileExists($autoload);
        $proc = proc_open(
            [\PHP_BINARY, $this->childScriptPath(), $autoload, $target, $id, (string) $loops],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        if ($proc === false) {
            $this->markTestSkipped('proc_open unavailable in this environment');
        }
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$proc, $pipes];
    }

    /**
     * True while $proc still names a live process resource — proc_close()
     * leaves the handle unusable, and proc_get_status() on it throws a
     * TypeError that `?? false` cannot swallow.
     */
    private function isLive($proc): bool
    {
        return \get_resource_type($proc) === 'process';
    }

    /** @param resource $proc */
    private function terminate($proc): void
    {
        // 9 = SIGKILL spelled as an int so the test needs no ext-pcntl.
        proc_terminate($proc, 9);
    }

    public function testWriterBlocksUntilTheSidecarLockIsReleased(): void
    {
        $this->writeChildScript();
        $target = $this->tmpDir . '/state.json';
        $store = AtomicJsonFile::new($target);
        $store->write(['gen' => 0]);

        // A plain read() never takes the lock (rename publishes whole files);
        // only writers must serialize on it.
        $lockHandle = fopen($this->lockPathFor($store), 'cb');
        $this->assertNotFalse($lockHandle);
        $this->assertTrue(flock($lockHandle, \LOCK_EX));

        try {
            [$proc, $pipes] = $this->launchChild(0, 'A', $target);
            try {
                // The child's ONLY plausible progress stop here is the sidecar
                // flock: it has no other blocking input. If the write() ever
                // drops the lock, this assert is the red pin.
                usleep(400000);
                $this->assertTrue(
                    proc_get_status($proc)['running'],
                    'a concurrent writer slipped past the sidecar lock — flock serialization is gone',
                );

                flock($lockHandle, \LOCK_UN);
                [$code, $stdout] = $this->drainAndFinish($proc, $pipes, 15.0);
                $this->assertSame(0, $code);
                $this->assertStringContainsString('wrote', $stdout);
                $this->assertSame(['child' => 'A'], $store->read());
            } finally {
                if ($this->isLive($proc) && (proc_get_status($proc)['running'] ?? false) === true) {
                    $this->terminate($proc);
                }
            }
        } finally {
            fclose($lockHandle);
        }
    }

    public function testTwoConcurrentWritersSerializeWithoutTearingTheFile(): void
    {
        $this->writeChildScript();
        $target = $this->tmpDir . '/state.json';
        AtomicJsonFile::new($target)->write(['seed' => true]);

        $handles = [];
        try {
            [$procA, $pipesA] = $this->launchChild(15, 'A', $target);
            $handles[] = [$procA, $pipesA];
            [$procB, $pipesB] = $this->launchChild(15, 'B', $target);
            $handles[] = [$procB, $pipesB];

            // Interleave the drains so a stalled pipe never deadlocks the pair.
            [$outA, $outB] = ['', ''];
            $deadline = microtime(true) + 30.0;
            $doneA = false;
            $doneB = false;
            while (microtime(true) < $deadline && (!($doneA && $doneB))) {
                $outA .= (string) fread($pipesA[1], 65536);
                $outB .= (string) fread($pipesB[1], 65536);
                $doneA = !proc_get_status($procA)['running'];
                $doneB = !proc_get_status($procB)['running'];
                if ($doneA && $doneB) {
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($doneA && $doneB, 'a hammer writer process outlived its budget');

            foreach ($handles as $n => [$proc, $pipes]) {
                $out = $n === 0 ? $outA : $outB;
                $out .= (string) stream_get_contents($pipes[1]);
                $stderr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                $this->assertSame(0, $code, "hammer child {$n} failed: {$stderr}");
                // All 15 iterations of THIS writer completed — the lock can
                // delay but never strand a writer.
                $this->assertSame(15, substr_count($out, 'done'), "writer {$n} lost loop iterations");
            }

            // Never torn, never interleaved: the final publish parses as one
            // whole payload from exactly one writer.
            $final = AtomicJsonFile::new($target)->read();
            $this->assertSame(['writer', 'seq', 'fill'], array_keys($final));
            $this->assertContains($final['writer'], ['A', 'B']);
            $this->assertIsInt($final['seq']);
            $this->assertSame(2000, \strlen($final['fill']));
        } finally {
            foreach ($handles as [$proc, $pipes]) {
                if ($this->isLive($proc) && (proc_get_status($proc)['running'] ?? false) === true) {
                    $this->terminate($proc);
                }
            }
        }
    }
}

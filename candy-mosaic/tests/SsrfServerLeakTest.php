<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Tests\Support\LoopbackHttpServer;

/**
 * Guard for E365: the SSRF suite must not orphan `php -S` servers.
 *
 * Before the fix, ImageSourceSsrfTest leaked exactly one server per test
 * method — four per suite run, `ppid=1`, accumulating for hours. Each one held
 * the CALLER'S STDOUT at fd 4 (inherited from phpunit, which `proc_open()`
 * never remapped), so `vendor/bin/phpunit | anything` finished green and then
 * hung forever waiting for an EOF that a leaked server was holding open.
 *
 * This guard drives the real spawn/reap path — the same LoopbackHttpServer the
 * SSRF tests use, not a re-implementation — and asserts the process census
 * returns to its starting value. Crucially it also asserts the census SEES the
 * server while it is running: a leak detector that reads zero because it is
 * looking in the wrong place would otherwise pass vacuously forever.
 *
 * @covers \SugarCraft\Mosaic\Tests\Support\LoopbackHttpServer
 */
final class SsrfServerLeakTest extends TestCase
{
    public function testServerLifecycleOrphansNoProcess(): void
    {
        $before = LoopbackHttpServer::livePids();
        if ($before === null) {
            $this->markTestSkipped('Process census needs /proc; unavailable on this platform.');
        }

        // In the default (file-name-ordered) run this class comes after
        // ImageSourceSsrfTest, so a non-empty census here is that file leaking.
        $this->assertSame(
            [],
            $before,
            'A php -S server from earlier in this run is still alive: ' . implode(',', $before),
        );

        $dir    = LoopbackHttpServer::makeTempDir();
        $router = $dir . '/' . LoopbackHttpServer::ROUTER_FILE;

        try {
            file_put_contents($router, "<?php echo 'ok'; return true;\n");

            // Two cycles: one cannot tell "reaped correctly" from "never came up".
            for ($cycle = 1; $cycle <= 2; $cycle++) {
                $server = LoopbackHttpServer::start($router);
                if ($server === null) {
                    $this->markTestSkipped('Could not start a local php -S server.');
                }

                $this->assertSame(
                    [$server->pid()],
                    LoopbackHttpServer::livePids(),
                    "Cycle {$cycle}: the census must see the running server, "
                        . 'otherwise its zero readings prove nothing.',
                );

                $server->stop();

                $this->assertFalse($server->isRunning(), "Cycle {$cycle}: server still running after stop().");
                $this->assertSame(
                    [],
                    LoopbackHttpServer::livePids(),
                    "Cycle {$cycle}: stop() left an orphaned php -S server behind.",
                );
            }
        } finally {
            LoopbackHttpServer::removeTempDir($dir);
        }
    }

    /**
     * The fd-inheritance half, asserted directly: a running server must hold
     * none of phpunit's descriptors. This is what makes a future leak a
     * nuisance rather than a hang, so it is guarded independently of the
     * reaping.
     */
    public function testServerInheritsNoneOfPhpunitsDescriptors(): void
    {
        if (!is_dir('/proc/self/fd')) {
            $this->markTestSkipped('Descriptor inspection needs /proc.');
        }

        $dir    = LoopbackHttpServer::makeTempDir();
        $router = $dir . '/' . LoopbackHttpServer::ROUTER_FILE;

        try {
            file_put_contents($router, "<?php echo 'ok'; return true;\n");

            $server = LoopbackHttpServer::start($router);
            if ($server === null) {
                $this->markTestSkipped('Could not start a local php -S server.');
            }

            $ours = self::descriptorTargets(getmypid());
            $its  = self::descriptorTargets($server->pid());
            $server->stop();

            $this->assertNotSame([], $its, 'Could not read the server descriptor table.');

            // Every descriptor phpunit holds — its script handle, its stdout
            // (a pipe when the caller pipes the run), its sockets — must be
            // absent from the child. The child's own opcache memfd and listen
            // socket are its business and are not in the parent's table.
            $leaked = array_intersect($its, $ours);
            $this->assertSame(
                [],
                array_values(array_unique($leaked)),
                'Server inherited phpunit descriptors: ' . implode(', ', array_unique($leaked)),
            );
        } finally {
            LoopbackHttpServer::removeTempDir($dir);
        }
    }

    /**
     * Readlink target of every descriptor a process holds above fd 2.
     *
     * /dev/null is excluded: that is precisely what the spawner points the
     * child's inherited slots at, and the parent may legitimately hold it too.
     *
     * @return list<string>
     */
    private static function descriptorTargets(int $pid): array
    {
        $targets = [];
        $entries = @scandir('/proc/' . $pid . '/fd');
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (!ctype_digit((string) $entry) || (int) $entry <= 2) {
                continue;
            }

            $target = @readlink('/proc/' . $pid . '/fd/' . $entry);
            if (!is_string($target) || $target === '' || $target === '/dev/null') {
                continue;
            }

            $targets[] = $target;
        }

        return $targets;
    }
}

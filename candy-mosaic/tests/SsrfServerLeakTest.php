<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use FFI;
use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Tests\Support\LoopbackHttpServer;
use Throwable;

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

            $ours = self::descriptorIdentities(getmypid());
            $its  = self::descriptorIdentities($server->pid());
            $server->stop();

            $this->assertNotSame([], $its, 'Could not read the server descriptor table.');

            // Every descriptor phpunit holds — its script handle, its stdout
            // (a pipe when the caller pipes the run), its sockets — must be
            // absent from the child. The identity is the object the descriptor
            // refers to, never the readlink label: the child's freshly opened
            // /memfd:opcache_lock and the parent's readlink identically while
            // shmem gives each one its own inode. Anonymous inodes go further
            // and share even their stat pair on modern kernels, which is why
            // the census keys them by fdinfo instead — see
            // descriptorIdentities().
            $leaked = [];
            foreach ($its as $identity => $label) {
                if (isset($ours[$identity])) {
                    $leaked[$label] = true;
                }
            }

            $this->assertSame(
                [],
                array_keys($leaked),
                'Server inherited phpunit descriptors: ' . implode(', ', array_keys($leaked)),
            );
        } finally {
            LoopbackHttpServer::removeTempDir($dir);
        }
    }

    /**
     * The exact shape of the run-33796495350 CI red, manufactured instead of
     * awaited, plus the opposite direction it must not break. Kernels 6.6+
     * give every anonymous inode ONE shared stat (dev, ino) — measured: on
     * 6.8.0-138 eventfd, eventpoll and inotify all report 15:1063 — so a
     * (dev, ino) census reads the child's own eventfd as the parent's. The
     * fdinfo identity must not, while a fork-inherited eventfd — the same
     * live context on both sides — must still be accused.
     *
     * The child is a direct `php -r` spawn, not the LoopbackHttpServer: the
     * built-in server's request context refuses FFI by design here
     * (ffi.enable=preload gates it; measured locally), while the property
     * under test is the census's identity arithmetic, not the spawner's
     * /dev/null remapping — which the two tests above already guard.
     */
    public function testEventfdIdentitySeparatesOwnFromInherited(): void
    {
        if (!is_dir('/proc/self/fd')) {
            $this->markTestSkipped('Descriptor inspection needs /proc.');
        }

        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('Creating an eventfd from a test needs ext-ffi.');
        }

        try {
            // No library argument: eventfd()/close() resolve against the
            // process's own already-loaded libc — 'c' is not a portable
            // pseudo-handle.
            $libc = FFI::cdef('int eventfd(unsigned int initval, int flags); int close(int fd);');
            $parentId = $libc->eventfd(0, 0);
        } catch (Throwable) {
            $this->markTestSkipped('FFI cannot bind libc on this host.');
        }

        if ($parentId < 0) {
            $this->markTestSkipped('eventfd() is unavailable on this host.');
        }

        $child = null;

        try {
            // -r runs in the master CLI context, where cdef is allowed; the
            // explicit -d outranks whatever preload/0 the host ini carries.
            $child = proc_open(
                [
                    PHP_BINARY,
                    '-d',
                    'ffi.enable=1',
                    '-r',
                    implode('', [
                        '$f = FFI::cdef("int eventfd(unsigned int initval, int flags);"); ',
                        '$f->eventfd(0, 0); ',
                        // Outlives the census deadline below so its descriptor
                        // table cannot vanish mid-poll; the test kills it early.
                        'usleep(8000000);',
                    ]),
                ],
                [0 => ['null'], 1 => ['null'], 2 => ['null']],
                $pipes,
            );
            $this->assertIsResource($child, 'Could not spawn the php child.');
            $childPid = (int) proc_get_status($child)['pid'];

            $inheritedKey = self::anonymousIdentity(getmypid(), $parentId);
            $this->assertNotNull($inheritedKey, 'The census must identify phpunit\'s own eventfd.');

            // The child inherits $parentId's context across fork (eventfd(0, 0)
            // carries no EFD_CLOEXEC) and opens one of its own; wait until the
            // census sees that second, parent-absent eventfd key.
            $own = [];
            $deadline = microtime(true) + 5.0;
            do {
                $ours = self::descriptorIdentities(getmypid());
                $its  = self::descriptorIdentities($childPid);
                $own  = array_filter(
                    $its,
                    // ARRAY_FILTER_USE_BOTH hands the callback (value, key) —
                    // here the readlink label first, the identity key second.
                    static fn (string $label, string $key): bool => $label === 'anon_inode:[eventfd]' && !isset($ours[$key]),
                    ARRAY_FILTER_USE_BOTH,
                );
                if ($own !== []) {
                    break;
                }
                usleep(50_000);
            } while (microtime(true) < $deadline);

            // False-positive direction (the CI red): the child's own eventfd
            // is nobody else's descriptor.
            $this->assertNotSame(
                [],
                $own,
                'The child never came up with an eventfd of its own; the census would prove nothing.',
            );

            // Real-leak direction: the inherited context shares the parent's
            // eventfd-id, so the census must still see it as one object.
            $this->assertArrayHasKey(
                $inheritedKey,
                $its,
                'A fork-inherited eventfd must still read as shared — the census may not blind '
                    . 'itself to real leaks to dodge false ones.',
            );
        } finally {
            if (is_resource($child)) {
                proc_terminate($child);
                proc_close($child);
            }
            $libc->close($parentId);
        }
    }

    /**
     * Identity of every descriptor a process holds above fd 2: an opaque
     * per-object key mapped to the human-readable readlink label.
     *
     * For named files, pipes, sockets and shmem (memfd) that key is the
     * (st_dev, st_ino) pair: stat() on /proc/<pid>/fd/<n> resolves the magic
     * link to the underlying object, and two descriptors agree on the pair
     * exactly when they are the same object — across fork, a genuinely
     * inherited file description. Labels alone lie ("/memfd:opcache_lock
     * (deleted)" repeats verbatim across instances); the pairs do not.
     *
     * Anonymous inodes are the exception the kernel stopped letting stat()
     * answer for: since the anon_inodefs consolidation (6.6+, measured on
     * 6.8.0-138) every anonymous object — eventfd, eventpoll, inotify —
     * shares ONE (dev, ino), so the stat pair both falsely accuses the
     * child's own eventfd of being the parent's (this was the CI red of
     * run 33796495350) and would mask real inheritance. Their discriminator
     * is the object's own fdinfo — see anonymousIdentity().
     *
     * /dev/null is excluded: that is precisely what the spawner points the
     * child's inherited slots at, and the parent may legitimately hold it too.
     *
     * @return array<string, string> object key => readlink label
     */
    private static function descriptorIdentities(int $pid): array
    {
        $identities = [];
        $entries = @scandir('/proc/' . $pid . '/fd');
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (!ctype_digit((string) $entry) || (int) $entry <= 2) {
                continue;
            }

            $path   = '/proc/' . $pid . '/fd/' . $entry;
            $target = @readlink($path);
            if (!is_string($target) || $target === '' || $target === '/dev/null') {
                continue;
            }

            if (str_starts_with($target, 'anon_inode:')) {
                $anonymous = self::anonymousIdentity($pid, (int) $entry);
                if ($anonymous !== null) {
                    $identities[$anonymous] = $target;
                }

                continue;
            }

            // A descriptor closed between the readdir and the stat fails here;
            // unreadable is not inherited, and the census cannot assert about
            // an object that no longer exists.
            $stat = @stat($path);
            if ($stat === false) {
                continue;
            }

            $identities[$stat['dev'] . ':' . $stat['ino']] = $target;
        }

        return $identities;
    }

    /**
     * fdinfo-derived identity of one anonymous descriptor, or null when the
     * object publishes no per-instance id — an anonymous type that cannot be
     * told apart from another live instance of its kind is not evidence of
     * inheritance, and the census does not accuse what it cannot prove.
     *
     * eventfd is the type this matrix actually meets, and its fdinfo carries
     * `eventfd-id`: unique among live contexts, and equal across fork and dup
     * because it names the context itself — precisely the inheritance signal.
     */
    private static function anonymousIdentity(int $pid, int $fd): ?string
    {
        $fdinfo = @file_get_contents('/proc/' . $pid . '/fdinfo/' . $fd);
        if (!is_string($fdinfo)) {
            return null;
        }

        if (preg_match('/^eventfd-id:\s+(\d+)$/m', $fdinfo, $id) === 1) {
            return 'eventfd:' . $id[1];
        }

        return null;
    }
}

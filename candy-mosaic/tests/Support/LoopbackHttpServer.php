<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests\Support;

/**
 * An ephemeral `php -S` server on 127.0.0.1, spawned and reaped deterministically.
 *
 * Extracted from ImageSourceSsrfTest, which leaked exactly one server per test
 * method — four per suite run — for two independent reasons, both measured on
 * this box rather than inferred (E365):
 *
 * 1. **The shell intermediary.** `proc_open()` was handed a command STRING, so
 *    PHP routed it through `/bin/sh -c`. `/bin/sh` here is dash, and dash did
 *    NOT exec the php binary: it forked the server and stayed alive as the
 *    parent. `proc_open()` therefore reported the SHELL's pid, and
 *    `proc_terminate()` killed the shell while the real server was reparented
 *    to init (`ppid=1`) and ran forever. Passing an ARRAY command removes the
 *    shell entirely, so the pid `proc_open()` reports IS the server's pid.
 *
 * 2. **Descriptor inheritance — the half that caused hangs.** `proc_open()`
 *    only touches descriptors named in its spec; the old spec named 0, 1 and 2.
 *    Every descriptor above fd 2 was inherited from phpunit: its script handle
 *    (fd 3), its own sockets, and — the damaging one — THE CALLER'S STDOUT
 *    (fd 4). A leaked server holds the write end of that pipe open forever, so
 *    `vendor/bin/phpunit | anything` never reaches EOF: the suite finishes
 *    green in 13 seconds and the pipeline never returns. PHP exposes no way to
 *    close a raw descriptor and no close-on-exec control, so this class instead
 *    NAMES every inherited descriptor in the spec and points it at /dev/null.
 *    That holds even if a server somehow leaks again — a leaked process that
 *    does not hold the caller's stdout is a nuisance, one that does is a hang.
 *
 * The reaping is likewise made deterministic: `proc_terminate()` only requests
 * death, so we wait for it and escalate to SIGKILL rather than returning from
 * the test method with a live child.
 */
final class LoopbackHttpServer
{
    /** Router filename inside each server's temp dir. */
    public const ROUTER_FILE = 'router.php';

    /** Shared by the spawner and the leak census, so the guard cannot look in the wrong place. */
    private const TEMP_PREFIX = 'mosaic-ssrf-';

    /** Signal numbers as literals: ext-pcntl (which defines SIG*) is not guaranteed. */
    private const SIG_TERM = 15;
    private const SIG_KILL = 9;

    private const REAP_TIMEOUT_SECONDS = 3.0;
    private const REAP_POLL_US         = 5_000;

    private const HEALTH_ATTEMPTS = 50;
    private const HEALTH_POLL_US  = 100_000;

    /** @var resource|null */
    private $proc;

    /**
     * @param resource $proc
     */
    private function __construct($proc, private readonly int $pid, private readonly int $port)
    {
        $this->proc = $proc;
    }

    /**
     * Spawn a server for $routerFile, retrying on a lost port race.
     *
     * Returns null when no server could be brought up, so callers can skip
     * rather than fail on a hostile sandbox.
     */
    public static function start(string $routerFile, int $attempts = 3): ?self
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $port = self::freePort();
            if ($port === 0) {
                continue;
            }

            // ARRAY form: no /bin/sh, so no intermediary to absorb the signal.
            $proc = @proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, $routerFile],
                self::descriptorSpec(),
                $pipes,
            );
            if (!is_resource($proc)) {
                continue;
            }

            $status = proc_get_status($proc);
            $server  = new self($proc, (int) ($status['pid'] ?? 0), $port);

            if ($server->waitForHealth()) {
                return $server;
            }

            $server->stop();
        }

        return null;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function isRunning(): bool
    {
        if (!is_resource($this->proc)) {
            return false;
        }

        $status = proc_get_status($this->proc);

        return $status !== false && (bool) $status['running'];
    }

    /**
     * Kill the server and do not return until it is actually dead.
     *
     * Idempotent: safe to call from both tearDown() and an explicit stop.
     */
    public function stop(): void
    {
        if (!is_resource($this->proc)) {
            return;
        }

        if ($this->isRunning()) {
            proc_terminate($this->proc, self::SIG_TERM);
            $this->awaitExit();
        }

        // A server that ignored or was too slow to honour SIGTERM must not
        // outlive the test method; SIGKILL cannot be ignored.
        if ($this->isRunning()) {
            proc_terminate($this->proc, self::SIG_KILL);
            $this->awaitExit();
        }

        proc_close($this->proc);
        $this->proc = null;
    }

    // ---- temp dirs ------------------------------------------------------

    /**
     * Prefix every router temp dir this PROCESS creates carries.
     *
     * Process-unique so the leak census counts only servers this phpunit run
     * started — a concurrent run in another checkout must not turn the guard
     * red, and must not mask a real leak either.
     */
    public static function tempDirPrefix(): string
    {
        return self::TEMP_PREFIX . getmypid() . '-';
    }

    public static function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/' . self::tempDirPrefix() . uniqid('', true);
        mkdir($dir, 0o755, true);

        return $dir;
    }

    /** Deletes by exact path only — never a glob. */
    public static function removeTempDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        @unlink($dir . '/' . self::ROUTER_FILE);
        @rmdir($dir);
    }

    // ---- leak census ----------------------------------------------------

    /**
     * pids of servers this process started that are still alive.
     *
     * Returns null where /proc is unavailable, so a caller can skip rather
     * than read a vacuous zero.
     *
     * @return list<int>|null
     */
    public static function livePids(): ?array
    {
        return self::pidsMatching(self::tempDirPrefix());
    }

    /**
     * @return list<int>|null
     */
    public static function pidsMatching(string $needle): ?array
    {
        if (!is_dir('/proc/self')) {
            return null;
        }

        $pids    = [];
        $entries = @scandir('/proc');
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (!ctype_digit((string) $entry)) {
                continue;
            }

            // A reaped-but-not-yet-waited zombie has an empty cmdline; it holds
            // no descriptors and is not a leak.
            $cmdline = @file_get_contents('/proc/' . $entry . '/cmdline');
            if (!is_string($cmdline) || $cmdline === '') {
                continue;
            }

            if (str_contains($cmdline, $needle)) {
                $pids[] = (int) $entry;
            }
        }

        sort($pids);

        return $pids;
    }

    // ---- internals ------------------------------------------------------

    /**
     * @return array<int, array{0: string}>
     */
    private static function descriptorSpec(): array
    {
        $spec = [
            0 => ['null'],
            1 => ['null'],
            2 => ['null'],
        ];

        foreach (self::inheritedDescriptors() as $fd) {
            $spec[$fd] = ['null'];
        }

        return $spec;
    }

    /**
     * Descriptor numbers the child would otherwise inherit from phpunit.
     *
     * @return list<int>
     */
    private static function inheritedDescriptors(): array
    {
        $highest = 0;
        $entries = @scandir('/proc/' . getmypid() . '/fd');
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (ctype_digit((string) $entry) && (int) $entry > $highest) {
                $highest = (int) $entry;
            }
        }

        // Margin above the highest descriptor actually observed, because
        // proc_open() allocates handles of its own between this snapshot and
        // the fork. Where /proc is absent (non-Linux) fall back to a blanket
        // range — cheap, since /dev/null opens cost nothing measurable.
        $ceiling = $highest > 0 ? $highest + 8 : 63;

        return range(3, $ceiling);
    }

    private function awaitExit(): void
    {
        $deadline = microtime(true) + self::REAP_TIMEOUT_SECONDS;
        while ($this->isRunning() && microtime(true) < $deadline) {
            usleep(self::REAP_POLL_US);
        }
    }

    private function waitForHealth(): bool
    {
        for ($i = 0; $i < self::HEALTH_ATTEMPTS; $i++) {
            $body = @file_get_contents(
                "http://127.0.0.1:{$this->port}/health",
                false,
                stream_context_create(['http' => ['timeout' => 1]]),
            );
            if ($body === 'ok') {
                return true;
            }
            usleep(self::HEALTH_POLL_US);
        }

        return false;
    }

    private static function freePort(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            return 0;
        }

        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);

        $colon = strrpos($name, ':');

        return $colon === false ? 0 : (int) substr($name, $colon + 1);
    }
}

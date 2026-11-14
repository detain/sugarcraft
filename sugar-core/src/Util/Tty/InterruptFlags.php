<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Tty;

/**
 * Cross-platform process-shared interrupt flag.
 *
 * This class manages a single byte in a shared-memory segment that
 * can be written by a native C callback (on Windows via FFI) and read
 * by the PHP main loop.  Using `shmop` keeps the read/write path free
 * of any Zend VM heap operations — the C callback never touches PHP
 * strings, arrays, or reference counts.
 *
 * ## Platform behaviour
 *
 * - **Linux / macOS**: uses `shmop_open()` with an `ftok(__FILE__, 'S')`
 *   key. Multiple processes with the same key see the same segment.
 * - **Windows**: INERT, by the constructor's `extension_loaded('shmop')`
 *   guard — ext-shmop is not built for Windows PHP. An earlier version of
 *   this bullet claimed Windows shmop worked "via the POSIX shmop shim
 *   that PHP's ext-shmop provides on Windows"; that shim does not exist on
 *   any PHP release, and the named-int keying in {@see makeKey()} below is
 *   dead code kept only in case a future build ever enables the extension.
 *   The native-Windows interrupt machine is the CONIN$ KEY_EVENT poll in
 *   {@see WindowsBackend::drainSignals()} (sc-02, finding sugar-core-051's
 *   doc-truth leg).
 *
 * ## Safety contract
 *
 * The writer (C callback) MUST only write a single byte (`"\x01"`) to
 * the segment and return immediately.  No PHP allocations, no string
 * concat, no array operations — those are all unsafe from a non-main
 * OS thread inside the Zend VM.
 *
 * @internal for use by WindowsBackend only
 */
final class InterruptFlags
{
    /** Cached singleton instance (process-shared). */
    private static ?InterruptFlags $instance = null;

    /** Shmop segment ID, or false on failure. */
    private $shmId = false;

    /**
     * Return the singleton InterruptFlags instance.
     *
     * All callers — `WindowsBackend::drainSignals()` and the process-shutdown
     * cleanup `enableRawMode()` registers — share the same underlying shmop
     * segment. {@see destroy()} releases the cache, so a sibling that holds a
     * stale reference can never strand this entry point on a dead segment.
     */
    public static function self(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Create (or attach to) the shared interrupt flag segment.
     *
     * The segment is created with `c` mode (create if missing).
     * Its teardown is process-scoped, not instance-scoped: the shutdown
     * hook {@see WindowsBackend::enableRawMode()} registers calls
     * {@see destroyShared()} exactly once (sc-02, finding sugar-core-053 —
     * the earlier per-restore() destroy let any one backend tear the shared
     * segment out from under its siblings).
     */
    private function __construct()
    {
        if (!extension_loaded('shmop')) {
            $this->shmId = false;

            return;
        }

        $key = $this->makeKey();

        // 1 byte, mode 0644 (owner read+write, world read-only).
        $this->shmId = @shmop_open($key, 'c', 0644, 1);

        if ($this->shmId === false) {
            // Fall back to attach (already created by another process).
            $this->shmId = @shmop_open($key, 'a+', 0, 1);
        }
    }

    /**
     * Write the interrupt-pending byte into shared memory.
     *
     * This is the ONLY safe operation in a native C callback context.
     * It performs a single write syscall with no Zend heap involvement.
     */
    public function set(): bool
    {
        if ($this->shmId === false) {
            return false;
        }

        return shmop_write($this->shmId, "\x01", 0) === 1;
    }

    /**
     * Read and clear the interrupt-pending byte.
     *
     * Returns `true` if the interrupt byte was present (meaning Ctrl+C
     * or similar was received), `false` if the flag is clear.
     *
     * The clear is atomic at the shmop level: the write and read are
     * two separate syscalls, but the flag state (0 = no interrupt,
     * 1 = interrupt pending) means a consumed 1 is indistinguishable
     * from "never set" — both read as 0 on the next call.
     */
    public function consume(): bool
    {
        if ($this->shmId === false) {
            return false;
        }

        $raw = @shmop_read($this->shmId, 0, 1);

        // Empty or zero byte means no interrupt. The @ is INERT on PHP 8:
        // shmop_read is typed `string` and THROWS ValueError for out-of-range
        // reads, so there is no false return to guard (the historic comment
        // claimed one — stale from the pre-8.0 resource era; corrected in a
        // PHP 8.3.6 remeasurement). Offset/size here are
        // constants inside a 1-byte segment, so the throw is unreachable.
        if (strlen($raw) === 0 || $raw === "\x00") {
            return false;
        }

        // Clear the flag after reading (write 0x00 at offset 0).
        shmop_write($this->shmId, "\x00", 0);

        return true;
    }

    /**
     * Destroy the shared-memory segment, if we created it.
     *
     * sc-02 (finding sugar-core-053): this also releases the cached
     * singleton when the caller IS that singleton. Without the release a
     * post-destroy `self()` kept handing back this object with `$shmId`
     * false — a zombie every consume() answered `false` from, silently
     * deaf to interrupts for the rest of the process.
     *
     * Returns `true` on success, `false` on error or if already detached.
     */
    public function destroy(): bool
    {
        if (self::$instance === $this) {
            self::$instance = null;
        }

        if ($this->shmId === false) {
            return false;
        }

        $result = @shmop_delete($this->shmId);
        @shmop_close($this->shmId);
        $this->shmId = false;

        return $result;
    }

    /**
     * Destroy the cached singleton without holding an instance reference.
     *
     * The process-lifetime cleanup WindowsBackend registers at raw-mode
     * entry (sc-02/053). A no-op when nothing ever created a segment this
     * run — which is every POSIX test process and every Windows run
     * (there this class is inert by construction).
     */
    public static function destroyShared(): void
    {
        $instance = self::$instance;
        self::$instance = null;

        if ($instance !== null) {
            $instance->destroy();
        }
    }

    /**
     * Derive a platform-specific shared-memory key.
     *
     * POSIX: `ftok()` maps this file's inode to a System V IPC key —
     * every process that can stat the same file agrees on the number.
     *
     * Windows: unreachable in practice. ext-shmop is not built for Windows
     * PHP, so the constructor's `extension_loaded('shmop')` guard returns
     * before this method is ever called there. An earlier body of this
     * method explained the branch as "shmop_open on Windows accepts a
     * string identifier" and "ftok() on Windows maps to a shared memory
     * slot via the registry" — neither mechanism exists (sc-02, finding
     * sugar-core-051's doc-truth leg). The fixed int stays as a
     * project-scoped placeholder for a hypothetical future build that
     * enables the extension, nothing more.
     */
    private function makeKey(): int
    {
        static $cachedKey = null;
        if ($cachedKey !== null) {
            return $cachedKey;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return $cachedKey = 0x53475643; // 'SGVC' — SugarCraft Virtual Console
        }

        // POSIX: ftok for a stable System V IPC key.
        $result = @\ftok(__FILE__, 'S');
        // ftok returns int on success, -1 if the file is inaccessible.
        // The fixed fallback keeps single-process use working (no other
        // process needs to attach to the same segment in tests).
        return $cachedKey = $result === -1 ? 0x41544347 : $result; // 'ATCG' fallback
    }
}

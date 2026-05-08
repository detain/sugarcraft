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
 * - **Linux / macOS**: uses `shmop_open()` with key `ftok(__FILE__, 1)`.
 *   Multiple processes with the same key see the same segment.
 * - **Windows**: uses `shmop_open()` with a fixed namespaced string key.
 *   Windows maps this to a named shared-memory object via the POSIX shmop
 *   shim that PHP's `ext-shmop` provides on Windows.
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

    /** Actual shared memory segment key. */
    private int $shmKey = 0;

    /**
     * Return the singleton InterruptFlags instance.
     *
     * All callers — `WindowsBackend::drainSignals()`, `restore()`, and
     * test doubles — share the same underlying shmop segment.
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
     * It is NOT automatically destroyed on process exit — that is the
     * responsibility of {@see WindowsBackend::restore()}.
     */
    private function __construct()
    {
        $key = $this->makeKey();

        // 1 byte, mode 0644 (owner read+write, world read-only).
        $this->shmId = @shmop_open($key, 'c', 0644, 1);

        if ($this->shmId === false) {
            // Fall back to attach (already created by another process).
            $this->shmId = @shmop_open($key, 'a+', 0, 1);
        }

        $this->shmKey = $key;
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

        $data = shmop_read($this->shmId, 0, 1);

        if ($data === false || $data === "\x00" || $data === '') {
            return false;
        }

        // Clear the flag after reading (write 0x00 at offset 0).
        shmop_write($this->shmId, "\x00", 0);

        return true;
    }

    /**
     * Destroy the shared-memory segment, if we created it.
     *
     * Called by {@see WindowsBackend::restore()} to clean up.
     * Returns `true` on success, `false` on error or if already detached.
     */
    public function destroy(): bool
    {
        if ($this->shmId === false) {
            return false;
        }

        $result = @shmop_delete($this->shmId);
        @shmop_close($this->shmId);
        $this->shmId = false;

        return $result;
    }

    /**
     * Derive a platform-specific shared-memory key from this file.
     *
     * Uses `ftok()` on POSIX for filesystem-association; falls back to
     * a fixed key on Windows (the shmop ext on Windows uses different
     * key semantics).
     */
    private function makeKey(): int
    {
        // On Windows, shmop_open uses a platform-native named shared
        // memory object.  The key string is passed as-is on Windows.
        // On POSIX, ftok() maps a file inode to a System V IPC key.
        if (DIRECTORY_SEPARATOR === '\\') {
            // Use a well-known name string.  shmop_open on Windows accepts
            // this as the shared memory object name.
            // We pass it via ftok emulation — on Windows PHP shmop, the
            // "key" parameter is actually a string identifier.
            // Check if ext-shmop on Windows uses string keys:
            static $windowsKey = null;
            if ($windowsKey === null) {
                // PHP on Windows shmop: key is an int, but maps to a
                // named kernel object.  Use a fixed project-scoped id.
                // ftok() on Windows maps to a shared memory slot via the
                // registry; we use a fixed value to avoid reliance on it.
                $windowsKey = 0x53475643; // 'SGVC' — SugarCraft Virtual Console
            }

            return $windowsKey;
        }

        // POSIX: use ftok for a stable System V IPC key.
        static $posixKey = null;
        if ($posixKey === null) {
            $posixKey = @\ftok(__FILE__, 'S');
            if ($posixKey === false) {
                // ftok fallback: use a fixed key if the file is unavailable.
                // This is safe for single-process use (no other process needs
                // to attach to the same segment in tests).
                $posixKey = 0x41544347; // 'SGC' — fixed fallback
            }
        }

        return $posixKey;
    }
}

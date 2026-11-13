<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests\Support;

/**
 * Userland `probe://` stream wrapper making the WRITE-THROUGH FLUSH LAW
 * observable: stream_write() only appends to a pending buffer, and bytes
 * become "committed" (what an external reader would see at the OS level)
 * exclusively through stream_flush() — which a sink can only trigger via
 * fflush(). A sink that forgot to fflush leaves committed permanently
 * behind pending, so the two flush legs (StreamSink::write law and Writer
 * one-flush-per-section) are load-bearing pins, not hopeful counts.
 *
 * Registered once per process (setUp resets the ledgers, never the
 * registration).
 */
final class ProbeStreamWrapper
{
    /** @var array<string, string> per-target bytes taken by fwrite but not yet flushed */
    public static array $pending = [];

    /** @var array<string, string> per-target bytes visible to an external reader */
    public static array $committed = [];

    /** @var array<string, int> per-target fwrite calls */
    public static array $writes = [];

    /** @var array<string, int> per-target flush calls */
    public static array $flushes = [];

    /** @var array<string, int> armed byte-budgets: the next write commits at
     * most this many bytes and reports a short write (SM-S54 legs) */
    public static array $shortWrite = [];

    /** @var array<string, bool> armed flush failures: consumed once, and
     * self-healing — PHP keeps reporting a wrapper flush failure through
     * cached stream-level state until a flush succeeds again, so the repair
     * flush re-runs the (now un-armed) commit path transparently */
    public static array $failFlush = [];

    public $context;

    private string $target = '';

    public static function register(): void
    {
        if (in_array('probe', stream_get_wrappers(), true)) {
            return;
        }
        stream_wrapper_register('probe', self::class);
    }

    public static function reset(string $target): void
    {
        self::$pending[$target]   = '';
        self::$committed[$target] = '';
        self::$writes[$target]    = 0;
        self::$flushes[$target]   = 0;
        unset(self::$shortWrite[$target], self::$failFlush[$target]);
    }

    /** Open handle for probe://<target> in write mode. */
    public static function open(string $target)
    {
        self::register();
        self::reset($target);

        return fopen('probe://' . $target, 'wb');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->target       = substr($path, strlen('probe://'));
        self::$pending[$this->target]   ??= '';
        self::$committed[$this->target] ??= '';
        self::$writes[$this->target]    ??= 0;
        self::$flushes[$this->target]   ??= 0;

        return true;
    }

    /**
     * SM-S54: honour the one option the failure legs need — melting the
     * write buffer makes fwrite call stream_write directly (and take its
     * short-write return as its own), which is how real byte-limited doors
     * misbehave. Every other option stays unsupported.
     */
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return $option === STREAM_OPTION_WRITE_BUFFER && $arg1 === 0;
    }

    public function stream_write(string $data): int
    {
        if (isset(self::$shortWrite[$this->target])) {
            // SM-S54 arm: accept only the budgeted prefix, then disarm.
            $accept = min(self::$shortWrite[$this->target], strlen($data));
            unset(self::$shortWrite[$this->target]);
            self::$pending[$this->target] .= substr($data, 0, $accept);
            self::$writes[$this->target]++;

            return $accept;
        }
        self::$pending[$this->target] .= $data;
        self::$writes[$this->target]++;

        return strlen($data);
    }

    public function stream_flush(): bool
    {
        if (self::$failFlush[$this->target] ?? false) {
            // SM-S54 arm: the commit door fails exactly once.
            unset(self::$failFlush[$this->target]);
            self::$flushes[$this->target]++;

            return false;
        }
        self::$committed[$this->target] .= self::$pending[$this->target];
        self::$pending[$this->target]    = '';
        self::$flushes[$this->target]++;

        return true;
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_close(): void
    {
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Backend;

use SugarCraft\Metrics\Lang;
use SugarCraft\Metrics\Backend;
use SugarCraft\Metrics\Descriptor;
use SugarCraft\Metrics\Util;

/**
 * Newline-delimited JSON emitter. Every metric event gets a fresh
 * line on the underlying stream (file, php://stderr, a socket).
 * The simplest and most diagnostic-friendly backend — `tail -f` /
 * `jq` away.
 *
 * Each line shape:
 *   `{"ts":"2026-05-02T16:30:00+00:00","kind":"counter","name":"x","value":1,"tags":{...}}`
 *
 * Multi-writer contract (sugar-metrics-009): when the backend opened
 * its own stream (a path target or the php://stderr default), each
 * emit takes `flock(LOCK_EX)`, writes the whole record, flushes inside
 * the lock window, then unlocks — so records are line-atomic even when
 * one exceeds the 8 KB stream buffer (tag arrays are unbounded at this
 * API; only SessionMetrics clamps them). Concurrent appenders that
 * bypass this class (foreign writers on the same file) are outside its
 * reach; an injected stream resource is written exactly as given — no
 * lock, no forced flush — because the backend cannot claim a locking
 * contract over a handle it does not own.
 *
 * A short write quarantines its torn record with one best-effort
 * `@fwrite("\n")` before the throw/drop, so a failure truncates only
 * itself, never every successor line after it. The quarantine is
 * best-effort: if the stream still refuses progress the newline is
 * lost and the splice risk returns — bounded, disclosed residual.
 */
final class JsonStreamBackend implements Backend
{
    /** @var resource */
    private $stream;
    private bool $owns = false;
    private bool $throwOnError;

    /**
     * Preallocated record (sugar-metrics-047): the five slots are
     * overwritten per emit instead of rebuilding the array, so
     * json_encode() re-walks a stable shape. Tags ride as a plain array
     * with JSON_FORCE_OBJECT on the encode, which renders them byte-
     * identical to the old `(object)` cast (including numeric-looking
     * keys and the empty case) without the per-record cast rehash.
     *
     * @var array{ts:string,kind:string,name:string,value:float|string,tags:array<array-key,string>}
     */
    private array $record = ['ts' => '', 'kind' => '', 'name' => '', 'value' => 0.0, 'tags' => []];

    /**
     * @param resource|string|null $target A path or the php://stderr default
     *        is opened (and exclusively locked per record) by this backend;
     *        an injected resource is written as-is — no lock, no forced
     *        flush (see class docblock, multi-writer contract).
     * @param bool $throwOnError If false, silently drop metrics on partial write failure.
     */
    /**
     * House-idiom factory (sugar-metrics-014): `JsonStreamBackend::new($target)`.
     */
    public static function new($target = null, bool $throwOnError = true): self
    {
        return new self($target, $throwOnError);
    }

    public function __construct($target = null, bool $throwOnError = true)
    {
        if (is_string($target)) {
            // fopen() raises an E_WARNING *before* returning false when its
            // parent cannot host the file, which would redden the suite under
            // failOnWarning even though the `=== false` branch below is the
            // documented refusal contract. Probe the door first; keep the
            // check as the probe-vs-open race fallback with the same message.
            $dir = dirname($target);
            $stream = is_dir($dir) && is_writable($dir) ? fopen($target, 'a') : false;
            if ($stream === false) {
                throw new \RuntimeException(Lang::t('jsonstream.cannot_open_target', ['target' => Util::displaySafe($target)]));
            }
            $this->stream = $stream;
            $this->owns = true;
            $this->throwOnError = $throwOnError;
            return;
        }
        if ($target === null) {
            $stream = fopen('php://stderr', 'a');
            if ($stream === false) {
                throw new \RuntimeException(Lang::t('jsonstream.cannot_open_stderr'));
            }
            $this->stream = $stream;
            $this->owns = true;
            $this->throwOnError = $throwOnError;
            return;
        }
        if (is_resource($target) === false) {
            throw new \InvalidArgumentException(Lang::t('jsonstream.invalid_target'));
        }
        $this->stream = $target;
        $this->throwOnError = $throwOnError;
    }

    public function __destruct()
    {
        if ($this->owns && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function counter(string $name, float $value, array $tags = []): void       { $this->emit('counter',         $name, $value, $tags); }
    public function gauge(string $name, float $value, array $tags = []): void         { $this->emit('gauge',           $name, $value, $tags); }
    public function histogram(string $name, float $value, array $tags = []): void       { $this->emit('histogram',       $name, $value, $tags); }
    public function upDownCounter(string $name, float $amount, array $tags = []): void { $this->emit('up_down_counter', $name, $amount, $tags); }
    public function asyncCounter(string $name, float $value, array $tags = []): void      { $this->emit('async_counter',  $name, $value, $tags); }
    public function asyncGauge(string $name, float $value, array $tags = []): void        { $this->emit('async_gauge',    $name, $value, $tags); }

    // JSON stream has no concept of pre-emitted TYPE/HELP metadata.
    public function describe(Descriptor $descriptor): void
    {
        // No-op: JSON stream is a diagnostic format; TYPE/HELP are unnecessary.
    }

    // Flush drains the stream buffer to the underlying resource — meaningful
    // for file/stderr targets. The throw below is an unreachable-but-loud
    // safety net (sugar-metrics-041): no stream reachable through the public
    // API reliably fails fflush() while is_resource() stays true; the write
    // path owns every real failure. Pinned by JsonStreamBackendTest's closed-
    // stream probes.
    public function flush(): void
    {
        if (is_resource($this->stream) && @fflush($this->stream) === false && $this->throwOnError) {
            throw new \RuntimeException(Lang::t('jsonstream.flush_failed'));
        }
    }

    /**
     * JSON stream backend has no in-memory accumulation;
     * metrics are already written to the stream and cannot be retracted.
     *
     * @param array<string,string> $tags
     */
    public function remove(string $name, array $tags = []): void
    {
        // No-op: metrics are already written to the stream.
    }

    /**
     * JSON stream backend has no in-memory accumulation to clear.
     */
    public function clear(): void
    {
        // No-op: no local state is held between writes.
    }

    /**
     * @param array<string,string> $tags
     */
    private function emit(string $kind, string $name, float $value, array $tags): void
    {
        $this->record['ts'] = gmdate('c');   // per-record date; acceptable for a diagnostic backend
        $this->record['kind'] = $kind;
        $this->record['name'] = $name;
        // JSON has no literal for ±Inf/NaN — json_encode() returns false
        // for those floats. Render them as the canonical string tokens so
        // one poisoned reading cannot silently swallow the record.
        $this->record['value'] = Util::nonFiniteToken($value) ?? $value;
        $this->record['tags'] = $tags;
        $line = json_encode($this->record, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT);
        if ($line === false) {
            // Remaining encode failures are hostile-input territory
            // (non-UTF-8 names/tags): the throwOnError contract says a
            // dropped record must be visible, so loud mode throws here too
            // instead of returning before the write check.
            if ($this->throwOnError) {
                throw new \RuntimeException(Lang::t('jsonstream.encode_failed', ['name' => Util::displaySafe($name)]));
            }
            return;
        }
        // Line-atomicity on owned streams (sugar-metrics-009): exclusive
        // lock, full write, and flush share one lock window, so no other
        // appending process can splice between the chunks of a record
        // that outruns the stream buffer. Best-effort everywhere: a
        // filesystem without flock support simply proceeds.
        $locked = $this->owns && @flock($this->stream, LOCK_EX);
        $written = @fwrite($this->stream, $line . "\n");
        $short = $written === false || $written < strlen($line) + 1;
        if ($short) {
            // Quarantine the torn record (sugar-metrics-009a): one
            // terminating newline, best-effort, still inside the lock
            // window, so successors start on their own lines even if this
            // record died mid-write. The docblocks used to name only the
            // failed record; without this attempt every LATER record
            // fuses onto the tear permanently. If the quarantine itself
            // refuses progress the splice risk returns — bounded, disclosed.
            @fwrite($this->stream, "\n");
        } elseif ($this->owns) {
            @fflush($this->stream); // drain the buffer inside the same window
        }
        if ($locked) {
            @flock($this->stream, LOCK_UN);
        }
        if ($short) {
            if ($this->throwOnError) {
                throw new \RuntimeException(Lang::t('jsonstream.write_failed', ['name' => Util::displaySafe($name)]));
            }
            return;
        }
    }
}

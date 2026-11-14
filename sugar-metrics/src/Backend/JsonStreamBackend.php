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
 */
final class JsonStreamBackend implements Backend
{
    /** @var resource */
    private $stream;
    private bool $owns = false;
    private bool $throwOnError;

    /**
     * @param resource|string|null $target
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

    // Flush ensures the stream buffer is drained to the underlying resource.
    // In throwOnError mode a failed fflush is reported like a failed write —
    // the old bare call could not throw despite the class contract.
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
        $record = [
            // Acceptable for a diagnostic backend; not cached across events.
            'ts'    => gmdate('c'),
            'kind'  => $kind,
            'name'  => $name,
            // JSON has no literal for ±Inf/NaN — json_encode() returns false
            // for those floats. Render them as the canonical string tokens so
            // one poisoned reading cannot silently swallow the record.
            'value' => Util::nonFiniteToken($value) ?? $value,
            'tags'  => (object) $tags,
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
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
        $written = @fwrite($this->stream, $line . "\n");
        if ($written === false || $written < strlen($line) + 1) {
            if ($this->throwOnError) {
                throw new \RuntimeException(Lang::t('jsonstream.write_failed', ['name' => Util::displaySafe($name)]));
            }
            return;
        }
    }
}

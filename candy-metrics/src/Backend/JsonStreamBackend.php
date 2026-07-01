<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Backend;

use SugarCraft\Metrics\Lang;
use SugarCraft\Metrics\Backend;
use SugarCraft\Metrics\Descriptor;

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
    public function __construct($target = null, bool $throwOnError = true)
    {
        if (is_string($target)) {
            $stream = fopen($target, 'a');
            if ($stream === false) {
                throw new \RuntimeException(Lang::t('jsonstream.cannot_open_target', ['target' => $target]));
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
        if (!is_resource($target)) {
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

    public function counter(string $name, float $value, array $tags = []): void       { $this->emit('counter',        $name, $value, $tags); }
    public function gauge(string $name, float $value, array $tags = []): void         { $this->emit('gauge',          $name, $value, $tags); }
    public function histogram(string $name, float $value, array $tags = []): void       { $this->emit('histogram',       $name, $value, $tags); }
    public function upDownCounter(string $name, float $amount, array $tags = []): void { $this->emit('updowncounter',  $name, $amount, $tags); }
    public function asyncCounter(string $name, float $value, array $tags = []): void      { $this->emit('async_counter',  $name, $value, $tags); }
    public function asyncGauge(string $name, float $value, array $tags = []): void        { $this->emit('async_gauge',    $name, $value, $tags); }

    // JSON stream has no concept of pre-emitted TYPE/HELP metadata.
    public function describe(Descriptor $descriptor): void
    {
        // No-op: JSON stream is a diagnostic format; TYPE/HELP are unnecessary.
    }

    // Flush ensures the stream buffer is drained to the underlying resource.
    public function flush(): void
    {
        if (is_resource($this->stream)) {
            fflush($this->stream);
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
            'value' => $value,
            'tags'  => (object) $tags,
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        $written = @fwrite($this->stream, $line . "\n");
        if ($written === false || $written < strlen($line) + 1) {
            if ($this->throwOnError) {
                throw new \RuntimeException(Lang::t('jsonstream.write_failed', ['name' => $name]));
            }
            return;
        }
    }
}

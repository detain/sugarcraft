<?php

declare(strict_types=1);

namespace CandyCore\Metrics\Backend;

use CandyCore\Metrics\Backend;

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

    /**
     * @param resource|string|null $target
     */
    public function __construct($target = null)
    {
        if (is_string($target)) {
            $stream = fopen($target, 'a');
            if ($stream === false) {
                throw new \RuntimeException("cannot open metrics target: {$target}");
            }
            $this->stream = $stream;
            $this->owns = true;
            return;
        }
        if ($target === null) {
            $stream = fopen('php://stderr', 'a');
            if ($stream === false) {
                throw new \RuntimeException('cannot open php://stderr');
            }
            $this->stream = $stream;
            $this->owns = true;
            return;
        }
        if (!is_resource($target)) {
            throw new \InvalidArgumentException('target must be path, resource, or null');
        }
        $this->stream = $target;
    }

    public function __destruct()
    {
        if ($this->owns && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function counter(string $name, float $value, array $tags = []): void   { $this->emit('counter',   $name, $value, $tags); }
    public function gauge(string $name, float $value, array $tags = []): void     { $this->emit('gauge',     $name, $value, $tags); }
    public function histogram(string $name, float $value, array $tags = []): void { $this->emit('histogram', $name, $value, $tags); }

    /**
     * @param array<string,string> $tags
     */
    private function emit(string $kind, string $name, float $value, array $tags): void
    {
        $record = [
            'ts'    => date('c'),
            'kind'  => $kind,
            'name'  => $name,
            'value' => $value,
            'tags'  => (object) $tags,
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        fwrite($this->stream, $line . "\n");
    }
}

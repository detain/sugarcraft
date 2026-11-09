<?php

declare(strict_types=1);

namespace ChannelBridge;

/**
 * Structured file log. One JSON object per line, no transcript text: the
 * serve side already logs conversation content in its own log; the bridge
 * logs only what the bridge knows — session ids, seqs, key handles, byte
 * counts, error names. Message bodies never enter a log line.
 *
 * MUTABLE ON PURPOSE: it is the one open file handle of the process.
 */
final class Log
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];

    /** @var resource|null */
    private $handle = null;

    public function __construct(
        private readonly string $path,
        private readonly string $level = 'info',
    ) {
    }

    public function open(): void
    {
        $dir = \dirname($this->path);
        if (!\is_dir($dir) && !@mkdir($dir, 0o700, true) && !\is_dir($dir)) {
            throw new \RuntimeException("log directory not creatable: {$dir}");
        }
        $handle = @fopen($this->path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException("log not writable: {$this->path}");
        }
        @chmod($this->path, 0o600);
        $this->handle = $handle;
    }

    /** @param array<string, scalar|null> $fields */
    public function info(string $event, array $fields = []): void
    {
        $this->write('info', $event, $fields);
    }

    /** @param array<string, scalar|null> $fields */
    public function warn(string $event, array $fields = []): void
    {
        $this->write('warn', $event, $fields);
    }

    /** @param array<string, scalar|null> $fields */
    public function error(string $event, array $fields = []): void
    {
        $this->write('error', $event, $fields);
    }

    /** @param array<string, scalar|null> $fields */
    public function debug(string $event, array $fields = []): void
    {
        $this->write('debug', $event, $fields);
    }

    private function write(string $level, string $event, array $fields): void
    {
        if (self::LEVELS[$level] < (self::LEVELS[$this->level] ?? 1)) {
            return;
        }
        if ($this->handle === null) {
            $this->open();
        }
        $line = \json_encode(
            ['ts' => \date('c'), 'level' => $level, 'event' => $event] + $fields,
            \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($line === false) {
            $line = '{"ts":"' . \date('c') . '","level":"error","event":"log_encode_failed"}';
        }
        \fwrite($this->handle, $line . "\n");
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            \fclose($this->handle);
            $this->handle = null;
        }
    }
}

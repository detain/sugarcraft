<?php

declare(strict_types=1);

namespace SugarCraft\Shine;

/**
 * Write-through byte sink for the E736 7.3 streaming family
 * ({@see Writer}, {@see Renderer::writer()}).
 *
 * Two factories, two ownership regimes:
 *  - {@see toPath()} opens the file itself and OWNS it: {@see close()}
 *    closes the handle.
 *  - {@see toStream()} adopts a caller-supplied resource and NEVER closes
 *    it — the hard-close guard kills the sink's own usability, not the
 *    caller's stream.
 *
 * Door law (fail fast, before any rendering work): construction validates
 * the target. A path door fires when the parent directory is missing or
 * unwritable, when an existing file is unwritable, or when the open itself
 * fails anyway — the `@` + `=== false` net behind the probe is the r83-s2
 * idiom keeping the probe→read race silent-but-fatal rather than
 * warning-emitting (failOnWarning era).
 *
 * Write-through flush law: every {@see write()} lands its bytes in the
 * operating system (or the underlying wrapper's flush) before returning —
 * a reader watching the file observes every completed section as soon as
 * the feed that proved it returned.
 */
final class StreamSink
{
    /** @var resource|null Live handle between construction and close(). */
    private mixed $stream;

    private bool $closed = false;

    /** @param resource $stream */
    private function __construct(mixed $stream, private readonly bool $owned)
    {
        $this->stream = $stream;
    }

    /**
     * Adopt a caller-owned writable stream resource. The resource is never
     * closed by this sink.
     */
    public static function toStream(mixed $resource): self
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException(Lang::t('renderer.stream_invalid'));
        }
        $mode = (string) (stream_get_meta_data($resource)['mode'] ?? '');
        if (!preg_match('/[waxc#]|\\+/', $mode)) {
            throw new \InvalidArgumentException(Lang::t('renderer.stream_not_writable', ['mode' => $mode]));
        }

        return new self($resource, owned: false);
    }

    /**
     * Open (create/truncate) a file for writing. Every door fires before
     * any content exists on disk: missing parent, unwritable parent or
     * target, and the open-failure race net.
     */
    public static function toPath(string $path): self
    {
        $dir = dirname($path);
        if (is_dir($dir) === false) {
            throw new \InvalidArgumentException(Lang::t('sink.parent_missing', ['dir' => $dir]));
        }
        if (is_writable($dir) === false || (file_exists($path) && is_writable($path) === false)) {
            throw new \InvalidArgumentException(Lang::t('sink.path_unwritable', ['path' => $path]));
        }
        $stream = @fopen($path, 'wb');
        if ($stream === false) {
            throw new \RuntimeException(Lang::t('sink.open_failed', ['path' => $path]));
        }

        return new self($stream, owned: true);
    }

    /**
     * Write the bytes and flush them through before returning.
     *
     * @return int Bytes written (always == strlen($bytes); short writes
     *             throw — a stream that will not take the whole section is
     *             a failed stream, not a partial success).
     */
    public function write(string $bytes): int
    {
        if ($this->closed) {
            throw new \LogicException(Lang::t('sink.closed'));
        }
        $written = @fwrite($this->stream, $bytes);
        if ($written === false || $written < strlen($bytes)) {
            throw new \RuntimeException(Lang::t('renderer.write_failed', ['bytes' => strlen($bytes)]));
        }
        if (@fflush($this->stream) === false) {
            throw new \RuntimeException(Lang::t('sink.flush_failed'));
        }

        return $written;
    }

    /**
     * Close the sink. Idempotent: a second call is a no-op. Owned handles
     * (toPath) are closed; adopted resources (toStream) survive — only the
     * sink itself becomes unusable, guarded by {@see isOpen()}.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->owned && is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    public function isOpen(): bool
    {
        return !$this->closed;
    }
}

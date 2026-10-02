<?php

declare(strict_types=1);

namespace SugarCraft\Shine;

use SugarCraft\Shine\Render\SectionStream;

/**
 * Push-side object channel of the E736 7.3 streaming family: feed Markdown
 * chunks as they arrive, and every newly proven top-level section is
 * rendered and written through the {@see StreamSink} immediately —
 * write-through flush law, one flush per completed section, no buffering
 * beyond the section stream's own hold.
 *
 * Byte-identity law (shared with {@see Renderer::stream()}, pinned by the
 * same corpus): for any split of the same input bytes,
 *   feed(all chunks in order); close()
 * leaves exactly {@see Renderer::render()} bytes in the sink. The section
 * stream's state depends only on assembled bytes, never on where a chunk
 * ended, and close() performs the terminal flush-partial: the trailing
 * section, and any section held back for a link reference definition that
 * never came, are rendered and written (minus the final newline run, which
 * render() rtrims anyway).
 *
 * Renderers with document-scope post-processing ({@see
 * Renderer::defersStreaming()}) buffer the source and emit render() exactly
 * once at close() — same fallback law as stream(), same predicate, so the
 * two channels can never disagree on which regime applies.
 *
 * Hard-close guard: the writer is single-use. feed() after close() throws
 * LogicException; a second close() is an idempotent no-op that does not
 * double-close the sink's handle.
 *
 * Pure channel aside from the sink: no timers, no ReactPHP, no reads
 * (E646). All doors — writable path, writable resource — fire at
 * construction via the {@see StreamSink} factories, before any rendering.
 */
final class Writer
{
    private readonly SectionStream $sections;

    /** The newline run held back until the next non-blank body: render() drops the last one. */
    private string $pending = '';

    private bool $closed = false;

    /** Non-null source accumulator exactly when the renderer defers streaming. */
    private ?string $deferredSource = null;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly StreamSink $sink,
    ) {
        $this->sections = new SectionStream($renderer);
        if ($renderer->defersStreaming()) {
            $this->deferredSource = '';
        }
    }

    /**
     * Writer over a file sink. Construction fires every path door (missing
     * parent, unwritable target, failed open) before anything renders.
     */
    public static function toPath(Renderer $renderer, string $path): self
    {
        return new self($renderer, StreamSink::toPath($path));
    }

    /** Writer over a caller-owned writable stream resource. */
    public static function toStream(Renderer $renderer, mixed $resource): self
    {
        return new self($renderer, StreamSink::toStream($resource));
    }

    /**
     * Absorb one Markdown chunk; newly completed sections are written
     * through to the sink before this returns.
     */
    public function feed(string $chunk): void
    {
        if ($this->closed) {
            throw new \LogicException(Lang::t('writer.feed_after_close'));
        }
        if ($this->deferredSource !== null) {
            $this->deferredSource .= $chunk;

            return;
        }
        foreach ($this->sections->push($chunk) as $body) {
            $this->emit($body);
        }
    }

    /**
     * Declare end of document: flush the trailing partial section
     * (terminal flush-partial), drop the final newline run exactly like
     * render() rtrims it, and close the sink. Idempotent.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        if ($this->deferredSource !== null) {
            $rendered = $this->renderer->render($this->deferredSource);
            if ($rendered !== '') {
                $this->sink->write($rendered);
            }
        } else {
            foreach ($this->sections->finish() as $body) {
                $this->emit($body);
            }
            // The pending run is dropped, not written: render() rtrims
            // exactly this trailing newline run at document end.
        }

        $this->sink->close();
    }

    public function isOpen(): bool
    {
        return !$this->closed;
    }

    /**
     * Write one rendered section body through, reproducing stream()'s
     * choreography byte for byte: the previous body's trailing newline run
     * rides out with this body's head, and a body of nothing but newlines
     * joins that run.
     */
    private function emit(string $body): void
    {
        $head = rtrim($body, "\n");
        if ($head === '') {
            $this->pending .= $body;

            return;
        }
        if ($this->pending !== '') {
            $this->sink->write($this->pending);
        }
        $this->sink->write($head);
        $this->pending = substr($body, strlen($head));
    }
}

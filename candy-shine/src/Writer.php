<?php

declare(strict_types=1);

namespace SugarCraft\Shine;

use SugarCraft\Shine\Render\SectionScanner;

/**
 * Push-side object channel of the E736 7.3 streaming family: feed Markdown
 * chunks as they arrive, and every newly proven top-level section is
 * rendered and written through the {@see StreamSink} immediately —
 * write-through flush law, one flush per completed section, no buffering
 * beyond the section scanner's own hold.
 *
 * Byte-identity law (shared with {@see Renderer::stream()}, pinned by the
 * same corpus): for any split of the same input bytes,
 *   feed(all chunks in order); close()
 * leaves exactly {@see Renderer::render()} bytes in the sink. The scanner's
 * state depends only on assembled bytes, never on where a chunk ended, and
 * close() performs the terminal flush-partial: the trailing section the
 * scanner was holding is rendered and written (minus the final newline run,
 * which render() rtrims anyway).
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
    private readonly SectionScanner $scanner;

    private bool $started = false;

    private string $carryRun = '';

    private bool $closed = false;

    /** Non-null source accumulator exactly when the renderer defers streaming. */
    private ?string $deferredSource = null;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly StreamSink $sink,
    ) {
        $this->scanner = new SectionScanner();
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
        foreach ($this->scanner->push($chunk) as $section) {
            $this->emitSection($section);
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
            $final = $this->scanner->finish();
            if ($final !== null) {
                $this->emitSection($final);
            }
            // The pending carryRun is dropped, not written: render() rtrims
            // exactly this trailing newline run at document end.
        }

        $this->sink->close();
    }

    public function isOpen(): bool
    {
        return !$this->closed;
    }

    /**
     * Render one completed section and write it through, reproducing
     * stream()'s head/carry choreography byte for byte: the previous
     * section's trailing newline run rides out with this section's head.
     */
    private function emitSection(string $section): void
    {
        $body           = $this->renderer->renderSection($section);
        $tail           = strlen(rtrim($body, "\n"));
        $head           = substr($body, 0, $tail);
        if ($this->started && $this->carryRun !== '') {
            $this->sink->write($this->carryRun);
        }
        $this->started  = true;
        $this->carryRun = $tail < strlen($body) ? substr($body, $tail) : '';
        if ($head !== '') {
            $this->sink->write($head);
        }
    }
}

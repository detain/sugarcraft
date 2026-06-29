<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * Single-choice selection prompt with case-insensitive substring filtering,
 * cursor navigation, and pagination.
 *
 * Filter is applied as a stable view over the original choice list; cursor and
 * pagination are tracked in the *filtered* index space.
 *
 * Each input handler returns a new immutable instance.
 */
final class SelectionPrompt
{
    /** @var list<string> */
    private array $choices;

    /** @var list<int>  Indices of {@see $choices} that survive {@see $filter}. */
    private array $filtered;

    private string $filter   = '';
    private int $cursor      = 0;
    private int $page        = 0;
    private int $pageSize    = 10;
    private bool $submitted  = false;
    private bool $aborted    = false;

    private string $cursorStyle = '7';     // reverse
    private string $labelStyle  = '1;36';  // bold cyan

    /** @param list<string> $choices */
    public function __construct(private readonly string $label, array $choices)
    {
        $this->choices  = array_values($choices);
        $this->filtered = array_keys($this->choices);
    }

    /** @param list<string> $choices */
    public static function new(string $label, array $choices): self
    {
        return new self($label, $choices);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function withPageSize(int $size): self
    {
        $clone = clone $this;
        $clone->pageSize = max(1, $size);
        $clone->reclampPage();
        return $clone;
    }

    public function withFilter(string $needle): self
    {
        $clone = clone $this;
        $clone->filter   = $needle;
        $clone->filtered = $needle === ''
            ? array_keys($clone->choices)
            : array_values(array_filter(
                array_keys($clone->choices),
                fn(int $i): bool => stripos($clone->choices[$i], $needle) !== false,
            ));
        $clone->cursor = 0;
        $clone->page   = 0;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    public function handleKey(string $key): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }

        return match ($key) {
            Key::Up       => $this->moveCursor(-1),
            Key::Down     => $this->moveCursor(1),
            Key::PageUp   => $this->changePage(-1),
            Key::PageDown => $this->changePage(1),
            Key::Home     => $this->moveCursorTo(0),
            Key::End      => $this->moveCursorTo(count($this->filtered) - 1),
            Key::Enter, Key::Space => $this->submit(),
            Key::Escape, Key::CtrlC => $this->abort(),
            default       => $this,
        };
    }

    public function submit(): self
    {
        if ($this->submitted || $this->aborted || $this->filtered === []) {
            return $this;
        }
        $clone = clone $this;
        $clone->submitted = true;
        return $clone;
    }

    public function abort(): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }
        $clone = clone $this;
        $clone->aborted = true;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /** Current choice under the cursor, or null when nothing matches the filter. */
    public function selectedValue(): ?string
    {
        if ($this->aborted || $this->filtered === []) {
            return null;
        }
        $idx = $this->filtered[$this->cursor] ?? null;
        return $idx === null ? null : $this->choices[$idx];
    }

    public function cursor(): int       { return $this->cursor; }
    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }

    /** Number of choices currently visible after filtering. */
    public function filteredCount(): int { return count($this->filtered); }

    public function totalChoices(): int { return count($this->choices); }

    public function currentPage(): int { return $this->page; }

    public function totalPages(): int
    {
        return max(1, (int) ceil(count($this->filtered) / $this->pageSize));
    }

    /** @return list<string> The slice of filtered choices on the current page. */
    public function currentPageItems(): array
    {
        $offset = $this->page * $this->pageSize;
        $slice  = array_slice($this->filtered, $offset, $this->pageSize);
        return array_map(fn(int $i): string => $this->choices[$i], $slice);
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function view(): string
    {
        $lines = [Ansi::wrap(Ansi::sanitize($this->label), $this->labelStyle)];

        $bar = '[filter] ' . Ansi::sanitize($this->filter);
        if ($this->filter !== '') {
            $bar .= sprintf(' (%d match%s)', count($this->filtered), count($this->filtered) === 1 ? '' : 'es');
        }
        $lines[] = $bar;

        if ($this->filtered === []) {
            $lines[] = '(no matches)';
            return implode("\n", $lines);
        }

        $offset = $this->page * $this->pageSize;
        $end    = min($offset + $this->pageSize, count($this->filtered));
        for ($i = $offset; $i < $end; $i++) {
            $value  = Ansi::sanitize($this->choices[$this->filtered[$i]]);
            $marker = $i === $this->cursor ? '❯ ' : '  ';
            $line   = $marker . $value;
            if ($i === $this->cursor) {
                $line = Ansi::wrap($line, $this->cursorStyle);
            }
            $lines[] = $line;
        }

        if ($this->totalPages() > 1) {
            $lines[] = sprintf('Page %d/%d', $this->page + 1, $this->totalPages());
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function moveCursor(int $delta): self
    {
        return $this->moveCursorTo($this->cursor + $delta);
    }

    private function moveCursorTo(int $position): self
    {
        $count = count($this->filtered);
        if ($count === 0) {
            return $this;
        }
        $clamped = max(0, min($count - 1, $position));
        if ($clamped === $this->cursor) {
            return $this;
        }
        $clone = clone $this;
        $clone->cursor = $clamped;
        $clone->page   = intdiv($clamped, $clone->pageSize);
        return $clone;
    }

    private function changePage(int $delta): self
    {
        $target = $this->page + $delta;
        $max    = $this->totalPages() - 1;
        $clamped = max(0, min($max, $target));
        if ($clamped === $this->page) {
            return $this;
        }
        $clone = clone $this;
        $clone->page   = $clamped;
        $clone->cursor = max(
            $clamped * $clone->pageSize,
            min(($clamped + 1) * $clone->pageSize - 1, count($clone->filtered) - 1),
        );
        return $clone;
    }

    private function reclampPage(): void
    {
        // Clamp cursor into valid range first
        $count = count($this->filtered);
        if ($count === 0) {
            $this->cursor = 0;
            $this->page = 0;
            return;
        }
        $this->cursor = max(0, min($count - 1, $this->cursor));
        // Re-derive page from cursor (consistent with moveCursorTo)
        $this->page = intdiv($this->cursor, $this->pageSize);
        // Finally clamp page to valid range
        $this->page = min($this->page, max(0, $this->totalPages() - 1));
    }
}

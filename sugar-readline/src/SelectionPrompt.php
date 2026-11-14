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

    /** @var int|null Display bound in cells; null = unset (width-adoption eligible), 0 = explicit unbounded. */
    private ?int $displayWidth = null;

    /** @var int|null Terminal row budget; null = unset (height-adoption eligible), 0 = explicit unbounded. */
    private ?int $viewportRows = null;

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

    /**
     * Bound the rendered rows to $cells display columns (CJK/emoji aware).
     * Mirrors {@see TextPrompt::withDisplayWidth()}: pass 0 to opt out
     * (rows paint unbounded), pass null to leave the bound unset — the
     * only state {@see Readline::run()} will auto-adopt a width into.
     */
    public function withDisplayWidth(?int $cells): self
    {
        $clone = clone $this;
        $clone->displayWidth = $cells === null ? null : max(0, $cells);
        return $clone;
    }

    /** Current display viewport width in cells; null = unset (adoption-eligible), 0 = explicit unbounded. */
    public function displayWidth(): ?int
    {
        return $this->displayWidth;
    }

    /**
     * Bound the painted height to $rows terminal rows. The list chrome
     * (label + filter bar + page footer) is reserved, so at most
     * `max(1, $rows - 3)` choices paint per page. Pass 0 or null for an
     * unbounded page; null is the adoption-eligible state that
     * {@see Readline::run()} fills from the terminal row count. A terminal
     * shorter than the chrome is gated: the page still shows one choice
     * (never an empty frame) and relies on paging.
     */
    public function withViewportRows(?int $rows): self
    {
        $clone = clone $this;
        $clone->viewportRows = $rows === null ? null : max(0, $rows);
        $clone->reclampPage();
        return $clone;
    }

    /** Current row budget; null = unset (adoption-eligible), 0 = explicit unbounded. */
    public function viewportRows(): ?int
    {
        return $this->viewportRows;
    }

    /** Effective choices-per-page after the height clamp. */
    private function effectivePageSize(): int
    {
        if ($this->viewportRows === null || $this->viewportRows <= 0) {
            return $this->pageSize;
        }
        return max(1, min($this->pageSize, $this->viewportRows - 3));
    }

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    public function handleKey(string $key): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }

        $key = Key::normalize($key);

        return match ($key) {
            // GNU-readline movement aliases, mirroring TextPrompt's default
            // keymap on the list surface. Ctrl+B/F stay out: the single-row
            // selection has no Left/Right arms to alias.
            Key::Up, 'ctrl_p'     => $this->moveCursor(-1),
            Key::Down, 'ctrl_n'   => $this->moveCursor(1),
            Key::PageUp           => $this->changePage(-1),
            Key::PageDown         => $this->changePage(1),
            Key::Home, 'ctrl_a'   => $this->moveCursorTo(0),
            Key::End, 'ctrl_e'    => $this->moveCursorTo(count($this->filtered) - 1),
            Key::Enter, Key::Space => $this->submit(),
            Key::Escape, Key::CtrlC => $this->abort(),
            // Filter editing — mirrors the [filter] bar the view renders.
            Key::Backspace => $this->withFilter(mb_substr($this->filter, 0, max(0, mb_strlen($this->filter, 'UTF-8') - 1), 'UTF-8')),
            'ctrl_u'       => $this->withFilter(''),
            default       => $this,
        };
    }

    /**
     * Type-to-filter: plain characters extend the search needle shown in the
     * [filter] bar (case-insensitive substring match via withFilter()).
     */
    public function handleChar(string $char): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }
        if ($char === '' || mb_strlen($char, 'UTF-8') !== 1) {
            return $this;
        }
        $ord = \ord($char);
        if ($ord === 32) {
            // Space is a bound key (submit/toggle), never needle text — some
            // decoders surface it as the raw character.
            return $this->handleKey(Key::Space);
        }
        if ($ord < 32 || $ord === 127) {
            // Control bytes belong to handleKey(), not the needle.
            return $this;
        }
        return $this->withFilter($this->filter . $char);
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
        return max(1, (int) ceil(count($this->filtered) / $this->effectivePageSize()));
    }

    /** @return list<string> The slice of filtered choices on the current page. */
    public function currentPageItems(): array
    {
        $offset = $this->page * $this->effectivePageSize();
        $slice  = array_slice($this->filtered, $offset, $this->effectivePageSize());
        return array_map(fn(int $i): string => $this->choices[$i], $slice);
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function view(): string
    {
        $bound = $this->displayWidth;
        $lines = [RowFit::row([[Ansi::sanitize($this->label), $this->labelStyle]], $bound)];

        $barSegments = [['[filter] ' . Ansi::sanitize($this->filter), null]];
        if ($this->filter !== '') {
            $barSegments[] = [sprintf(' (%d match%s)', count($this->filtered), count($this->filtered) === 1 ? '' : 'es'), null];
        }
        $lines[] = RowFit::row($barSegments, $bound);

        if ($this->filtered === []) {
            $lines[] = RowFit::row([['(no matches)', null]], $bound);
            return implode("\n", $lines);
        }

        $offset = $this->page * $this->effectivePageSize();
        $end    = min($offset + $this->effectivePageSize(), count($this->filtered));
        for ($i = $offset; $i < $end; $i++) {
            $value  = Ansi::sanitize($this->choices[$this->filtered[$i]]);
            $marker = $i === $this->cursor ? '❯ ' : '  ';
            $style  = $i === $this->cursor ? $this->cursorStyle : null;
            $lines[] = RowFit::row([[$marker . $value, $style]], $bound);
        }

        if ($this->totalPages() > 1) {
            $lines[] = RowFit::row([[sprintf('Page %d/%d', $this->page + 1, $this->totalPages()), null]], $bound);
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
        $clone->page   = intdiv($clamped, $clone->effectivePageSize());
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
            $clamped * $clone->effectivePageSize(),
            min(($clamped + 1) * $clone->effectivePageSize() - 1, count($clone->filtered) - 1),
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
        $this->page = intdiv($this->cursor, $this->effectivePageSize());
        // Finally clamp page to valid range
        $this->page = min($this->page, max(0, $this->totalPages() - 1));
    }
}

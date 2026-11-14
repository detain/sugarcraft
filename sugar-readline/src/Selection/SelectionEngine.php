<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Selection;

use SugarCraft\Readline\Ansi;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\RowFit;

/**
 * Shared selection machinery for the list prompts.
 *
 * Both {@see \SugarCraft\Readline\SelectionPrompt} and
 * {@see \SugarCraft\Readline\MultiSelectPrompt} present the same engine:
 * an original choice list, a case-insensitive substring filter applied as a
 * stable view over it (cursor and pagination live in FILTERED index space),
 * GNU-readline movement aliases, page/clamp maths, and the width/height
 * bounds the run-loop auto-adopts. Only the mark layer (none vs ◉ toggling),
 * the submit veto, and the painted rows differ — so the engine is written
 * once, here, and each prompt supplies its deltas.
 *
 * Identity of the two key maps is structural, not copy-pasted: the aliases
 * (ctrl_p/ctrl_n/ctrl_a/ctrl_e/ctrl_u, page up/down) resolve here, so a fix
 * lands once. Space/Enter route through {@see self::onSpace()} because the
 * bindings genuinely differ per prompt.
 *
 * Immutability: every `with*()` / handler clones; trait state is private to
 * the using class (PHP compiles trait members into it), which is what lets
 * the prompt classes read `$this->filtered` in `view()` untouched.
 *
 * The engine stays in this library on purpose: the fleet has twin selection
 * code elsewhere, but promoting a shared home to sugar-core is an
 * owner-consent call, not a coverage hole (this row's gate default).
 */
trait SelectionEngine
{
    /** @var list<string> */
    private array $choices;

    /** @var list<int>  Indices of {@see $choices} that survive {@see $filter}. */
    private array $filtered;

    private string $filter = '';
    private int $cursor    = 0;
    private int $page      = 0;
    private int $pageSize  = 10;
    private bool $submitted = false;
    private bool $aborted   = false;

    /** @var int|null Display bound in cells; null = unset (width-adoption eligible), 0 = explicit unbounded. */
    private ?int $displayWidth = null;

    /** @var int|null Terminal row budget; null = unset (height-adoption eligible), 0 = explicit unbounded. */
    private ?int $viewportRows = null;

    private string $cursorStyle = '7';     // reverse
    private string $labelStyle  = '1;36';  // bold cyan

    /**
     * Terminal rows the list chrome eats (label + bars + page footer), so a
     * viewport of $rows leaves `max(1, $rows - chromeRows())` choice rows.
     * MultiSelect reserves one more for its selected-count hint.
     */
    abstract protected function chromeRows(): int;

    /**
     * What the Space binding does on this surface: submit (single-choice,
     * mirroring Enter) or toggle the marked state (multi).
     */
    abstract protected function onSpace(): static;

    /** Constructor wiring: seed the choice list with an all-passing filter view. */
    protected function initChoices(array $choices): void
    {
        $this->choices  = array_values($choices);
        $this->filtered = array_keys($this->choices);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function withPageSize(int $size): static
    {
        $clone = clone $this;
        $clone->pageSize = max(1, $size);
        $clone->reclampPage();
        return $clone;
    }

    public function withFilter(string $needle): static
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
     * Replace the choice list at runtime.
     *
     * The current filter is re-applied against the new list; marks and
     * prompt-specific veto state are handled by the surface. By default the
     * cursor position is preserved (clamped and paged into the new filtered
     * view); pass `$preserveCursor = false` for the withFilter-style reset
     * to the top of page one.
     *
     * @param iterable<string> $choices
     */
    public function withChoices(iterable $choices, bool $preserveCursor = true): static
    {
        return $this->onChoicesReplaced($this->applyChoices($choices, $preserveCursor));
    }

    /** @param iterable<string> $choices */
    private function applyChoices(iterable $choices, bool $preserveCursor): static
    {
        $list = array_values(is_array($choices) ? $choices : iterator_to_array($choices));

        $clone         = clone $this;
        $clone->choices = $list;
        $clone->filtered = $clone->filter === ''
            ? array_keys($list)
            : array_values(array_filter(
                array_keys($list),
                fn(int $i): bool => stripos($list[$i], $clone->filter) !== false,
            ));

        if (!$preserveCursor) {
            $clone->cursor = 0;
            $clone->page   = 0;
            return $clone;
        }

        $clone->reclampPage();
        return $clone;
    }

    /**
     * Surface hook for withChoices(): marks are keyed by ORIGINAL choice
     * index, so a list replacement can only invalidate them — MultiSelect
     * drops the set; SelectionPrompt has none.
     */
    protected function onChoicesReplaced(self $state): static
    {
        return $state;
    }

    /**
     * Bound the rendered rows to $cells display columns (CJK/emoji aware).
     * Mirrors {@see \SugarCraft\Readline\TextPrompt::withDisplayWidth()}:
     * pass 0 to opt out (rows paint unbounded), pass null to leave the
     * bound unset — the only state {@see \SugarCraft\Readline\Readline::run()}
     * will auto-adopt a width into.
     */
    public function withDisplayWidth(?int $cells): static
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
     * Bound the painted height to $rows terminal rows; the chrome rows
     * (label + bars + page footer) stay, choices shrink to fit. Pass 0 or
     * null for an unbounded page; null is the adoption-eligible state that
     * {@see \SugarCraft\Readline\Readline::run()} fills from the terminal
     * row count. A terminal shorter than the chrome is gated: the page
     * still shows one choice (never an empty frame) and relies on paging.
     */
    public function withViewportRows(?int $rows): static
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
        return max(1, min($this->pageSize, $this->viewportRows - $this->chromeRows()));
    }

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    /**
     * Shared key map: movement with GNU-readline aliases, filter editing,
     * Escape/Ctrl+C abort, and the two surface-bound keys. One home means
     * both prompts answer `ctrl_p` exactly when the other does.
     */
    public function handleKey(string $key): static
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }

        $key = Key::normalize($key);

        return match ($key) {
            // GNU-readline movement aliases, mirroring TextPrompt's default
            // keymap on the list surface. Ctrl+B/F stay out: the single-row
            // selection has no Left/Right arms to alias.
            Key::Up, 'ctrl_p'   => $this->moveCursor(-1),
            Key::Down, 'ctrl_n' => $this->moveCursor(1),
            Key::PageUp         => $this->changePage(-1),
            Key::PageDown       => $this->changePage(1),
            Key::Home, 'ctrl_a' => $this->moveCursorTo(0),
            Key::End, 'ctrl_e'  => $this->moveCursorTo(count($this->filtered) - 1),
            Key::Enter          => $this->submit(),
            Key::Space          => $this->onSpace(),
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
     * Space routes through handleKey(), where each surface applies its own
     * binding (submit vs toggle) — that binding wins, as documented.
     */
    public function handleChar(string $char): static
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

    public function abort(): static
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

    /**
     * The painted `[filter] …` bar (needle plus match count). Shared by both
     * views: identical bytes on both surfaces for the same filter state.
     */
    protected function renderFilterBar(): string
    {
        $segments = [['[filter] ' . Ansi::sanitize($this->filter), null]];
        if ($this->filter !== '') {
            $segments[] = [sprintf(' (%d match%s)', count($this->filtered), count($this->filtered) === 1 ? '' : 'es'), null];
        }
        return RowFit::row($segments, $this->displayWidth);
    }

    /** Original choice index under the filtered cursor, or null when the view is empty. */
    private function currentChoiceIndex(): ?int
    {
        return $this->filtered[$this->cursor] ?? null;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function moveCursor(int $delta): static
    {
        return $this->moveCursorTo($this->cursor + $delta);
    }

    private function moveCursorTo(int $position): static
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

    private function changePage(int $delta): static
    {
        $target  = $this->page + $delta;
        $max     = $this->totalPages() - 1;
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

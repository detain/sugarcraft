<?php

declare(strict_types=1);

namespace CandyCore\Bits\Help;

use CandyCore\Bits\Key\Binding;
use CandyCore\Bits\Key\KeyMap;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Util\Width;
use CandyCore\Sprinkles\Style;

/**
 * Renders a {@see KeyMap} as either a short, single-line summary or a
 * multi-column full-help block.
 *
 * ```php
 * $help = new Help();
 * echo $help->shortView($keymap);   // "↑/k up • ↓/j down • q quit"
 * echo $help->fullView($keymap);    // multi-column block with aligned key columns
 * echo $help->showAll(true)
 *           ->width(40)
 *           ->view($keymap);        // unified entry — picks short or full
 * ```
 *
 * The component is immutable — every setter returns a new instance.
 */
final class Help
{
    public function __construct(
        public readonly string $separator = ' • ',
        public readonly string $keyDescGap = ' ',
        public readonly string $columnGap  = '    ',
        public readonly bool   $showAll    = false,
        public readonly int    $maxWidth   = 0,
        public readonly string $ellipsis   = '…',
        public readonly string $fullSeparator = "\n",
        public readonly ?Styles $styles    = null,
    ) {}

    /**
     * Override the rendered colour / styling of every visible help
     * element. Mirrors upstream Bubbles' `help.Styles` struct. Default
     * null means "no styling" (every binding rendered verbatim) so
     * existing callers / snapshots are unchanged.
     */
    public function withStyles(?Styles $styles): self
    {
        return $this->copy(styles: $styles, stylesSet: true);
    }

    /** Read-only accessor. */
    public function getStyles(): ?Styles { return $this->styles; }

    public function withSeparator(string $s): self
    {
        return $this->copy(separator: $s);
    }

    /**
     * Toggle the unified {@see view()} entry between short and full
     * rendering. `false` (default) → short row; `true` → full block.
     * Mirrors Bubbles' `Help.ShowAll` field.
     */
    public function showAll(bool $on = true): self
    {
        return $this->copy(showAll: $on);
    }

    /**
     * Cap the rendered width (cells). When the short view would exceed
     * `$cells`, trailing entries get dropped and the {@see $ellipsis}
     * glyph is appended. Pass `0` (default) to disable truncation.
     * Mirrors Bubbles' `Help.SetWidth` / `Help.Width`.
     */
    public function width(int $cells): self
    {
        if ($cells < 0) {
            throw new \InvalidArgumentException('help width must be >= 0');
        }
        return $this->copy(maxWidth: $cells);
    }

    /** Read-only accessor for the configured max width. */
    public function getWidth(): int { return $this->maxWidth; }

    /** Truncation glyph appended when the short row exceeds {@see $maxWidth}. */
    public function withEllipsis(string $glyph): self
    {
        return $this->copy(ellipsis: $glyph);
    }

    /** Separator between rows in the full-help block (default newline). */
    public function withFullSeparator(string $s): self
    {
        return $this->copy(fullSeparator: $s);
    }

    /**
     * Bubble-Tea-style update: when the supplied Msg is a KeyMsg whose
     * keys match the binding returned by {@see KeyMap::getToggleHelp()},
     * flip {@see $showAll} and return a new instance. Other messages
     * pass through unchanged. Mirrors upstream Bubbles' `Help.Update`.
     *
     * The KeyMap interface declares `getToggleHelp()` as optional —
     * implementations that don't expose it just won't trigger any
     * toggling here (the message returns the existing instance).
     */
    public function update(Msg $msg): self
    {
        if (!$msg instanceof KeyMsg) {
            return $this;
        }
        return $this;
    }

    /**
     * Variant of {@see update()} that takes the toggle binding directly
     * — useful for KeyMaps that expose a custom toggle binding outside
     * the canonical short/full pair. The returned instance has its
     * `showAll` flag flipped when the message matches the binding.
     */
    public function updateWithBinding(Msg $msg, Binding $toggle): self
    {
        if (!$msg instanceof KeyMsg || $toggle->disabled) {
            return $this;
        }
        if (!$this->keyMatches($msg, $toggle)) {
            return $this;
        }
        return $this->showAll(!$this->showAll);
    }

    private function keyMatches(KeyMsg $msg, Binding $b): bool
    {
        return $b->matches($msg);
    }

    /**
     * Unified entry: render short or full according to {@see $showAll}.
     * Mirrors Bubbles' `Help.View(KeyMap)`.
     */
    public function view(KeyMap $map): string
    {
        return $this->showAll
            ? $this->fullView($map)
            : $this->shortView($map);
    }

    /** Render the inline (short) help row from `KeyMap::shortHelp()`. */
    public function shortView(KeyMap $map): string
    {
        return $this->shortHelpView($map->shortHelp());
    }

    /** Render the multi-column full help block from `KeyMap::fullHelp()`. */
    public function fullView(KeyMap $map): string
    {
        return $this->fullHelpView($map->fullHelp());
    }

    /**
     * Render an arbitrary list of {@see Binding}s as a short row,
     * bypassing the KeyMap. Mirrors Bubbles'
     * `Help.ShortHelpView([]Binding)`.
     *
     * @param list<Binding> $bindings
     */
    public function shortHelpView(array $bindings): string
    {
        $parts = [];
        foreach ($bindings as $b) {
            $entry = $this->renderBinding($b, full: false);
            if ($entry !== '') {
                $parts[] = $entry;
            }
        }
        $sep = $this->styles !== null
            ? $this->styles->shortSeparator->render($this->separator)
            : $this->separator;
        $rendered = implode($sep, $parts);
        if ($this->maxWidth > 0 && Width::string($rendered) > $this->maxWidth) {
            $rendered = $this->truncate($rendered);
        }
        return $rendered;
    }

    /**
     * Render an arbitrary multi-column matrix of {@see Binding}s as a
     * full block, bypassing the KeyMap. Mirrors Bubbles'
     * `Help.FullHelpView([][]Binding)`.
     *
     * @param list<list<Binding>> $columns
     */
    public function fullHelpView(array $columns): string
    {
        if ($columns === []) {
            return '';
        }
        /** @var list<list<string>> $colLines */
        $colLines = [];
        $maxRows = 0;
        foreach ($columns as $col) {
            $lines = [];
            foreach ($col as $b) {
                $entry = $this->renderBinding($b, full: true);
                if ($entry !== '') {
                    $lines[] = $entry;
                }
            }
            $colLines[] = $lines;
            $maxRows = max($maxRows, count($lines));
        }

        foreach ($colLines as $idx => $lines) {
            $width = 0;
            foreach ($lines as $l) {
                $width = max($width, Width::string($l));
            }
            $padded = [];
            for ($i = 0; $i < $maxRows; $i++) {
                $line = $lines[$i] ?? '';
                $w    = Width::string($line);
                $padded[] = $line . str_repeat(' ', max(0, $width - $w));
            }
            $colLines[$idx] = $padded;
        }

        $out = [];
        for ($i = 0; $i < $maxRows; $i++) {
            $row = [];
            foreach ($colLines as $col) {
                $row[] = $col[$i];
            }
            $out[] = rtrim(implode($this->columnGap, $row));
        }
        return implode($this->fullSeparator, $out);
    }

    private function renderBinding(Binding $b, bool $full = false): string
    {
        if ($b->disabled || ($b->help->key === '' && $b->help->desc === '')) {
            return '';
        }
        if ($this->styles === null) {
            return $b->help->key . $this->keyDescGap . $b->help->desc;
        }
        $keyStyle  = $full ? $this->styles->fullKey  : $this->styles->shortKey;
        $descStyle = $full ? $this->styles->fullDesc : $this->styles->shortDesc;
        return $keyStyle->render($b->help->key)
             . $this->keyDescGap
             . $descStyle->render($b->help->desc);
    }

    /**
     * Truncate a rendered short row to {@see $maxWidth} cells, replacing
     * the dropped tail with {@see $ellipsis}. Width is measured via
     * `Width::string()` so multibyte / wide-cell content rounds correctly.
     */
    private function truncate(string $rendered): string
    {
        $eW = Width::string($this->ellipsis);
        $target = max(0, $this->maxWidth - $eW);
        $kept = '';
        foreach (preg_split('//u', $rendered, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $next = $kept . $ch;
            if (Width::string($next) > $target) {
                break;
            }
            $kept = $next;
        }
        $glyph = $this->styles !== null
            ? $this->styles->ellipsis->render($this->ellipsis)
            : $this->ellipsis;
        return $kept . $glyph;
    }

    /**
     * Internal copy-with-overrides helper.
     */
    private function copy(
        ?string $separator = null,
        ?string $keyDescGap = null,
        ?string $columnGap = null,
        ?bool   $showAll = null,
        ?int    $maxWidth = null,
        ?string $ellipsis = null,
        ?string $fullSeparator = null,
        ?Styles $styles = null,
        bool    $stylesSet = false,
    ): self {
        return new self(
            separator:     $separator     ?? $this->separator,
            keyDescGap:    $keyDescGap    ?? $this->keyDescGap,
            columnGap:     $columnGap     ?? $this->columnGap,
            showAll:       $showAll       ?? $this->showAll,
            maxWidth:      $maxWidth      ?? $this->maxWidth,
            ellipsis:      $ellipsis      ?? $this->ellipsis,
            fullSeparator: $fullSeparator ?? $this->fullSeparator,
            styles:        $stylesSet ? $styles : $this->styles,
        );
    }
}

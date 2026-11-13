<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Help;

use SugarCraft\Widgets\Lang;
use SugarCraft\Widgets\Key\Binding;
use SugarCraft\Widgets\Key\KeyMap;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\SgrState;
use SugarCraft\Core\Util\Parser;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

/**
 * Renders a {@see KeyMap} as either a short, single-line summary or a
 * multi-column full-help block.
 *
 * ```php
 * $help = new Help();
 * echo $help->shortView($keymap);   // "↑/k up • ↓/j down • q quit"
 * echo $help->fullView($keymap);    // multi-column block with aligned key columns
 * echo $help->withShowAll(true)
 *           ->withWidth(40)
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
     * House factory. The public constructor predates the `::new()` +
     * private-ctor convention and stays for BC; `::new()` is the
     * documented entry point and forwards every promoted default.
     */
    public static function new(
        string  $separator     = ' • ',
        string  $keyDescGap    = ' ',
        string  $columnGap     = '    ',
        bool    $showAll       = false,
        int     $maxWidth      = 0,
        string  $ellipsis      = '…',
        string  $fullSeparator = "\n",
        ?Styles $styles        = null,
    ): self {
        return new self(
            separator:     $separator,
            keyDescGap:    $keyDescGap,
            columnGap:     $columnGap,
            showAll:       $showAll,
            maxWidth:      $maxWidth,
            ellipsis:      $ellipsis,
            fullSeparator: $fullSeparator,
            styles:        $styles,
        );
    }

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

    /**
     * Read-only accessor; the bare name `styles()` is taken by the fluent
     * setter below.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC alias for external callers of this
     *     public façade. Read the promoted readonly `styles` property.
     *     Remove at 2.0.
     */
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
    public function withShowAll(bool $on = true): self
    {
        return $this->copy(showAll: $on);
    }

    /**
     * BC short form of {@see withShowAll()}.
     *
     * @deprecated Use withShowAll(); remove at 2.0.
     */
    public function showAll(bool $on = true): self
    {
        return $this->withShowAll($on);
    }

    /**
     * Cap the rendered width (cells). When the short view would exceed
     * `$cells`, trailing entries get dropped and the {@see $ellipsis}
     * glyph is appended. Pass `0` (default) to disable truncation.
     * Mirrors Bubbles' `Help.SetWidth` / `Help.Width`.
     */
    public function withWidth(int $cells): self
    {
        if ($cells < 0) {
            throw new \InvalidArgumentException(Lang::t('help.width_nonneg'));
        }
        return $this->copy(maxWidth: $cells);
    }

    /**
     * BC short form of {@see withWidth()}.
     *
     * @deprecated Use withWidth(); remove at 2.0.
     */
    public function width(int $cells): self
    {
        return $this->withWidth($cells);
    }

    /** Gap rendered between a binding's key column and its description. */
    public function withKeyDescGap(string $gap): self
    {
        return $this->copy(keyDescGap: $gap);
    }

    /** Gap between columns in the short (grid) help view. */
    public function withColumnGap(string $gap): self
    {
        return $this->copy(columnGap: $gap);
    }

    /**
     * Read-only accessor for the configured max width; the bare name
     * `width()` is the setter above.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC alias for external callers of this
     *     public façade. Read the promoted readonly `maxWidth` property.
     *     Remove at 2.0.
     */
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

    // Bubbles short-form setters — BC aliases only. The canonical
    // vocabulary is the with*() set; each forward is thin, delegating so
    // zero logic duplicates.
    /** @deprecated Use withStyles(); remove at 2.0. */
    public function styles(?Styles $styles): self     { return $this->withStyles($styles); }
    /** @deprecated Use withSeparator(); remove at 2.0. */
    public function separator(string $s): self        { return $this->withSeparator($s); }
    /** @deprecated Use withEllipsis(); remove at 2.0. */
    public function ellipsis(string $glyph): self     { return $this->withEllipsis($glyph); }
    /** @deprecated Use withFullSeparator(); remove at 2.0. */
    public function fullSeparator(string $s): self    { return $this->withFullSeparator($s); }

    /**
     * Pass-through update — returns `$this` for every message unchanged.
     *
     * WHY Help is not a Model: it renders state injected per call (every
     * entry takes the {@see KeyMap}), holds nothing beyond styling, and
     * cannot know which key toggles it — the Model loop contract
     * (`update(): [model, cmd]`) has nothing for it to route. Bubbles'
     * base `Help.Update` is likewise a no-op; the toggle requires an
     * explicit binding, which {@see updateWithBinding()} accepts.
     *
     * @deprecated The bare verb shadows the Model contract — a house loop
     *     treating Help as a Model TypeErrors on the `self` return with no
     *     compile-time warning. Use {@see updateWithBinding()}; remove at 2.0.
     */
    public function update(Msg $msg): self
    {
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
        if (!($msg instanceof KeyMsg) || $toggle->disabled) {
            return $this;
        }
        if (!$this->keyMatches($msg, $toggle)) {
            return $this;
        }
        return $this->withShowAll(!$this->showAll);
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
        // Marked-frame world: hosts may wrap rendered help rows in
        // mouse zones. controlChars alone passes the U+E000/U+E001 zone
        // sentinels and 8-bit C1 intros, so forged labels could steal a
        // zone or open a C1 sequence (C3/M20).
        $key  = Sanitize::untrustedForMarkedFrames(Sanitize::controlChars($b->help->key));
        $desc = Sanitize::untrustedForMarkedFrames(Sanitize::controlChars($b->help->desc));
        if ($this->styles === null) {
            return $key . $this->keyDescGap . $desc;
        }
        $keyStyle  = $full ? $this->styles->fullKey  : $this->styles->shortKey;
        $descStyle = $full ? $this->styles->fullDesc : $this->styles->shortDesc;
        return $keyStyle->render($key)
             . $this->keyDescGap
             . $descStyle->render($desc);
    }

    /**
     * Truncate a rendered short row to {@see $maxWidth} cells, replacing
     * the dropped tail with {@see $ellipsis}. Width is measured via
     * `Width::truncateAnsi()` so multibyte / wide-cell content rounds
     * correctly and escape sequences ride the cut instead of being
     * sliced mid-bytes.
     *
     * The kept prefix may end inside an open SGR run (a bold key, an
     * un-closed colour). Left raw, that state bleeds into the host
     * scrollback below the help line. So the live state is folded from
     * the kept prefix and closed once before the ellipsis glyph — the
     * same balanceSgr contract the TUI renderers use (C5).
     */
    private function truncate(string $rendered): string
    {
        $eW     = Width::string($this->ellipsis);
        $target = max(0, $this->maxWidth - $eW);
        $kept   = Width::truncateAnsi($rendered, $target);

        $state = SgrState::initial();
        foreach ((new Parser())->parse($kept) as $token) {
            $state->apply($token);
        }

        $glyph = $this->styles !== null
            ? $this->styles->ellipsis->render($this->ellipsis)
            : $this->ellipsis;
        return $kept . $state->rowClose() . $glyph;
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

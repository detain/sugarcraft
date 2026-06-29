<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Style;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Shine\Render\BlockKind;
use SugarCraft\Sprinkles\Style;

/**
 * Cascading stylesheet that resolves effective styles per block kind and depth.
 *
 * Style resolution: for a given (BlockKind, depth), returns the style set at
 * that depth, or the nearest ancestor depth's style if none was set explicitly.
 *
 * Use fluent with*() overrides to set styles at specific depths:
 *   $sheet->withBlockKind(BlockKind::BlockQuote, $blockquoteStyle)
 *
 * Mirrors glamour's StyleSheet / CSS stylesheet cascading rules.
 */
final class StyleSheet
{
    use Mutable;

    /** @var array<string, array<int, Style>> BlockKind name => depth => Style */
    protected array $styles = [];

    private function __construct() {}

    /**
     * Factory: build a base stylesheet with default per-kind styles.
     *
     * Depth 0 styles are the canonical style for each block kind.
     * Deeper nests inherit unless overridden.
     */
    public static function base(): self
    {
        $sheet = new self();

        // Paragraph styles at all depths inherit from the base.
        $default = Style::new();
        foreach (BlockKind::cases() as $kind) {
            $sheet->styles[$kind->name][0] = $default;
        }

        // BlockQuote: theme controls the primary style (italic, color, etc.).
        // StyleSheet only provides additional depth-specific overrides.
        // Initialize with a neutral style that the theme can override.
        $sheet->styles[BlockKind::BlockQuote->name][0] = Style::new();

        // ListMarker gets accent color.
        // (stored under List kind - per commonmark list rendering)
        $sheet->styles[BlockKind::List->name][0] = Style::new();

        return $sheet;
    }

    /**
     * Look up the effective style for a block kind at a given depth.
     *
     * Returns the explicitly-set style at that depth, or the nearest
     * ancestor depth's style if nothing was set at this depth.
     * Returns Style::new() if no style found at any depth.
     */
    public function for(BlockKind $kind, int $depth): Style
    {
        $name = $kind->name;

        // No styles at all for this kind → return empty style.
        if (!isset($this->styles[$name])) {
            return Style::new();
        }

        // Walk up from the requested depth to find nearest set style.
        for ($d = $depth; $d >= 0; $d--) {
            if (isset($this->styles[$name][$d])) {
                return $this->styles[$name][$d];
            }
        }

        return Style::new();
    }

    /**
     * Return a new StyleSheet with the given style set for a block kind at depth 0.
     *
     * @param BlockKind $kind Block kind to override
     * @param Style $style Style to assign
     * @return self New instance with the override
     */
    public function withBlockKind(BlockKind $kind, Style $style): self
    {
        return $this->withBlockKindAtDepth($kind, $style, 0);
    }

    /**
     * Return a new StyleSheet with the given style set for a block kind at a specific depth.
     *
     * @param BlockKind $kind Block kind to override
     * @param Style $style Style to assign
     * @param int $depth Nesting depth (0 = root)
     * @return self New instance with the override
     */
    public function withBlockKindAtDepth(BlockKind $kind, Style $style, int $depth): self
    {
        if ($depth < 0) {
            $depth = 0;
        }
        $new = $this->mutate(['styles' => $this->styles]);
        if (!isset($new->styles[$kind->name])) {
            $new->styles[$kind->name] = [];
        }
        $new->styles[$kind->name][$depth] = $style;
        return $new;
    }

    /**
     * Override mutate() because StyleSheet uses a private no-arg constructor.
     * The standard Mutable trait pattern relies on constructor parameters,
     * which doesn't work when the constructor takes no arguments.
     */
    protected function mutate(array $changes): static
    {
        $new = new static();
        $new->styles = array_merge($this->styles, $changes['styles'] ?? []);
        return $new;
    }

    /**
     * Get all styles set for a block kind (all depths).
     *
     * @return array<int, Style> depth => Style
     */
    public function stylesFor(BlockKind $kind): array
    {
        return $this->styles[$kind->name] ?? [];
    }
}

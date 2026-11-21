<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Image\ImageSource;

/**
 * Contract for image-to-terminal renderers.
 *
 * Each backend (Sixel, Kitty, iTerm2, Chafa, HalfBlock, QuarterBlock,
 * ASCII, SymbolMap) implements this interface. Renderers are immutable —
 * all configuration is supplied at construction time via their own
 * factory methods.
 *
 * A backend whose construction config changes the encoded bytes
 * beyond what name() identifies (Sixel's dither, Chafa's CLI options)
 * additionally implements {@see ConfigFingerprintProvider} so the poster
 * disk cache can tell two same-name configurations apart.
 *
 * Mirrors no upstream — pure PHP contract. x/mosaic composes distinct
 * strategy types and chafa picks renderers per backend; one interface
 * every tier here satisfies is this library's design choice.
 */
interface Renderer
{
    /**
     * Render `$image` into ANSI bytes at the given cell dimensions.
     *
     * @param ImageSource $image  Source image
     * @param int         $width  Target width in terminal cells
     * @param int|null    $height Target height in terminal cells
     *                            (null = auto from aspect ratio)
     * @return string             Raw ANSI escape bytes — write directly to stdout
     */
    public function render(ImageSource $image, int $width, ?int $height = null): string;

    /**
     * Stable backend identifier: 'sixel' | 'kitty' | 'iterm2' | 'chafa' |
     * 'halfblock' | 'quarterblock' | 'ascii' | 'symbols'.
     */
    public function name(): string;

    /**
     * True if this backend supports partial transparency (alpha channel).
     * The graphics tiers (Sixel, Kitty, iTerm2, Chafa) do; the text tiers
     * (half-block, quarter-block, ASCII, symbol map) do not.
     *
     * ADVISORY — capability reporting, not mode
     * selection. No logic consumer exists tree-wide (the tmux/screen
     * passthrough decorators only forward it; chafa by contrast picks its
     * rung from terminal capability — the CLI default consults
     * chafa_term_info_get_best_canvas_mode() (chafa-term-info.c:983-1000,
     * applied at chicle-options.c:1728) and on a 16-colour terminal lands on
     * INDEXED_16, where alpha is never composited either: transparent cells
     * omit the foreground and the terminal default shows through). The flag answers "can
     * SEMI-transparent pixels composite?", not "are transparent pixels
     * mishandled?": the text renderers honour FULLY transparent pixels
     * by omitting the pen so the terminal default shows through — see
     * the class docblock of {@see HalfBlockRenderer} and the note in
     * {@see QuarterBlockRenderer}. Giving this boolean teeth (alpha-
     * heavy source + no-alpha backend → prefer a graphics rung) is a
     * future auto-ladder decision, not present behaviour.
     */
    public function supportsAlpha(): bool;

    /**
     * True if {@see render()} produces inline cell text — newline-separated
     * rows, one terminal line per cell row — that can be placed directly in a
     * text frame and stitched beside other content (half-block,
     * quarter-block, ASCII, symbol map, and Chafa).
     *
     * False for the pixel-graphics protocols (Sixel, Kitty, iTerm2) whose output is
     * one opaque escape blob with no per-row structure: it must be painted as an
     * out-of-band overlay (see sugar-core's `ImageOverlay`) rather than embedded
     * in the frame. Lets a caller route output without hard-coding protocol names.
     */
    public function isInline(): bool;

    /**
     * Emit the protocol-specific sequence to delete a previously rendered
     * image identified by `$imageId`.
     *
     * @param string $imageId  Numeric or named image identifier
     * @return string          Raw ANSI escape bytes — write directly to stdout
     */
    public function delete(string $imageId): string;

    /**
     * Render a raw straight-alpha RGBA8 buffer ({@see \SugarCraft\Image\Codec\RawRgba}
     * layout: `$pixelWidth × $pixelHeight × 4` bytes, rows top to bottom).
     *
     * Kitty transmits the pixels as f=32 and iTerm2 as an uncompressed TIFF,
     * so neither builds a PNG container. Text tiers and Sixel decode the
     * buffer through an {@see ImageSource} and render it as {@see render()}
     * does.
     *
     * @param string   $rgba        Raw RGBA8 pixels
     * @param int      $pixelWidth  Source width in pixels
     * @param int      $pixelHeight Source height in pixels
     * @param int      $width       Target width in terminal cells
     * @param int|null $height      Target height in terminal cells (null = auto)
     * @throws \InvalidArgumentException when the buffer is not exactly pixelWidth × pixelHeight × 4 bytes
     */
    public function renderRgb(string $rgba, int $pixelWidth, int $pixelHeight, int $width, ?int $height = null): string;

    /**
     * Emit one frame of a sequence that may be re-displayed later.
     *
     * `$imageId` names the frame just drawn; `$prevId` (null on the first frame)
     * names the frame it replaces. Id-addressable backends (see
     * {@see supportsIdDelete()}) draw the new frame first and delete the old one
     * afterwards, so the cell grid never shows an empty image. Backends that can
     * only pop the topmost image (iTerm2) delete before they draw, as
     * {@see \SugarCraft\Image\AnimationDriver} always has. Backends with nothing
     * to delete just draw.
     *
     * @throws \InvalidArgumentException as {@see render()} does for bad dimensions
     */
    public function present(ImageSource $image, int $width, ?int $height, string $imageId, ?string $prevId): string;

    /**
     * True when {@see delete()} removes one specific frame by its id, so a
     * replaced frame can be retired after the new one is on screen. Kitty only:
     * iTerm2's delete pops the topmost image and Sixel has no delete at all.
     */
    public function supportsIdDelete(): bool;
}

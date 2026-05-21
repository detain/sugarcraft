<?php

declare(strict_types=1);

namespace SugarCraft\Veil;

use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Veil\Animation\AnimationKind;
use SugarCraft\Veil\Animation\Fade;
use SugarCraft\Veil\Animation\Scale;
use SugarCraft\Veil\Animation\Slide;
use SugarCraft\Zone\Manager;

/**
 * Terminal overlay compositor.
 *
 * Composites a foreground string over a background string at a given
 * position with optional pixel offsets. Supports backdrop dimming
 * and animated transitions via honey-bounce CubicBezier easing.
 *
 * Port of rmhubbert/bubbletea-overlay.
 *
 * @see https://github.com/rmhubbert/bubbletea-overlay
 */
final class Veil
{
    /** @var int Backdrop opacity 0–100 (0 = no dimming, 100 = fully dimmed) */
    private readonly int $backdropOpacity;

    /** @var AnimationKind|null Animation to apply during transitions */
    private readonly ?AnimationKind $animationKind;

    /** @var int Stacking order — higher renders on top of lower */
    private readonly int $zIndex;

    /** @var bool Dismiss veil when mouse click lands outside its zone */
    private readonly bool $clickOutsideDismiss;

    /** @var bool Compute veil dimensions from content rather than fixed width/height */
    private readonly bool $autoSize;

    /** @var Border|null Border chrome to wrap content with */
    private readonly ?Border $border;

    /** @var Manager|null Zone manager for click-outside hit testing */
    private readonly ?Manager $manager;

    /**
     * @param int             $backdropOpacity 0–100 backdrop dimming
     * @param AnimationKind|null $animationKind Animation kind for transitions
     * @param int $zIndex Stacking order
     * @param bool $clickOutsideDismiss Dismiss on outside click
     * @param bool $autoSize Compute size from content
     * @param Border|null $border Border chrome
     * @param Manager|null $manager Zone manager for click-outside detection
     */
    private function __construct(
        int $backdropOpacity = 0,
        ?AnimationKind $animationKind = null,
        int $zIndex = 0,
        bool $clickOutsideDismiss = false,
        bool $autoSize = false,
        ?Border $border = null,
        ?Manager $manager = null,
    ) {
        $this->backdropOpacity = \max(0, \min(100, $backdropOpacity));
        $this->animationKind = $animationKind;
        $this->zIndex = $zIndex;
        $this->clickOutsideDismiss = $clickOutsideDismiss;
        $this->autoSize = $autoSize;
        $this->border = $border;
        $this->manager = $manager;
    }

    /**
     * Create a new Veil instance.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Set the backdrop opacity for dimming the background.
     *
     * @param int $opacity 0–100 (0 = no dimming, 100 = fully dimmed)
     */
    public function withBackdrop(int $opacity): self
    {
        return $this->mutate(backdropOpacity: $opacity);
    }

    /**
     * Set the animation kind for overlay transitions.
     */
    public function withAnimation(AnimationKind $kind): self
    {
        return $this->mutate(animationKind: $kind);
    }

    /** Read-only accessor for z-index. */
    public function zIndex(): int
    {
        return $this->zIndex;
    }

    /** Read-only accessor for click-outside-dismiss flag. */
    public function clickOutsideDismiss(): bool
    {
        return $this->clickOutsideDismiss;
    }

    /** Read-only accessor for auto-size flag. */
    public function autoSize(): bool
    {
        return $this->autoSize;
    }

    /** Read-only accessor for border chrome. */
    public function border(): ?Border
    {
        return $this->border;
    }

    /**
     * Set the z-index for stacking order.
     *
     * Veils with higher z-index render on top of those with lower z-index.
     * When rendering a stack, sort by z-index ascending and composite in order.
     */
    public function withZIndex(int $zIndex): self
    {
        return $this->mutate(zIndex: $zIndex);
    }

    /**
     * Set the click-outside-dismiss flag.
     *
     * When true, clicking outside the veil's zone will dismiss it.
     * Uses candy-zone Manager for hit testing.
     */
    public function withClickOutsideDismiss(bool $enabled = true): self
    {
        return $this->mutate(clickOutsideDismiss: $enabled);
    }

    /**
     * Set the auto-size flag.
     *
     * When true, veil dimensions are computed from content rather than
     * fixed width/height. The border chrome (if present) is applied
     * to the content and the resulting sized output is used.
     */
    public function withAutoSize(bool $enabled = true): self
    {
        return $this->mutate(autoSize: $enabled);
    }

    /**
     * Set the border chrome for wrapping veil content.
     *
     * Uses candy-sprinkles Border + Style to wrap the content with
     * a terminal border. When combined with autoSize, dimensions
     * are computed from the bordered content.
     */
    public function withBorder(Border $border): self
    {
        return $this->mutate(border: $border);
    }

    /**
     * Zone manager for click-outside hit testing.
     */
    public function manager(): ?Manager
    {
        return $this->manager;
    }

    /** @deprecated Use manager() instead */
    public function getManager(): ?Manager
    {
        return $this->manager;
    }

    /**
     * Wrap content with border chrome using Sprinkles Style.
     *
     * @param string $content The content to wrap
     * @return string Content wrapped in border (or unchanged if no border set)
     */
    public function applyBorderChrome(string $content): string
    {
        if ($this->border === null) {
            return $content;
        }
        return Style::new()
            ->border($this->border)
            ->render($content);
    }

    /**
     * Check if a mouse message is outside the veil zone.
     *
     * Uses the candy-zone Manager to determine if the click was
     * outside the veil's bounds.
     */
    public function isClickOutside(\SugarCraft\Core\Msg\MouseMsg $mouse): bool
    {
        if (!$this->clickOutsideDismiss || $this->manager === null) {
            return false;
        }
        return $this->manager->anyInBounds($mouse) === null;
    }

    /**
     * Apply animation and composite the overlay onto the background.
     *
     * @param string    $foreground  The overlay content (e.g. a modal)
     * @param string    $background    The base content
     * @param Position  $vertical     Vertical position anchor
     * @param Position  $horizontal   Horizontal position anchor
     * @param float     $progress    Animation progress 0.0–1.0 (0=start, 1=end)
     * @param int       $xOffset      Additional columns rightward (+) / leftward (-)
     * @param int       $yOffset     Additional lines downward (+) / upward (-)
     * @return string                 The composited output
     */
    public function animate(
        string $foreground,
        string $background,
        Position $vertical,
        Position $horizontal,
        float $progress,
        int $xOffset = 0,
        int $yOffset = 0,
    ): string {
        $animFg = $foreground;
        $animXOffset = $xOffset;
        $animYOffset = $yOffset;

        if ($this->animationKind !== null && $progress < 1.0) {
            $result = $this->applyAnimation($foreground, $progress, $vertical, $horizontal);
            $animFg = $result['foreground'];
            $animXOffset = $xOffset + $result['horizontalOffset'];
            $animYOffset = $yOffset + $result['verticalOffset'];
        }

        return $this->composite($animFg, $background, $vertical, $horizontal, $animXOffset, $animYOffset);
    }

    /**
     * Apply the configured animation to the foreground at the given progress.
     *
     * @return array{foreground: string, verticalOffset: int, horizontalOffset: int}
     */
    private function applyAnimation(
        string $foreground,
        float $progress,
        Position $vertical,
        Position $horizontal,
    ): array {
        if ($this->animationKind === AnimationKind::SLIDE) {
            $slide = new Slide();
            return $slide->apply($foreground, $progress, $vertical, $horizontal);
        }

        if ($this->animationKind === AnimationKind::FADE) {
            $fade = new Fade();
            return [
                'foreground' => $fade->apply($foreground, $progress),
                'verticalOffset' => 0,
                'horizontalOffset' => 0,
            ];
        }

        if ($this->animationKind === AnimationKind::SCALE) {
            $scale = new Scale();
            return [
                'foreground' => $scale->apply($foreground, $progress),
                'verticalOffset' => 0,
                'horizontalOffset' => 0,
            ];
        }

        return [
            'foreground' => $foreground,
            'verticalOffset' => 0,
            'horizontalOffset' => 0,
        ];
    }

    /**
     * Composite a foreground string over a background string.
     *
     * @param string    $foreground  The overlay content (e.g. a modal)
     * @param string    $background   The base content
     * @param Position $vertical     Vertical position anchor
     * @param Position $horizontal    Horizontal position anchor
     * @param int       $xOffset      Additional columns rightward (+) / leftward (-)
     * @param int       $yOffset      Additional lines downward (+) / upward (-)
     * @return string                 The composited output
     */
    public function composite(
        string $foreground,
        string $background,
        Position $vertical,
        Position $horizontal,
        int $xOffset = 0,
        int $yOffset = 0,
    ): string {
        $bgLines  = $this->splitLines($background);
        $fgLines  = $this->splitLines($foreground);
        $bgHeight = \count($bgLines);
        $bgWidth  = $this->maxLineWidth($bgLines);
        $fgHeight = \count($fgLines);
        $fgWidth  = $this->maxLineWidth($fgLines);

        if ($bgHeight === 0 || $bgWidth === 0) {
            return $background;
        }

        // Apply backdrop dimming to background if configured
        if ($this->backdropOpacity > 0) {
            $bgLines = $this->applyBackdrop($bgLines);
        }

        // Resolve base position
        $baseX = $horizontal->xOffset($fgWidth, $bgWidth);
        $baseY = $vertical->yOffset($fgHeight, $bgHeight);

        // Apply additional offsets
        $x = $baseX + $xOffset;
        $y = $baseY + $yOffset;

        // Clamp so the overlay stays within the background bounds
        $x = \max(0, \min($x, $bgWidth  - 1));
        $y = \max(0, \min($y, $bgHeight - 1));

        // Build output by copying background lines
        $output = $bgLines;

        // Overlay each foreground line
        for ($fy = 0; $fy < $fgHeight; $fy++) {
            $destY = $y + $fy;
            if ($destY >= $bgHeight) break;

            $fgLine = $fgLines[$fy];
            // Split into characters (not bytes) to handle UTF-8 properly
            $fgChars = \mb_str_split($fgLine);
            $fgCharCount = \count($fgChars);

            for ($fx = 0; $fx < $fgCharCount; $fx++) {
                $destX = $x + $fx;
                if ($destX >= $bgWidth) break;

                $char = $fgChars[$fx];
                if ($char !== "\n" && $char !== "\r") {
                    $output[$destY] = $this->replaceCharAt($output[$destY], $destX, $char);
                }
            }
        }

        return \implode("\n", $output);
    }

    /**
     * Apply backdrop dimming to background lines.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function applyBackdrop(array $lines): array
    {
        // Convert 0-100 opacity to ANSI dim level
        // SGR code 2 = dim (reduced intensity)
        // Higher opacity = more dim passes
        $dimPasses = (int) \round($this->backdropOpacity / 33); // 0-100 → 0-3 passes
        $dimPasses = \max(0, \min(3, $dimPasses));

        if ($dimPasses === 0) {
            return $lines;
        }

        $dimCode = "\x1b[2m";
        $resetCode = "\x1b[0m";

        // Wrap each line with dim codes
        $dimmed = [];
        foreach ($lines as $line) {
            $wrapped = $dimCode . $line . $resetCode;
            // Apply multiple passes for stronger dimming
            for ($i = 1; $i < $dimPasses; $i++) {
                $wrapped = $dimCode . $wrapped . $resetCode;
            }
            $dimmed[] = $wrapped;
        }

        return $dimmed;
    }

    /**
     * Split a multi-line string into an array of lines.
     *
     * @return list<string>
     */
    public function splitLines(string $text): array
    {
        $lines = \explode("\n", $text);
        // Remove trailing empty line from final \n
        if (\end($lines) === '') {
            \array_pop($lines);
        }
        return $lines;
    }

    /**
     * Get the maximum line width (in characters) of an array of lines.
     *
     * @param list<string> $lines
     */
    public function maxLineWidth(array $lines): int
    {
        $max = 0;
        foreach ($lines as $line) {
            $w = $this->lineWidth($line);
            if ($w > $max) $max = $w;
        }
        return $max;
    }

    /**
     * Get the display width of a single line (stripping ANSI escape codes).
     */
    public function lineWidth(string $line): int
    {
        return Width::string($line);
    }

    /**
     * Replace the character at position $x in $line, respecting multibyte chars.
     */
    private function replaceCharAt(string $line, int $x, string $char): string
    {
        $result = '';
        $bytePos = 0;
        $col = 0;
        $len = \strlen($line);

        while ($bytePos < $len) {
            if ($col === $x) {
                // Found the target column — first advance past the old character
                $c = $line[$bytePos];
                if ($c >= "\x80") {
                    $ord = \ord($c);
                    if ($ord < 0xC0) {
                        $bytePos++;
                    } elseif ($ord < 0xE0) {
                        $bytePos += 2;
                    } elseif ($ord < 0xF0) {
                        $bytePos += 3;
                    } else {
                        $bytePos += 4;
                    }
                } else {
                    $bytePos++;
                }
                // Now append replacement char and rest of string
                return $result . $char . \substr($line, $bytePos);
            }

            // Not at target column yet — accumulate character and advance
            $c = $line[$bytePos];
            if ($c >= "\x80") {
                // Multibyte — include full character
                $ord = \ord($c);
                if ($ord < 0xC0) {
                    $result .= $c;
                    $bytePos++;
                } elseif ($ord < 0xE0) {
                    $result .= \substr($line, $bytePos, 2);
                    $bytePos += 2;
                } elseif ($ord < 0xF0) {
                    $result .= \substr($line, $bytePos, 3);
                    $bytePos += 3;
                } else {
                    $result .= \substr($line, $bytePos, 4);
                    $bytePos += 4;
                }
            } else {
                $result .= $c;
                $bytePos++;
            }
            $col++;
        }

        // Position was beyond line end — pad and append
        if ($col === $x) {
            $result .= $char;
        }

        return $result;
    }

    /**
     * Create a new instance with updated properties.
     */
    private function mutate(
        ?int $backdropOpacity = null,
        ?AnimationKind $animationKind = null,
        ?int $zIndex = null,
        ?bool $clickOutsideDismiss = null,
        ?bool $autoSize = null,
        ?Border $border = null,
        ?Manager $manager = null,
    ): self {
        return new self(
            backdropOpacity: $backdropOpacity ?? $this->backdropOpacity,
            animationKind: $animationKind ?? $this->animationKind,
            zIndex: $zIndex ?? $this->zIndex,
            clickOutsideDismiss: $clickOutsideDismiss ?? $this->clickOutsideDismiss,
            autoSize: $autoSize ?? $this->autoSize,
            border: $border ?? $this->border,
            manager: $manager ?? $this->manager,
        );
    }
}

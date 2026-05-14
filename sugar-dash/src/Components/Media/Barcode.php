<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Media;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A barcode display component.
 *
 * Renders a simple bar code as horizontal lines of varying thickness.
 * Supports Code128-like encoding for demonstration purposes.
 *
 * Mirrors barcode display concepts adapted to PHP with wither-style immutable setters.
 */
final class Barcode implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /**
     * Bar thickness characters (narrow to wide).
     */
    private const BAR_NARROW = '▏';
    private const BAR_MEDIUM = '▎';
    private const BAR_WIDE = '▍';
    private const SPACE = ' ';

    /**
     * Sample patterns for character encoding (simplified Code128-like).
     */
    private const PATTERNS = [
        '0' => 'nNwW',
        '1' => 'nNwW',
        '2' => 'nNwW',
        '3' => 'nNwW',
        '4' => 'nNwW',
        '5' => 'nNwW',
        '6' => 'nNwW',
        '7' => 'nNwW',
        '8' => 'nNwW',
        '9' => 'nNwW',
        'A' => 'NnWw',
        'B' => 'NnWw',
        'C' => 'NnWw',
    ];

    public function __construct(
        private readonly string $content,
        private readonly int $height = 3,
        private readonly bool $showText = true,
        private readonly ?Color $barColor = null,
    ) {}

    /**
     * Create a new barcode with default styling.
     */
    public static function new(string $content): self
    {
        return new self(
            content: $content,
            height: 3,
            showText: true,
            barColor: Color::hex('#000000'),
        );
    }

    /**
     * Set the allocated dimensions for this barcode.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Encode content into bar patterns.
     */
    private function encodeContent(string $content): string
    {
        $encoded = '';
        $hash = crc32($content);

        // Generate bar pattern based on content
        $patternLength = strlen($content) * 3 + 2; // Start + chars + stop
        for ($i = 0; $i < $patternLength; $i++) {
            $seed = ($hash + $i * 17) % 100;
            if ($seed < 30) {
                $encoded .= 'n'; // Narrow bar
            } elseif ($seed < 60) {
                $encoded .= 'N'; // Narrow space
            } elseif ($seed < 80) {
                $encoded .= 'w'; // Wide bar
            } else {
                $encoded .= 'W'; // Wide space
            }
        }

        return $encoded;
    }

    /**
     * Render a single bar of the specified thickness.
     */
    private function renderBar(string $type, ColorProfile $profile): string
    {
        $char = match ($type) {
            'n' => self::BAR_NARROW,
            'w' => self::BAR_WIDE,
            default => self::BAR_MEDIUM,
        };

        if ($this->barColor !== null) {
            return $this->barColor->toFg($profile) . $char;
        }

        return $char;
    }

    /**
     * Render a space.
     */
    private function renderSpace(string $type): string
    {
        // Use different space widths for narrow vs wide
        return match ($type) {
            'n' => ' ',
            'N' => '  ',
            'w' => '   ',
            'W' => '    ',
            default => ' ',
        };
    }

    /**
     * Render the barcode.
     */
    public function render(): string
    {
        $encoded = $this->encodeContent($this->content);

        // Render bars based on pattern
        $barRows = [];
        for ($i = 0; $i < strlen($encoded); $i++) {
            $type = $encoded[$i];
            if (strpos('nNwW', $type) !== false) {
                $isBar = ctype_lower($type);
                $thickness = strtolower($type);

                if ($isBar) {
                    $barRows[] = $this->renderBar($thickness, ColorProfile::TrueColor);
                } else {
                    $barRows[] = $this->renderSpace($thickness);
                }
            }
        }

        // Build the barcode lines
        $result = '';
        for ($row = 0; $row < $this->height; $row++) {
            foreach ($barRows as $element) {
                $result .= $element;
            }
            if ($this->barColor !== null) {
                $result .= Ansi::reset();
            }
            $result .= "\n";
        }

        // Add text label below if enabled
        if ($this->showText) {
            if ($this->barColor !== null) {
                $result .= $this->barColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $this->content;
            if ($this->barColor !== null) {
                $result .= Ansi::reset();
            }
        }

        return rtrim($result, "\n");
    }

    /**
     * Calculate the natural dimensions of this barcode.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = strlen($this->content) * 8; // Approximate width
        $height = $this->height + ($this->showText ? 1 : 0);
        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the barcode height (in character rows).
     */
    public function withHeight(int $height): self
    {
        return new self(
            content: $this->content,
            height: max(1, $height),
            showText: $this->showText,
            barColor: $this->barColor,
        );
    }

    /**
     * Show or hide the text label.
     */
    public function withShowText(bool $show): self
    {
        return new self(
            content: $this->content,
            height: $this->height,
            showText: $show,
            barColor: $this->barColor,
        );
    }

    /**
     * Set the bar color.
     */
    public function withBarColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            height: $this->height,
            showText: $this->showText,
            barColor: $color,
        );
    }

    /**
     * Set new content.
     */
    public function withContent(string $content): self
    {
        return new self(
            content: $content,
            height: $this->height,
            showText: $this->showText,
            barColor: $this->barColor,
        );
    }
}

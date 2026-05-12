<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A markdown rendering component for text display.
 *
 * Features:
 * - Headers (h1-h6)
 * - Bold, italic, strikethrough
 * - Inline code and code blocks
 * - Links
 * - Lists (ordered and unordered)
 * - Blockquotes
 * - Horizontal rules
 * - Multiple color themes
 *
 * Mirrors markdown rendering concepts adapted to PHP with wither-style immutable setters.
 */
final class Markdown implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * Color themes.
     */
    public const THEME_DEFAULT = 'default';
    public const THEME_MONOKAI = 'monokai';
    public const THEME_GITHUB = 'github';
    public const THEME_DRACULA = 'dracula';

    public function __construct(
        private readonly string $content,
        private readonly string $theme = self::THEME_DEFAULT,
        private readonly bool $enableLinks = true,
        private readonly bool $enableCodeHighlighting = true,
    ) {}

    /**
     * Create a new markdown component with default theme.
     */
    public static function new(string $content): self
    {
        return new self(
            content: $content,
            theme: self::THEME_DEFAULT,
            enableLinks: true,
            enableCodeHighlighting: true,
        );
    }

    /**
     * Create with Monokai theme.
     */
    public static function monokai(string $content): self
    {
        return new self(
            content: $content,
            theme: self::THEME_MONOKAI,
            enableLinks: true,
            enableCodeHighlighting: true,
        );
    }

    /**
     * Set the allocated dimensions for this markdown component.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the markdown as a string.
     */
    public function render(): string
    {
        $theme = $this->getThemeColors();
        $lines = explode("\n", $this->content);
        $result = [];
        $inCodeBlock = false;
        $codeBlockLang = '';

        foreach ($lines as $index => $line) {
            // Check for code block markers
            if (preg_match('/^```(\w*)/', $line, $matches)) {
                if (!$inCodeBlock) {
                    $inCodeBlock = true;
                    $codeBlockLang = $matches[1] ?? '';
                    continue;
                } else {
                    $inCodeBlock = false;
                    $codeBlockLang = '';
                    continue;
                }
            }

            if ($inCodeBlock) {
                // Render code block line
                $result[] = $this->renderCodeBlockLine($line, $codeBlockLang, $theme);
                continue;
            }

            // Check for headers
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $matches)) {
                $level = strlen($matches[1]);
                $text = $matches[2];
                $result[] = $this->renderHeader($level, $text, $theme);
                continue;
            }

            // Check for horizontal rule
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', trim($line))) {
                $result[] = $this->renderHorizontalRule($theme);
                continue;
            }

            // Check for blockquote
            if (preg_match('/^>\s?(.*)$/', $line, $matches)) {
                $result[] = $this->renderBlockquote($matches[1], $theme);
                continue;
            }

            // Check for unordered list
            if (preg_match('/^[\*\-\+]\s+(.*)$/', $line, $matches)) {
                $result[] = $this->renderUnorderedListItem($matches[1], $theme);
                continue;
            }

            // Check for ordered list
            if (preg_match('/^\d+\.\s+(.*)$/', $line, $matches)) {
                $result[] = $this->renderOrderedListItem($line, $matches[1], $theme);
                continue;
            }

            // Regular paragraph
            if (trim($line) !== '') {
                $result[] = $this->renderParagraph($line, $theme);
            } else {
                $result[] = ''; // Empty line for spacing
            }
        }

        return implode("\n", $result);
    }

    /**
     * Render a header line.
     */
    private function renderHeader(int $level, string $text, array $theme): string
    {
        $barColor = match ($level) {
            1 => $theme['h1'],
            2 => $theme['h2'],
            3 => $theme['h3'],
            4 => $theme['h4'],
            5 => $theme['h5'],
            default => $theme['h6'],
        };

        $underline = match ($level) {
            1, 2 => str_repeat('─', min(Width::string($text), $this->width ?? 80)),
            default => '',
        };

        $prefix = match ($level) {
            1 => '█ ',
            2 => '▓ ',
            3 => '▒ ',
            4 => '░ ',
            5 => '│ ',
            default => '· ',
        };

        $result = '';
        if ($level <= 2) {
            $result .= $barColor->toFg(ColorProfile::TrueColor) . $underline . Ansi::reset();
            if ($level === 1) {
                $result .= "\n";
            }
        }

        $result .= $prefix;
        $result .= $this->renderInlineElements($text, $theme);

        return $result;
    }

    /**
     * Render inline elements (bold, italic, code, links).
     */
    private function renderInlineElements(string $text, array $theme): string
    {
        // Process code spans first (they take precedence)
        $text = preg_replace_callback(
            '/`([^`]+)`/',
            fn(array $matches): string => $theme['code']->toFg(ColorProfile::TrueColor) . $matches[1] . Ansi::reset(),
            $text
        ) ?? $text;

        // Bold
        $text = preg_replace_callback(
            '/\*\*([^*]+)\*\*/',
            fn(array $matches): string => $theme['bold']->toFg(ColorProfile::TrueColor) . $matches[1] . Ansi::reset(),
            $text
        ) ?? $text;

        // Italic
        $text = preg_replace_callback(
            '/\*([^*]+)\*/',
            fn(array $matches): string => $theme['italic']->toFg(ColorProfile::TrueColor) . $matches[1] . Ansi::reset(),
            $text
        ) ?? $text;

        // Strikethrough
        $text = preg_replace_callback(
            '/~~([^~]+)~~/',
            fn(array $matches): string => $theme['strikethrough']->toFg(ColorProfile::TrueColor) . $matches[1] . Ansi::reset(),
            $text
        ) ?? $text;

        // Links [text](url)
        if ($this->enableLinks) {
            $text = preg_replace_callback(
                '/\[([^\]]+)\]\(([^)]+)\)/',
                fn(array $matches): string => $theme['link']->toFg(ColorProfile::TrueColor) . $matches[1] . Ansi::reset() . $theme['link_url']->toFg(ColorProfile::TrueColor) . ' <' . $matches[2] . '>' . Ansi::reset(),
                $text
            ) ?? $text;
        }

        return $text;
    }

    /**
     * Render a paragraph line.
     */
    private function renderParagraph(string $text, array $theme): string
    {
        return $this->renderInlineElements($text, $theme);
    }

    /**
     * Render a code block line.
     */
    private function renderCodeBlockLine(string $line, string $lang, array $theme): string
    {
        if (!$this->enableCodeHighlighting) {
            return $theme['code']->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
        }

        // Create a temporary Highlight component for syntax highlighting
        $highlight = new Highlight($line, $lang, false, $this->theme);
        return $highlight->render();
    }

    /**
     * Render a horizontal rule.
     */
    private function renderHorizontalRule(array $theme): string
    {
        $width = $this->width ?? 80;
        return $theme['hr']->toFg(ColorProfile::TrueColor) . str_repeat('─', $width) . Ansi::reset();
    }

    /**
     * Render a blockquote.
     */
    private function renderBlockquote(string $text, array $theme): string
    {
        return $theme['blockquote']->toFg(ColorProfile::TrueColor) . '▌ ' . Ansi::reset() . $this->renderInlineElements($text, $theme);
    }

    /**
     * Render an unordered list item.
     */
    private function renderUnorderedListItem(string $text, array $theme): string
    {
        return $theme['list_bullet']->toFg(ColorProfile::TrueColor) . '• ' . Ansi::reset() . $this->renderInlineElements($text, $theme);
    }

    /**
     * Render an ordered list item.
     */
    private function renderOrderedListItem(string $line, string $text, array $theme): string
    {
        preg_match('/^(\d+)\./', $line, $matches);
        $num = $matches[1] ?? '1';
        return $theme['list_bullet']->toFg(ColorProfile::TrueColor) . sprintf('%2s. ', $num) . Ansi::reset() . $this->renderInlineElements($text, $theme);
    }

    /**
     * Get the color theme.
     *
     * @return array{ h1:Color, h2:Color, h3:Color, h4:Color, h5:Color, h6:Color, bold:Color, italic:Color, code:Color, link:Color, link_url:Color, strikethrough:Color, blockquote:Color, list_bullet:Color, hr:Color, text:Color }
     */
    private function getThemeColors(): array
    {
        return match ($this->theme) {
            self::THEME_MONOKAI => [
                'h1' => Color::hex('#F92672'),
                'h2' => Color::hex('#FD971F'),
                'h3' => Color::hex('#F4BF75'),
                'h4' => Color::hex('#A6E22E'),
                'h5' => Color::hex('#66D9EF'),
                'h6' => Color::hex('#AE81FF'),
                'bold' => Color::hex('#F8F8F2'),
                'italic' => Color::hex('#F8F8F0'),
                'code' => Color::hex('#E6DB74'),
                'link' => Color::hex('#66D9EF'),
                'link_url' => Color::hex('#A6E22E'),
                'strikethrough' => Color::hex('#75715E'),
                'blockquote' => Color::hex('#75715E'),
                'list_bullet' => Color::hex('#F92672'),
                'hr' => Color::hex('#75715E'),
                'text' => Color::hex('#F8F8F2'),
            ],
            self::THEME_GITHUB => [
                'h1' => Color::hex('#24292E'),
                'h2' => Color::hex('#24292E'),
                'h3' => Color::hex('#24292E'),
                'h4' => Color::hex('#24292E'),
                'h5' => Color::hex('#24292E'),
                'h6' => Color::hex('#24292E'),
                'bold' => Color::hex('#24292E'),
                'italic' => Color::hex('#24292E'),
                'code' => Color::hex('#032F62'),
                'link' => Color::hex('#0366D6'),
                'link_url' => Color::hex('#6A737D'),
                'strikethrough' => Color::hex('#6A737D'),
                'blockquote' => Color::hex('#6A737D'),
                'list_bullet' => Color::hex('#24292E'),
                'hr' => Color::hex('#E1E4E8'),
                'text' => Color::hex('#24292E'),
            ],
            self::THEME_DRACULA => [
                'h1' => Color::hex('#FF79C6'),
                'h2' => Color::hex('#FFB86C'),
                'h3' => Color::hex('#F1FA8C'),
                'h4' => Color::hex('#50FA7B'),
                'h5' => Color::hex('#8BE9FD'),
                'h6' => Color::hex('#BD93F9'),
                'bold' => Color::hex('#F8FAFC'),
                'italic' => Color::hex('#F8FAF8'),
                'code' => Color::hex('#F1FA8C'),
                'link' => Color::hex('#8BE9FD'),
                'link_url' => Color::hex('#50FA7B'),
                'strikethrough' => Color::hex('#6272A4'),
                'blockquote' => Color::hex('#6272A4'),
                'list_bullet' => Color::hex('#FF79C6'),
                'hr' => Color::hex('#6272A4'),
                'text' => Color::hex('#F8FAFC'),
            ],
            default => [
                'h1' => Color::hex('#FFFFFF'),
                'h2' => Color::hex('#FFFFFF'),
                'h3' => Color::hex('#FFFFFF'),
                'h4' => Color::hex('#FFFFFF'),
                'h5' => Color::hex('#FFFFFF'),
                'h6' => Color::hex('#FFFFFF'),
                'bold' => Color::hex('#FFFFFF'),
                'italic' => Color::hex('#CCCCCC'),
                'code' => Color::hex('#A5C25C'),
                'link' => Color::hex('#6897BB'),
                'link_url' => Color::hex('#6E9C28'),
                'strikethrough' => Color::hex('#808080'),
                'blockquote' => Color::hex('#808080'),
                'list_bullet' => Color::hex('#CC7832'),
                'hr' => Color::hex('#AAAAAA'),
                'text' => Color::hex('#AAAAAA'),
            ],
        };
    }

    /**
     * Calculate the natural dimensions of this markdown component.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $lines = explode("\n", $this->content);
        $maxWidth = 0;
        $lineCount = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $lineCount++;
            $lineWidth = Width::string($line);
            $maxWidth = max($maxWidth, $lineWidth);
        }

        $width = $this->width ?? $maxWidth;

        return [$width, $lineCount];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the markdown content.
     */
    public function withContent(string $content): self
    {
        return new self(
            content: $content,
            theme: $this->theme,
            enableLinks: $this->enableLinks,
            enableCodeHighlighting: $this->enableCodeHighlighting,
        );
    }

    /**
     * Set the color theme.
     */
    public function withTheme(string $theme): self
    {
        return new self(
            content: $this->content,
            theme: $theme,
            enableLinks: $this->enableLinks,
            enableCodeHighlighting: $this->enableCodeHighlighting,
        );
    }

    /**
     * Set link rendering.
     */
    public function withEnableLinks(bool $enable): self
    {
        return new self(
            content: $this->content,
            theme: $this->theme,
            enableLinks: $enable,
            enableCodeHighlighting: $this->enableCodeHighlighting,
        );
    }

    /**
     * Set code highlighting.
     */
    public function withEnableCodeHighlighting(bool $enable): self
    {
        return new self(
            content: $this->content,
            theme: $this->theme,
            enableLinks: $this->enableLinks,
            enableCodeHighlighting: $enable,
        );
    }
}

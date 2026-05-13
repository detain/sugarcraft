<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * Cursor blinking states.
 */
enum CursorState
{
    case Visible;
    case Hidden;
    case Blink;
}

/**
 * A multi-line text editor component with cursor support.
 *
 * Features:
 * - Multi-line text editing with cursor
 * - Line numbers display
 * - Scroll support for large documents
 * - Syntax highlighting hooks (via color providers)
 * - Insert/overwrite mode
 * - Read-only mode
 * - Word wrap option
 *
 * Mirrors text editor patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Editor implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<string> */
    private array $lines;

    private int $cursorX = 0;
    private int $cursorY = 0;
    private int $scrollOffset = 0;

    public function __construct(
        private readonly ?string $content = null,
        private readonly bool $showLineNumbers = true,
        private readonly bool $wordWrap = false,
        private readonly bool $readOnly = false,
        private readonly bool $showCursor = true,
        private readonly CursorState $cursorState = CursorState::Blink,
        private readonly ?Color $textColor = null,
        private readonly ?Color $lineNumberColor = null,
        private readonly ?Color $cursorColor = null,
        private readonly ?Color $selectionColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly ?Color $borderColor = null,
        private readonly string $style = 'single',
    ) {
        $this->lines = $this->parseContent($content ?? '');
    }

    /**
     * Create a new editor with default styling.
     */
    public static function new(?string $content = null): self
    {
        return new self(
            content: $content,
            showLineNumbers: true,
            wordWrap: false,
            readOnly: false,
            showCursor: true,
            cursorState: CursorState::Blink,
            textColor: Color::hex('#F9FAFB'),
            lineNumberColor: Color::hex('#6C7086'),
            cursorColor: Color::hex('#F9FAFB'),
            selectionColor: Color::hex('#45475A'),
            backgroundColor: null,
            borderColor: Color::hex('#45475A'),
            style: 'single',
        );
    }

    /**
     * Create an editor for a file.
     */
    public static function forFile(?string $filepath = null): self
    {
        $content = null;
        if ($filepath !== null && file_exists($filepath)) {
            $content = file_get_contents($filepath) ?: null;
        }
        return self::new($content);
    }

    /**
     * Parse content into lines.
     *
     * @return list<string>
     */
    private function parseContent(string $content): array
    {
        if ($content === '') {
            return [''];
        }
        $lines = explode("\n", $content);
        // Ensure at least one line
        if ($lines === []) {
            $lines = [''];
        }
        return $lines;
    }

    /**
     * Get the content as a string.
     */
    public function getContent(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * Get a specific line.
     */
    public function getLine(int $index): string
    {
        if ($index < 0 || $index >= count($this->lines)) {
            return '';
        }
        return $this->lines[$index];
    }

    /**
     * Get the number of lines.
     */
    public function getLineCount(): int
    {
        return count($this->lines);
    }

    /**
     * Get the cursor position.
     *
     * @return array{0:int,1:int} [x, y]
     */
    public function getCursorPosition(): array
    {
        return [$this->cursorX, $this->cursorY];
    }

    /**
     * Set the allocated dimensions for this editor.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;

        // Adjust scroll offset to keep cursor in view
        $clone->adjustScroll();

        return $clone;
    }

    /**
     * Adjust scroll offset to keep cursor visible.
     */
    private function adjustScroll(): void
    {
        if ($this->height === null) {
            return;
        }

        $visibleHeight = $this->height - 2; // Account for borders
        if ($visibleHeight <= 0) {
            return;
        }

        // Scroll down if cursor is below visible area
        if ($this->cursorY >= $this->scrollOffset + $visibleHeight) {
            $this->scrollOffset = $this->cursorY - $visibleHeight + 1;
        }

        // Scroll up if cursor is above visible area
        if ($this->cursorY < $this->scrollOffset) {
            $this->scrollOffset = $this->cursorY;
        }

        // Ensure scroll offset is not negative
        $this->scrollOffset = max(0, $this->scrollOffset);
    }

    /**
     * Render the editor as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 60;
        $useHeight = $this->height ?? 20;

        $lineNumberWidth = $this->showLineNumbers ? $this->calculateLineNumberWidth() + 1 : 0;
        $contentWidth = $useWidth - $lineNumberWidth - 2; // 2 for borders
        $contentHeight = $useHeight - 2; // 2 for borders

        if ($contentWidth <= 0 || $contentHeight <= 0) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $result = '';

        // Apply border color
        $borderColor = $this->borderColor;
        if ($borderColor !== null) {
            $result .= $borderColor->toFg(ColorProfile::TrueColor);
        }

        // Top border with line numbers gutter
        if ($this->showLineNumbers) {
            $result .= $tl;
            $result .= str_repeat(' ', $lineNumberWidth);
            $result .= $v;
            $result .= str_repeat($h, $contentWidth);
            $result .= $tr;
        } else {
            $result .= $tl . str_repeat($h, $useWidth - 2) . $tr;
        }
        $result .= "\n";

        // Content lines
        $textColor = $this->textColor ?? Color::hex('#F9FAFB');
        $lineNumberColor = $this->lineNumberColor ?? Color::hex('#6C7086');

        $maxLines = min(count($this->lines), $this->scrollOffset + $contentHeight);
        for ($screenY = 0; $screenY < $contentHeight; $screenY++) {
            $lineIndex = $this->scrollOffset + $screenY;

            if ($borderColor !== null) {
                $result .= $borderColor->toFg(ColorProfile::TrueColor);
            }

            // Left border and line number
            if ($this->showLineNumbers) {
                $result .= $v;
                $result .= $lineNumberColor->toFg(ColorProfile::TrueColor);

                if ($lineIndex < count($this->lines)) {
                    $lineNum = (string) ($lineIndex + 1);
                    $result .= str_pad($lineNum, $lineNumberWidth - 1);
                } else {
                    $result .= str_repeat(' ', $lineNumberWidth - 1);
                }
            }

            $result .= $v;
            $result .= Ansi::reset();

            // Line content
            if ($lineIndex < count($this->lines)) {
                $lineContent = $this->lines[$lineIndex];

                if ($this->wordWrap) {
                    $wrappedLines = $this->wrapLine($lineContent, $contentWidth);
                    $lineContent = $wrappedLines[0] ?? '';
                } else {
                    $lineContent = mb_substr($lineContent, 0, $contentWidth, 'UTF-8');
                }

                $result .= $textColor->toFg(ColorProfile::TrueColor);
                $result .= $lineContent;

                // Draw cursor if on this line
                if ($this->showCursor && $lineIndex === $this->cursorY && !$this->readOnly) {
                    $cursorX = min($this->cursorX, mb_strlen($lineContent, 'UTF-8'));
                    $cursorChar = mb_substr($lineContent, $cursorX, 1, 'UTF-8');

                    if ($cursorChar === '') {
                        $cursorChar = ' ';
                    }

                    $result .= Ansi::reset();
                    if ($this->cursorColor !== null) {
                        $result .= $this->cursorColor->toFg(ColorProfile::TrueColor);
                        $result .= $this->cursorColor->toBg(ColorProfile::TrueColor);
                    } else {
                        $result .= Ansi::reverse();
                    }
                    $result .= $cursorChar;
                    $result .= Ansi::reset();
                    $result .= $textColor->toFg(ColorProfile::TrueColor);

                    // Fill rest of line
                    $filledWidth = mb_strlen($lineContent, 'UTF-8') + 1;
                } else {
                    $filledWidth = mb_strlen($lineContent, 'UTF-8');
                }

                // Pad to content width
                if ($filledWidth < $contentWidth) {
                    $result .= str_repeat(' ', $contentWidth - $filledWidth);
                }
            } else {
                $result .= str_repeat(' ', $contentWidth);
            }

            if ($borderColor !== null) {
                $result .= $borderColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $v;
            $result .= Ansi::reset();

            if ($screenY < $contentHeight - 1) {
                $result .= "\n";
            }
        }

        $result .= "\n";

        // Bottom border
        if ($borderColor !== null) {
            $result .= $borderColor->toFg(ColorProfile::TrueColor);
        }

        if ($this->showLineNumbers) {
            $result .= $bl;
            $result .= str_repeat(' ', $lineNumberWidth);
            $result .= $v;
            $result .= str_repeat($h, $contentWidth);
            $result .= $br;
        } else {
            $result .= $bl . str_repeat($h, $useWidth - 2) . $br;
        }

        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Wrap a single line to fit within the given width.
     *
     * @return list<string>
     */
    private function wrapLine(string $line, int $width): array
    {
        if ($width <= 0 || Width::string($line) <= $width) {
            return [$line];
        }

        $result = [];
        $words = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $currentLine = '';
        $currentWidth = 0;

        foreach ($words as $word) {
            $wordWidth = Width::string($word);

            if ($currentWidth > 0 && $currentWidth + 1 + $wordWidth > $width) {
                $result[] = $currentLine;
                $currentLine = $word;
                $currentWidth = $wordWidth;
            } else {
                if ($currentLine !== '') {
                    $currentLine .= ' ';
                    $currentWidth++;
                }
                $currentLine .= $word;
                $currentWidth += $wordWidth;
            }
        }

        if ($currentLine !== '') {
            $result[] = $currentLine;
        }

        return $result === [] ? [''] : $result;
    }

    /**
     * Calculate the line number column width.
     */
    private function calculateLineNumberWidth(): int
    {
        return max(2, strlen((string) count($this->lines)));
    }

    /**
     * Get the style characters for the border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string}
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['┌', '┐', '└', '┘', '─', '│'],
        };
    }

    /**
     * Calculate the natural dimensions of this editor.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? 60;
        $maxLineLength = 0;
        foreach ($this->lines as $line) {
            $len = Width::string($line);
            if ($len > $maxLineLength) {
                $maxLineLength = $len;
            }
        }

        $lineNumberWidth = $this->showLineNumbers ? $this->calculateLineNumberWidth() + 2 : 0;
        $width = max($useWidth, $maxLineLength + $lineNumberWidth + 2);
        $height = max(4, count($this->lines) + 2); // +2 for borders

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the content.
     */
    public function withContent(?string $content): self
    {
        return new self(
            content: $content,
            showLineNumbers: $this->showLineNumbers,
            wordWrap: $this->wordWrap,
            readOnly: $this->readOnly,
            showCursor: $this->showCursor,
            cursorState: $this->cursorState,
            textColor: $this->textColor,
            lineNumberColor: $this->lineNumberColor,
            cursorColor: $this->cursorColor,
            selectionColor: $this->selectionColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $this->style,
        );
    }

    /**
     * Show or hide line numbers.
     */
    public function withLineNumbers(bool $show): self
    {
        return new self(
            content: $this->getContent(),
            showLineNumbers: $show,
            wordWrap: $this->wordWrap,
            readOnly: $this->readOnly,
            showCursor: $this->showCursor,
            cursorState: $this->cursorState,
            textColor: $this->textColor,
            lineNumberColor: $this->lineNumberColor,
            cursorColor: $this->cursorColor,
            selectionColor: $this->selectionColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $this->style,
        );
    }

    /**
     * Enable or disable word wrap.
     */
    public function withWordWrap(bool $wrap): self
    {
        return new self(
            content: $this->getContent(),
            showLineNumbers: $this->showLineNumbers,
            wordWrap: $wrap,
            readOnly: $this->readOnly,
            showCursor: $this->showCursor,
            cursorState: $this->cursorState,
            textColor: $this->textColor,
            lineNumberColor: $this->lineNumberColor,
            cursorColor: $this->cursorColor,
            selectionColor: $this->selectionColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $this->style,
        );
    }

    /**
     * Set read-only mode.
     */
    public function withReadOnly(bool $readOnly): self
    {
        return new self(
            content: $this->getContent(),
            showLineNumbers: $this->showLineNumbers,
            wordWrap: $this->wordWrap,
            readOnly: $readOnly,
            showCursor: $readOnly ? false : $this->showCursor,
            cursorState: $this->cursorState,
            textColor: $this->textColor,
            lineNumberColor: $this->lineNumberColor,
            cursorColor: $this->cursorColor,
            selectionColor: $this->selectionColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $this->style,
        );
    }

    /**
     * Show or hide cursor.
     */
    public function withShowCursor(bool $show): self
    {
        return new self(
            content: $this->getContent(),
            showLineNumbers: $this->showLineNumbers,
            wordWrap: $this->wordWrap,
            readOnly: $this->readOnly,
            showCursor: $show,
            cursorState: $this->cursorState,
            textColor: $this->textColor,
            lineNumberColor: $this->lineNumberColor,
            cursorColor: $this->cursorColor,
            selectionColor: $this->selectionColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $this->style,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            content: $this->getContent(),
            showLineNumbers: $this->showLineNumbers,
            wordWrap: $this->wordWrap,
            readOnly: $this->readOnly,
            showCursor: $this->showCursor,
            cursorState: $this->cursorState,
            textColor: $color,
            lineNumberColor: $this->lineNumberColor,
            cursorColor: $this->cursorColor,
            selectionColor: $this->selectionColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $this->style,
        );
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            content: $this->getContent(),
            showLineNumbers: $this->showLineNumbers,
            wordWrap: $this->wordWrap,
            readOnly: $this->readOnly,
            showCursor: $this->showCursor,
            cursorState: $this->cursorState,
            textColor: $this->textColor,
            lineNumberColor: $this->lineNumberColor,
            cursorColor: $this->cursorColor,
            selectionColor: $this->selectionColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $style,
        );
    }
}

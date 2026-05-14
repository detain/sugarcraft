<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\System;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * Terminal prompt styles.
 */
enum PromptStyle: string
{
    case Bash = 'bash';
    case Pwsh = 'pwsh';
    case PS = 'ps';
    case Simple = 'simple';

    /**
     * Get the prompt string for this style.
     */
    public function prompt(string $cwd = '~'): string
    {
        return match ($this) {
            self::Bash => "\033[1;32muser\033[0m@\033[1;34mmachine\033[0m:\033[1;34m{$cwd}\033[0m$ ",
            self::Pwsh => "\033[1;32mPS \033[0m\033[1;34m{$cwd}\033[0m> ",
            self::PS => "\033[1;32muser\033[0m[\033[1;34m{$cwd}\033[0m]: ",
            self::Simple => '$ ',
        };
    }
}

/**
 * A terminal emulator component with command history and scrollback.
 *
 * Features:
 * - Command prompt with customizable style
 * - Command history (up/down arrows)
 * - Scrollback buffer
 * - Output formatting with ANSI support
 * - Clear screen support
 * - Prompt customization
 *
 * Mirrors terminal emulator patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Terminal implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<string> */
    private array $history = [];

    /** @var list<string> */
    private array $output = [];

    private int $historyIndex = -1;
    private string $currentInput = '';
    private string $currentWorkingDir = '~';

    public function __construct(
        private readonly ?int $maxHistory = null,
        private readonly ?int $maxOutput = null,
        private readonly bool $showPrompt = true,
        private readonly PromptStyle $promptStyle = PromptStyle::Bash,
        private readonly ?string $customPrompt = null,
        private readonly ?Color $promptColor = null,
        private readonly ?Color $inputColor = null,
        private readonly ?Color $outputColor = null,
        private readonly ?Color $errorColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly ?Color $borderColor = null,
        private readonly string $style = 'rounded',
    ) {}

    /**
     * Create a new terminal with default styling.
     */
    public static function new(): self
    {
        return new self(
            maxHistory: 100,
            maxOutput: 1000,
            showPrompt: true,
            promptStyle: PromptStyle::Bash,
            customPrompt: null,
            promptColor: Color::hex('#A6E3A1'),
            inputColor: Color::hex('#89B4FA'),
            outputColor: Color::hex('#CDD6F4'),
            errorColor: Color::hex('#F38BA8'),
            backgroundColor: null,
            borderColor: Color::hex('#45475A'),
            style: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this terminal.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the prompt string.
     */
    public function getPrompt(): string
    {
        if ($this->customPrompt !== null) {
            return $this->customPrompt;
        }
        return $this->promptStyle->prompt($this->currentWorkingDir);
    }

    /**
     * Get the current input.
     */
    public function getInput(): string
    {
        return $this->currentInput;
    }

    /**
     * Get the command history.
     *
     * @return list<string>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Get the output lines.
     *
     * @return list<string>
     */
    public function getOutput(): array
    {
        return $this->output;
    }

    /**
     * Type a command into the terminal.
     */
    public function type(string $input): self
    {
        $clone = clone $this;
        $clone->currentInput = $input;
        return $clone;
    }

    /**
     * Submit the current command.
     */
    public function submit(): self
    {
        if ($this->currentInput === '') {
            return $this;
        }

        $clone = clone $this;

        // Add to history
        $clone->history[] = $clone->currentInput;
        if ($clone->maxHistory !== null && count($clone->history) > $clone->maxHistory) {
            $clone->history = array_slice($clone->history, -$clone->maxHistory);
        }

        // Add to output with prompt
        if ($clone->showPrompt) {
            $clone->output[] = $clone->getPrompt() . $clone->currentInput;
        } else {
            $clone->output[] = $clone->currentInput;
        }

        $clone->currentInput = '';
        $clone->historyIndex = count($clone->history);

        return $clone;
    }

    /**
     * Add output text.
     */
    public function withOutput(string $text, bool $isError = false): self
    {
        $clone = clone $this;

        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $prefix = $isError ? "\033[31m[ERROR]\033[0m " : '';
            $clone->output[] = $prefix . $line;
        }

        if ($clone->maxOutput !== null && count($clone->output) > $clone->maxOutput) {
            $clone->output = array_slice($clone->output, -$clone->maxOutput);
        }

        return $clone;
    }

    /**
     * Add a blank line to output.
     */
    public function withBlankLine(): self
    {
        $clone = clone $this;
        $clone->output[] = '';
        return $clone;
    }

    /**
     * Navigate history up (previous command).
     */
    public function historyUp(): self
    {
        if ($this->history === []) {
            return $this;
        }

        $clone = clone $this;

        if ($clone->historyIndex > 0) {
            $clone->historyIndex--;
            $clone->currentInput = $clone->history[$clone->historyIndex];
        } elseif ($clone->historyIndex === -1 && count($clone->history) > 0) {
            $clone->historyIndex = count($clone->history) - 1;
            $clone->currentInput = $clone->history[$clone->historyIndex];
        }

        return $clone;
    }

    /**
     * Navigate history down (next command).
     */
    public function historyDown(): self
    {
        $clone = clone $this;

        if ($clone->historyIndex === -1) {
            return $clone;
        }

        $clone->historyIndex++;

        if ($clone->historyIndex >= count($clone->history)) {
            $clone->historyIndex = count($clone->history);
            $clone->currentInput = '';
        } else {
            $clone->currentInput = $clone->history[$clone->historyIndex];
        }

        return $clone;
    }

    /**
     * Clear the screen (adds clear command to output).
     */
    public function clearScreen(): self
    {
        $clone = clone $this;
        $clone->output = [];
        return $clone;
    }

    /**
     * Clear the input line.
     */
    public function clearInput(): self
    {
        $clone = clone $this;
        $clone->currentInput = '';
        $clone->historyIndex = count($clone->history);
        return $clone;
    }

    /**
     * Render the terminal as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 80;
        $useHeight = $this->height ?? 24;

        $contentWidth = $useWidth - 2;
        $contentHeight = $useHeight - 2;

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

        // Top border
        $result .= $tl . str_repeat($h, $useWidth - 2) . $tr . "\n";

        // Calculate visible output range
        $outputStart = max(0, count($this->output) - $contentHeight + 1);
        $visibleOutput = array_slice($this->output, $outputStart, $contentHeight - 1);
        $promptLine = count($visibleOutput) < $contentHeight - 1;

        $outputColor = $this->outputColor ?? Color::hex('#CDD6F4');
        $inputColor = $this->inputColor ?? Color::hex('#89B4FA');

        // Render output lines
        foreach ($visibleOutput as $line) {
            if ($borderColor !== null) {
                $result .= $borderColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $v;

            // Handle ANSI codes in output
            $displayLine = $this->formatOutputLine($line, $contentWidth);
            $result .= $displayLine;

            $filledWidth = $this->calculateDisplayWidth($line);
            if ($filledWidth < $contentWidth) {
                $result .= str_repeat(' ', $contentWidth - $filledWidth);
            }

            if ($borderColor !== null) {
                $result .= $borderColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $v . "\n";
        }

        // Render prompt + input line
        if ($promptLine) {
            if ($borderColor !== null) {
                $result .= $borderColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $v;

            $prompt = $this->getPrompt();
            $promptWidth = $this->calculateDisplayWidth($prompt);

            // Prompt
            $result .= $this->promptColor?->toFg(ColorProfile::TrueColor) ?? Ansi::reset();
            $result .= $prompt;

            // Input
            $inputDisplayWidth = $this->calculateDisplayWidth($this->currentInput);
            $cursorDisplayWidth = 1;
            $availableWidth = $contentWidth - $promptWidth;

            if ($inputDisplayWidth < $availableWidth) {
                $result .= $inputColor->toFg(ColorProfile::TrueColor);
                $result .= $this->currentInput;
                $result .= Ansi::reset();
                $result .= ' '; // Cursor position

                // Pad to cursor position
                $filledWidth = $promptWidth + $inputDisplayWidth + 1;
            } else {
                // Input too long, show truncated with cursor at end
                $truncated = mb_substr($this->currentInput, 0, $availableWidth - 1, 'UTF-8');
                $result .= $inputColor->toFg(ColorProfile::TrueColor);
                $result .= $truncated;
                $result .= Ansi::reset();
                $result .= ' '; // Cursor at end

                $filledWidth = $contentWidth;
            }

            // Pad rest of line
            if ($filledWidth < $contentWidth) {
                $result .= str_repeat(' ', $contentWidth - $filledWidth);
            }

            if ($borderColor !== null) {
                $result .= $borderColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $v;
            $result .= Ansi::reset();
        }

        // Bottom border
        $result .= "\n";
        if ($borderColor !== null) {
            $result .= $borderColor->toFg(ColorProfile::TrueColor);
        }
        $result .= $bl . str_repeat($h, $useWidth - 2) . $br;
        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Format an output line for display.
     */
    private function formatOutputLine(string $line, int $maxWidth): string
    {
        // Count visible characters (accounting for ANSI codes)
        $displayWidth = $this->calculateDisplayWidth($line);

        if ($displayWidth <= $maxWidth) {
            return $line;
        }

        // Truncate while preserving ANSI
        $result = '';
        $visibleCount = 0;
        $inEscape = false;
        $chars = mb_str_split($line);

        foreach ($chars as $char) {
            if ($char === "\033" || str_starts_with($char, "\x1b")) {
                $inEscape = true;
                $result .= $char;
                continue;
            }

            if ($inEscape) {
                $result .= $char;
                if ($char === 'm') {
                    $inEscape = false;
                }
                continue;
            }

            if ($visibleCount >= $maxWidth - 3) {
                $result .= '...';
                break;
            }

            $result .= $char;
            $visibleCount++;
        }

        return $result;
    }

    /**
     * Calculate the display width of a string (excluding ANSI codes).
     */
    private function calculateDisplayWidth(string $str): int
    {
        // Strip ANSI codes for width calculation
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $str);
        if ($stripped === null) {
            return mb_strlen($str, 'UTF-8');
        }
        return Width::string($stripped);
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
            default => ['╭', '╮', '╰', '╯', '─', '│'],
        };
    }

    /**
     * Calculate the natural dimensions of this terminal.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 80;
        $height = $this->height ?? 24;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the prompt style.
     */
    public function withPromptStyle(PromptStyle $style): self
    {
        return new self(
            maxHistory: $this->maxHistory,
            maxOutput: $this->maxOutput,
            showPrompt: $this->showPrompt,
            promptStyle: $style,
            customPrompt: $this->customPrompt,
            promptColor: $this->promptColor,
            inputColor: $this->inputColor,
            outputColor: $this->outputColor,
            errorColor: $this->errorColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $this->style,
        );
    }

    /**
     * Set a custom prompt string.
     */
    public function withCustomPrompt(?string $prompt): self
    {
        return new self(
            maxHistory: $this->maxHistory,
            maxOutput: $this->maxOutput,
            showPrompt: $this->showPrompt,
            promptStyle: $this->promptStyle,
            customPrompt: $prompt,
            promptColor: $this->promptColor,
            inputColor: $this->inputColor,
            outputColor: $this->outputColor,
            errorColor: $this->errorColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $this->style,
        );
    }

    /**
     * Show or hide the prompt.
     */
    public function withShowPrompt(bool $show): self
    {
        return new self(
            maxHistory: $this->maxHistory,
            maxOutput: $this->maxOutput,
            showPrompt: $show,
            promptStyle: $this->promptStyle,
            customPrompt: $this->customPrompt,
            promptColor: $this->promptColor,
            inputColor: $this->inputColor,
            outputColor: $this->outputColor,
            errorColor: $this->errorColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $this->style,
        );
    }

    /**
     * Set the maximum history size.
     */
    public function withMaxHistory(?int $max): self
    {
        return new self(
            maxHistory: $max,
            maxOutput: $this->maxOutput,
            showPrompt: $this->showPrompt,
            promptStyle: $this->promptStyle,
            customPrompt: $this->customPrompt,
            promptColor: $this->promptColor,
            inputColor: $this->inputColor,
            outputColor: $this->outputColor,
            errorColor: $this->errorColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $this->style,
        );
    }

    /**
     * Set the maximum output size.
     */
    public function withMaxOutput(?int $max): self
    {
        return new self(
            maxHistory: $this->maxHistory,
            maxOutput: $max,
            showPrompt: $this->showPrompt,
            promptStyle: $this->promptStyle,
            customPrompt: $this->customPrompt,
            promptColor: $this->promptColor,
            inputColor: $this->inputColor,
            outputColor: $this->outputColor,
            errorColor: $this->errorColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $this->style,
        );
    }

    /**
     * Set the prompt color.
     */
    public function withPromptColor(?Color $color): self
    {
        return new self(
            maxHistory: $this->maxHistory,
            maxOutput: $this->maxOutput,
            showPrompt: $this->showPrompt,
            promptStyle: $this->promptStyle,
            customPrompt: $this->customPrompt,
            promptColor: $color,
            inputColor: $this->inputColor,
            outputColor: $this->outputColor,
            errorColor: $this->errorColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $this->style,
        );
    }

    /**
     * Set the input color.
     */
    public function withInputColor(?Color $color): self
    {
        return new self(
            maxHistory: $this->maxHistory,
            maxOutput: $this->maxOutput,
            showPrompt: $this->showPrompt,
            promptStyle: $this->promptStyle,
            customPrompt: $this->customPrompt,
            promptColor: $this->promptColor,
            inputColor: $color,
            outputColor: $this->outputColor,
            errorColor: $this->errorColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $this->style,
        );
    }

    /**
     * Set the output color.
     */
    public function withOutputColor(?Color $color): self
    {
        return new self(
            maxHistory: $this->maxHistory,
            maxOutput: $this->maxOutput,
            showPrompt: $this->showPrompt,
            promptStyle: $this->promptStyle,
            customPrompt: $this->customPrompt,
            promptColor: $this->promptColor,
            inputColor: $this->inputColor,
            outputColor: $color,
            errorColor: $this->errorColor,
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
            maxHistory: $this->maxHistory,
            maxOutput: $this->maxOutput,
            showPrompt: $this->showPrompt,
            promptStyle: $this->promptStyle,
            customPrompt: $this->customPrompt,
            promptColor: $this->promptColor,
            inputColor: $this->inputColor,
            outputColor: $this->outputColor,
            errorColor: $this->errorColor,
            backgroundColor: $this->backgroundColor,
            borderColor: $this->borderColor,
            style: $style,
        );
    }
}
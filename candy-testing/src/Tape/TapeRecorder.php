<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tape;

use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Testing\Lang;

/**
 * Records a Program's output stream to a VHS-compatible `.tape` file.
 *
 * TapeRecorder wraps a program output stream and emits a `.tape` file
 * in the VHS format used by the Charmbracelet VHS tool for
 * rendering demo GIFs.
 *
 * The tape format:
 *   Set Theme "TokyoNight"
 *   Set FontSize 14
 *   Set Width 800
 *   Set Height 480
 *   Type "php examples/counter.php"
 *   Enter
 *   Sleep 1
 *   Type "q"
 *   Enter
 *
 * @see Mirrors charmbracelet/bubbletea — cassette recording pattern (issue #1654)
 */
final class TapeRecorder
{
    /** @var list<string> */
    private array $lines = [];

    private bool $headerWritten = false;

    private function __construct(
        private readonly string $outputPath,
    ) {}

    /**
     * Factory — create a new tape recorder writing to $outputPath.
     */
    public static function to(string $outputPath): self
    {
        return new self($outputPath);
    }

    /**
     * Write the VHS header with theme and dimensions.
     *
     * @param string $theme Font/Theme name (default: TokyoNight)
     * @param int    $width  Terminal width in pixels
     * @param int    $height Terminal height in pixels
     * @param int    $fontSize Font size
     * @return $this
     */
    public function header(
        string $theme = 'TokyoNight',
        int $width = 800,
        int $height = 480,
        int $fontSize = 14,
    ): self {
        if (!$this->headerWritten) {
            $this->lines[] = 'Set Theme "' . $theme . '"';
            $this->lines[] = 'Set FontSize ' . $fontSize;
            $this->lines[] = 'Set Width ' . $width;
            $this->lines[] = 'Set Height ' . $height;
            $this->lines[] = '';
            $this->headerWritten = true;
        }
        return $this;
    }

    /**
     * Record a keypress as Type command.
     *
     * @param string $keys Space-separated key names or chars
     * @return $this
     */
    public function type(string $keys): self
    {
        $this->lines[] = 'Type "' . $this->escapeVhs($keys) . '"';
        return $this;
    }

    /**
     * Record pressing Enter.
     *
     * @return $this
     */
    public function enter(): self
    {
        $this->lines[] = 'Enter';
        return $this;
    }

    /**
     * Record a sleep delay.
     *
     * @param int|float $seconds
     * @return $this
     */
    public function sleep(float $seconds): self
    {
        $this->lines[] = 'Sleep ' . $seconds;
        return $this;
    }

    /**
     * Record a window resize event.
     *
     * @param int $cols
     * @param int $rows
     * @return $this
     */
    public function resize(int $cols, int $rows): self
    {
        $this->lines[] = "# Resize {$cols}x{$rows}";
        return $this;
    }

    /**
     * Record a comment.
     *
     * @param string $comment
     * @return $this
     */
    public function comment(string $comment): self
    {
        $this->lines[] = '# ' . $comment;
        return $this;
    }

    /**
     * Append an arbitrary line verbatim.
     *
     * @param string $line
     * @return $this
     */
    public function line(string $line): self
    {
        $this->lines[] = $line;
        return $this;
    }

    /**
     * Finalize and write the tape file to disk.
     *
     * @return void
     */
    public function save(): void
    {
        $dir = \dirname($this->outputPath);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0755, true);
        }

        $content = implode("\n", $this->lines) . "\n";
        $result = \file_put_contents($this->outputPath, $content);

        if ($result === false) {
            throw new \RuntimeException(Lang::t('tape.write_failed', ['path' => $this->outputPath]));
        }
    }

    /**
     * Convert a KeyMsg to VHS Type syntax.
     *
     * @param KeyMsg $msg
     * @return string|null VHS-compatible key string, or null if unsupported
     */
    public static function keyMsgToVhs(KeyMsg $msg): ?string
    {
        $rune = $msg->rune;

        if ($rune !== '') {
            // Character key — escape for VHS Type command.
            return '"' . self::escapeVhsRune($rune) . '"';
        }

        // Named key — map to VHS syntax.
        return match ($msg->type) {
            \SugarCraft\Core\KeyType::Enter => 'Enter',
            \SugarCraft\Core\KeyType::Escape => 'Escape',
            \SugarCraft\Core\KeyType::Backspace => 'Backspace',
            \SugarCraft\Core\KeyType::Tab => 'Tab',
            \SugarCraft\Core\KeyType::Up => 'Up',
            \SugarCraft\Core\KeyType::Down => 'Down',
            \SugarCraft\Core\KeyType::Left => 'Left',
            \SugarCraft\Core\KeyType::Right => 'Right',
            default => null,
        };
    }

    /**
     * Escape a rune (character key) for safe inclusion in VHS Type command.
     *
     * Both double-quotes and backslashes must be escaped so a rune like
     * `\` produces valid VHS output.
     *
     * @param string $rune
     * @return string
     */
    private static function escapeVhsRune(string $rune): string
    {
        $result = '';
        for ($i = 0; $i < strlen($rune); $i++) {
            $c = $rune[$i];
            if ($c === '\\') {
                $result .= '\\\\'; // escape backslash as \\
            } elseif ($c === '"') {
                $result .= '\\"';  // escape quote as \"
            } else {
                $result .= $c;
            }
        }
        return $result;
    }

    /**
     * Escape a string for safe inclusion in VHS Type command.
     *
     * @param string $input
     * @return string
     */
    private function escapeVhs(string $input): string
    {
        return self::escapeVhsRune($input);
    }
}

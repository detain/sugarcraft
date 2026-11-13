<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tape;

use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Testing\Lang;

/**
 * Builds VHS-compatible `.tape` files for demo-GIF rendering.
 *
 * TapeRecorder is a SCRIPT BUILDER, not a recorder: nothing is captured
 * from a running Program. You compose the keystroke choreography
 * ({@see type()}, {@see enter()}, {@see sleep()}) and {@see save()} emits
 * the `.tape` the Charmbracelet VHS tool renders into a GIF in CI.
 *
 * All interpolated text is escaped so it cannot forge a standalone
 * directive line: {@see type()} neutralizes newlines, carriage returns and
 * other control bytes inside the quoted string, and {@see comment()}
 * prefixes every physical line. {@see line()} is the deliberate verbatim
 * escape hatch — whatever you hand it lands in the tape unescaped, so it
 * must only ever receive trusted, generated directive text (CI executes
 * the resulting tape; a `Shell "..."` line is arbitrary command execution).
 *
 * The tape format:
 *   Set Theme "TokyoNight"
 *   Set FontSize 14
 *   Set Width 800
 *   Set Height 480
 *   Type "php examples/tape-recorder.php"
 *   Enter
 *   Sleep 1
 *   Type "q"
 *   Enter
 *
 * First-party harness; the output format is charmbracelet/vhs's tape
 * dialect (see docs/MATCHUPS.md).
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
        if ($this->headerWritten === false) {
            // $theme is caller-supplied and CI executes the tape: escape it
            // like typed content so a quote or newline cannot forge a line.
            $this->lines[] = 'Set Theme "' . self::escapeVhsRune($theme) . '"';
            $this->lines[] = 'Set FontSize ' . $fontSize;
            $this->lines[] = 'Set Width ' . $width;
            $this->lines[] = 'Set Height ' . $height;
            $this->lines[] = '';
            $this->headerWritten = true;
        }
        return $this;
    }

    /**
     * Record literal typed text as a quoted Type directive.
     *
     * Quoted Type content is typed verbatim except for the dialect's
     * double-brace key-substitution tokens — and caller text carrying
     * those markers is refused by the escape, so this method is literal
     * characters only: key names go through {@see keyMsgToVhs()} or the
     * verbatim line() escape hatch, never through here.
     *
     * @param string $keys literal characters to type
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
     * Emit a resize annotation.
     *
     * The harness supports initial dimensions only: {@see header()} fixes
     * Width/Height at the top of the tape, so this records the intent as a
     * comment line rather than a directive.
     *
     * Dated probe (2026-11): no vhs binary on this host and no Resize
     * directive in the repo's committed .vhs/*.tape corpus — whether
     * upstream VHS supports a mid-tape resize remains unverified; emitting
     * one is deliberately out of scope here, not asserted impossible.
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
     * A multi-line comment becomes one '# '-prefixed tape line per
     * physical line, so no comment body can surface an unprefixed line
     * (which VHS would execute as a directive).
     *
     * @param string $comment
     * @return $this
     */
    public function comment(string $comment): self
    {
        foreach (preg_split('/\r\n|\r|\n/', $comment) ?: [$comment] as $physical) {
            $this->lines[] = '# ' . $physical;
        }
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
     * A short or failed write throws with the underlying reason — a tape
     * that silently stops half-way would render a truncated GIF that looks
     * intentional.
     *
     * @return void
     */
    public function save(): void
    {
        $dir = \dirname($this->outputPath);
        if (\is_dir($dir) === false) {
            @\mkdir($dir, 0755, true);
            if (\is_dir($dir) === false) {
                throw new \RuntimeException(Lang::t('tape.write_failed', [
                    'path' => $dir,
                    'reason' => (string) (error_get_last()['message'] ?? 'directory could not be created'),
                ]));
            }
        }

        $content = implode("\n", $this->lines) . "\n";
        $written = @\file_put_contents($this->outputPath, $content);

        if ($written !== \strlen($content)) {
            throw new \RuntimeException(Lang::t('tape.write_failed', [
                'path' => $this->outputPath,
                'reason' => $written === false
                    ? (string) (error_get_last()['message'] ?? 'write failed')
                    : \sprintf('short write (%d of %d bytes)', $written, \strlen($content)),
            ]));
        }
    }

    /**
     * Convert a KeyMsg to VHS Type syntax.
     *
     * Returns null for unsupported shapes: key chords (ctrl/alt modifiers)
     * have no verifiable tape spelling — the dialect's double-brace key
     * token channel cannot be confirmed against the pinned vhs here, and
     * silently dropping the modifier would mis-record Ctrl+C as a plain
     * "c". Shift folds into the rune the same way a terminal reports it,
     * so shifted characters convert normally.
     *
     * @param KeyMsg $msg
     * @return string|null VHS-compatible key string, or null if unsupported
     */
    public static function keyMsgToVhs(KeyMsg $msg): ?string
    {
        if ($msg->ctrl === true || $msg->alt === true) {
            return null;
        }

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
            \SugarCraft\Core\KeyType::Space => 'Space',
            \SugarCraft\Core\KeyType::Up => 'Up',
            \SugarCraft\Core\KeyType::Down => 'Down',
            \SugarCraft\Core\KeyType::Left => 'Left',
            \SugarCraft\Core\KeyType::Right => 'Right',
            \SugarCraft\Core\KeyType::Home => 'Home',
            \SugarCraft\Core\KeyType::End => 'End',
            \SugarCraft\Core\KeyType::PageUp => 'PageUp',
            \SugarCraft\Core\KeyType::PageDown => 'PageDown',
            \SugarCraft\Core\KeyType::Delete => 'Delete',
            \SugarCraft\Core\KeyType::Insert => 'Insert',
            \SugarCraft\Core\KeyType::F1 => 'F1',
            \SugarCraft\Core\KeyType::F2 => 'F2',
            \SugarCraft\Core\KeyType::F3 => 'F3',
            \SugarCraft\Core\KeyType::F4 => 'F4',
            \SugarCraft\Core\KeyType::F5 => 'F5',
            \SugarCraft\Core\KeyType::F6 => 'F6',
            \SugarCraft\Core\KeyType::F7 => 'F7',
            \SugarCraft\Core\KeyType::F8 => 'F8',
            \SugarCraft\Core\KeyType::F9 => 'F9',
            \SugarCraft\Core\KeyType::F10 => 'F10',
            \SugarCraft\Core\KeyType::F11 => 'F11',
            \SugarCraft\Core\KeyType::F12 => 'F12',
            default => null,
        };
    }

    /**
     * Escape a string for safe inclusion in a quoted VHS directive.
     *
     * Double quotes and backslashes are escaped first so a payload like
     * `\` produces valid VHS output. Control bytes are then neutralized to
     * VHS backslash escapes: an unescaped newline inside type()'s argument
     * would otherwise emit a STANDALONE directive line — "\nSet FontSize 4242"
     * or "\nShell \"...\"" becomes arbitrary CI action (vhs.yml renders
     * committed tapes), so the one-line-per-directive guarantee is a
     * security boundary, not cosmetics. The same reasoning refuses caller
     * content carrying the dialect's double-brace key-substitution token
     * markers: a quoted string holding them could steer keys the demo
     * never pressed.
     *
     * @param string $rune
     * @return string
     */
    private static function escapeVhsRune(string $rune): string
    {
        if (\preg_match('/\{\{|\}\}/', $rune) === 1) {
            throw new \InvalidArgumentException(Lang::t('tape.forbidden_substitution', [
                'value' => $rune,
            ]));
        }

        $escaped = addcslashes($rune, '"\\');

        $neutralized = preg_replace_callback('/[\x00-\x1f\x7f]/', static function (array $m): string {
            return match ($m[0]) {
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                default => sprintf('\\x%02X', \ord($m[0])),
            };
        }, $escaped);

        return $neutralized ?? $escaped;
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

<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Model;

use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Core\Util\Width;
use SugarCraft\ScriptUi\Runtime\Emphasis;
use SugarCraft\ScriptUi\Runtime\Geometry;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Mirrors charmbracelet/gum.write (the multi-line editor).
 * Multi-line editor used by {@see \SugarCraft\ScriptUi\Command\WriteCommand}.
 * Wraps {@see TextArea}; **Ctrl+D** submits (Enter inserts a newline,
 * matching most multi-line editors), **Esc / Ctrl+C** abort.
 */
final class WriteModel implements Model
{
    public static function newPrompt(
        string $placeholder = '',
        int $width = 0,
        int $height = 0,
        string $value = '',
        int $charLimit = 0,
        int $maxLines = 0,
        string $prompt = '',
        bool $showLineNumbers = false,
        string $header = '',
        ?string $endOfBufferCharacter = null,
    ): self {
        // Untrusted paint (cluster 12).
        $placeholder = \SugarCraft\Core\Util\Sanitize::cellValue($placeholder);
        $prompt = \SugarCraft\Core\Util\Sanitize::cellValue($prompt);
        // The body is multi-line text: real newlines are data here, so the
        // sanitizer keeps LF (everything else folds to its visible form).
        $value = \SugarCraft\Core\Util\Sanitize::cellValue($value, preserveNewlines: true);
        $header = \SugarCraft\Core\Util\Sanitize::cellValue($header);

        $area = TextArea::new()->withPlaceholder($placeholder);
        // scriptui-14 (finding 004): same Geometry auto-width treatment as
        // the lists and InputModel — an unset width used to leave the
        // wrap budget unbounded, so long buffer lines emitted physical
        // rows past the terminal. The chrome is the prompt plus the
        // line-number gutter TextArea prefixes onto every row
        // (`str_pad(n, max(2, digits+1)) . ' '`); the gutter digit count
        // grows with the buffer, so a row can only overflow by digit
        // crossings — the frame epilogue clips those too.
        $cols   = Geometry::columnsOrNull();
        $chrome = Width::string($prompt);
        if ($showLineNumbers) {
            $digits    = strlen((string) max(1, substr_count($value, "\n") + 1));
            $chrome   += max(2, $digits + 1) + 1;
        }
        $frame = $cols ?? 0;
        if ($width > 0) {
            $width = $cols !== null ? min($width, $cols - $chrome) : $width;
        } elseif ($cols !== null) {
            $width = max(1, $cols - $chrome);
        }
        if ($width  > 0)            { $area = $area->withWidth($width); }
        if ($height > 0)            { $area = $area->withHeight($height); }
        if ($charLimit > 0)         { $area = $area->withCharLimit($charLimit); }
        if ($maxLines > 0)          { $area = $area->withMaxHeight($maxLines); }
        if ($prompt !== '')         { $area = $area->withPrompt($prompt); }
        if ($showLineNumbers)       { $area = $area->showLineNumbers(true); }
        if ($endOfBufferCharacter !== null) {
            $area = $area->withEndOfBufferCharacter(\SugarCraft\Core\Util\Sanitize::cellValue($endOfBufferCharacter));
        }
        if ($value !== '')          { $area = $area->setValue($value); }
        [$area, ] = $area->focus();
        return new self($area, false, false, $header, frameCols: $frame);
    }

    private function __construct(
        public readonly TextArea $area,
        public readonly bool $submitted,
        public readonly bool $aborted,
        public readonly string $header = '',
        public readonly bool $interrupted = false,
        // scriptui-14: terminal columns at launch (0 = unknowable, no cap).
        public readonly int $frameCols = 0,
    ) {}

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($this->submitted || $this->aborted) {
            return [$this, null];
        }
        if ($msg instanceof KeyMsg) {
            if ($msg->type === KeyType::Escape) {
                return [new self($this->area, false, true, $this->header, frameCols: $this->frameCols), Cmd::quit()];
            }
            if ($msg->ctrl && $msg->rune === 'd') {
                return [new self($this->area, true, false, $this->header, frameCols: $this->frameCols), Cmd::quit()];
            }
            if ($msg->ctrl && $msg->rune === 'c') {
                // Same distinction as everywhere else: cancellation is 130,
                // not the Esc-abort code.
                return [new self($this->area, false, true, $this->header, true, $this->frameCols), Cmd::quit()];
            }
        }
        [$next, $cmd] = $this->area->update($msg);
        return [new self($next, false, false, $this->header, frameCols: $this->frameCols), $cmd];
    }

    public function view(): string
    {
        $body = $this->ghostText();
        return $this->frame($this->header === '' ? $body : $this->header . "\n" . $body);
    }

    /**
     * scriptui-14 (finding 004): per-line column budget for the composed
     * frame (the wrap budget covers buffer rows; this catches the header,
     * the ghost, and gutter digit crossings). 0 cols = historical unbounded.
     */
    private function frame(string $body): string
    {
        if ($this->frameCols <= 0) {
            return $body;
        }
        $cols = $this->frameCols;

        return implode("\n", array_map(
            static fn(string $line): string => Width::truncateAnsi($line, $cols),
            explode("\n", $body)
        ));
    }

    /**
     * scriptui-17: same unfocused-only placeholder gate as InputModel —
     * TextArea paints its hint only while BLURRED, and this editor keeps
     * focus for the whole session. While the buffer is empty the frame is
     * rendered through the widget's own unfocused path (a pure copy — the
     * model's focus state never changes), so the dim hint appears; the
     * first keystroke takes the buffer off empty and the real editor
     * surface returns.
     */
    private function ghostText(): string
    {
        $area = $this->area;
        if ($area->value() !== '' || !$area->focused() || $area->placeholder === '') {
            return Emphasis::stripDecor($area->view());
        }

        return Emphasis::stripDecor($area->blur()->view());
    }
    public function value(): string { return $this->area->value(); }
    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }
    public function isInterrupted(): bool { return $this->interrupted; }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}

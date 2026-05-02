<?php

declare(strict_types=1);

namespace CandyCore\Bits\TextInput;

use CandyCore\Bits\Cursor\BlinkMsg;
use CandyCore\Bits\Cursor\Cursor;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

/**
 * Single-line text input.
 *
 * Handles printable insertion, backspace / delete, arrow / Home / End
 * cursor movement, and a few common Emacs-style chord shortcuts (Ctrl+A
 * / Ctrl+E / Ctrl+U / Ctrl+K). Multibyte-safe — positions and edits use
 * `mb_substr` / `mb_strlen` so a `cursorPos` always refers to a grapheme
 * index, not a byte offset.
 *
 * The component embeds a {@see Cursor} so its visual cursor blinks via
 * `Cmd::tick`. The parent Model is responsible for deciding what an
 * {@see KeyType::Enter} press means — TextInput simply leaves the value
 * unchanged on Enter.
 */
final class TextInput implements Model
{
    private function __construct(
        public readonly string $value,
        public readonly int $cursorPos,
        public readonly string $placeholder,
        public readonly string $prompt,
        public readonly int $charLimit,
        public readonly int $width,
        public readonly bool $focused,
        public readonly Cursor $cursor,
        public readonly EchoMode $echoMode,
        public readonly string $echoChar,
        public readonly int $offset,
    ) {}

    public static function new(): self
    {
        return new self(
            value: '',
            cursorPos: 0,
            placeholder: '',
            prompt: '> ',
            charLimit: 0,
            width: 0,
            focused: false,
            cursor: Cursor::new(),
            echoMode: EchoMode::Normal,
            echoChar: '*',
            offset: 0,
        );
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($msg instanceof BlinkMsg) {
            [$cursor, $cmd] = $this->cursor->update($msg);
            return [$this->withCursor($cursor), $cmd];
        }
        if (!$msg instanceof KeyMsg || !$this->focused) {
            return [$this, null];
        }

        // Cursor movement / line edits.
        if ($msg->ctrl) {
            return match ($msg->rune) {
                'a'     => [$this->moveCursor(0), null],
                'e'     => [$this->moveCursor($this->length()), null],
                'u'     => [$this->deleteToStart(), null],
                'k'     => [$this->deleteToEnd(), null],
                default => [$this, null],
            };
        }

        return match ($msg->type) {
            KeyType::Left      => [$this->moveCursor(max(0, $this->cursorPos - 1)), null],
            KeyType::Right     => [$this->moveCursor(min($this->length(), $this->cursorPos + 1)), null],
            KeyType::Home      => [$this->moveCursor(0), null],
            KeyType::End       => [$this->moveCursor($this->length()), null],
            KeyType::Backspace => [$this->backspace(), null],
            KeyType::Delete    => [$this->deleteForward(), null],
            KeyType::Space     => [$this->insert(' '), null],
            KeyType::Char      => [$this->insert($msg->rune), null],
            default            => [$this, null],
        };
    }

    public function view(): string
    {
        // Empty + unfocused with a placeholder: show the placeholder.
        if ($this->value === '' && !$this->focused && $this->placeholder !== '') {
            return $this->prompt . $this->placeholder;
        }

        $display = $this->displayedValue();
        $len     = mb_strlen($display, 'UTF-8');
        $pos     = $this->cursorPos;

        // Scroll window when width > 0 so the cursor stays visible.
        $start = $this->offset;
        if ($this->width > 0) {
            if ($pos < $start) {
                $start = $pos;
            }
            if ($pos - $start >= $this->width) {
                $start = $pos - $this->width + 1;
            }
            $start = max(0, min($start, max(0, $len - $this->width + 1)));
        } else {
            $start = 0;
        }

        $sliceLen = $this->width > 0 ? $this->width : null;
        $slice    = mb_substr($display, $start, $sliceLen, 'UTF-8');
        $relPos   = $pos - $start;

        if (!$this->focused) {
            return $this->prompt . $slice;
        }

        $sliceLenActual = mb_strlen($slice, 'UTF-8');
        $before = mb_substr($slice, 0, $relPos, 'UTF-8');
        $charAt = $relPos < $sliceLenActual ? mb_substr($slice, $relPos, 1, 'UTF-8') : ' ';
        $after  = $relPos < $sliceLenActual ? mb_substr($slice, $relPos + 1, null, 'UTF-8') : '';

        $cursorView = $this->cursor->setChar($charAt)->view();
        return $this->prompt . $before . $cursorView . $after;
    }

    // ---- focus + setters ------------------------------------------------

    /**
     * @return array{0:self, 1:?\Closure}
     */
    public function focus(): array
    {
        [$cursor, $cmd] = $this->cursor->focus();
        return [$this->withCursor($cursor)->withFocused(true), $cmd];
    }

    public function blur(): self
    {
        return $this->withCursor($this->cursor->blur())->withFocused(false);
    }

    public function setValue(string $v): self
    {
        if ($this->charLimit > 0) {
            $v = mb_substr($v, 0, $this->charLimit, 'UTF-8');
        }
        $clone = clone $this;
        $clone = $clone->mutate(value: $v, cursorPos: mb_strlen($v, 'UTF-8'));
        return $clone;
    }

    public function reset(): self
    {
        return $this->mutate(value: '', cursorPos: 0, offset: 0);
    }

    public function withPlaceholder(string $p): self { return $this->mutate(placeholder: $p); }
    public function withPrompt(string $p): self      { return $this->mutate(prompt: $p); }
    public function withCharLimit(int $n): self      { return $this->mutate(charLimit: max(0, $n)); }
    public function withWidth(int $w): self          { return $this->mutate(width: max(0, $w)); }
    public function withEchoMode(EchoMode $m): self  { return $this->mutate(echoMode: $m); }
    public function withEchoChar(string $c): self    { return $this->mutate(echoChar: $c); }

    public function length(): int
    {
        return mb_strlen($this->value, 'UTF-8');
    }

    // ---- internal mutations -------------------------------------------

    private function insert(string $rune): self
    {
        if ($this->charLimit > 0 && $this->length() >= $this->charLimit) {
            return $this;
        }
        $before = mb_substr($this->value, 0, $this->cursorPos, 'UTF-8');
        $after  = mb_substr($this->value, $this->cursorPos, null, 'UTF-8');
        return $this->mutate(
            value: $before . $rune . $after,
            cursorPos: $this->cursorPos + mb_strlen($rune, 'UTF-8'),
        );
    }

    private function backspace(): self
    {
        if ($this->cursorPos === 0) {
            return $this;
        }
        $before = mb_substr($this->value, 0, $this->cursorPos - 1, 'UTF-8');
        $after  = mb_substr($this->value, $this->cursorPos, null, 'UTF-8');
        return $this->mutate(value: $before . $after, cursorPos: $this->cursorPos - 1);
    }

    private function deleteForward(): self
    {
        if ($this->cursorPos >= $this->length()) {
            return $this;
        }
        $before = mb_substr($this->value, 0, $this->cursorPos, 'UTF-8');
        $after  = mb_substr($this->value, $this->cursorPos + 1, null, 'UTF-8');
        return $this->mutate(value: $before . $after);
    }

    private function deleteToStart(): self
    {
        $after = mb_substr($this->value, $this->cursorPos, null, 'UTF-8');
        return $this->mutate(value: $after, cursorPos: 0);
    }

    private function deleteToEnd(): self
    {
        $before = mb_substr($this->value, 0, $this->cursorPos, 'UTF-8');
        return $this->mutate(value: $before);
    }

    private function moveCursor(int $pos): self
    {
        $clamped = max(0, min($this->length(), $pos));
        return $this->mutate(cursorPos: $clamped);
    }

    private function displayedValue(): string
    {
        return match ($this->echoMode) {
            EchoMode::Normal   => $this->value,
            EchoMode::Password => str_repeat($this->echoChar, $this->length()),
            EchoMode::None     => '',
        };
    }

    private function withCursor(Cursor $c): self    { return $this->mutate(cursor: $c); }
    private function withFocused(bool $f): self     { return $this->mutate(focused: $f); }

    private function mutate(
        ?string $value = null,
        ?int $cursorPos = null,
        ?string $placeholder = null,
        ?string $prompt = null,
        ?int $charLimit = null,
        ?int $width = null,
        ?bool $focused = null,
        ?Cursor $cursor = null,
        ?EchoMode $echoMode = null,
        ?string $echoChar = null,
        ?int $offset = null,
    ): self {
        return new self(
            value:       $value       ?? $this->value,
            cursorPos:   $cursorPos   ?? $this->cursorPos,
            placeholder: $placeholder ?? $this->placeholder,
            prompt:      $prompt      ?? $this->prompt,
            charLimit:   $charLimit   ?? $this->charLimit,
            width:       $width       ?? $this->width,
            focused:     $focused     ?? $this->focused,
            cursor:      $cursor      ?? $this->cursor,
            echoMode:    $echoMode    ?? $this->echoMode,
            echoChar:    $echoChar    ?? $this->echoChar,
            offset:      $offset      ?? $this->offset,
        );
    }
}

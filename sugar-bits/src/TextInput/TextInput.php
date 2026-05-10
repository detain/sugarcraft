<?php

declare(strict_types=1);

namespace SugarCraft\Bits\TextInput;

use SugarCraft\Bits\Cursor\BlinkMsg;
use SugarCraft\Bits\Cursor\Cursor;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

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
 *
 * Vim Mode:
 * When enabled via `withVimMode(true)`, the input supports vim-style
 * keybindings in two modes:
 * - Normal mode: `h/l` move, `w/b` word navigation, `0/$` line boundaries,
 *   `i/a/A/I` enter insert mode, `x` delete character
 * - Insert mode: standard editing behavior, Escape returns to normal mode
 */
final class TextInput implements Model
{
    /**
     * @param list<string>            $suggestions     full set of completion candidates
     * @param ?\Closure(string): ?string $validate    null = valid; non-null = error message
     */
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
        public readonly array $suggestions = [],
        public readonly bool $showSuggestions = false,
        public readonly int $currentSuggestionIndex = 0,
        public readonly ?\Closure $validate = null,
        public readonly ?string $err = null,
        public readonly ?Styles $styles = null,
        public readonly bool $vimMode = false,
        public readonly bool $vimNormalMode = true,
    ) {}

    /** Construct a fresh instance with default state. */
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

    /** Bubble-Tea Init — returns the bootstrap Cmd (cursor blink, first tick, etc.) or null. */
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

        // Vim mode handling
        if ($this->vimMode) {
            return $this->vimUpdate($msg);
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
            KeyType::Escape    => [$this, null],
            default            => [$this, null],
        };
    }

    /**
     * Handle vim mode key events.
     *
     * @return array{0:Model, 1:?\Closure}
     */
    private function vimUpdate(KeyMsg $msg): array
    {
        // In normal mode, handle vim navigation and commands
        if ($this->vimNormalMode) {
            // Escape does nothing in normal mode (already there)
            // Arrow keys work in both modes
            if ($msg->type === KeyType::Left) {
                return [$this->moveCursor(max(0, $this->cursorPos - 1)), null];
            }
            if ($msg->type === KeyType::Right) {
                return [$this->moveCursor(min($this->length(), $this->cursorPos + 1)), null];
            }

            // h key also moves left
            if ($msg->type === KeyType::Char && $msg->rune === 'h' && !$msg->ctrl) {
                return [$this->moveCursor(max(0, $this->cursorPos - 1)), null];
            }
            // l key also moves right
            if ($msg->type === KeyType::Char && $msg->rune === 'l' && !$msg->ctrl) {
                return [$this->moveCursor(min($this->length(), $this->cursorPos + 1)), null];
            }
            // w = word forward
            if ($msg->type === KeyType::Char && $msg->rune === 'w' && !$msg->ctrl) {
                return [$this->vimWordForward(), null];
            }
            // b = word backward
            if ($msg->type === KeyType::Char && $msg->rune === 'b' && !$msg->ctrl) {
                return [$this->vimWordBackward(), null];
            }
            // 0 = beginning of line
            if ($msg->type === KeyType::Char && $msg->rune === '0' && !$msg->ctrl) {
                return [$this->moveCursor(0), null];
            }
            // $ = end of line
            if ($msg->type === KeyType::Char && $msg->rune === '$' && !$msg->ctrl) {
                return [$this->moveCursor($this->length()), null];
            }
            // x = delete character under cursor (like vim)
            if ($msg->type === KeyType::Char && $msg->rune === 'x' && !$msg->ctrl) {
                return [$this->vimDeleteChar(), null];
            }
            // i = enter insert mode
            if ($msg->type === KeyType::Char && $msg->rune === 'i' && !$msg->ctrl) {
                return [$this->mutate(vimNormalMode: false), null];
            }
            // a = append (move cursor right, enter insert mode)
            if ($msg->type === KeyType::Char && $msg->rune === 'a' && !$msg->ctrl) {
                $nextPos = min($this->length(), $this->cursorPos + 1);
                return [$this->mutate(cursorPos: $nextPos, vimNormalMode: false), null];
            }
            // A = append at end of line, enter insert mode
            if ($msg->type === KeyType::Char && $msg->rune === 'A' && !$msg->ctrl) {
                return [$this->mutate(cursorPos: $this->length(), vimNormalMode: false), null];
            }
            // I = insert at beginning of line, enter insert mode
            if ($msg->type === KeyType::Char && $msg->rune === 'I' && !$msg->ctrl) {
                return [$this->mutate(cursorPos: 0, vimNormalMode: false), null];
            }
            // u = undo (basic implementation)
            if ($msg->type === KeyType::Char && $msg->rune === 'u' && !$msg->ctrl) {
                // TODO: implement undo functionality
                return [$this, null];
            }
            // Ctrl+r = redo
            if ($msg->ctrl && $msg->rune === 'r') {
                // TODO: implement redo functionality
                return [$this, null];
            }

            return [$this, null];
        }

        // In insert mode, behave like normal but watch for Escape
        if ($msg->type === KeyType::Escape) {
            return [$this->mutate(vimNormalMode: true), null];
        }

        // Handle Ctrl combos in insert mode
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

    /**
     * Move cursor to the start of the next word (vim w).
     */
    private function vimWordForward(): self
    {
        $len = $this->length();
        $pos = $this->cursorPos;

        if ($pos >= $len) {
            return $this;
        }

        // Skip current word characters
        while ($pos < $len && $this->isWordChar($pos)) {
            $pos++;
        }
        // Skip non-word characters
        while ($pos < $len && !$this->isWordChar($pos)) {
            $pos++;
        }

        return $this->moveCursor($pos);
    }

    /**
     * Move cursor to the start of the previous word (vim b).
     */
    private function vimWordBackward(): self
    {
        $pos = $this->cursorPos;

        if ($pos <= 0) {
            return $this;
        }

        $pos--; // Move back one from current position

        // Skip non-word characters
        while ($pos > 0 && !$this->isWordChar($pos)) {
            $pos--;
        }
        // Skip word characters
        while ($pos > 0 && $this->isWordChar($pos - 1)) {
            $pos--;
        }

        return $this->moveCursor($pos);
    }

    /**
     * Check if character at position is a word character (alphanumeric or underscore).
     */
    private function isWordChar(int $pos): bool
    {
        if ($pos < 0 || $pos >= $this->length()) {
            return false;
        }
        $char = mb_substr($this->value, $pos, 1, 'UTF-8');
        return $char !== '' && preg_match('/[a-zA-Z0-9_]/', $char) === 1;
    }

    /**
     * Delete character under cursor (vim x).
     */
    private function vimDeleteChar(): self
    {
        $len = $this->length();
        if ($len === 0 || $this->cursorPos >= $len) {
            return $this;
        }

        $before = mb_substr($this->value, 0, $this->cursorPos, 'UTF-8');
        $after = mb_substr($this->value, $this->cursorPos + 1, null, 'UTF-8');

        return $this->mutate(value: $before . $after);
    }

    /** Render the component as a multi-line ANSI string. */
    public function view(): string
    {
        $stylePrompt      = fn (string $s): string => $this->styles !== null ? $this->styles->prompt->render($s)      : $s;
        $stylePlaceholder = fn (string $s): string => $this->styles !== null ? $this->styles->placeholder->render($s) : $s;
        $styleText        = fn (string $s): string => $this->styles !== null ? $this->styles->text->render($s)        : $s;

        // Empty + unfocused with a placeholder: show the placeholder.
        if ($this->value === '' && !$this->focused && $this->placeholder !== '') {
            return $stylePrompt($this->prompt) . $stylePlaceholder($this->placeholder);
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
            return $stylePrompt($this->prompt) . $styleText($slice);
        }

        $sliceLenActual = mb_strlen($slice, 'UTF-8');
        $before = mb_substr($slice, 0, $relPos, 'UTF-8');
        $charAt = $relPos < $sliceLenActual ? mb_substr($slice, $relPos, 1, 'UTF-8') : ' ';
        $after  = $relPos < $sliceLenActual ? mb_substr($slice, $relPos + 1, null, 'UTF-8') : '';

        $cursorView = $this->cursor->setChar($charAt)->view();
        return $stylePrompt($this->prompt) . $styleText($before) . $cursorView . $styleText($after);
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

    /** Release focus; companion to { focus()}. */
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

    /** Configured display width. 0 = no cap. Mirrors Bubbles' `Width()`. */
    public function getWidth(): int  { return $this->width; }

    /** Cursor position (0-based char index). Mirrors Bubbles' `Position()`. */
    public function position(): int  { return $this->cursorPos; }

    /** Mirror of {@see $focused}. Mirrors Bubbles' `Focused()`. */
    public function isFocused(): bool { return $this->focused; }

    /** Read-only accessor for the underlying {@see Cursor}. */
    public function cursor(): Cursor { return $this->cursor; }

    /**
     * Full configured suggestion list. Mirrors Bubbles'
     * `AvailableSuggestions()`.
     *
     * @return list<string>
     */
    public function availableSuggestions(): array { return $this->suggestions; }

    /** Index of the currently-highlighted suggestion. */
    public function currentSuggestionIndex(): int { return $this->currentSuggestionIndex; }

    /**
     * Apply per-element styling. Mirrors upstream Bubbles' `Styles`
     * struct + `SetStyles`. Pass null to clear.
     */
    public function withStyles(?Styles $styles): self
    {
        return $this->mutate(styles: $styles, stylesSet: true);
    }

    public function getStyles(): ?Styles { return $this->styles; }
    public function withCharLimit(int $n): self      { return $this->mutate(charLimit: max(0, $n)); }
    public function withWidth(int $w): self          { return $this->mutate(width: max(0, $w)); }
    public function withEchoMode(EchoMode $m): self  { return $this->mutate(echoMode: $m); }
    public function withEchoChar(string $c): self    { return $this->mutate(echoChar: $c); }

    /**
     * Enable or disable vim keybindings mode.
     *
     * When enabled, the input starts in normal mode with vim-style navigation:
     * - `h/l` or arrows: move left/right
     * - `w/b`: word forward/backward
     * - `0/$`: beginning/end of line
     * - `i/a/A/I`: enter insert mode
     * - `x`: delete character under cursor
     * - `Escape`: return to normal mode
     */
    public function withVimMode(bool $enabled = true): self
    {
        return $this->mutate(vimMode: $enabled, vimNormalMode: true);
    }

    /**
     * Short-form alias for withVimMode.
     */
    public function vimMode(bool $enabled = true): self
    {
        return $this->withVimMode($enabled);
    }

    /**
     * Replace the suggestion pool. Call {@see showSuggestions()} to
     * enable rendering of the matching subset.
     *
     * @param list<string> $candidates
     */
    public function setSuggestions(array $candidates): self
    {
        return $this->mutate(suggestions: array_values($candidates), currentSuggestionIndex: 0);
    }

    public function withSuggestions(array $candidates): self
    {
        return $this->setSuggestions($candidates);
    }

    /** Toggle whether suggestions render below the input. Default off. */
    public function showSuggestions(bool $on = true): self
    {
        return $this->mutate(showSuggestions: $on);
    }

    /**
     * Filter the suggestion pool by current prefix, case-insensitively.
     *
     * @return list<string>
     */
    public function matchedSuggestions(): array
    {
        if ($this->value === '' || $this->suggestions === []) {
            return [];
        }
        $needle = mb_strtolower($this->value, 'UTF-8');
        $out = [];
        foreach ($this->suggestions as $candidate) {
            if (str_starts_with(mb_strtolower($candidate, 'UTF-8'), $needle)) {
                $out[] = $candidate;
            }
        }
        return $out;
    }

    /** Currently-highlighted suggestion (or null when none match). */
    public function currentSuggestion(): ?string
    {
        $matches = $this->matchedSuggestions();
        if ($matches === []) {
            return null;
        }
        $i = $this->currentSuggestionIndex % max(1, count($matches));
        return $matches[$i];
    }

    /**
     * Set a validator. Receives the current value, returns an error
     * message (string) for invalid input or null for valid. The
     * latest message is exposed via {@see err()} after every edit.
     *
     * @param ?\Closure(string): ?string $fn  pass null to clear
     */
    public function withValidator(?\Closure $fn): self
    {
        return $this->mutate(
            validate: $fn,
            validateSet: true,
            err: $fn !== null ? $fn($this->value) : null,
            errSet: true,
        );
    }

    // Short-form aliases.
    public function placeholder(string $p): self  { return $this->withPlaceholder($p); }
    public function prompt(string $p): self       { return $this->withPrompt($p); }
    public function charLimit(int $n): self       { return $this->withCharLimit($n); }
    public function width(int $w): self           { return $this->withWidth($w); }
    public function echoMode(EchoMode $m): self   { return $this->withEchoMode($m); }
    public function echoChar(string $c): self     { return $this->withEchoChar($c); }
    /** @param list<string> $candidates */
    public function suggest(array $candidates): self { return $this->withSuggestions($candidates); }
    public function validator(?\Closure $fn): self   { return $this->withValidator($fn); }
    public function styles(?Styles $styles): self    { return $this->withStyles($styles); }

    /** Latest validator error or null. */
    public function err(): ?string
    {
        return $this->err;
    }

    /** Manually move the cursor (clamped to `[0, length()]`). */
    public function setCursor(int $pos): self
    {
        return $this->moveCursor($pos);
    }

    public function cursorStart(): self
    {
        return $this->moveCursor(0);
    }

    public function cursorEnd(): self
    {
        return $this->moveCursor($this->length());
    }

    /**
     * Insert `$text` at the cursor position. Newlines are stripped (a
     * single-line input can't represent them). Honors `charLimit`.
     */
    public function paste(string $text): self
    {
        $text = str_replace(["\r\n", "\r", "\n"], '', $text);
        return $this->insert($text);
    }

    /** Cycle to the next matching suggestion. No-op when none match. */
    public function nextSuggestion(): self
    {
        $matches = $this->matchedSuggestions();
        if ($matches === []) {
            return $this;
        }
        $next = ($this->currentSuggestionIndex + 1) % count($matches);
        return $this->mutate(currentSuggestionIndex: $next);
    }

    public function prevSuggestion(): self
    {
        $matches = $this->matchedSuggestions();
        if ($matches === []) {
            return $this;
        }
        $count = count($matches);
        $next = ($this->currentSuggestionIndex - 1 + $count) % $count;
        return $this->mutate(currentSuggestionIndex: $next);
    }

    /** Replace the current value with the highlighted suggestion. */
    public function acceptSuggestion(): self
    {
        $s = $this->currentSuggestion();
        return $s !== null ? $this->setValue($s) : $this;
    }

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
        ?array $suggestions = null,
        ?bool $showSuggestions = null,
        ?int $currentSuggestionIndex = null,
        ?\Closure $validate = null, bool $validateSet = false,
        ?string $err = null, bool $errSet = false,
        ?Styles $styles = null, bool $stylesSet = false,
        ?bool $vimMode = null,
        ?bool $vimNormalMode = null,
    ): self {
        $newValue = $value ?? $this->value;
        // Auto-revalidate when the value changes and a validator is set,
        // unless the caller explicitly supplied an err override.
        $resolvedValidate = $validateSet ? $validate : $this->validate;
        if (!$errSet) {
            $err = $resolvedValidate !== null && ($value !== null)
                ? $resolvedValidate($newValue)
                : ($value !== null ? null : $this->err);
        }
        return new self(
            value:                  $newValue,
            cursorPos:              $cursorPos              ?? $this->cursorPos,
            placeholder:            $placeholder            ?? $this->placeholder,
            prompt:                 $prompt                 ?? $this->prompt,
            charLimit:              $charLimit              ?? $this->charLimit,
            width:                  $width                  ?? $this->width,
            focused:                $focused                ?? $this->focused,
            cursor:                 $cursor                 ?? $this->cursor,
            echoMode:               $echoMode               ?? $this->echoMode,
            echoChar:               $echoChar               ?? $this->echoChar,
            offset:                 $offset                 ?? $this->offset,
            suggestions:            $suggestions            ?? $this->suggestions,
            showSuggestions:        $showSuggestions        ?? $this->showSuggestions,
            currentSuggestionIndex: $currentSuggestionIndex ?? $this->currentSuggestionIndex,
            validate:               $resolvedValidate,
            err:                    $err,
            styles:                 $stylesSet ? $styles : $this->styles,
            vimMode:                $vimMode                ?? $this->vimMode,
            vimNormalMode:          $vimNormalMode          ?? $this->vimNormalMode,
        );
    }
}

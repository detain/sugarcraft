<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Model;

use SugarCraft\Forms\TextInput\EchoMode;
use SugarCraft\Forms\TextInput\TextInput;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\ScriptUi\Runtime\Emphasis;
use SugarCraft\ScriptUi\Runtime\Geometry;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Mirrors charmbracelet/gum.input (the single-line editor).
 * Single-line prompt used by {@see \SugarCraft\ScriptUi\Command\InputCommand}.
 * Wraps {@see TextInput}; Enter submits, Esc/Ctrl-C aborts.
 */
final class InputModel implements Model
{
    public static function newPrompt(
        string $placeholder = '',
        bool $password = false,
        string $prompt = '> ',
        string $value = '',
        int $charLimit = 0,
        int $width = 0,
        string $header = '',
    ): self {
        // Untrusted paint (cluster 12).
        $placeholder = \SugarCraft\Core\Util\Sanitize::cellValue($placeholder);
        $prompt = \SugarCraft\Core\Util\Sanitize::cellValue($prompt);
        $value = \SugarCraft\Core\Util\Sanitize::cellValue($value);
        $header = \SugarCraft\Core\Util\Sanitize::cellValue($header);

        $ti = TextInput::new()
            ->withPlaceholder($placeholder)
            ->withPrompt($prompt);
        if ($charLimit > 0) {
            $ti = $ti->withCharLimit($charLimit);
        }
        // scriptui-14 (finding 004): `input` never consulted Geometry — a
        // 0 width left the TextInput scroll window unbounded, so long
        // values emitted lines past the terminal columns and the wrapped
        // tail desynced the line-addressed frame diff. Same resolution
        // rule as the lists: an explicit width is capped by the terminal,
        // an unset one takes the columns minus the line chrome (the prompt
        // and any prefix/suffix, which ride OUTSIDE the widget's width
        // budget); no knowable size keeps the historical uncapped window.
        $cols   = Geometry::columnsOrNull();
        $chrome = Width::string($ti->prefix . $ti->prompt . $ti->suffix);
        $frame  = $cols ?? 0;
        if ($width > 0) {
            $width = $cols !== null ? min($width, $cols - $chrome) : $width;
        } elseif ($cols !== null) {
            $width = max(1, $cols - $chrome);
        }
        if ($width > 0) {
            $ti = $ti->withWidth($width);
        }
        if ($value !== '') {
            $ti = $ti->setValue($value);
        }
        if ($password) {
            $ti = $ti->withEchoMode(EchoMode::Password);
        }
        [$ti, ] = $ti->focus();
        return new self($ti, false, false, $header, frameCols: $frame);
    }

    private function __construct(
        public readonly TextInput $input,
        public readonly bool $submitted,
        public readonly bool $aborted,
        public readonly string $header = '',
        public readonly bool $interrupted = false,
        // scriptui-14: the terminal columns resolved at launch (0 = not
        // knowable, no cap). The header line and the ghost placeholder are
        // composed around the widget, so the per-line budget is enforced on
        // the WHOLE frame, not just the editor window.
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
            if ($msg->type === KeyType::Escape || ($msg->ctrl && $msg->rune === 'c')) {
                return [new self($this->input, false, true, $this->header, $msg->ctrl, $this->frameCols), Cmd::quit()];
            }
            if ($msg->type === KeyType::Enter) {
                return [new self($this->input, true, false, $this->header, frameCols: $this->frameCols), Cmd::quit()];
            }
        }
        [$next, $cmd] = $this->input->update($msg);
        return [new self($next, false, false, $this->header, frameCols: $this->frameCols), $cmd];
    }

    public function view(): string
    {
        $body = $this->ghostText();
        return $this->frame(
            $this->header === ''
                ? $body
                : $this->header . "\n" . $body
        );
    }

    /**
     * scriptui-14 (finding 004): last-line budget for every emitted line.
     * The widget window already respects it; this catches the header row and
     * the composed ghost line (the same per-line no-ellipsis truncation the
     * list bodies use). 0 cols = historical unbounded frame.
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
     * scriptui-17: the widget paints its placeholder only while UNFOCUSED
     * (TextInput empty+!focused gate) — and this prompt holds focus for the
     * whole session, so `input --placeholder 'Your name?'` rendered nothing.
     * While the value is empty the candy side owns the ghost: the block
     * cursor carries the first character (reverse video keeps it legible),
     * the remainder rides the widget's faint placeholder style. The first
     * keystroke moves the value off '' and the ghost is gone.
     */
    private function ghostText(): string
    {
        $ti = $this->input;
        if ($ti->value !== '' || !$ti->focused || $ti->placeholder === '') {
            return Emphasis::stripDecor($ti->view());
        }

        $ghost = $ti->placeholder;
        $first = mb_substr($ghost, 0, 1, 'UTF-8');
        $rest  = mb_substr($ghost, 1, null, 'UTF-8');

        return Emphasis::stripDecor(
            $ti->prefix
            . $ti->prompt
            . $ti->cursor->setChar($first)->view()
            . ($rest === '' ? '' : Emphasis::sgr(Ansi::FAINT) . $rest . Emphasis::reset())
            . $ti->suffix,
        );
    }

    public function value(): string { return $this->input->value; }
    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }
    public function isInterrupted(): bool { return $this->interrupted; }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}

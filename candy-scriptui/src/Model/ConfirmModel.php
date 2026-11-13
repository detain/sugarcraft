<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Model;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\ScriptUi\Runtime\Emphasis;

/**
 * Mirrors charmbracelet/gum.confirm (the answer model).
 * Yes/no prompt used by {@see \SugarCraft\ScriptUi\Command\ConfirmCommand}.
 * Wraps {@see Confirm}; Enter / `y` / `n` commit, Esc/Ctrl-C aborts.
 *
 * On exit the host process maps {@see answer()} to the conventional
 * shell exit codes: 0 for yes, 1 for no, 2 for abort.
 */
final class ConfirmModel implements Model
{
    public static function newPrompt(
        string $title = '',
        bool $default = false,
        string $affirmative = 'Yes',
        string $negative = 'No',
        string $pointer = '',
    ): self {
        // Untrusted paint (cluster 12).
        $title = \SugarCraft\Core\Util\Sanitize::cellValue($title);
        $affirmative = \SugarCraft\Core\Util\Sanitize::cellValue($affirmative);
        $negative = \SugarCraft\Core\Util\Sanitize::cellValue($negative);

        $field = Confirm::new('confirm', $default)
            ->withLabels($affirmative, $negative);
        if ($title !== '') {
            $field = $field->withTitle($title);
        }
        [$field, ] = $field->focus();
        return new self($field, false, false, pointer: \SugarCraft\Core\Util\Sanitize::cellValue($pointer));
    }

    private function __construct(
        public readonly Confirm $field,
        public readonly bool $submitted,
        public readonly bool $aborted,
        public readonly bool $interrupted = false,
        // scriptui-84: marker drawn before the currently chosen answer, on
        // top of the reverse-video pill ('' = the historical pill-only look).
        public readonly string $pointer = '',
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
                // Ctrl+C and Esc both abort, but they are DIFFERENT exits:
                // the shell convention reserves 130 for cancellation, so the
                // command layer must be able to tell them apart.
                return [new self($this->field, false, true, $msg->ctrl, $this->pointer), Cmd::quit()];
            }
            // Direct y / n keys also commit.
            if (!$msg->ctrl && $msg->type === KeyType::Char) {
                if ($msg->rune === 'y' || $msg->rune === 'Y') {
                    $next = $this->field->withDefault(true);
                    return [new self(self::refocus($next), true, false, false, $this->pointer), Cmd::quit()];
                }
                if ($msg->rune === 'n' || $msg->rune === 'N') {
                    $next = $this->field->withDefault(false);
                    return [new self(self::refocus($next), true, false, false, $this->pointer), Cmd::quit()];
                }
            }
            if ($msg->type === KeyType::Enter) {
                return [new self($this->field, true, false, false, $this->pointer), Cmd::quit()];
            }
        }
        [$next, $cmd] = $this->field->update($msg);
        if (!$next instanceof Confirm) {
            return [$this, $cmd];
        }
        return [new self($next, false, false, false, $this->pointer), $cmd];
    }

    public function view(): string
    {
        $view = $this->field->view();
        if ($this->pointer !== '') {
            $view = $this->withPointer($view);
        }
        return Emphasis::stripDecor($view);
    }

    public function answer(): bool       { return (bool) $this->field->value(); }
    public function isSubmitted(): bool  { return $this->submitted; }
    public function isAborted(): bool    { return $this->aborted; }
    public function isInterrupted(): bool { return $this->interrupted; }

    /**
     * Pad the pill row so the pointer sits before the chosen pill while the
     * other pill keeps the same indent: the row never jumps as the answer
     * toggles. The pill row is always the last line of the field view.
     */
    private function withPointer(string $view): string
    {
        $lines = explode("\n", $view);
        $row = array_pop($lines);
        $pad = str_repeat(' ', \SugarCraft\Core\Util\Width::string($this->pointer));
        $marker = $this->pointer;
        $yes = $this->answer();
        // Layout is "<yes pill>   <no pill>" (themed escapes may wrap each
        // pill); split on the 3-space gutter the field itself emits.
        $gutter = strpos($row, '   ');
        if ($gutter === false) {
            return $view;
        }
        $left = substr($row, 0, $gutter);
        $right = substr($row, $gutter + 3);
        $lines[] = ($yes ? $marker : $pad) . $left . '   ' . ($yes ? $pad : $marker) . $right;
        return implode("\n", $lines);
    }

    private static function refocus(Confirm $f): Confirm
    {
        [$out, ] = $f->focus();
        return $out;
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}

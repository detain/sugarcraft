<?php

declare(strict_types=1);

namespace CandyCore\Shell\Model;

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Util\Ansi;
use CandyCore\Prompt\Field\Confirm;

/**
 * Yes/no prompt used by {@see \CandyCore\Shell\Command\ConfirmCommand}.
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
    ): self {
        $field = Confirm::new('confirm', $default)
            ->withLabels($affirmative, $negative);
        if ($title !== '') {
            $field = $field->withTitle($title);
        }
        [$field, ] = $field->focus();
        return new self($field, false, false);
    }

    private function __construct(
        public readonly Confirm $field,
        public readonly bool $submitted,
        public readonly bool $aborted,
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
                return [new self($this->field, false, true), Cmd::quit()];
            }
            // Direct y / n keys also commit.
            if (!$msg->ctrl && $msg->type === KeyType::Char) {
                if ($msg->rune === 'y' || $msg->rune === 'Y') {
                    $next = $this->field->withDefault(true);
                    return [new self(self::refocus($next), true, false), Cmd::quit()];
                }
                if ($msg->rune === 'n' || $msg->rune === 'N') {
                    $next = $this->field->withDefault(false);
                    return [new self(self::refocus($next), true, false), Cmd::quit()];
                }
            }
            if ($msg->type === KeyType::Enter) {
                return [new self($this->field, true, false), Cmd::quit()];
            }
        }
        [$next, $cmd] = $this->field->update($msg);
        if (!$next instanceof Confirm) {
            return [$this, $cmd];
        }
        return [new self($next, false, false), $cmd];
    }

    public function view(): string
    {
        return $this->field->view();
    }

    public function answer(): bool       { return (bool) $this->field->value(); }
    public function isSubmitted(): bool  { return $this->submitted; }
    public function isAborted(): bool    { return $this->aborted; }

    private static function refocus(Confirm $f): Confirm
    {
        [$out, ] = $f->focus();
        return $out;
    }
}

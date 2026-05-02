<?php

declare(strict_types=1);

namespace CandyCore\Shell\Model;

use CandyCore\Bits\TextInput\EchoMode;
use CandyCore\Bits\TextInput\TextInput;
use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

/**
 * Single-line prompt used by {@see \CandyCore\Shell\Command\InputCommand}.
 * Wraps {@see TextInput}; Enter submits, Esc/Ctrl-C aborts.
 */
final class InputModel implements Model
{
    public static function newPrompt(string $placeholder = '', bool $password = false): self
    {
        $ti = TextInput::new()->withPlaceholder($placeholder);
        if ($password) {
            $ti = $ti->withEchoMode(EchoMode::Password);
        }
        [$ti, ] = $ti->focus();
        return new self($ti, false, false);
    }

    private function __construct(
        public readonly TextInput $input,
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
                return [new self($this->input, false, true), Cmd::quit()];
            }
            if ($msg->type === KeyType::Enter) {
                return [new self($this->input, true, false), Cmd::quit()];
            }
        }
        [$next, $cmd] = $this->input->update($msg);
        return [new self($next, false, false), $cmd];
    }

    public function view(): string
    {
        return $this->input->view();
    }

    public function value(): string { return $this->input->value; }
    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }
}

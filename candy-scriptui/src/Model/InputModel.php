<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Model;

use SugarCraft\Forms\TextInput\EchoMode;
use SugarCraft\Forms\TextInput\TextInput;
use SugarCraft\ScriptUi\Runtime\Emphasis;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
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
        return new self($ti, false, false, $header);
    }

    private function __construct(
        public readonly TextInput $input,
        public readonly bool $submitted,
        public readonly bool $aborted,
        public readonly string $header = '',
        public readonly bool $interrupted = false,
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
                return [new self($this->input, false, true, $this->header, $msg->ctrl), Cmd::quit()];
            }
            if ($msg->type === KeyType::Enter) {
                return [new self($this->input, true, false, $this->header), Cmd::quit()];
            }
        }
        [$next, $cmd] = $this->input->update($msg);
        return [new self($next, false, false, $this->header), $cmd];
    }

    public function view(): string
    {
        $body = Emphasis::stripDecor($this->input->view());
        return $this->header === ''
            ? $body
            : $this->header . "\n" . $body;
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

<?php

declare(strict_types=1);

namespace CandyCore\Shell\Model;

use CandyCore\Bits\TextArea\TextArea;
use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

/**
 * Multi-line editor used by {@see \CandyCore\Shell\Command\WriteCommand}.
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
    ): self {
        $area = TextArea::new()->withPlaceholder($placeholder);
        if ($width  > 0)            { $area = $area->withWidth($width); }
        if ($height > 0)            { $area = $area->withHeight($height); }
        if ($charLimit > 0)         { $area = $area->withCharLimit($charLimit); }
        if ($maxLines > 0)          { $area = $area->withMaxHeight($maxLines); }
        if ($prompt !== '')         { $area = $area->withPrompt($prompt); }
        if ($showLineNumbers)       { $area = $area->showLineNumbers(true); }
        if ($value !== '')          { $area = $area->setValue($value); }
        [$area, ] = $area->focus();
        return new self($area, false, false, $header);
    }

    private function __construct(
        public readonly TextArea $area,
        public readonly bool $submitted,
        public readonly bool $aborted,
        public readonly string $header = '',
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
                return [new self($this->area, false, true, $this->header), Cmd::quit()];
            }
            if ($msg->ctrl && $msg->rune === 'd') {
                return [new self($this->area, true, false, $this->header), Cmd::quit()];
            }
            if ($msg->ctrl && $msg->rune === 'c') {
                return [new self($this->area, false, true, $this->header), Cmd::quit()];
            }
        }
        [$next, $cmd] = $this->area->update($msg);
        return [new self($next, false, false, $this->header), $cmd];
    }

    public function view(): string
    {
        $body = $this->area->view();
        return $this->header === '' ? $body : $this->header . "\n" . $body;
    }
    public function value(): string { return $this->area->value(); }
    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }
}

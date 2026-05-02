<?php

declare(strict_types=1);

namespace CandyCore\Shell\Model;

use CandyCore\Bits\FilePicker\FilePicker;
use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

/**
 * Filesystem picker used by {@see \CandyCore\Shell\Command\FileCommand}.
 * Wraps {@see FilePicker}; the picker handles Enter/Backspace internally
 * for navigation; we add Esc/Ctrl-C abort and short-circuit when a path
 * is selected.
 */
final class FileModel implements Model
{
    public static function newPrompt(?string $cwd = null, int $height = 10): self
    {
        $picker = FilePicker::new($cwd, max(1, $height));
        [$picker, ] = $picker->focus();
        return new self($picker, false);
    }

    private function __construct(
        public readonly FilePicker $picker,
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
        if ($this->aborted || $this->picker->selected() !== null) {
            return [$this, null];
        }
        if ($msg instanceof KeyMsg
            && ($msg->type === KeyType::Escape || ($msg->ctrl && $msg->rune === 'c'))) {
            return [new self($this->picker, true), Cmd::quit()];
        }
        [$next, $cmd] = $this->picker->update($msg);
        $self = new self($next, false);
        if ($next->selected() !== null && !$next->highlightedEntry()?->isDir) {
            // File selected — quit; cwd-only descents keep the picker open.
            return [$self, Cmd::quit()];
        }
        return [$self, $cmd];
    }

    public function view(): string  { return $this->picker->view(); }
    public function selected(): ?string { return $this->picker->selected(); }
    public function isAborted(): bool   { return $this->aborted; }
    public function isSubmitted(): bool { return !$this->aborted && $this->picker->selected() !== null; }
}

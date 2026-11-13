<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Model;

use SugarCraft\Forms\FilePicker\FilePicker;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Filesystem picker used by {@see \SugarCraft\ScriptUi\Command\FileCommand}.
 * Wraps {@see FilePicker}; the picker handles Enter/Backspace internally
 * for navigation; we add Esc/Ctrl-C abort and short-circuit when a path
 * is selected.
 */
final class FileModel implements Model
{
    /**
     * @param bool $allowDirs    When true, Enter on a directory selects it
     *                           rather than descending. Used for `--directory`.
     * @param bool $allowFiles   When false, files are listed but cannot be
     *                           selected (paired with `--directory`).
     * @param bool $showHidden   Include dotfiles in the listing.
     * @param bool $showSize     Render the file-size column.
     * @param list<string> $allowedExtensions  Only files with these
     *        extensions are listed (scriptui-45; empty = all; directories
     *        always ride through — the widget owns normalisation).
     */
    public static function newPrompt(
        ?string $cwd = null,
        int $height = 10,
        bool $allowDirs = false,
        bool $allowFiles = true,
        bool $showHidden = false,
        bool $showSize = false,
        array $allowedExtensions = [],
    ): self {
        $picker = FilePicker::new($cwd, max(1, $height))
            ->withDirAllowed($allowDirs)
            ->withFileAllowed($allowFiles)
            ->withShowHidden($showHidden)
            ->withShowSize($showSize);
        if ($allowedExtensions !== []) {
            // scriptui-45 (finding 063): the widget's own readdir predicate —
            // the picker normalises (lower-case, leading dot trimmed).
            $picker = $picker->withAllowedExtensions($allowedExtensions);
        }
        [$picker, ] = $picker->focus();
        return new self($picker, false);
    }

    private function __construct(
        public readonly FilePicker $picker,
        public readonly bool $aborted,
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
        if ($this->aborted || $this->picker->selected() !== null) {
            return [$this, null];
        }
        if ($msg instanceof KeyMsg
            && ($msg->type === KeyType::Escape || ($msg->ctrl && $msg->rune === 'c'))) {
            return [new self($this->picker, true, $msg->ctrl), Cmd::quit()];
        }
        [$next, $cmd] = $this->picker->update($msg);
        $self = new self($next, false);
        if ($next->selected() !== null) {
            // Selection lands once the picker confirms — for files when
            // dirAllowed=false, or for directories when it's true.
            return [$self, Cmd::quit()];
        }
        return [$self, $cmd];
    }

    public function view(): string  { return $this->picker->view(); }
    public function selected(): ?string { return $this->picker->selected(); }
    public function isAborted(): bool   { return $this->aborted; }
    public function isInterrupted(): bool { return $this->interrupted; }
    public function isSubmitted(): bool { return !$this->aborted && $this->picker->selected() !== null; }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}

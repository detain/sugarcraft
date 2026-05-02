<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Field;

use CandyCore\Bits\FilePicker\FilePicker as PickerWidget;
use CandyCore\Core\Msg;
use CandyCore\Prompt\Field;

/**
 * File-system picker field. Wraps {@see PickerWidget}; the field's value
 * is whatever path the picker has selected (`null` until the user
 * confirms a choice).
 *
 * Enter and Backspace are forwarded to the picker (descend / ascend) so
 * the field declares itself a consumer of those keys.
 */
final class FilePicker implements Field
{
    private function __construct(
        public readonly string $key,
        public readonly PickerWidget $picker,
        public readonly string $title,
        public readonly string $description,
    ) {}

    public static function new(string $key, ?string $cwd = null): self
    {
        return new self($key, PickerWidget::new($cwd), '', '');
    }

    public function withTitle(string $t): self        { return $this->mutate(title: $t); }
    public function withDescription(string $d): self  { return $this->mutate(description: $d); }
    public function withShowHidden(bool $on): self    { return $this->mutate(picker: $this->picker->withShowHidden($on)); }
    /** @param list<string> $exts */
    public function withAllowedExtensions(array $exts): self
    {
        return $this->mutate(picker: $this->picker->withAllowedExtensions($exts));
    }
    public function withDirAllowed(bool $on): self  { return $this->mutate(picker: $this->picker->withDirAllowed($on)); }
    public function withFileAllowed(bool $on): self { return $this->mutate(picker: $this->picker->withFileAllowed($on)); }
    public function withHeight(int $h): self        { return $this->mutate(picker: $this->picker->withHeight($h)); }

    public function key(): string  { return $this->key; }
    public function value(): mixed { return $this->picker->selected(); }

    public function focus(): array
    {
        [$p, $cmd] = $this->picker->focus();
        return [$this->mutate(picker: $p), $cmd];
    }

    public function blur(): Field
    {
        return $this->mutate(picker: $this->picker->blur());
    }

    public function update(Msg $msg): array
    {
        [$p, $cmd] = $this->picker->update($msg);
        return [$this->mutate(picker: $p), $cmd];
    }

    public function view(): string
    {
        $lines = [];
        if ($this->title !== '')       { $lines[] = $this->title; }
        if ($this->description !== '') { $lines[] = $this->description; }
        $lines[] = $this->picker->view();
        if ($this->picker->selected() !== null) {
            $lines[] = '→ ' . $this->picker->selected();
        }
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->picker->focused; }
    public function getTitle(): string       { return $this->title; }
    public function getDescription(): string { return $this->description; }
    public function getError(): ?string      { return null; }
    public function skippable(): bool        { return false; }

    /**
     * Enter (descend / select) and Backspace (ascend) are owned by the
     * picker; the form must not absorb them.
     */
    public function consumes(Msg $msg): bool
    {
        if (!$this->picker->focused || !$msg instanceof \CandyCore\Core\Msg\KeyMsg) {
            return false;
        }
        return $msg->type === \CandyCore\Core\KeyType::Enter
            || $msg->type === \CandyCore\Core\KeyType::Backspace;
    }

    private function mutate(?PickerWidget $picker = null, ?string $title = null, ?string $description = null): self
    {
        return new self(
            key:         $this->key,
            picker:      $picker      ?? $this->picker,
            title:       $title       ?? $this->title,
            description: $description ?? $this->description,
        );
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use SugarCraft\Core\Msg;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\FilePicker\FilePicker as PickerWidget;
use SugarCraft\Forms\HasDynamicLabels;
use SugarCraft\Forms\HasHideFunc;

/**
 * File-system picker field. Wraps {@see PickerWidget}; the field's value
 * is whatever path the picker has selected (`null` until the user
 * confirms a choice).
 *
 * Enter and Backspace are forwarded to the picker (descend / ascend) so
 * the field declares itself a consumer of those keys.
 */
final class FilePicker implements \SugarCraft\Forms\Field
{
    use HasHideFunc;
    use HasDynamicLabels;

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

    // Short-form aliases.
    public function title(string $t): self            { return $this->withTitle($t); }
    public function desc(string $d): self             { return $this->withDescription($d); }
    public function showHidden(bool $on): self        { return $this->withShowHidden($on); }
    /** @param list<string> $exts */
    public function exts(array $exts): self           { return $this->withAllowedExtensions($exts); }
    public function dirAllowed(bool $on): self        { return $this->withDirAllowed($on); }
    public function fileAllowed(bool $on): self       { return $this->withFileAllowed($on); }
    public function height(int $h): self              { return $this->withHeight($h); }

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
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') { $lines[] = $title; }
        if ($desc  !== '') { $lines[] = $desc; }
        $lines[] = $this->picker->view();
        if ($this->picker->selected() !== null) {
            $lines[] = '→ ' . $this->picker->selected();
        }
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->picker->focused; }
    public function getTitle(): string       { return $this->resolveTitle($this->title); }
    public function getDescription(): string { return $this->resolveDescription($this->description); }
    public function getError(): ?string      { return null; }
    public function revalidate(): Field      { return $this; }
    public function skippable(): bool       { return false; }

    /**
     * Enter (descend / select) and Backspace (ascend) are owned by the
     * picker; the form must not absorb them. Up / Down move the cursor
     * between entries and must not be stolen for between-field nav.
     */
    public function consumes(Msg $msg): bool
    {
        if (!$this->picker->focused || !$msg instanceof \SugarCraft\Core\Msg\KeyMsg) {
            return false;
        }
        return $msg->type === \SugarCraft\Core\KeyType::Enter
            || $msg->type === \SugarCraft\Core\KeyType::Backspace
            || $msg->type === \SugarCraft\Core\KeyType::Up
            || $msg->type === \SugarCraft\Core\KeyType::Down;
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
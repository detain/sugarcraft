<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\HasDynamicLabels;
use SugarCraft\Forms\HasHideFunc;

/**
 * Read-only paragraph. Renders title + description; tab navigation
 * skips over the field by default. When {@see withNext()} is enabled,
 * the Note becomes interactive: a labelled button is rendered after
 * the description and Enter / Space activates it (mirrors huh's
 * `Note.Next` / `NextLabel`).
 */
final class Note implements \SugarCraft\Forms\Field
{
    use HasHideFunc;
    use HasDynamicLabels;

    public function __construct(
        public readonly string $key,
        public readonly string $title = '',
        public readonly string $description = '',
        public readonly int $height = 0,
        public readonly bool $next = false,
        public readonly string $nextLabel = 'Next',
        public readonly bool $focused = false,
    ) {}

    public static function new(string $key): self
    {
        return new self($key);
    }

    public function withTitle(string $t): self       { return new self($this->key, $t, $this->description, $this->height, $this->next, $this->nextLabel, $this->focused); }
    public function withDescription(string $d): self { return new self($this->key, $this->title, $d, $this->height, $this->next, $this->nextLabel, $this->focused); }

    /**
     * Pin the rendered note to a fixed row count. Padding-only — short
     * content is bottom-padded with blanks; over-long content still
     * renders in full so callers don't lose information silently.
     * Default 0 = render at natural height. Mirrors huh's `Note.Height`.
     */
    public function withHeight(int $rows): self
    {
        return new self($this->key, $this->title, $this->description, max(0, $rows), $this->next, $this->nextLabel, $this->focused);
    }

    /**
     * Show a confirm-style button after the description. When on, the
     * Note participates in form navigation (no longer skippable) and
     * Enter / Space advances to the next field. Mirrors huh's
     * `Note.Next(bool)`.
     */
    public function withNext(bool $on = true): self
    {
        return new self($this->key, $this->title, $this->description, $this->height, $on, $this->nextLabel, $this->focused);
    }

    /**
     * Override the button label rendered by {@see withNext()}. Default
     * `Next`. Mirrors huh's `Note.NextLabel`.
     */
    public function withNextLabel(string $label): self
    {
        return new self($this->key, $this->title, $this->description, $this->height, $this->next, $label, $this->focused);
    }

    // Short-form aliases.
    public function title(string $t): self        { return $this->withTitle($t); }
    public function desc(string $d): self         { return $this->withDescription($d); }
    public function height(int $rows): self       { return $this->withHeight($rows); }
    public function next(bool $on = true): self   { return $this->withNext($on); }
    public function nextLabel(string $l): self    { return $this->withNextLabel($l); }

    /** Resolved button label (post-default). */
    public function getNextLabel(): string { return $this->nextLabel; }
    /** True when the Next button is rendered & participates in nav. */
    public function isNext(): bool { return $this->next; }
    /** Configured row budget; 0 means natural height. */
    public function getHeight(): int { return $this->height; }

    public function key(): string         { return $this->key; }
    public function value(): mixed        { return null; }
    public function focus(): array        { return [new self($this->key, $this->title, $this->description, $this->height, $this->next, $this->nextLabel, true), null]; }
    public function blur(): Field         { return new self($this->key, $this->title, $this->description, $this->height, $this->next, $this->nextLabel, false); }
    public function update(Msg $msg): array { return [$this, null]; }

    public function view(): string
    {
        $parts = [];
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') { $parts[] = $title; }
        if ($desc  !== '') { $parts[] = $desc; }
        if ($this->next) {
            $marker = $this->focused ? '> ' : '  ';
            $parts[] = $marker . '[ ' . $this->nextLabel . ' ]';
        }
        $body = implode("\n", $parts);
        if ($this->height > 0) {
            $rows = explode("\n", $body);
            while (count($rows) < $this->height) {
                $rows[] = '';
            }
            $body = implode("\n", $rows);
        }
        return $body;
    }

    public function isFocused(): bool         { return $this->focused; }
    public function getTitle(): string        { return $this->resolveTitle($this->title); }
    public function getDescription(): string  { return $this->resolveDescription($this->description); }
    public function getError(): ?string       { return null; }
    public function revalidate(): Field        { return $this; }

    public function skippable(): bool         { return !$this->next; }
    public function consumes(Msg $msg): bool  { return false; }
}
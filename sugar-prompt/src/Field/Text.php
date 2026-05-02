<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Field;

use CandyCore\Bits\TextArea\TextArea;
use CandyCore\Core\Msg;
use CandyCore\Prompt\Field;
use CandyCore\Prompt\HasHideFunc;

/**
 * Multi-line text field. Wraps a {@see TextArea}; Enter inserts a newline
 * inside the field rather than advancing the form, so the field declares
 * itself a consumer of Enter via {@see consumes()}.
 */
final class Text implements Field
{
    use HasHideFunc;

    /** @var (\Closure(string):?string)|null */
    private $validator;

    private function __construct(
        public readonly string $key,
        public readonly TextArea $area,
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $error,
        ?\Closure $validator = null,
    ) {
        $this->validator = $validator;
    }

    public static function new(string $key): self
    {
        return new self(
            key: $key,
            area: TextArea::new(),
            title: '',
            description: '',
            error: null,
        );
    }

    public function withTitle(string $t): self        { return $this->mutate(title: $t); }
    public function withDescription(string $d): self  { return $this->mutate(description: $d); }
    public function withPlaceholder(string $p): self  { return $this->mutate(area: $this->area->withPlaceholder($p)); }
    public function withCharLimit(int $n): self       { return $this->mutate(area: $this->area->withCharLimit($n)); }
    public function withWidth(int $w): self           { return $this->mutate(area: $this->area->withWidth($w)); }
    public function withHeight(int $h): self          { return $this->mutate(area: $this->area->withHeight($h)); }

    /** @param \Closure(string):?string $fn */
    public function withValidator(\Closure $fn): self
    {
        return new self($this->key, $this->area, $this->title, $this->description, $this->error, $fn);
    }

    public function key(): string  { return $this->key; }
    public function value(): mixed { return $this->area->value(); }

    public function focus(): array
    {
        [$a, $cmd] = $this->area->focus();
        return [$this->mutate(area: $a), $cmd];
    }

    public function blur(): Field
    {
        return $this->mutate(area: $this->area->blur());
    }

    public function update(Msg $msg): array
    {
        [$a, $cmd] = $this->area->update($msg);
        $next = $this->mutate(area: $a)->validate();
        return [$next, $cmd];
    }

    public function view(): string
    {
        $lines = [];
        if ($this->title !== '')       { $lines[] = $this->title; }
        if ($this->description !== '') { $lines[] = $this->description; }
        $lines[] = $this->area->view();
        if ($this->error !== null)     { $lines[] = '! ' . $this->error; }
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->area->focused; }
    public function getTitle(): string       { return $this->title; }
    public function getDescription(): string { return $this->description; }
    public function getError(): ?string      { return $this->error; }
    public function skippable(): bool        { return false; }

    /**
     * Enter inside a Text field inserts a newline rather than advancing
     * the form. Up / Down move the inner cursor between lines and must
     * not be hijacked by the form's between-field navigation.
     */
    public function consumes(Msg $msg): bool
    {
        if (!$this->area->focused || !$msg instanceof \CandyCore\Core\Msg\KeyMsg) {
            return false;
        }
        return $msg->type === \CandyCore\Core\KeyType::Enter
            || $msg->type === \CandyCore\Core\KeyType::Up
            || $msg->type === \CandyCore\Core\KeyType::Down;
    }

    private function validate(): self
    {
        if ($this->validator === null) {
            return $this;
        }
        $err = ($this->validator)($this->area->value());
        if ($err === $this->error) {
            return $this;
        }
        return new self($this->key, $this->area, $this->title, $this->description, $err, $this->validator);
    }

    private function mutate(?TextArea $area = null, ?string $title = null, ?string $description = null): self
    {
        return new self(
            key:         $this->key,
            area:        $area        ?? $this->area,
            title:       $title       ?? $this->title,
            description: $description ?? $this->description,
            error:       $this->error,
            validator:   $this->validator,
        );
    }
}

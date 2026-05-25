<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use SugarCraft\Core\Msg;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\HasDynamicLabels;
use SugarCraft\Forms\HasHideFunc;
use SugarCraft\Forms\TextArea\TextArea;

/**
 * Multi-line text field. Wraps a {@see TextArea}; Enter inserts a newline
 * inside the field rather than advancing the form, so the field declares
 * itself a consumer of Enter via {@see consumes()}.
 */
final class Text implements \SugarCraft\Forms\Field
{
    use HasHideFunc;
    use HasDynamicLabels;

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

    /**
     * Attach a validation rule using a predicate + error message.
     * The predicate receives the current value and returns true if valid,
     * false if invalid. When invalid the $errorMessage is displayed.
     */
    public function withValidation(callable $predicate, string $errorMessage): self
    {
        return $this->withValidator(
            static fn (string $value): ?string => $predicate($value) ? null : $errorMessage,
        );
    }

    // Short-form aliases.
    public function title(string $t): self        { return $this->withTitle($t); }
    public function desc(string $d): self         { return $this->withDescription($d); }
    public function placeholder(string $p): self  { return $this->withPlaceholder($p); }
    public function charLimit(int $n): self       { return $this->withCharLimit($n); }
    public function width(int $w): self           { return $this->withWidth($w); }
    public function height(int $h): self          { return $this->withHeight($h); }
    public function validator(\Closure $fn): self { return $this->withValidator($fn); }
    public function validation(callable $predicate, string $errorMessage): self { return $this->withValidation($predicate, $errorMessage); }

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
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') { $lines[] = $title; }
        if ($desc  !== '') { $lines[] = $desc; }
        $lines[] = $this->area->view();
        if ($this->error !== null)     { $lines[] = '! ' . $this->error; }
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->area->focused; }
    public function getTitle(): string       { return $this->resolveTitle($this->title); }
    public function getDescription(): string { return $this->resolveDescription($this->description); }
    public function getError(): ?string      { return $this->error; }
    public function skippable(): bool        { return false; }

    /**
     * Enter inside a Text field inserts a newline rather than advancing
     * the form. Up / Down move the inner cursor between lines and must
     * not be hijacked by the form's between-field navigation.
     */
    public function consumes(Msg $msg): bool
    {
        if (!$this->area->focused || !$msg instanceof \SugarCraft\Core\Msg\KeyMsg) {
            return false;
        }
        return $msg->type === \SugarCraft\Core\KeyType::Enter
            || $msg->type === \SugarCraft\Core\KeyType::Up
            || $msg->type === \SugarCraft\Core\KeyType::Down;
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
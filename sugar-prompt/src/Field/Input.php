<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Field;

use CandyCore\Bits\TextInput\TextInput;
use CandyCore\Core\Msg;
use CandyCore\Prompt\Field;
use CandyCore\Prompt\HasHideFunc;

/**
 * Single-line text field. Wraps a {@see TextInput} and exposes an
 * optional validator that runs on every keystroke.
 */
final class Input implements Field
{
    use HasHideFunc;

    /** @var (\Closure(string):?string)|null */
    private $validator;

    private function __construct(
        public readonly string $key,
        public readonly TextInput $input,
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
            input: TextInput::new(),
            title: '',
            description: '',
            error: null,
        );
    }

    public function withTitle(string $t): self       { return $this->mutate(title: $t); }
    public function withDescription(string $d): self { return $this->mutate(description: $d); }
    public function withPlaceholder(string $p): self { return $this->mutate(input: $this->input->withPlaceholder($p)); }
    public function withPrompt(string $p): self      { return $this->mutate(input: $this->input->withPrompt($p)); }
    public function withCharLimit(int $n): self      { return $this->mutate(input: $this->input->withCharLimit($n)); }
    public function withWidth(int $w): self          { return $this->mutate(input: $this->input->withWidth($w)); }

    /** @param \Closure(string):?string $fn returns null on valid, error string on invalid */
    public function withValidator(\Closure $fn): self
    {
        return new self($this->key, $this->input, $this->title, $this->description, $this->error, $fn);
    }

    public function key(): string  { return $this->key; }
    public function value(): mixed { return $this->input->value; }

    public function focus(): array
    {
        [$ti, $cmd] = $this->input->focus();
        return [$this->mutate(input: $ti), $cmd];
    }

    public function blur(): Field
    {
        return $this->mutate(input: $this->input->blur());
    }

    public function update(Msg $msg): array
    {
        [$ti, $cmd] = $this->input->update($msg);
        $next = $this->mutate(input: $ti);
        $next = $next->validate();
        return [$next, $cmd];
    }

    public function view(): string
    {
        $lines = [];
        if ($this->title !== '') {
            $lines[] = $this->title;
        }
        if ($this->description !== '') {
            $lines[] = $this->description;
        }
        $lines[] = $this->input->view();
        if ($this->error !== null) {
            $lines[] = '! ' . $this->error;
        }
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->input->focused; }
    public function getTitle(): string       { return $this->title; }
    public function getDescription(): string { return $this->description; }
    public function getError(): ?string      { return $this->error; }
    public function skippable(): bool        { return false; }
    public function consumes(Msg $msg): bool { return false; }

    private function validate(): self
    {
        if ($this->validator === null) {
            return $this;
        }
        $err = ($this->validator)($this->input->value);
        if ($err === $this->error) {
            return $this;
        }
        return new self($this->key, $this->input, $this->title, $this->description, $err, $this->validator);
    }

    private function mutate(?TextInput $input = null, ?string $title = null, ?string $description = null, ?string $error = null): self
    {
        return new self(
            key:         $this->key,
            input:       $input       ?? $this->input,
            title:       $title       ?? $this->title,
            description: $description ?? $this->description,
            error:       $error       ?? $this->error,
            validator:   $this->validator,
        );
    }
}

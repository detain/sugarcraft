<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Field;

use SugarCraft\Bits\TextInput\TextInput;
use SugarCraft\Core\Msg;
use SugarCraft\Prompt\Field;
use SugarCraft\Prompt\HasDynamicLabels;
use SugarCraft\Prompt\HasHideFunc;

/**
 * Single-line text field. Wraps a {@see TextInput} and exposes an
 * optional validator that runs on every keystroke.
 */
final class Input implements Field
{
    use HasHideFunc;
    use HasDynamicLabels;

    /** @var (\Closure(string):?string)|null */
    private $validator;

    /** @var (\Closure(string):list<string>)|null */
    private $suggestionsFunc;

    private function __construct(
        public readonly string $key,
        public readonly TextInput $input,
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $error,
        ?\Closure $validator = null,
        ?\Closure $suggestionsFunc = null,
    ) {
        $this->validator = $validator;
        $this->suggestionsFunc = $suggestionsFunc;
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

    // Short-form aliases: same behavior, less typing.
    public function title(string $t): self        { return $this->withTitle($t); }
    public function desc(string $d): self         { return $this->withDescription($d); }
    public function placeholder(string $p): self  { return $this->withPlaceholder($p); }
    public function prompt(string $p): self       { return $this->withPrompt($p); }
    public function charLimit(int $n): self       { return $this->withCharLimit($n); }
    public function width(int $w): self           { return $this->withWidth($w); }
    public function password(bool $on = true, string $echoChar = '*'): self { return $this->withPassword($on, $echoChar); }
    public function suggest(array $candidates): self { return $this->withSuggestions($candidates); }
    public function validator(\Closure $fn): self { return $this->withValidator($fn); }
    public function validation(callable $predicate, string $errorMessage): self { return $this->withValidation($predicate, $errorMessage); }

    /**
     * Mask the rendered value with a fixed echo character. Mirrors huh's
     * `Password()` modifier — handy for token / passphrase prompts.
     * Pass empty string to clear masking.
     */
    public function withPassword(bool $on = true, string $echoChar = '*'): self
    {
        if ($on) {
            $next = $this->input
                ->withEchoMode(\SugarCraft\Bits\TextInput\EchoMode::Password)
                ->withEchoChar($echoChar === '' ? '*' : $echoChar);
        } else {
            $next = $this->input->withEchoMode(\SugarCraft\Bits\TextInput\EchoMode::Normal);
        }
        return $this->mutate(input: $next);
    }

    /**
     * Provide an autocomplete pool that the user can cycle with the
     * default TextInput suggestion bindings. Pass an empty list (or omit)
     * to disable. Mirrors huh's `Suggestions([]string)`.
     *
     * @param list<string> $candidates
     */
    public function withSuggestions(array $candidates): self
    {
        $next = $this->input
            ->withSuggestions($candidates)
            ->showSuggestions($candidates !== []);
        return $this->mutate(input: $next);
    }

    /**
     * Dynamic suggestions: the closure receives the current input value
     * (post-keystroke) and returns the candidate list to display. Mirrors
     * huh's `SuggestionsFunc`. The closure is re-evaluated on every
     * keystroke so callers can hit a remote completion endpoint, etc.
     *
     * @param \Closure(string):list<string> $fn
     */
    public function withSuggestionsFunc(\Closure $fn): self
    {
        return new self(
            $this->key,
            $this->input,
            $this->title,
            $this->description,
            $this->error,
            $this->validator,
            $fn,
        );
    }

    /** @param \Closure(string):?string $fn returns null on valid, error string on invalid */
    public function withValidator(\Closure $fn): self
    {
        return new self(
            $this->key,
            $this->input,
            $this->title,
            $this->description,
            $this->error,
            $fn,
            $this->suggestionsFunc,
        );
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
        if ($this->suggestionsFunc !== null) {
            $candidates = ($this->suggestionsFunc)($ti->value);
            $ti = $ti->withSuggestions($candidates)->showSuggestions($candidates !== []);
        }
        $next = $this->mutate(input: $ti);
        $next = $next->validate();
        return [$next, $cmd];
    }

    public function view(): string
    {
        $lines = [];
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') {
            $lines[] = $title;
        }
        if ($desc !== '') {
            $lines[] = $desc;
        }
        $lines[] = $this->input->view();
        if ($this->error !== null) {
            $lines[] = '! ' . $this->error;
        }
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->input->focused; }
    public function getTitle(): string       { return $this->resolveTitle($this->title); }
    public function getDescription(): string { return $this->resolveDescription($this->description); }
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
        return new self($this->key, $this->input, $this->title, $this->description, $err, $this->validator, $this->suggestionsFunc);
    }

    private function mutate(?TextInput $input = null, ?string $title = null, ?string $description = null, ?string $error = null): self
    {
        return new self(
            key:             $this->key,
            input:           $input       ?? $this->input,
            title:           $title       ?? $this->title,
            description:     $description ?? $this->description,
            error:           $error       ?? $this->error,
            validator:       $this->validator,
            suggestionsFunc: $this->suggestionsFunc,
        );
    }
}

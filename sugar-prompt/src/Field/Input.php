<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Field;

use SugarCraft\Bits\TextInput\TextInput;
use SugarCraft\Core\Msg;
use SugarCraft\Prompt\Field;
use SugarCraft\Prompt\HasDynamicLabels;
use SugarCraft\Prompt\HasHideFunc;
use SugarCraft\Prompt\Validator\Validator;

/**
 * Single-line text field. Wraps a {@see TextInput} and exposes an
 * optional validator that runs on every keystroke.
 */
final class Input implements Field
{
    use HasHideFunc;
    use HasDynamicLabels;

    /**
     * @var list<\Closure(string):?string>
     */
    private array $validators = [];

    /** @var (\Closure(string):list<string>)|null */
    private $suggestionsFunc;

    private function __construct(
        public readonly string $key,
        public readonly TextInput $input,
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $error,
        array $validators = [],
        ?\Closure $suggestionsFunc = null,
    ) {
        $this->validators = $validators;
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
            validators: [],
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
            $this->validators,
            $fn,
        );
    }

    /**
     * Attach a validator. Accepts a Validator instance or a closure.
     * Multiple calls chain validators together — each runs in sequence
     * and the first error message is returned.
     *
     * @param Validator|\Closure(string):?string $validator
     */
    public function withValidator(Validator|\Closure $validator): self
    {
        if ($validator instanceof Validator) {
            $fn = static fn (string $v): ?string => match (true) {
                $validator->validate($v) === true => null,
                default => (string) $validator->validate($v),
            };
        } else {
            $fn = $validator;
        }

        $chained = $this->buildChainedValidator($fn);
        return new self(
            $this->key,
            $this->input,
            $this->title,
            $this->description,
            $this->error,
            $chained,
            $this->suggestionsFunc,
        );
    }

    /**
     * Build a new validator closure that runs $fn in sequence with
     * the existing chained validator (first error wins).
     *
     * @param \Closure(string):?string $fn
     * @return list<\Closure(string):?string>
     */
    private function buildChainedValidator(\Closure $fn): array
    {
        $existing = $this->validators;

        // If no existing validators, just return the new one wrapped in array.
        if ($existing === []) {
            return [$fn];
        }

        // Chain: run existing first, then $fn.
        $chained = static function (string $v) use ($existing, $fn): ?string {
            foreach ($existing as $vfn) {
                $err = $vfn($v);
                if ($err !== null) {
                    return $err;
                }
            }
            return $fn($v);
        };

        return [$chained];
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
        if ($this->validators === []) {
            return $this;
        }
        foreach ($this->validators as $vfn) {
            $err = $vfn($this->input->value);
            if ($err !== null) {
                if ($err === $this->error) {
                    return $this;
                }
                return new self($this->key, $this->input, $this->title, $this->description, $err, $this->validators, $this->suggestionsFunc);
            }
        }
        if ($this->error !== null) {
            return new self($this->key, $this->input, $this->title, $this->description, null, $this->validators, $this->suggestionsFunc);
        }
        return $this;
    }

    private function mutate(?TextInput $input = null, ?string $title = null, ?string $description = null, ?string $error = null): self
    {
        return new self(
            key:             $this->key,
            input:           $input       ?? $this->input,
            title:           $title       ?? $this->title,
            description:     $description ?? $this->description,
            error:           $error       ?? $this->error,
            validators:      $this->validators,
            suggestionsFunc: $this->suggestionsFunc,
        );
    }
}

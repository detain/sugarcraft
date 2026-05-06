<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Field;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Util\Ansi;
use CandyCore\Prompt\Field;
use CandyCore\Prompt\HasDynamicLabels;
use CandyCore\Prompt\HasHideFunc;

/**
 * Yes / No question. The user toggles the answer with `←/→`, `h/l`, or
 * `Tab`, or commits a side directly with `y` / `n`.
 */
final class Confirm implements Field
{
    use HasHideFunc;
    use HasDynamicLabels;

    /** @var ?\Closure(bool):?string */
    private ?\Closure $validator = null;

    private function __construct(
        public readonly string $key,
        public readonly bool $value,
        public readonly bool $focused,
        public readonly string $title,
        public readonly string $description,
        public readonly string $affirmative,
        public readonly string $negative,
        public readonly ?string $error = null,
    ) {}

    public static function new(string $key, bool $default = false): self
    {
        return new self($key, $default, false, '', '', 'Yes', 'No');
    }

    public function withTitle(string $t): self       { return $this->mutate(title: $t); }
    public function withDescription(string $d): self { return $this->mutate(description: $d); }
    public function withLabels(string $yes, string $no): self
    {
        return $this->mutate(affirmative: $yes, negative: $no);
    }
    public function withDefault(bool $v): self       { return $this->mutate(value: $v); }

    /**
     * Run the closure on every value change. Returns null on valid,
     * a non-empty error string on invalid. Mirrors huh's
     * `WithValidator` on Confirm.
     *
     * @param ?\Closure(bool):?string $fn pass null to clear
     */
    public function withValidator(?\Closure $fn): self
    {
        $clone = clone $this;
        $clone->validator = $fn;
        return $clone->revalidate();
    }

    /** @internal */
    private function revalidate(): self
    {
        $err = $this->validator !== null ? ($this->validator)($this->value) : null;
        if ($err === $this->error) {
            return $this;
        }
        $clone = clone $this;
        // Bypass mutate() because $error is readonly on a fresh ctor.
        return new self(
            $this->key, $this->value, $this->focused, $this->title,
            $this->description, $this->affirmative, $this->negative, $err,
        );
    }

    public function key(): string  { return $this->key; }
    public function value(): mixed { return $this->value; }

    public function focus(): array { return [$this->mutate(focused: true), null]; }
    public function blur(): Field  { return $this->mutate(focused: false); }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg || !$this->focused) {
            return [$this, null];
        }
        return match (true) {
            $msg->type === KeyType::Left
                || ($msg->type === KeyType::Char && $msg->rune === 'h')
                => [$this->mutate(value: true), null],
            $msg->type === KeyType::Right
                || ($msg->type === KeyType::Char && $msg->rune === 'l')
                => [$this->mutate(value: false), null],
            $msg->type === KeyType::Char && $msg->rune === 'y' && !$msg->ctrl
                => [$this->mutate(value: true), null],
            $msg->type === KeyType::Char && $msg->rune === 'n' && !$msg->ctrl
                => [$this->mutate(value: false), null],
            default => [$this, null],
        };
    }

    public function view(): string
    {
        $lines = [];
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') { $lines[] = $title; }
        if ($desc  !== '') { $lines[] = $desc; }

        $yes = $this->value ? Ansi::sgr(Ansi::REVERSE) . " {$this->affirmative} " . Ansi::reset()
                            : " {$this->affirmative} ";
        $no  = $this->value ? " {$this->negative} "
                            : Ansi::sgr(Ansi::REVERSE) . " {$this->negative} " . Ansi::reset();
        $lines[] = $yes . '   ' . $no;
        return implode("\n", $lines);
    }

    public function isFocused(): bool         { return $this->focused; }
    public function getTitle(): string        { return $this->resolveTitle($this->title); }
    public function getDescription(): string  { return $this->resolveDescription($this->description); }
    public function getError(): ?string       { return $this->error; }
    public function skippable(): bool         { return false; }
    public function consumes(Msg $msg): bool  { return false; }

    private function mutate(
        ?bool $value = null,
        ?bool $focused = null,
        ?string $title = null,
        ?string $description = null,
        ?string $affirmative = null,
        ?string $negative = null,
    ): self {
        $next = new self(
            key:         $this->key,
            value:       $value       ?? $this->value,
            focused:     $focused     ?? $this->focused,
            title:       $title       ?? $this->title,
            description: $description ?? $this->description,
            affirmative: $affirmative ?? $this->affirmative,
            negative:    $negative    ?? $this->negative,
            error:       $this->error,
        );
        $next->validator = $this->validator;
        // Re-run validator when the value changed.
        if ($value !== null && $value !== $this->value) {
            return $next->revalidate();
        }
        return $next;
    }
}

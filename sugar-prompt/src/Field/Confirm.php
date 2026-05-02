<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Field;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Util\Ansi;
use CandyCore\Prompt\Field;

/**
 * Yes / No question. The user toggles the answer with `←/→`, `h/l`, or
 * `Tab`, or commits a side directly with `y` / `n`.
 */
final class Confirm implements Field
{
    private function __construct(
        public readonly string $key,
        public readonly bool $value,
        public readonly bool $focused,
        public readonly string $title,
        public readonly string $description,
        public readonly string $affirmative,
        public readonly string $negative,
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
        if ($this->title !== '')       { $lines[] = $this->title; }
        if ($this->description !== '') { $lines[] = $this->description; }

        $yes = $this->value ? Ansi::sgr(Ansi::REVERSE) . " {$this->affirmative} " . Ansi::reset()
                            : " {$this->affirmative} ";
        $no  = $this->value ? " {$this->negative} "
                            : Ansi::sgr(Ansi::REVERSE) . " {$this->negative} " . Ansi::reset();
        $lines[] = $yes . '   ' . $no;
        return implode("\n", $lines);
    }

    public function isFocused(): bool         { return $this->focused; }
    public function getTitle(): string        { return $this->title; }
    public function getDescription(): string  { return $this->description; }
    public function getError(): ?string       { return null; }
    public function skippable(): bool         { return false; }

    private function mutate(
        ?bool $value = null,
        ?bool $focused = null,
        ?string $title = null,
        ?string $description = null,
        ?string $affirmative = null,
        ?string $negative = null,
    ): self {
        return new self(
            key:         $this->key,
            value:       $value       ?? $this->value,
            focused:     $focused     ?? $this->focused,
            title:       $title       ?? $this->title,
            description: $description ?? $this->description,
            affirmative: $affirmative ?? $this->affirmative,
            negative:    $negative    ?? $this->negative,
        );
    }
}

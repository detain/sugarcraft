<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Field;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Util\Ansi;
use CandyCore\Prompt\Field;

/**
 * Multi-checkbox picker. Cursor moves with `↑↓/jk`, `Space` toggles the
 * highlighted item. Optional `withMin()` / `withMax()` constrain the
 * number of selected items (0 = unlimited).
 *
 * `value()` returns the list of selected option strings, in declaration
 * order.
 */
final class MultiSelect implements Field
{
    /**
     * @param list<string>      $options
     * @param array<int,bool>   $selected map of option index => true
     */
    private function __construct(
        public readonly string $key,
        public readonly array $options,
        public readonly array $selected,
        public readonly int $cursor,
        public readonly bool $focused,
        public readonly string $title,
        public readonly string $description,
        public readonly int $min,
        public readonly int $max,
        public readonly ?string $error,
    ) {}

    public static function new(string $key): self
    {
        return new self(
            key: $key,
            options: [],
            selected: [],
            cursor: 0,
            focused: false,
            title: '',
            description: '',
            min: 0,
            max: 0,
            error: null,
        );
    }

    public function withOptions(string ...$options): self
    {
        return $this->mutate(options: array_values($options), cursor: 0, selected: []);
    }

    public function withTitle(string $t): self       { return $this->mutate(title: $t); }
    public function withDescription(string $d): self { return $this->mutate(description: $d); }

    /** Force at least N selections (0 = no minimum). */
    public function withMin(int $n): self { return $this->mutate(min: max(0, $n)); }

    /** Cap selections at N (0 = no limit). */
    public function withMax(int $n): self { return $this->mutate(max: max(0, $n)); }

    public function key(): string { return $this->key; }

    /** @return list<string> selected option strings in declaration order */
    public function value(): mixed
    {
        $out = [];
        foreach ($this->options as $i => $opt) {
            if (!empty($this->selected[$i])) {
                $out[] = $opt;
            }
        }
        return $out;
    }

    public function focus(): array { return [$this->mutate(focused: true), null]; }
    public function blur(): Field  { return $this->mutate(focused: false); }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg || !$this->focused) {
            return [$this, null];
        }
        return match (true) {
            $msg->type === KeyType::Up
                || ($msg->type === KeyType::Char && $msg->rune === 'k')
                => [$this->moveCursor($this->cursor - 1), null],
            $msg->type === KeyType::Down
                || ($msg->type === KeyType::Char && $msg->rune === 'j')
                => [$this->moveCursor($this->cursor + 1), null],
            $msg->type === KeyType::Home
                || ($msg->type === KeyType::Char && $msg->rune === 'g')
                => [$this->moveCursor(0), null],
            $msg->type === KeyType::End
                || ($msg->type === KeyType::Char && $msg->rune === 'G')
                => [$this->moveCursor(count($this->options) - 1), null],
            $msg->type === KeyType::Space
                => [$this->toggle($this->cursor), null],
            default => [$this, null],
        };
    }

    public function view(): string
    {
        $lines = [];
        if ($this->title !== '')       { $lines[] = $this->title; }
        if ($this->description !== '') { $lines[] = $this->description; }
        foreach ($this->options as $i => $opt) {
            $box   = empty($this->selected[$i]) ? '[ ]' : '[x]';
            $marker = ($i === $this->cursor && $this->focused) ? '>' : ' ';
            $line  = $marker . ' ' . $box . ' ' . $opt;
            if ($i === $this->cursor && $this->focused) {
                $line = Ansi::sgr(Ansi::REVERSE) . $line . Ansi::reset();
            }
            $lines[] = $line;
        }
        if ($this->error !== null) {
            $lines[] = '! ' . $this->error;
        }
        return implode("\n", $lines);
    }

    public function isFocused(): bool         { return $this->focused; }
    public function getTitle(): string        { return $this->title; }
    public function getDescription(): string  { return $this->description; }
    public function getError(): ?string       { return $this->error; }
    public function skippable(): bool         { return false; }
    public function consumes(Msg $msg): bool  { return false; }

    private function moveCursor(int $idx): self
    {
        $count = count($this->options);
        if ($count === 0) {
            return $this->mutate(cursor: 0);
        }
        return $this->mutate(cursor: max(0, min($count - 1, $idx)));
    }

    private function toggle(int $idx): self
    {
        if (!isset($this->options[$idx])) {
            return $this;
        }
        $next = $this->selected;
        if (!empty($next[$idx])) {
            unset($next[$idx]);
        } else {
            // Honour max cap.
            if ($this->max > 0 && self::countTrue($next) >= $this->max) {
                return $this->mutate(error: "Pick at most {$this->max}.", touchError: true);
            }
            $next[$idx] = true;
        }
        $err = null;
        if ($this->min > 0 && self::countTrue($next) < $this->min) {
            $err = "Pick at least {$this->min}.";
        }
        return $this->mutate(selected: $next, error: $err, touchError: true);
    }

    /** @param array<int,bool> $set */
    private static function countTrue(array $set): int
    {
        $n = 0;
        foreach ($set as $v) {
            if ($v) $n++;
        }
        return $n;
    }

    /**
     * @param list<string>|null    $options
     * @param array<int,bool>|null $selected
     */
    private function mutate(
        ?array $options = null,
        ?array $selected = null,
        ?int $cursor = null,
        ?bool $focused = null,
        ?string $title = null,
        ?string $description = null,
        ?int $min = null,
        ?int $max = null,
        ?string $error = null,
        bool $touchError = false,
    ): self {
        return new self(
            key:         $this->key,
            options:     $options     ?? $this->options,
            selected:    $selected    ?? $this->selected,
            cursor:      $cursor      ?? $this->cursor,
            focused:     $focused     ?? $this->focused,
            title:       $title       ?? $this->title,
            description: $description ?? $this->description,
            min:         $min         ?? $this->min,
            max:         $max         ?? $this->max,
            error:       $touchError ? $error : $this->error,
        );
    }
}

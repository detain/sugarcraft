<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\HasDynamicLabels;
use SugarCraft\Forms\HasHideFunc;
use SugarCraft\Forms\Util\RenderSafe;

/**
 * Multi-checkbox picker. Cursor moves with `↑↓/jk`, `Space` toggles the
 * highlighted item. Optional `withMin()` / `withMax()` constrain the
 * number of selected items (0 = unlimited).
 *
 * `value()` returns the list of selected option strings, in declaration
 * order.
 */
final class MultiSelect implements \SugarCraft\Forms\Field
{
    use HasHideFunc;
    use HasDynamicLabels;

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

    /**
     * huh-style combined cap: a single integer that sets both
     * the minimum (0) and maximum to the same value when positive.
     * Matches huh's `WithLimit` shape — convenient when you want
     * "exactly N or fewer" as one call. Pass `0` to clear both.
     */
    public function withLimit(int $n): self
    {
        $n = max(0, $n);
        return $this->mutate(min: 0, max: $n);
    }

    // Short-form aliases.
    public function title(string $t): self                { return $this->withTitle($t); }
    public function desc(string $d): self                 { return $this->withDescription($d); }
    public function options(string ...$options): self    { return $this->withOptions(...$options); }
    public function min(int $n): self                     { return $this->withMin($n); }
    public function max(int $n): self                     { return $this->withMax($n); }
    public function limit(int $n): self                   { return $this->withLimit($n); }

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
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') { $lines[] = $title; }
        if ($desc  !== '') { $lines[] = $desc; }
        foreach ($this->options as $i => $opt) {
            $box   = empty($this->selected[$i]) ? '[ ]' : '[x]';
            $marker = ($i === $this->cursor && $this->focused) ? '>' : ' ';
            $line  = $marker . ' ' . $box . ' ' . RenderSafe::clean($opt);
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
    public function getTitle(): string        { return $this->resolveTitle($this->title); }
    public function getDescription(): string  { return $this->resolveDescription($this->description); }
    public function getError(): ?string       { return $this->error; }
    public function revalidate(): Field
    {
        return $this->mutate(error: $this->computeConstraintError(self::countTrue($this->selected)), touchError: true);
    }
    public function skippable(): bool         { return false; }

    /**
     * Up / Down / j / k move the inner cursor; without claiming them
     * the form would steal the keys for between-field navigation,
     * leaving the checkbox cursor unreachable.
     */
    public function consumes(Msg $msg): bool
    {
        if (!$this->focused || !$msg instanceof KeyMsg) {
            return false;
        }
        if ($msg->type === KeyType::Up || $msg->type === KeyType::Down) {
            return true;
        }
        // Claim j/k vim navigation keys when in char form.
        if ($msg->type === KeyType::Char && ($msg->rune === 'j' || $msg->rune === 'k')) {
            return true;
        }
        return false;
    }

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
            // Honour max cap before adding.
            if ($this->max > 0 && self::countTrue($next) >= $this->max) {
                return $this->mutate(error: "Pick at most {$this->max}.", touchError: true);
            }
            $next[$idx] = true;
        }
        return $this->mutate(selected: $next, error: $this->computeConstraintError(self::countTrue($next)), touchError: true);
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
     * Compute the min/max constraint error for a given selection count.
     * Used by both toggle() (for immediate user feedback) and revalidate()
     * (for submit-time / validateAll enforcement).
     */
    private function computeConstraintError(int $count): ?string
    {
        if ($this->min > 0 && $count < $this->min) {
            return "Pick at least {$this->min}.";
        }
        if ($this->max > 0 && $count > $this->max) {
            return "Pick at most {$this->max}.";
        }
        return null;
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
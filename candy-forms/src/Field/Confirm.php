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
use SugarCraft\Forms\Validator\Validator;

/**
 * Yes / No question. The user toggles the answer with `←/→`, `h/l`, or
 * `Tab`, or commits a side directly with `y` / `n`.
 */
final class Confirm implements \SugarCraft\Forms\Field
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
     * Run the validator on every value change. Returns null on valid,
     * a non-empty error string on invalid. Mirrors huh's `WithValidator`
     * on Confirm.
     *
     * Accepts a bool closure or a {@see Validator} instance (for parity with
     * {@see Input::withValidator()}, which also accepts both). Because the
     * {@see Validator} contract validates a string, a Validator is fed the
     * checkbox state as `'1'` (checked) / `''` (unchecked) — so e.g. a
     * {@see Validator\Required} means "must be checked".
     *
     * @param Validator|\Closure(bool):?string|null $validator pass null to clear
     */
    public function withValidator(Validator|\Closure|null $validator): self
    {
        if ($validator instanceof Validator) {
            $fn = static function (bool $value) use ($validator): ?string {
                $result = $validator->validate($value ? '1' : '');
                return $result === true ? null : (string) $result;
            };
        } else {
            $fn = $validator;
        }

        // Canonical immutable path (E736 1.7): the validator swap rides
        // mutate() with its set-sentinel — no clone + direct property write.
        // mutate() is also the only carrier for the HasHideFunc /
        // HasDynamicLabels trait state, so routing here keeps withValidator
        // consistent with every other setter. revalidate() still runs
        // immediately: attaching a rule must judge the CURRENT value against
        // it (mirrors Input::withValidator, which validates on attach).
        return $this->mutate(validator: $fn, validatorSet: true)->revalidate();
    }

    // Short-form aliases.
    public function title(string $t): self                { return $this->withTitle($t); }
    public function desc(string $d): self                 { return $this->withDescription($d); }
    public function labels(string $yes, string $no): self { return $this->withLabels($yes, $no); }
    public function default(bool $v): self                { return $this->withDefault($v); }
    public function validator(Validator|\Closure|null $fn): self { return $this->withValidator($fn); }

    /** @internal */
    public function revalidate(): Field
    {
        $err = $this->validator !== null ? ($this->validator)($this->value) : null;
        if ($err === $this->error) {
            return $this;
        }
        // mutate() cannot produce this instance: $error is readonly, so only
        // the ctor may write it. The non-readonly state that new self() would
        // silently reset to null — validator plus the HasHideFunc /
        // HasDynamicLabels closures — is carried over explicitly (E736 1.7:
        // before this, recomputing an error dropped the validator, so a field
        // that started invalid stopped validating after the first toggle).
        $next = new self(
            $this->key, $this->value, $this->focused, $this->title,
            $this->description, $this->affirmative, $this->negative, $err,
        );
        return $this->carryNonCtorState($next);
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

    /**
     * Render the prompt with the affirm/deny pills, the selected one in
     * reverse video.
     *
     * The highlight uses raw Ansi::sgr(REVERSE) rather than a Style
     * (E736 plan 3.9, kept deliberately): upstream huh hardcodes reverse
     * video for the Confirm pill — Confirm has no themeable style slots
     * the way TextInput's Styles or Cursor's withStyle do — so migrating
     * to Style would first have to invent that public API, a product
     * decision beyond a correctness pass. Pill text stays behind
     * RenderSafe::clean() so user-supplied labels cannot inject escapes.
     */
    public function view(): string
    {
        $lines = [];
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') { $lines[] = $title; }
        if ($desc  !== '') { $lines[] = $desc; }

        $yes = $this->value ? Ansi::sgr(Ansi::REVERSE) . ' ' . RenderSafe::clean($this->affirmative) . ' ' . Ansi::reset()
                            : ' ' . RenderSafe::clean($this->affirmative) . ' ';
        $no  = $this->value ? ' ' . RenderSafe::clean($this->negative) . ' '
                            : Ansi::sgr(Ansi::REVERSE) . ' ' . RenderSafe::clean($this->negative) . ' ' . Ansi::reset();
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
        ?\Closure $validator = null,
        bool $validatorSet = false,
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
        $next->validator = $validatorSet ? $validator : $this->validator;
        // The validator/trait closures are not ctor args; carry them so no
        // mutation path silently drops them (E736 1.7).
        $next = $this->carryNonCtorState($next, carryValidator: false);
        // Re-run validator when the value changed.
        if ($value !== null && $value !== $this->value) {
            return $next->revalidate();
        }
        return $next;
    }

    /**
     * Copy the state that lives outside the private ctor — the validator
     * closure and the HasHideFunc / HasDynamicLabels closures — onto a
     * freshly constructed instance. The ctor initialises each to null, so
     * every `new self(...)` path must funnel through here or those
     * closures vanish (immutable-model property carry, per the trait
     * docblocks' "preserved across mutations" contract).
     */
    private function carryNonCtorState(self $next, bool $carryValidator = true): self
    {
        if ($carryValidator) {
            $next->validator = $this->validator;
        }
        $next->hideFunc        = $this->hideFunc;
        $next->titleFunc       = $this->titleFunc;
        $next->descriptionFunc = $this->descriptionFunc;
        return $next;
    }
}
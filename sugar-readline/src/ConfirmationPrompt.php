<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * Yes / No confirmation prompt.
 *
 * Selection and submission are decoupled by default: y / n / left / right /
 * tab / space change the selected value but do NOT auto-submit. Call
 * {@see submit()} (or feed {@see Key::Enter}) to commit. With
 * {@see withAutoSubmit()} armed, the y / n answer keys select AND commit in
 * one press (the gum-style reflex; arrows and the toggle stay browse-only).
 * {@see result()} is meaningful only after {@see isSubmitted()} returns true.
 */
final class ConfirmationPrompt
{
    private bool $value;       // tracks current selection
    private bool $submitted = false;
    private bool $aborted   = false;

    /** Opt-in y/n-commits-immediately; OFF preserves the Enter-to-commit contract. */
    private bool $autoSubmit = false;

    /**
     * Chrome labels ride the lang/ catalog; null = "unoverridden" (render
     * Lang::t), an explicit string — including '' — is opaque host text and
     * renders as given. The nullable-sentinel exemption (AGENTS: null is
     * itself the meaning) is why these are ?string and not translated
     * property defaults: PHP property initialisers cannot call Lang::t().
     */
    private ?string $confirmLabel = null;
    private ?string $cancelLabel  = null;
    private ?string $hint         = null;
    private string $labelStyle    = '1;36';
    private string $selectedStyle = '1;32';

    /** @var int|null Display bound in cells; null = unset (width-adoption eligible), 0 = explicit unbounded. */
    private ?int $displayWidth = null;

    public function __construct(private readonly string $label, bool $defaultValue = false)
    {
        $this->value = $defaultValue;
    }

    public static function new(string $label, bool $defaultValue = false): self
    {
        return new self($label, $defaultValue);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function withConfirmLabel(string $label): self
    {
        $clone = clone $this;
        $clone->confirmLabel = $label;
        return $clone;
    }

    public function withCancelLabel(string $label): self
    {
        $clone = clone $this;
        $clone->cancelLabel = $label;
        return $clone;
    }

    public function withHint(string $hint): self
    {
        $clone = clone $this;
        $clone->hint = $hint;
        return $clone;
    }

    /**
     * Arm the immediate-answer reflex: with $on, a y or n press selects and
     * submits in one key (finding 090). The feature defaults to OFF — plain
     * usage keeps the documented Enter-to-commit parity, so existing hosts
     * see byte-identical behaviour; calling withAutoSubmit() arms it,
     * withAutoSubmit(false) stays off.
     */
    public function withAutoSubmit(bool $on = true): self
    {
        $clone = clone $this;
        $clone->autoSubmit = $on;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    public function handleKey(string $key): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }

        // One canonical key language (same choke as TextPrompt and
        // SelectionPrompt): raw control bytes become symbolic names here so
        // the arms below fire identically whether driven through Readline or
        // fed bytes directly.
        $key = Key::normalize($key);

        return match ($key) {
            'y', 'Y'         => $this->answer(true),
            'n', 'N'         => $this->answer(false),
            Key::Left        => $this->select(true),
            Key::Right       => $this->select(false),
            Key::Tab, Key::Space => $this->select(!$this->value),
            Key::Enter           => $this->submit(),
            Key::Escape, Key::CtrlC => $this->abort(),
            default              => $this,
        };
    }

    /**
     * y/n lands on the selection — and under withAutoSubmit() it also
     * commits (finding 090's opt-in reflex). Arrow keys and the
     * Space/Tab toggle stay browse-only even when auto-submit is armed:
     * they move a highlight, they are not an answer. Enter-to-commit
     * parity is untouched either way — the default is OFF.
     */
    private function answer(bool $value): self
    {
        $selected = $this->select($value);

        return $this->autoSubmit ? $selected->submit() : $selected;
    }

    public function submit(): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }
        $clone = clone $this;
        $clone->submitted = true;
        return $clone;
    }

    public function abort(): self
    {
        if ($this->submitted || $this->aborted) {
            return $this;
        }
        $clone = clone $this;
        $clone->aborted = true;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /** Final boolean answer. False when aborted or before submission. */
    public function result(): bool
    {
        return $this->submitted && $this->value;
    }

    /** Currently selected value, regardless of submission state. */
    public function currentValue(): bool { return $this->value; }

    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function view(): string
    {
        // null = unoverridden -> catalog chrome (byte-identical to the old
        // baked 'Yes'/'No'/'[y/n]' under the default en locale).
        $yesLabel = $this->confirmLabel ?? Lang::t('confirm.yes');
        $noLabel  = $this->cancelLabel ?? Lang::t('confirm.no');
        $yes = $this->value
            ? ['[' . Ansi::sanitize($yesLabel) . ']', $this->selectedStyle]
            : [' ' . Ansi::sanitize($yesLabel) . ' ', null];
        $no  = !$this->value
            ? ['[' . Ansi::sanitize($noLabel) . ']', $this->selectedStyle]
            : [' ' . Ansi::sanitize($noLabel) . ' ', null];

        return RowFit::row([
            [Ansi::sanitize($this->label), $this->labelStyle],
            [' ', null],
            $yes,
            [' / ', null],
            $no,
            [' ', null],
            [Ansi::sanitize($this->hint ?? Lang::t('confirm.hint')), null],
        ], $this->displayWidth);
    }

    /**
     * Bound the rendered row to $cells display columns (CJK/emoji aware).
     * Mirrors {@see TextPrompt::withDisplayWidth()}: pass 0 to opt out
     * (row paints unbounded), pass null to leave the bound unset — the
     * only state {@see Readline::run()} will auto-adopt a width into.
     * When the budget ends mid-row the trailing segments (the hint last)
     * are dropped rather than wrapped.
     */
    public function withDisplayWidth(?int $cells): self
    {
        $clone = clone $this;
        $clone->displayWidth = $cells === null ? null : max(0, $cells);
        return $clone;
    }

    /** Current display viewport width in cells; null = unset (adoption-eligible), 0 = explicit unbounded. */
    public function displayWidth(): ?int
    {
        return $this->displayWidth;
    }

    private function select(bool $value): self
    {
        if ($value === $this->value) {
            return $this;
        }
        $clone = clone $this;
        $clone->value = $value;
        return $clone;
    }
}

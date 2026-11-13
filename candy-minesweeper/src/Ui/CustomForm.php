<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Ui;

/**
 * The in-game custom-board form state (msw-09/G5-b): three digit fields —
 * width, height, mines — typed one field at a time, submitted through
 * {@see CustomDifficulty::fromInput()} so the interactive door and the CLI
 * positional door share one validation law (msw-18).
 *
 * Immutable: every edit returns a new form, matching the Model discipline.
 * The values are digit STRINGS, not ints — "09" and "" are states the
 * player can actually be in mid-typing, and coercing them at entry would
 * lose the difference between "nothing typed yet" and "zero typed" that
 * the refusal messages name.
 *
 * Vocabulary (msw-09/-033 bridge): the fields are width then height then
 * mines — the CLI order; {@see submit()} performs the rows=height /
 * cols=width translation at this boundary and nowhere else.
 */
final class CustomForm
{
    /** Field digit caps: sides 2..50 need 2 digits, the mine count up to
     *  50×50−9 = 471 needs 3. Typing past the cap is a dead keypress —
     *  refusing at submit would let a bored finger type "1234567890" and
     *  blame the player for a number they cannot even hold on screen. */
    private const MAX_DIGITS = [self::FIELD_WIDTH => 2, self::FIELD_HEIGHT => 2, self::FIELD_MINES => 3];

    public const FIELD_WIDTH  = 0;
    public const FIELD_HEIGHT = 1;
    public const FIELD_MINES  = 2;

    public function __construct(
        public readonly string $width = '',
        public readonly string $height = '',
        public readonly string $mines = '',
        public readonly int $field = self::FIELD_WIDTH,
        /** The last refusal's translated message, shown under the fields.
         *  Any edit clears it — the blame line must not outlive the text
         *  it blamed. */
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Seed the form from {@see CustomDifficulty::new()} so the default
     * shape is the one the validator itself proposes — Enter three times
     * plays a board, matching the preset ladder's easiest rung.
     */
    public static function seeded(): self
    {
        $d = CustomDifficulty::new();
        return new self(
            width:  (string) $d->cols,
            height: (string) $d->rows,
            mines:  (string) $d->mines,
        );
    }

    /** @return list<string> the field values in display/typing order */
    public function values(): array
    {
        return [$this->width, $this->height, $this->mines];
    }

    public function value(int $field): string
    {
        return match ($field) {
            self::FIELD_HEIGHT => $this->height,
            self::FIELD_MINES  => $this->mines,
            default            => $this->width,
        };
    }

    /** Append a digit to the active field; past the field's cap the
     *  keypress is inert (see MAX_DIGITS). Any edit answers the refusal. */
    public function withDigit(string $digit): self
    {
        if ($digit === '' || !ctype_digit($digit)) {
            return $this;
        }
        $current = $this->value($this->field);
        if (strlen($current) >= self::MAX_DIGITS[$this->field]) {
            return $this;
        }
        return $this->edit($this->field, $current . $digit);
    }

    /** Delete the last digit of the active field; empty stays empty. */
    public function withBackspace(): self
    {
        $current = $this->value($this->field);
        if ($current === '') {
            return $this;
        }
        return $this->edit($this->field, substr($current, 0, -1));
    }

    /** Enter/Tab: move to the next field; on the mines field there is no
     *  next — the caller submits. The refusal line clears with the move:
     *  it blamed a digit the player has now stepped away from. */
    public function advanced(): self
    {
        if ($this->field >= self::FIELD_MINES) {
            return $this;
        }
        return new self($this->width, $this->height, $this->mines, $this->field + 1, null);
    }

    public function atSubmitField(): bool
    {
        return $this->field === self::FIELD_MINES;
    }

    /**
     * Validate the typed shape. Throws the translated refusal (the
     * ui wording names rows/columns — the bridge sentence above maps that
     * vocabulary onto the field labels the player sees).
     *
     * @throws \InvalidArgumentException
     */
    public function submit(): CustomDifficulty
    {
        return CustomDifficulty::fromInput(
            (int) $this->height,
            (int) $this->width,
            (int) $this->mines,
        );
    }

    /** Attach a refusal message without touching the typed digits, and
     *  park the cursor back on the width field: the form advances only
     *  (Enter walks forward), so a player told "columns must be at
     *  least 2" must find the editing cursor where the fix begins,
     *  not stranded on the field that just submitted. */
    public function withError(string $error): self
    {
        return new self($this->width, $this->height, $this->mines, self::FIELD_WIDTH, $error);
    }

    private function edit(int $field, string $value): self
    {
        return new self(
            width:  $field === self::FIELD_WIDTH  ? $value : $this->width,
            height: $field === self::FIELD_HEIGHT ? $value : $this->height,
            mines:  $field === self::FIELD_MINES  ? $value : $this->mines,
            field:  $this->field,
        );
    }
}

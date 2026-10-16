<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Input;

/**
 * The W3.1 placeholder section: the prompt as a live sugar-forms
 * {@see Input}, and the draft's core knobs as read-only summary rows.
 *
 * DECISION RECORD (plan :398). The prior art was checked before inventing a
 * widget layer: `SettingsEditor` drives sugar-forms `Field` objects directly
 * — `Forms\Form` and `Form::groups` appear nowhere in `sugar-crush/src`
 * (verified by census at W3.1 time) — so `MediaForm` does the same: each
 * section owns its Fields, the form drives them through the {@see Section}
 * door, and the composite form machinery stays unused. W3.4's FieldSpec
 * registry will replace this placeholder by shipping generated sections
 * through the very same interface.
 *
 * The prompt text lives in the field while typing and is folded into the
 * draft only at {@see collect()} — the draft is the single wire truth, and
 * the summary rows read it, never the field.
 */
final class ParamsSection implements Section
{
    private const ROW_PROMPT = 0;

    /** Row labels in display order; each is a Lang key. */
    private const ROWS = [
        GenFormKeys::ROW_PROMPT,
        GenFormKeys::ROW_STEPS,
        GenFormKeys::ROW_CFG_SCALE,
        GenFormKeys::ROW_SAMPLER,
        GenFormKeys::ROW_SIZE,
        GenFormKeys::ROW_SEED,
    ];

    private function __construct(
        private readonly Input $prompt,
        private readonly MediaRequest $seed,
    ) {
    }

    public static function forDraft(MediaRequest $draft): self
    {
        return new self(
            Input::new('prompt')
                ->withPrompt(Lang::t(GenFormKeys::ROW_PROMPT))
                ->withValue((string) ($draft->prompt() ?? '')),
            $draft,
        );
    }

    public function key(): string
    {
        return 'params';
    }

    public function label(): string
    {
        return Lang::t(GenFormKeys::TAB_PARAMS);
    }

    public function visible(MediaFormState $state): bool
    {
        return true;
    }

    /** @return list<string> */
    public function rows(): array
    {
        return array_map(static fn (string $key): string => Lang::t($key), self::ROWS);
    }

    public function fieldAt(int $row): ?Field
    {
        return $row === self::ROW_PROMPT ? $this->prompt : null;
    }

    /** A section holding $field in the prompt row; anything else is ignored. */
    public function withField(Field $field): self
    {
        return $field instanceof Input ? new self($field, $this->seed) : $this;
    }

    public function valueAt(int $row): string
    {
        if ($row === self::ROW_PROMPT) {
            return (string) $this->prompt->value();
        }

        $value = match ($row) {
            1 => $this->seed->steps(),
            2 => $this->seed->cfgScale(),
            3 => $this->seed->samplerName(),
            4 => $this->size(),
            5 => $this->seed->seed(),
            default => null,
        };

        return $value === null ? Lang::t(GenFormKeys::NOT_SET) : (string) $value;
    }

    public function collect(MediaRequest $draft): MediaRequest
    {
        $prompt = trim((string) $this->prompt->value());

        return $prompt === '' ? $draft : $draft->withPrompt($prompt);
    }

    private function size(): ?string
    {
        $width = $this->seed->width();
        $height = $this->seed->height();

        return $width === null || $height === null ? null : $width . 'x' . $height;
    }
}

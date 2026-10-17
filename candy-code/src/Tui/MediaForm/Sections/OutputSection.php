<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm\Sections;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Tui\MediaForm\GenFormKeys;
use SugarCraft\Code\Tui\MediaForm\Section;
use SugarCraft\Forms\Field;

/**
 * The Output tab (crush-media W3.9, plan §5.8 mirror-item 1's display half):
 * one read-only row exposing the effective `sd.savePattern` the generation
 * will name files with, and beneath it the token-palette help line — the
 * thirteen tokens {@see \SugarCraft\Code\Support\MediaStore::TOKENS} fills,
 * spelled by the translation so the SSOT list is pinned against the const in
 * tests rather than re-transcribed here.
 *
 * The pattern itself is EDITED in `/settings` (ui/sd keys are user-tier
 * layered settings, one authority per knob); the form shows what a
 * generation would actually use, which is why the caller hands in the
 * ALREADY-resolved effective value and null here can only mean "no settings
 * read happened" — the documented MediaStore default answers, honestly.
 *
 * APPENDIX-C RULING, RECORDED (plan :532): there is deliberately NO
 * `ui.mediaLastUsed` settings key. "Last used" is the session draft itself —
 * every commit folds into it and the form reopens over whatever the shell
 * hands it; minting a settings mirror for live form state would fork the
 * truth across two stores for zero user gain.
 */
final class OutputSection implements Section
{
    private function __construct(
        private readonly string $pattern,
    ) {
    }

    public static function forDraft(?string $savePattern): self
    {
        return new self($savePattern ?? \SugarCraft\Code\Support\MediaStore::DEFAULT_PATTERN);
    }

    public function key(): string
    {
        return 'output';
    }

    public function label(): string
    {
        return Lang::t(GenFormKeys::SECTION_OUTPUT);
    }

    public function visible(\SugarCraft\Code\Tui\MediaForm\MediaFormState $state): bool
    {
        return true;
    }

    /** @return list<string> the pattern row, then an unlabeled continuation row */
    public function rows(): array
    {
        return [Lang::t(GenFormKeys::OUTPUT_PATTERN), ''];
    }

    public function fieldAt(int $row): ?Field
    {
        return null;
    }

    public function withField(Field $field): Section
    {
        return $this;
    }

    public function valueAt(int $row): string
    {
        return $row === 1 ? Lang::t(GenFormKeys::OUTPUT_TOKENS) : $this->pattern;
    }

    public function collect(MediaRequest $draft): MediaRequest
    {
        return $draft;
    }
}

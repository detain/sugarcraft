<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm\Sections;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Tui\MediaForm\FieldSpec;
use SugarCraft\Code\Tui\MediaForm\FieldValuedSection;
use SugarCraft\Code\Tui\MediaForm\GenFormKeys;
use SugarCraft\Code\Tui\MediaForm\Section;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Text;

/**
 * The Prompt tab (crush-media W3.4, plan §2.1): prompt and negative prompt
 * as three-row text areas, styles as the multi-select.
 *
 * History caveat (probed at W3.4): sugar-forms `Text`/`Input` carry no
 * input-history machinery, and the plan promises none for v1 — the prompt
 * rows are plain areas, and prompt *recall* stays a W4 affordance. The
 * placeholders are the neutral C-2 pair ("Describe the image" / "What to
 * avoid") — the webui's chord hint is a browser-only behavior and would
 * lie in a TUI.
 *
 * `["Default"]` is the webui's spelling of "no style"; {@see FieldSpec}
 * folds it back to an unset wire field so the server picks.
 */
final class PromptSection implements Section, FieldValuedSection
{
    use RegistrySection;


    private function __construct(
        protected readonly array $specs,
        protected readonly array $fields,
        protected readonly array $rowKeys,
    ) {
    }

    /**
     * @param array<string, list<string>>|null $runtimeChoices reserved for the
     *        W3.6 `/styles` merge (it replaces the fixed option set); the
     *        W3.4 prompt rows carry no option feed yet
     */
    public static function forDraft(MediaRequest $draft, ?array $runtimeChoices = null): self
    {
        $specs = [];
        $fields = [];
        foreach (FieldSpec::coreRows() as $spec) {
            if (!$spec->belongsTo('prompt')) {
                continue;
            }

            $specs[$spec->key] = $spec;
            $fields[$spec->key] = self::seededField($spec, $draft);
        }

        return new self($specs, $fields, array_keys($specs));
    }

    public function key(): string
    {
        return 'prompt';
    }

    public function label(): string
    {
        return Lang::t(GenFormKeys::SECTION_PROMPT);
    }

    private static function seededField(FieldSpec $spec, MediaRequest $draft): Field
    {
        $label = Lang::t($spec->labelKey);
        $field = $spec->createField($spec->key);

        if ($field instanceof MultiSelect) {
            return $field
                ->withTitle($label)
                ->withOptions(...self::styleOptions($spec, $draft))
                ->withValue(self::styleSelection($spec, $draft));
        }

        if ($field instanceof Text) {
            return $field
                ->withTitle($label)
                ->withPlaceholder(Lang::t($spec->key === 'prompt'
                    ? GenFormKeys::PROMPT_PLACEHOLDER
                    : GenFormKeys::NEGATIVE_PLACEHOLDER))
                ->withValue((string) $spec->valueFrom($draft));
        }

        return $field;
    }

    /**
     * The draft's styles must be selectable before they can be shown, so a
     * resumed draft's names join the fixed set (the `/styles` feed of W3.6
     * will own the full list later).
     *
     * @return list<string>
     */
    private static function styleOptions(FieldSpec $spec, MediaRequest $draft): array
    {
        $selected = self::styleSelection($spec, $draft);

        return array_values(array_unique([...($spec->options ?? []), ...$selected]));
    }

    /** @return list<string> */
    private static function styleSelection(FieldSpec $spec, MediaRequest $draft): array
    {
        $styles = $draft->styles();

        return $styles === null || $styles === [] ? ($spec->defaultList ?? ['Default']) : array_values($styles);
    }
}

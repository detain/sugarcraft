<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm\Sections;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Crush\Tui\MediaForm\FieldSpec;
use SugarCraft\Crush\Tui\MediaForm\FieldValuedSection;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\Section;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Slider;

/**
 * The Refiner accordion (§2.3, crush-media W3.5): a checkpoint dropdown plus
 * a switch-at slider (0.01–1, step 0.01, UI default 0.8 — the fraction of
 * the step schedule after which the second model takes over the latents).
 *
 * Off means OFF (§2.3 wire law): with "None" selected the group folds
 * NOTHING — neither refiner_checkpoint nor refiner_switch_at reaches the
 * wire on a fresh draft, and a checkpoint the user switched away from folds
 * to null rather than a stale name. The switch-at row is not even rendered
 * while the group is closed.
 */
final class RefinerSection implements Section, FieldValuedSection
{
    use RegistrySection {
        collect as registryCollect;
        fieldAt as registryFieldAt;
        accept as registryAccept;
        display as registryDisplay;
        extraLabel as registryExtraLabel;
    }


    private function __construct(
        protected readonly array $specs,
        protected readonly array $fields,
        protected readonly array $rowKeys,
    ) {
    }

    private const KEY_CHECKPOINT = 'refiner_checkpoint';
    private const KEY_SWITCH_AT = 'refiner_switch_at';

    /**
     * @param array<string, list<string>>|null $runtimeChoices a {@see ChoiceFeed}
     *        map; its "models" roster (if any) joins the None entry.
     */
    public static function forDraft(MediaRequest $draft, ?array $runtimeChoices = null): self
    {
        $specs = [];
        $fields = [];
        foreach (FieldSpec::disclosureRows() as $spec) {
            if (!$spec->belongsTo('refiner')) {
                continue;
            }

            $specs[$spec->key] = $spec;
            $fields[$spec->key] = self::seededRefinerField($spec, $draft, $runtimeChoices);
        }

        return new self($specs, $fields, array_keys($specs));
    }

    public function key(): string
    {
        return 'refiner';
    }

    public function label(): string
    {
        return Lang::t(GenFormKeys::SECTION_REFINER);
    }

    public function collect(MediaRequest $draft): MediaRequest
    {
        return $this->clearGatedOff($this->registryCollect($draft));
    }

    protected function visibleRowKeys(): array
    {
        return $this->enabled() ? $this->rowKeys : [self::KEY_CHECKPOINT];
    }

    protected function editableSpecs(): array
    {
        // Off folds NOTHING at all: a fresh draft never grew the keys, and
        // clearGatedOff() nulls any the draft already carried — folding the
        // literal "None" label would put the key on the wire explicitly.
        return $this->enabled() ? $this->specs : [];
    }

    private function enabled(): bool
    {
        $checkpoint = $this->fields[self::KEY_CHECKPOINT] ?? null;

        return $checkpoint !== null
            && (string) $checkpoint->value() !== FieldSpec::noneLabel();
    }

    /**
     * The checkpoint row owns the neutral None entry: offline it stands
     * alone (no server is reachable, so "off" is the only honest choice);
     * the feed's titles join after it. A draft naming an unknown checkpoint
     * keeps its stale selection visible only when the feed carries it —
     * otherwise index 0 (None) wins and the pair folds off, which is the
     * fail-open truth about a server that no longer has the file.
     *
     * @param array<string, list<string>>|null $runtimeChoices
     */
    private static function seededRefinerField(FieldSpec $spec, MediaRequest $draft, ?array $runtimeChoices): Field
    {
        $label = Lang::t($spec->labelKey);
        $value = $spec->valueFrom($draft);

        if ($spec->key === self::KEY_CHECKPOINT) {
            $options = [FieldSpec::noneLabel(), ...($runtimeChoices[FieldSpec::CHOICES_MODELS] ?? [])];

            return Select::new($spec->key)
                ->withOptions(...$options)
                ->withTitle($label)
                ->withSelected((string) $value);
        }

        $field = $spec->createField($spec->key);
        if ($field instanceof Slider && (is_int($value) || is_float($value))) {
            return $field->withTitle($label)->withValue((float) $value);
        }

        throw new \LogicException('refiner row ' . $spec->key . ' carries an unexpected widget');
    }
}

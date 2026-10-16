<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm\Sections;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Tui\MediaForm\FieldSpec;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\Enum\Schedulers;
use SugarCraft\Crush\Tui\MediaForm\Enum\Samplers;
use SugarCraft\Crush\Tui\MediaForm\FieldValuedSection;
use SugarCraft\Crush\Tui\MediaForm\Section;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Slider;

/**
 * The Params tab (crush-media W3.4, plan §2.1): the core numeric/select
 * knobs from the FieldSpec registry, plus the seed row's two action rows and
 * its "last generated seed" read-out (§2.3's 🎲/♻ buttons rendered as
 * press-on-commit rows — sugar-forms carries no button widget).
 *
 * PRESS-ON-COMMIT RULING: a `Confirm` on an action row is a COMMAND, not a
 * question — committing the row fires it whatever side it shows, and the
 * row resets to a fresh field. Enter,Enter presses; the widget's own y/n/
 * arrow toggling is harmless because the reset discards it. This is also
 * why the action rows are intercepted in {@see accept()} instead of being
 * stored like registry rows.
 *
 * LAST-SEED SEAM: nothing in src persists a settled generation's seeds to
 * the view layer yet (probed at W3.4: `all_seeds` appears only on the W1
 * Response/Artifact DTOs), so ♻ reads {@see withLastSeed()}, a door the
 * shell walks when W4 lands a generation result back into the form state;
 * until something calls it the row honestly shows "not set".
 *
 * Legacy label split (§2.9 pairing law, the webui's fix_p analog): if the
 * sampler name ends with a scheduler's name and the schedule is still
 * Automatic, a KNOWN sampler prefix splits into sampler + scheduler; an
 * unknown prefix stays whole rather than inventing a sampler.
 */
final class ParamsSection implements Section, FieldValuedSection
{
    use RegistrySection {
        collect as registryCollect;
        fieldAt as registryFieldAt;
        accept as registryAccept;
        display as registryDisplay;
        extraLabel as registryExtraLabel;
    }

    public const ROW_RANDOMIZE = 'seed_randomize';
    public const ROW_REUSE = 'seed_reuse';
    public const ROW_LAST = 'seed_last';

    /**
     * @param array<string, FieldSpec> $specs
     * @param array<string, Field> $fields
     * @param list<string> $rowKeys
     */
    protected function __construct(
        protected readonly array $specs,
        protected readonly array $fields,
        protected readonly array $rowKeys,
        private readonly ?int $lastSeed = null,
    ) {

    }

    /**
     * @param array<string, list<string>>|null $runtimeChoices a {@see \SugarCraft\Crush\Tui\MediaForm\ChoiceFeed}
     *        map: when present it replaces the enum rosters for the option
     *        rows (fail-open: null keeps §2.9 verbatim)
     */
    public static function forDraft(MediaRequest $draft, ?array $runtimeChoices = null): self
    {
        $specs = [];
        $fields = [];
        foreach (FieldSpec::coreRows() as $spec) {
            if (!$spec->belongsTo('params')) {
                continue;
            }

            $specs[$spec->key] = $spec;
            $fields[$spec->key] = self::seededField($spec, $draft, $runtimeChoices);
        }

        $rowKeys = [...array_keys($specs), self::ROW_RANDOMIZE, self::ROW_REUSE, self::ROW_LAST];
        $fields[self::ROW_RANDOMIZE] = self::pressRow(self::ROW_RANDOMIZE);
        $fields[self::ROW_REUSE] = self::pressRow(self::ROW_REUSE);

        return new self($specs, $fields, $rowKeys);
    }

    public function key(): string
    {
        return 'params';
    }

    public function label(): string
    {
        return Lang::t(GenFormKeys::SECTION_PARAMS);
    }

    /** The seed the ♻ row copies — the shell's settled-generation door. */
    public function withLastSeed(?int $seed): self
    {
        return new self($this->specs, $this->fields, $this->rowKeys, $seed);
    }

    public function collect(MediaRequest $draft): MediaRequest
    {
        return self::applySchedulerSplit($this->registryCollect($draft));
    }

    /**
     * The §2.9 pairing law on a folded draft (exposed for the infotext
     * import path, which hydrates a raw 'DPM++ 2M Karras' label).
     */
    public static function applySchedulerSplit(MediaRequest $draft): MediaRequest
    {
        $sampler = $draft->samplerName();
        $scheduler = $draft->scheduler();
        if ($sampler === null || ($scheduler !== null && $scheduler !== Schedulers::AUTOMATIC->value)) {
            return $draft;
        }

        foreach (Schedulers::cases() as $case) {
            if ($case === Schedulers::AUTOMATIC) {
                continue;
            }

            $suffix = ' ' . $case->value;
            if (!str_ends_with($sampler, $suffix)) {
                continue;
            }

            $prefix = substr($sampler, 0, -\strlen($suffix));
            if (Samplers::knows($prefix)) {
                return $draft->withSamplerName($prefix)->withScheduler($case->value);
            }
        }

        return $draft;
    }

    protected function accept(string $key, Field $field): static
    {
        return match ($key) {
            self::ROW_RANDOMIZE => $this->pressRandomize(),
            self::ROW_REUSE => $this->pressReuse(),
            default => $this->registryAccept($key, $field),
        };
    }

    protected function rebuild(array $changes): static
    {
        return new self($this->specs, [...$this->fields, ...$changes], $this->rowKeys, $this->lastSeed);
    }

    protected function rebuildLattice(array $specs, array $fields, array $rowKeys): static
    {
        return new self($specs, $fields, $rowKeys, $this->lastSeed);
    }

    protected function extraLabel(string $key): string
    {
        return match ($key) {
            self::ROW_RANDOMIZE => Lang::t(GenFormKeys::SEED_ACTION_RANDOMIZE),
            self::ROW_REUSE => Lang::t(GenFormKeys::SEED_ACTION_REUSE),
            self::ROW_LAST => Lang::t(GenFormKeys::SEED_LAST),
            default => throw new \InvalidArgumentException('params has no row ' . $key),
        };
    }

    protected function display(string $key): string
    {
        return match ($key) {
            // Action rows are commands: the label IS the row, no value cell.
            self::ROW_RANDOMIZE, self::ROW_REUSE => '',
            self::ROW_LAST => $this->lastSeed === null
                ? Lang::t(GenFormKeys::NOT_SET)
                : (string) $this->lastSeed,
            default => $this->registryDisplay($key),
        };
    }

    private function pressRandomize(): static
    {
        return $this->rebuild([
            'seed' => self::seedField($this->fields)->withValue(-1),
            self::ROW_RANDOMIZE => self::pressRow(self::ROW_RANDOMIZE),
        ]);
    }

    private function pressReuse(): static
    {
        $changes = [self::ROW_REUSE => self::pressRow(self::ROW_REUSE)];
        if ($this->lastSeed !== null) {
            $changes['seed'] = self::seedField($this->fields)->withValue($this->lastSeed);
        }

        return $this->rebuild($changes);
    }

    private static function seedField(array $fields): NumberField
    {
        $seed = $fields['seed'];
        assert($seed instanceof NumberField);

        return $seed;
    }

    private static function pressRow(string $key): Confirm
    {
        return Confirm::new($key)->withTitle(Lang::t(match ($key) {
            self::ROW_RANDOMIZE => GenFormKeys::SEED_ACTION_RANDOMIZE,
            default => GenFormKeys::SEED_ACTION_REUSE,
        }));
    }

    private static function seededField(FieldSpec $spec, MediaRequest $draft, ?array $runtimeChoices): Field
    {
        $label = Lang::t($spec->labelKey);
        $field = $spec->createField($spec->key, $spec->choices === null ? null : ($runtimeChoices[$spec->choices] ?? null));
        $value = $spec->valueFrom($draft);

        // Every params row is numeric-or-select; the registry may grow text
        // rows into this section one day, and a silent wrong widget here is
        // a value that never reaches the wire — so unknown kinds fail loud.
        if ($field instanceof Slider) {
            return $field->withTitle($label)->withValue(self::numericValue($spec, $value));
        }

        if ($field instanceof NumberField) {
            return $field->withTitle($label)->withValue(self::numericValue($spec, $value));
        }

        if ($field instanceof Select) {
            return $field->withTitle($label)->withSelected((string) $value);
        }

        throw new \LogicException('params row ' . $spec->key . ' carries a non-params widget');
    }

    private static function numericValue(FieldSpec $spec, mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        throw new \LogicException('params row ' . $spec->key . ' default is not numeric');
    }
}

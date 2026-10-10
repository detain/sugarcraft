<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm\Sections;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\ScriptFields;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Select;

/**
 * The Scripts tab (crush-media W3.6, plan §4.4/§5.1/§5.2) — the client for
 * GET /sdapi/v1/script-info. Widgets are projected by {@see ScriptFields}
 * from the server's own descriptors; this class only lays out rows, gates
 * disclosures, and serialises the fold.
 *
 * Layout: one dropdown of the SELECTABLE scripts ('None' first, server
 * order = §5.2 — the client never re-sorts), the selected script's argument
 * group directly under it, an explanatory note whenever any descriptor is
 * img2img-only (today every script renders in every mode; mode gating is a
 * later wave), then one accordion row per ALWAYSON script titled with its
 * verbatim server name, expanding to its own argument group.
 *
 * With no descriptors (no client, or the fetch failed) the whole tab
 * collapses to a single "no backend connected" note row and collect() is
 * the identity — the fail-open doctrine of W1.2: discovery is best-effort,
 * a missing server must never block the form.
 *
 * Serialization (§4.5): `script_name` + `script_args` carry the selected
 * script and its ordered values 1:1 (ordered = declared order); switching
 * the dropdown rebuilds the argument group from the NEW script's
 * descriptors, so two selectable scripts can never coexist in script_args.
 * `alwayson_scripts` is `{exactName: {args: [...]}}` for ALL alwayson
 * scripts with their current values — the INCLUDE-ALL ruling: A1111 posts
 * every alwayson script's components regardless of accordion state (its
 * hidden inputs always ride the form), and omitting one changes the render,
 * so collapsed groups still serialize. Titles sent are the same strings
 * that built the widgets — they come out of the one `$descriptors` list,
 * never retyped (a mismatched title is a 422 from the server).
 */
final class ScriptsSection extends RegistrySection
{
    public const ROW_NO_BACKEND = 'scripts_no_backend';
    public const ROW_SELECT = 'scripts_select';
    public const ROW_IMG2IMG_NOTE = 'scripts_img2img_note';

    /** Selected selectable-script argument rows: `script_arg:{index}`. */
    private const ARG_PREFIX = 'script_arg:';

    /** Alwayson accordion headers `alwayson:{index}` and their args. */
    private const ALWAYSON_PREFIX = 'alwayson:';

    private const ALWAYSON_ARG_INFIX = ':arg:';

    /**
     * @param list<array{name: string, is_alwayson: bool, is_img2img: bool, args: list<array{label: string, value: mixed, minimum: float|null, maximum: float|null, step: float|null, choices: list<string>}>}>|null $descriptors
     */
    private function __construct(array $fields, array $rowKeys, private readonly ?array $descriptors)
    {
        parent::__construct([], $fields, $rowKeys);
    }

    /**
     * @param list<mixed>|null $scriptDescriptors raw /sdapi/v1/script-info payload; null = undiscovered
     */
    public static function forDraft(MediaRequest $draft, ?array $scriptDescriptors = null): self
    {
        if ($scriptDescriptors === null) {
            return new self([], [self::ROW_NO_BACKEND], null);
        }

        $descriptors = ScriptFields::normalize($scriptDescriptors);
        $fields = [];

        $selectable = array_values(array_filter($descriptors, static fn (array $d): bool => !$d['is_alwayson']));
        $noneLabel = Lang::t(GenFormKeys::SCRIPTS_NONE);
        $selectedName = null;
        foreach ($selectable as $candidate) {
            if ($candidate['name'] === $draft->scriptName()) {
                $selectedName = $candidate['name'];
            }
        }

        $fields[self::ROW_SELECT] = Select::new(self::ROW_SELECT)
            ->withOptions($noneLabel, ...array_column($selectable, 'name'))
            ->withSelected($selectedName ?? $noneLabel);

        if ($selectedName !== null) {
            $seed = self::positionalSeed($draft->scriptArgs(), self::descriptor($descriptors, $selectedName)['args']);
            foreach (self::descriptor($descriptors, $selectedName)['args'] as $index => $arg) {
                $key = self::ARG_PREFIX . $index;
                $fields[$key] = ScriptFields::field($key, $arg, $arg['label'], $seed[$index] ?? null);
            }
        }

        foreach (array_values($descriptors) as $index => $descriptor) {
            if (!$descriptor['is_alwayson']) {
                continue;
            }

            $header = self::ALWAYSON_PREFIX . $index;
            $fields[$header] = Confirm::new($header, false)->withTitle($descriptor['name']);
            // Collapsed or not, every alwayson argument widget exists: the
            // INCLUDE-ALL ruling posts their current values regardless.
            $prior = $draft->alwaysonScripts();
            $saved = is_array($prior) && is_array($prior[$descriptor['name']] ?? null)
                ? $prior[$descriptor['name']]['args'] ?? null
                : null;
            $seed = self::positionalSeed(is_array($saved) ? $saved : null, $descriptor['args']);
            foreach ($descriptor['args'] as $argIndex => $arg) {
                $key = $header . self::ALWAYSON_ARG_INFIX . $argIndex;
                $fields[$key] = ScriptFields::field($key, $arg, $arg['label'], $seed[$argIndex] ?? null);
            }
        }

        return new self($fields, self::layout($fields, $descriptors), $descriptors);
    }

    public function key(): string
    {
        return 'scripts';
    }

    public function label(): string
    {
        return Lang::t(GenFormKeys::SECTION_SCRIPTS);
    }

    public function collect(MediaRequest $draft): MediaRequest
    {
        if ($this->descriptors === null) {
            return $draft;
        }

        $selected = $this->selectedName();
        if ($selected === null) {
            // Stale-clear only when the draft actually carries the pair; a
            // never-touched tab emits nothing (the fresh-draft key law).
            if ($draft->isSet('script_name') || $draft->isSet('script_args')) {
                $draft = $draft->withScriptName(null)->withScriptArgs(null);
            }
        } else {
            $args = [];
            foreach (self::descriptor($this->descriptors, $selected)['args'] as $index => $arg) {
                $field = $this->fields[self::ARG_PREFIX . $index] ?? null;
                $args[] = $field === null ? ScriptFields::wireValue(
                    ScriptFields::field(self::ARG_PREFIX . $index, $arg, $arg['label']),
                    $arg,
                ) : ScriptFields::wireValue($field, $arg);
            }

            $draft = $draft->withScriptName($selected)->withScriptArgs($args);
        }

        $alwayson = [];
        foreach ($this->descriptors as $index => $descriptor) {
            if (!$descriptor['is_alwayson']) {
                continue;
            }

            $args = [];
            foreach ($descriptor['args'] as $argIndex => $arg) {
                $key = self::ALWAYSON_PREFIX . $index . self::ALWAYSON_ARG_INFIX . $argIndex;
                $field = $this->fields[$key] ?? null;
                $args[] = $field === null
                    ? ScriptFields::wireValue(ScriptFields::field($key, $arg, $arg['label']), $arg)
                    : ScriptFields::wireValue($field, $arg);
            }

            // Key inserted LAST = the exact title the widget group was built
            // from — structurally same-fetch, no hand-typed second copy.
            $alwayson[$descriptor['name']] = ['args' => $args];
        }

        if ($alwayson === []) {
            return $draft->isSet('alwayson_scripts') ? $draft->withAlwaysonScripts(null) : $draft;
        }

        return $draft->withAlwaysonScripts($alwayson);
    }

    protected function accept(string $key, Field $field): static
    {
        if ($this->descriptors === null) {
            return $this;
        }

        if ($key === self::ROW_SELECT) {
            // Drop the outgoing script's argument widgets wholesale, then
            // re-seed from the NEW script's descriptors at ITS defaults —
            // stale args structurally cannot survive a switch.
            $fields = [$key => $field];
            foreach ($this->fields as $existing => $widget) {
                if (!str_starts_with((string) $existing, self::ARG_PREFIX)) {
                    $fields[(string) $existing] ??= $widget;
                }
            }

            $selected = self::selectedFrom($fields, $this->descriptors);
            if ($selected !== null) {
                foreach (self::descriptor($this->descriptors, $selected)['args'] as $index => $arg) {
                    $argKey = self::ARG_PREFIX . $index;
                    $fields[$argKey] = ScriptFields::field($argKey, $arg, $arg['label']);
                }
            }

            return new self($fields, self::layout($fields, $this->descriptors), $this->descriptors);
        }

        if (preg_match('/^' . preg_quote(self::ALWAYSON_PREFIX, '/') . '(\d+)$/', $key, $matches) === 1) {
            $current = $this->fields[$key] ?? null;
            $openNow = $current instanceof Confirm && (bool) $current->value();
            $flipped = Confirm::new($key, !$openNow)
                ->withTitle((string) $current?->getTitle());
            $fields = [...$this->fields, $key => $flipped];

            return new self($fields, self::layout($fields, $this->descriptors), $this->descriptors);
        }

        $fields = [...$this->fields, $key => $field];

        return new self($fields, self::layout($fields, $this->descriptors), $this->descriptors);
    }

    protected function extraLabel(string $key): string
    {
        if ($this->descriptors === null) {
            return $key === self::ROW_NO_BACKEND ? Lang::t(GenFormKeys::SCRIPTS_NO_BACKEND) : parent::extraLabel($key);
        }

        // A regex step on the row key resolves it against the descriptor
        // lists — the source of both the on-screen label and the wire title.
        if ($key === self::ROW_NO_BACKEND) {
            return Lang::t(GenFormKeys::SCRIPTS_NO_BACKEND);
        }

        if ($key === self::ROW_IMG2IMG_NOTE) {
            return Lang::t(GenFormKeys::SCRIPTS_IMG2IMG_NOTE);
        }

        if ($key === self::ROW_SELECT) {
            return Lang::t(GenFormKeys::SCRIPTS_SELECT);
        }

        $prefix = preg_quote(self::ALWAYSON_PREFIX, '/');
        if (preg_match('/^' . $prefix . '(\d+)' . preg_quote(self::ALWAYSON_ARG_INFIX, '/') . '(\d+)$/', $key, $m) === 1) {
            return $this->descriptorAt((int) $m[1])['args'][(int) $m[2]]['label'];
        }

        if (preg_match('/^' . $prefix . '(\d+)$/', $key, $m) === 1) {
            return $this->descriptorAt((int) $m[1])['name'];
        }

        if (preg_match('/^' . preg_quote(self::ARG_PREFIX, '/') . '(\d+)$/', $key, $m) === 1) {
            $selected = $this->selectedName();

            return $selected === null
                ? parent::extraLabel($key)
                : self::descriptor($this->descriptors, $selected)['args'][(int) $m[1]]['label'];
        }

        return parent::extraLabel($key);
    }

    protected function display(string $key): string
    {
        $field = $this->fields[$key] ?? null;
        if ($field === null) {
            // Note rows ride the label column with an empty cell.
            return '';
        }

        if ($field instanceof Confirm) {
            return self::boolCell((bool) $field->value());
        }

        $value = (string) $field->value();

        return $value === '' ? Lang::t(GenFormKeys::NOT_SET) : $value;
    }

    /**
     * The row order currently on screen: dropdown, selected script's args,
     * img2img note, then each alwayson header with its args only while the
     * accordion is open.
     *
     * @param array<string, Field> $fields
     * @param list<array{name: string, is_alwayson: bool, is_img2img: bool, args: list<mixed>}> $descriptors
     * @return list<string>
     */
    private static function layout(array $fields, array $descriptors): array
    {
        $rowKeys = [self::ROW_SELECT];
        $selected = self::selectedFrom($fields, $descriptors);
        if ($selected !== null) {
            foreach (array_keys(self::descriptor($descriptors, $selected)['args']) as $index) {
                $rowKeys[] = self::ARG_PREFIX . $index;
            }
        }

        if (in_array(true, array_column($descriptors, 'is_img2img'), true)) {
            $rowKeys[] = self::ROW_IMG2IMG_NOTE;
        }

        foreach ($descriptors as $index => $descriptor) {
            if (!$descriptor['is_alwayson']) {
                continue;
            }

            $header = self::ALWAYSON_PREFIX . $index;
            $rowKeys[] = $header;
            $open = $fields[$header] ?? null;
            if ($open instanceof Confirm && (bool) $open->value()) {
                foreach (array_keys($descriptor['args']) as $argIndex) {
                    $rowKeys[] = $header . self::ALWAYSON_ARG_INFIX . $argIndex;
                }
            }
        }

        return $rowKeys;
    }

    private function selectedName(): ?string
    {
        return $this->descriptors === null ? null : self::selectedFrom($this->fields, $this->descriptors);
    }

    /**
     * @param array<string, Field> $fields
     * @param list<array{name: string, is_alwayson: bool, is_img2img: bool, args: list<mixed>}> $descriptors
     */
    private static function selectedFrom(array $fields, array $descriptors): ?string
    {
        $select = $fields[self::ROW_SELECT] ?? null;
        if (!$select instanceof Select) {
            return null;
        }

        $name = (string) $select->value();
        if ($name === '' || $name === Lang::t(GenFormKeys::SCRIPTS_NONE)) {
            return null;
        }

        foreach ($descriptors as $descriptor) {
            if ($descriptor['name'] === $name && !$descriptor['is_alwayson']) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Prior positional values only when the list length matches the script's
     * declared arity — a short/long script_args (switched script, foreign
     * payload) seeds nothing rather than half-aligning.
     *
     * @param list<mixed>|null                     $saved
     * @param list<array{label: string, value: mixed}> $args
     * @return list<mixed>|null
     */
    private static function positionalSeed(?array $saved, array $args): ?array
    {
        if ($saved === null || count($saved) !== count($args)) {
            return null;
        }

        return array_values($saved);
    }

    /**
     * @param list<array{name: string, is_alwayson: bool, is_img2img: bool, args: list<mixed>}> $descriptors
     * @return array{name: string, is_alwayson: bool, is_img2img: bool, args: list<mixed>}
     */
    private static function descriptor(array $descriptors, string $name): array
    {
        foreach ($descriptors as $descriptor) {
            if ($descriptor['name'] === $name) {
                return $descriptor;
            }
        }

        throw new \InvalidArgumentException('unknown script ' . $name);
    }

    /** @return array{name: string, is_alwayson: bool, is_img2img: bool, args: list<mixed>} */
    private function descriptorAt(int $index): array
    {
        $descriptor = $this->descriptors[$index] ?? null;
        if ($descriptor === null) {
            throw new \InvalidArgumentException('no script descriptor at ' . $index);
        }

        return $descriptor;
    }
}

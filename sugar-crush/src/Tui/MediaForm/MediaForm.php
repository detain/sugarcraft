<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\ImageIngress;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Theme;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Style\Style;

/**
 * The media generation form (crush-media W3.1, Shape A): a full-band,
 * App-owned modal in exactly the {@see \SugarCraft\Crush\Tui\Settings\SettingsEditor}
 * mold — plain-letters keys, hand-drawn rounded chrome, view-local click
 * zones, and no I/O of its own.
 *
 * HELD BY THE SHELL ({@see \SugarCraft\Crush\App\App::$mediaForm}), not by
 * `Chat`. While it is up the shell routes every key here except the gate
 * exceptions (mystage §6.2: a modal that leaks keystrokes is worse than no
 * modal), and what leaves the form is a {@see MediaFormAction}, never a
 * request the form itself sends: `Generate` carries the collected draft to
 * the shell, which feeds it through the very tool pipeline the typed
 * `/generate` uses — one dispatch path, not a forked second one.
 *
 * SECTIONS ARE PLUGGABLE (the {@see Section} door): the W3.1 shell ships one
 * placeholder Params section so the frame renders real chrome; W3.4's
 * FieldSpec registry replaces the list through {@see fromSections()} without
 * touching this class. Tab order, row counts, and focus clamps all derive
 * from the visible sections, so a section that hides itself under the current
 * state simply is not on the strip.
 *
 * The view is exactly `$rows` lines of exactly `$cols` cells — same fit law
 * as settings — and {@see zones()} computes click targets from the same
 * geometry the frame is drawn with, so a zone is where its tab or row is
 * painted.
 */
final class MediaForm
{
    /** Every mouse zone the view records starts with this. */
    public const ZONE_PREFIX = 'media:';
    public const TAB_ZONE = 'media:tab:';
    public const ROW_ZONE = 'media:row:';

    /**
     * Reserved for W3.7's help band (the key-reference footer as a click
     * target). Declared with the trio for named symmetry; the W3.1 shell
     * paints no zone under it.
     */
    public const HELP_ZONE = 'media:help:';

    /** Rows one wheel notch moves, matching the settings editor. */
    private const WHEEL_ROWS = 3;

    /**
     * Literal-English status strings, MediaPresets::STORE_UNAVAILABLE
     * precedent: they name a ruling, not a UI phrase, and must read the
     * same in every locale (the store refusal lane d shipped is one).
     */
    public const PRESET_NAME_REQUIRED = 'name the preset on the Presets tab first';

    public const CLIPBOARD_ORIGIN = 'clipboard.png';

    public const CLIPBOARD_ATTACHED = 'clipboard image attached';

    public const CLIPBOARD_UNAVAILABLE = 'the img2img section is not open on this launch';

    /**
     * @param list<Section> $sections tab order; visible ones form the strip
     */
    private function __construct(
        private readonly array $sections,
        private readonly MediaRequest $draft,
        private readonly MediaFormState $state,
        private readonly ?Field $editing = null,
        private readonly ?string $status = null,
        /** Parsed `sd.presets` store, null when the map refused to parse. */
        private readonly ?MediaPresets $presets = null,
        /** Door that hands the full replaced map to the settings writer. */
        private readonly ?\Closure $persistPresets = null,
    ) {
    }

    /** Open the form over `$request` (a fresh draft when null) with the W3.4
     *  registry sections: Prompt and Params. `$runtimeChoices` is an optional
     *  {@see ChoiceFeed} map — the HTTP hop lives in the App seam, never
     *  here, so this constructor stays deterministic and network-free.
     *  `$scriptDescriptors` is the same doctrine for W3.6: the raw
     *  /sdapi/v1/script-info payload fetched upstream, null until supplied;
     *  `$optionsMap` carries the W3.8 /sdapi/v1/options payload for the
     *  Advanced tab's key completion, same rule.
     *
     * @param array<string, list<string>>|null $runtimeChoices
     * @param list<mixed>|null $scriptDescriptors
     * @param array<string, mixed>|null $optionsMap
     * @param array<string, mixed>|null $presets raw `sd.presets` map; a
     *        malformed entry opens the form on the loud-refusal status
     * @param ?\Closure(array<string,mixed>):void $persistPresets settings-write door
     * @param array<string, mixed>|null $displayOverrides raw `ui.mediaDisplayOverrides` map
     */
    public static function open(?MediaRequest $request = null, ?array $runtimeChoices = null, ?array $scriptDescriptors = null, ?array $optionsMap = null, ?array $presets = null, ?\Closure $persistPresets = null, ?array $displayOverrides = null, ?string $savePattern = null): self
    {
        $draft = $request ?? MediaRequest::new();
        $store = null;
        $refusal = null;
        if ($presets !== null) {
            try {
                $store = MediaPresets::fromStored($presets);
            } catch (\InvalidArgumentException $error) {
                $refusal = $error->getMessage();
            }
        }

        $sections = [
            Sections\PromptSection::forDraft($draft),
            Sections\ParamsSection::forDraft($draft, $runtimeChoices),
            // W3.5 disclosure groups.
            Sections\SeedSection::forDraft($draft),
            Sections\HiresSection::forDraft($draft, $runtimeChoices),
            Sections\RefinerSection::forDraft($draft, $runtimeChoices),
            // W3.7 gated groups (Wave4Gate closed); lanes c/d append the
            // Scripts/Advanced and Presets/Output rows after these lines.
            Sections\ImageSection::forDraft($draft, $runtimeChoices),
            Sections\InpaintSection::forDraft($draft, $runtimeChoices),
            // W5.6 video tab: kind-gated FAIL-CLOSED for the tab (it shows
            // only when mediaKinds names 'video'; un-declared hosts keep the
            // tool path), zero-dial; an unengaged tab never folds onto the
            // draft, so image runs are untouched. Tests inject $enabled.
            Sections\VideoSection::forDraft($draft),
            // W3.6 scripts tab (descriptors from the caller; undiscovered
            // today renders the fail-open note).
            Sections\ScriptsSection::forDraft($draft, $scriptDescriptors),
            // W3.8 advanced tab (typed override_settings rows; the options
            // map only suggests keys). Lane d appends the Presets/Output
            // rows after these lines.
            Sections\AdvancedSection::forDraft($draft, $optionsMap),
            // W3.9 presets tab (named FieldSpec bundles over `sd.presets`)
            // and the read-only output tab (effective `sd.savePattern` plus
            // the token-palette help line).
            Sections\PresetsSection::forDraft($store),
            Sections\OutputSection::forDraft($savePattern),
        ];

        if ($displayOverrides !== null && $displayOverrides !== []) {
            $sections = array_map(
                static fn (Section $s): Section => $s instanceof FieldValuedSection
                    ? $s->withDisplayOverrides($displayOverrides)
                    : $s,
                $sections,
            );
        }

        return new self(
            $sections,
            $draft,
            MediaFormState::forSection(0, \count($sections[0]->rows())),
            presets: $store,
            persistPresets: $persistPresets,
            status: $refusal === null ? null : '! ' . $refusal,
        );
    }

    /**
     * Open over a caller-supplied section list (W3.4's registry seam): the
     * draft seeds every section's widgets exactly as {@see open()} seeds the
     * placeholder.
     *
     * @param list<Section> $sections
     */
    public static function fromSections(array $sections, ?MediaRequest $request = null, ?MediaPresets $presets = null, ?\Closure $persistPresets = null): self
    {
        $draft = $request ?? MediaRequest::new();
        if ($sections === []) {
            $sections = [ParamsSection::forDraft($draft)];
        }

        return new self(
            $sections,
            $draft,
            MediaFormState::forSection(0, \count($sections[0]->rows())),
            presets: $presets,
            persistPresets: $persistPresets,
        );
    }

    /** The registered sections, tab order (the wiring seam for later lanes). */
    public function sections(): array
    {
        return $this->sections;
    }

    /** The draft as it stood when the form opened — collection never mutates it. */
    public function draft(): MediaRequest
    {
        return $this->draft;
    }

    /**
     * The current widget values across every {@see FieldValuedSection},
     * keyed by wire name (preset save / infotext emit, crush-media W3.4).
     *
     * @return array<string, mixed>
     */
    public function collectValues(): array
    {
        $values = [];
        foreach ($this->sections as $section) {
            if ($section instanceof FieldValuedSection) {
                $values += $section->fieldValues();
            }
        }

        return $values;
    }

    /**
     * Push a flat wire-keyed value map (PNG infotext import, preset load)
     * into every value-carrying section and refresh the draft from the
     * rebuilt widgets. Junk values are refused by the sections' own
     * injection, so an import can never forge an off-lattice number.
     *
     * @param array<string, mixed> $values
     */
    public function hydrateFields(array $values): self
    {
        $sections = array_map(
            static fn (Section $s): Section => $s instanceof FieldValuedSection ? $s->withFieldValues($values) : $s,
            $this->sections,
        );
        $form = $this->mutate(['sections' => $sections]);

        return $form->mutate(['sections' => $sections, 'draft' => $form->collect()]);
    }

    /**
     * Teach the ♻ row the seed of the last settled generation (W3.5 seam:
     * the shell calls this when a result lands while the form is open;
     * until then the row honestly reports "not set").
     */
    public function withLastSeed(?int $seed): self
    {
        $sections = array_map(
            static fn (Section $s): Section => $s instanceof Sections\ParamsSection ? $s->withLastSeed($seed) : $s,
            $this->sections,
        );

        return $this->mutate(['sections' => $sections]);
    }

    public function state(): MediaFormState
    {
        return $this->state;
    }

    /** A one-line status from the shell (a refused dial, a staged preset). */
    public function withStatus(string $status): self
    {
        return $this->mutate(['status' => $status]);
    }

    /** The draft with every visible section folded into it, in tab order. */
    public function collect(): MediaRequest
    {
        $draft = $this->draft;
        foreach ($this->visibleSections() as $section) {
            $draft = $section->collect($draft);
        }

        return $draft;
    }

    // ── input ───────────────────────────────────────────────────────────

    /**
     * Answer a key. The first element is null when the form closed; the
     * second carries the action the shell must execute, if any.
     *
     * Plain letters, settings-style: `Esc` closes, `Enter` edits the
     * highlighted field row, `g` collects and generates, `↑`/`↓` (or `k`/`j`)
     * move, `←`/`→` (or `h`/`l` or `Tab`) switch sections. An open field owns
     * the keyboard: `Enter` commits it, `Esc` abandons the typed text.
     *
     * @return array{0: ?self, 1: ?MediaFormAction}
     */
    public function update(KeyMsg $msg): array
    {
        $plainRune = $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt ? $msg->rune : null;

        // An open field takes every key: Enter commits it, Esc drops it.
        // The one exception is the W4.1 image picker (file-URL law of this
        // shell): while the picker has no selection yet, Enter belongs to it
        // — descend and select. Once a path is selected the next Enter is
        // the commit, which routes through ImageSection::accept().
        if ($this->editing !== null) {
            return match ($msg->type) {
                KeyType::Enter => $this->pickerStillBrowsing()
                    ? [$this->feedEdit($msg), null]
                    : [$this->commitEdit(), null],
                KeyType::Escape => [$this->cancelEdit(), null],
                default => [$this->feedEdit($msg), null],
            };
        }

        if ($msg->type === KeyType::Escape) {
            return [null, MediaFormAction::cancel()];
        }

        if ($msg->type === KeyType::Up) {
            return [$this->moveRow(-1), null];
        }

        if ($msg->type === KeyType::Down) {
            return [$this->moveRow(1), null];
        }

        if ($msg->type === KeyType::Enter) {
            return [$this->beginEdit(), null];
        }

        if ($msg->type === KeyType::Left) {
            return [$this->switchSection(-1), null];
        }

        if ($msg->type === KeyType::Right) {
            return [$this->switchSection(1), null];
        }

        if ($msg->type === KeyType::Tab) {
            // InputReader maps both Tab and Shift+Tab to KeyType::Tab with
            // the shift flag — reverse crosses sections backwards.
            return [$this->switchSection($msg->shift ? -1 : 1), null];
        }

        // FormTabs ruling (W3.7): the tab strip is reached by these section-
        // jump keys and its click zones, never by Up/Down — those stay row
        // navigation so an editing NumberField keeps its ↑/↓ inc/dec.
        if ($msg->type === KeyType::PageUp) {
            return [$this->switchSection(-1), null];
        }

        if ($msg->type === KeyType::PageDown) {
            return [$this->switchSection(1), null];
        }

        if ($msg->type !== KeyType::Char || $msg->ctrl || $msg->alt) {
            return [$this, null];
        }

        return match ($msg->rune) {
            // `s` is the W4.2 SavePreset binding (crush-media W4.1/W4.2):
            // the action leaves the form, the shell routes it back into
            // {@see self::saveFocusedPreset()} — one code path with the
            // Presets tab's Save press-row. `i` is the W4.4 ImportPng
            // binding: same shape, routed into
            // {@see self::importAttachedPng()} over the attached PNG.
            'g' => [null, MediaFormAction::generate($this->collect())],
            's' => [null, MediaFormAction::savePreset($this->collect())],
            'i' => [null, MediaFormAction::importPng()],
            'k' => [$this->moveRow(-1), null],
            'j' => [$this->moveRow(1), null],
            'h' => [$this->switchSection(-1), null],
            'l' => [$this->switchSection(1), null],
            default => [$this, null],
        };
    }

    /** A completed click on one of the view's zones; anything else is ignored. */
    public function click(string $zoneId): self
    {
        if (str_starts_with($zoneId, self::TAB_ZONE)) {
            return $this->focusSection((int) substr($zoneId, \strlen(self::TAB_ZONE)));
        }

        if (str_starts_with($zoneId, self::ROW_ZONE)) {
            $rows = $this->focusedRows();
            $row = (int) substr($zoneId, \strlen(self::ROW_ZONE));

            return $row >= 0 && $row < $rows
                ? $this->mutate(['state' => $this->state->withRow($row, $rows), 'status' => null])
                : $this;
        }

        return $this;
    }

    /** One wheel notch: positive scrolls down the list. */
    public function wheel(int $notch): self
    {
        return $this->moveRow($notch * self::WHEEL_ROWS, wrap: false);
    }

    /** Start editing the highlighted row's field; read-only rows stay put. */
    public function beginEdit(): self
    {
        $field = $this->focusedSection()?->fieldAt($this->state->row);
        if ($field === null) {
            return $this;
        }

        [$focused] = $field->focus();

        return $this->mutate(['editing' => $focused, 'state' => $this->state->withEditing(true)]);
    }

    private function feedEdit(KeyMsg $msg): self
    {
        $field = $this->editing;
        if ($field === null) {
            return $this;
        }

        [$updated] = $field->update($msg);

        return $this->mutate(['editing' => $updated]);
    }

    /**
     * True while the open field is the W4.1 image picker with no selection
     * yet — during that browse Enter belongs to the picker (descend/select),
     * not to the commit. Once a path is selected, the next Enter commits.
     */
    private function pickerStillBrowsing(): bool
    {
        return $this->editing instanceof FilePicker && $this->editing->value() === null;
    }

    /** The name currently typed on the Presets tab, trimmed ('' when none). */
    public function pendingPresetName(): string
    {
        foreach ($this->sections as $section) {
            if ($section instanceof Sections\PresetsSection) {
                return $section->pendingName();
            }
        }

        return '';
    }

    /**
     * The shell-side `s` binding (crush-media W4.1): save the current
     * values under the typed preset name through the injected persist door.
     * Payload bytes never ride along — the save folds `collectValues()`,
     * and the image source rows are section-local, not lattice fields.
     * Refusals land as a '!' status exactly like the Presets tab's Save row.
     */
    public function saveFocusedPreset(): self
    {
        $name = $this->pendingPresetName();
        if ($name === '') {
            return $this->mutate(['status' => '! ' . self::PRESET_NAME_REQUIRED]);
        }

        return $this->runPresetCommand(['save', $name]);
    }

    /**
     * The W4.2 clipboard paste: the host persists the bytes first (volatile
     * temp law) and hands them here; the img2img section takes the slot, or
     * the refusal message rides the form status. Nothing is validated
     * twice — {@see ImageIngress::fromBytes()} is the single door.
     */
    public function withClipboardImage(string $bytes): self
    {
        foreach ($this->sections as $i => $section) {
            if (!$section instanceof Sections\ImageSection) {
                continue;
            }

            try {
                $ingress = ImageIngress::fromBytes($bytes, self::CLIPBOARD_ORIGIN);
            } catch (\InvalidArgumentException | \RuntimeException $error) {
                return $this->mutate(['status' => '! ' . $error->getMessage()]);
            }

            $sections = $this->sections;
            $sections[$i] = $section->withIngress($ingress);

            return $this->mutate([
                'sections' => $sections,
                'draft' => $sections[$i]->collect($this->draft),
                'status' => self::CLIPBOARD_ATTACHED . ' (' . $ingress->dimensions() . ')',
            ]);
        }

        return $this->mutate(['status' => '! ' . self::CLIPBOARD_UNAVAILABLE]);
    }

    /**
     * The first PNG attached on the Image tab, or null when none is — the
     * subject the `i` import reads ({@see self::importAttachedPng()}).
     * JPEG/GIF sources never qualify: the infotext lives in PNG chunks.
     */
    public function attachedPng(): ?ImageIngress
    {
        foreach ($this->sections as $section) {
            if (!$section instanceof Sections\ImageSection) {
                continue;
            }

            foreach ($section->sources() as $source) {
                if ($source->mime === 'image/png') {
                    return $source;
                }
            }
        }

        return null;
    }

    /**
     * The shell-side `i` binding (crush-media W4.4): import the attached
     * PNG's parameters over the draft, local chunk first and the gated
     * server png-info helper second ({@see ImportPng::import()}). The
     * imported fields land on the draft — the wire truth Generate folds
     * from — under Infotext's onlyUnset law, so nothing the user already
     * set is clobbered. Widget rows re-derive from the draft the next time
     * the form opens; mid-session the draft is authoritative on its own.
     * Refusals land as a '!' status exactly like the paste door's.
     *
     * @param ?callable(string):?string $serverInfo png-info channel seam;
     *        production passes the gated {@see ImportPng::serverInfoFor()}.
     */
    public function importAttachedPng(?callable $serverInfo = null): self
    {
        $png = $this->attachedPng();
        if ($png === null) {
            return $this->mutate(['status' => '! ' . ImportPng::NO_ATTACH]);
        }

        $result = ImportPng::import($png->bytes, $this->draft, $serverInfo);
        if ($result['error'] !== null) {
            return $this->mutate(['status' => '! ' . $result['error']]);
        }

        $via = $result['via'] === ImportPng::VIA_SERVER
            ? ImportPng::VIA_SERVER_LABEL
            : ImportPng::VIA_LOCAL_LABEL;

        return $this->mutate([
            'draft' => $result['request'],
            'status' => ImportPng::ATTACHED . ' from ' . $png->name() . ' (' . $via . ')',
        ]);
    }

    /** Hand the typed field back to its section, which owns coercion. */
    private function commitEdit(): self
    {
        $field = $this->editing;
        $index = $this->state->section;
        if ($field === null) {
            return $this;
        }

        $sections = $this->sections;
        $section = $this->visibleSections()[$index] ?? null;
        if ($section === null) {
            return $this->cancelEdit();
        }

        $replacement = $section->withField($field);
        $command = $replacement instanceof Sections\PresetsSection ? $replacement->pendingCommand() : null;
        if ($command !== null) {
            $replacement = $replacement->cleared();
        }

        foreach ($sections as $i => $candidate) {
            if ($candidate === $section) {
                $sections[$i] = $replacement;
            }
        }

        // The draft stays the single wire truth: committing a field folds its
        // section's collect() into it right away, not only at Generate.
        $form = $this->mutate([
            'sections' => $sections,
            'draft' => $replacement->collect($this->draft),
            'editing' => null,
            'state' => $this->state->withEditing(false),
        ]);

        return $command === null ? $form : $form->runPresetCommand($command);
    }

    /**
     * Execute a preset command the Presets tab just pressed (crush-media
     * W3.9). Save bundles `collectValues()` under the typed name — secret-
     * shaped keys are dropped by {@see MediaPresets::save()} — and the whole
     * replaced map rides the injected persist door; apply pushes the stored
     * bundle through {@see hydrateFields()}; delete retires the checked
     * names. A refusal lands as a '!' status and leaves the store untouched
     * (SettingsWriter's own refusals fire before anything is written, so a
     * rejected save never half-lands).
     *
     * @param array{0: string, 1: mixed} $command
     */
    private function runPresetCommand(array $command): self
    {
        [$kind, $payload] = $command;
        if ($this->presets === null) {
            return $this->mutate(['status' => '! ' . MediaPresets::STORE_UNAVAILABLE]);
        }

        try {
            $next = $this->presets;
            $persist = $this->persistPresets;
            switch ($kind) {
                case 'save':
                    $next = $next->save((string) $payload, $this->collectValues());
                    $status = Lang::t(GenFormKeys::PRESET_SAVE) . ' — ' . (string) $payload;
                    break;
                case 'apply':
                    $form = $this->hydrateFields($next->apply((string) $payload));

                    return $form->mutate(['status' => Lang::t(GenFormKeys::PRESET_APPLY) . ' — ' . (string) $payload]);
                case 'delete':
                    foreach ((array) $payload as $name) {
                        $next = $next->delete((string) $name);
                    }

                    $status = Lang::t(GenFormKeys::PRESET_DELETE) . ' — ' . implode(', ', array_map(strval(...), (array) $payload));
                    break;
                default:
                    return $this;
            }

            // Inside the try: a door that refuses (SettingsWriter throws its
            // refusals before anything is written) must land as a status,
            // never a crash — and the store the form shows stays the old one.
            if ($persist !== null) {
                $persist($next->toStored());
            }
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return $this->mutate(['status' => '! ' . $error->getMessage()]);
        }

        return $this->mutate([
            'sections' => $this->replacedStore($next),
            'presets' => $next,
            'status' => $status,
        ]);
    }

    /**
     * Re-mint every Presets tab over the new store so its name list refreshes.
     *
     * @return list<Section>
     */
    private function replacedStore(MediaPresets $store): array
    {
        return array_map(
            static fn (Section $s): Section => $s instanceof Sections\PresetsSection
                ? Sections\PresetsSection::forDraft($store)
                : $s,
            $this->sections,
        );
    }

    /** Abandon what was typed: the section keeps the field it already held. */
    private function cancelEdit(): self
    {
        return $this->mutate(['editing' => null, 'state' => $this->state->withEditing(false)]);
    }

    private function moveRow(int $delta, bool $wrap = true): self
    {
        $count = $this->focusedRows();
        if ($count === 0) {
            return $this;
        }

        $next = $this->state->row + $delta;
        $next = $wrap ? (($next % $count) + $count) % $count : max(0, min($count - 1, $next));

        // A status line is about the row it was said on; moving retires it.
        return $this->mutate(['state' => $this->state->withRow($next, $count), 'status' => null]);
    }

    private function switchSection(int $delta): self
    {
        $visible = $this->visibleSections();
        $count = \count($visible);
        if ($count === 0) {
            return $this;
        }

        return $this->focusSection(($this->state->section + $delta + $count) % $count);
    }

    /** Focus a strip position; out of range is a no-op (clicks arrive unchecked). */
    private function focusSection(int $index): self
    {
        $visible = $this->visibleSections();
        $section = $visible[$index] ?? null;
        if ($section === null) {
            return $this;
        }

        return $this->mutate([
            'state' => MediaFormState::forSection($index, \count($section->rows())),
            'editing' => null,
            'status' => null,
        ]);
    }

    // ── rendering ───────────────────────────────────────────────────────

    /**
     * The view, exactly `$rows` lines of exactly `$cols` cells.
     */
    public function view(Theme $theme, int $cols, int $rows): string
    {
        $cols = max(1, $cols);
        $rows = max(1, $rows);
        $width = $cols - 4;
        // Border (2) + tabs + rule + rule + footer (4), and a body.
        $body = $rows - 6;

        if ($width < 16 || $body < 1) {
            return self::fit([Style::new()->foreground($theme->shellMuted)->render(Lang::t(GenFormKeys::TOO_SMALL))], $cols, $rows);
        }

        $border = Style::new()->foreground($theme->shellPrimary);
        $muted = Style::new()->foreground($theme->shellMuted);

        // W7.5 perf: the visible set is evaluated once per painted frame and
        // threaded through tab labels and body rows (it was computed twice,
        // and zones() twice more, per keystroke-repaint).
        $visible = $this->visibleSections();

        $title = Width::truncate(' 🎨 ' . Lang::t(GenFormKeys::TITLE) . ' ', max(0, $cols - 3));
        $topFill = max(0, $cols - 3 - Width::string($title));
        $lines = [$border->render('╭─' . $title . str_repeat('─', $topFill) . '╮')];

        [$tabLine] = FormTabs::render($this->tabLabelsFrom($visible), $this->state->section, $width, $theme);

        $rule = $muted->render(str_repeat('─', $width));
        $statusLine = $this->status !== null
            ? Style::new()->foreground(str_starts_with($this->status, '! ') ? $theme->shellWarning : $theme->shellSuccess)
                ->render(Width::truncate(ltrim($this->status, '! '), $width))
            : $muted->render(self::hints($this->footer(), $width));

        $inner = [$tabLine, $rule, ...$this->bodyLines($theme, $width, $body, $visible), $rule, $statusLine];

        foreach ($inner as $line) {
            $lines[] = $border->render('│') . ' ' . self::cell($line, $width) . ' ' . $border->render('│');
        }

        $lines[] = $border->render('╰' . str_repeat('─', max(0, $cols - 2)) . '╯');

        return self::fit($lines, $cols, $rows);
    }

    /**
     * The click targets {@see view()} paints, in view-local cells (row 0 is
     * the top border), as `[row, from, to (exclusive), zone id]` — computed
     * from the same frame math, exactly like the settings editor's.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: string}>
     */
    public function zones(Theme $theme, int $cols, int $rows): array
    {
        $cols = max(1, $cols);
        $rows = max(1, $rows);
        $inner = $cols - 4;
        $body = $rows - 6;
        if ($inner < 16 || $body < 1) {
            return [];
        }

        $zones = [];
        $visible = $this->visibleSections();
        [, $tabSpans] = FormTabs::render($this->tabLabelsFrom($visible), $this->state->section, $inner, $theme);
        foreach ($tabSpans as [$from, $to, $index]) {
            $zones[] = [1, 2 + $from, 2 + $to, self::TAB_ZONE . $index];
        }

        // Rows are clickable only while the list is what the body shows.
        if ($this->editing === null) {
            $offset = $this->listOffset($body);
            $count = $this->focusedRowsFrom($visible);
            for ($r = 0; $r < $body; $r++) {
                $index = $offset + $r;
                if ($index >= $count) {
                    break;
                }

                $zones[] = [3 + $r, 2, 2 + $inner, self::ROW_ZONE . $index];
            }
        }

        return $zones;
    }

    /**
     * @param list<Section> $visible the frame's visible sections (evaluated once by the caller)
     *
     * @return list<string>
     */
    private function bodyLines(Theme $theme, int $width, int $rows, array $visible): array
    {
        if ($this->editing !== null) {
            return array_pad(\array_slice(explode("\n", $this->editing->view()), 0, $rows), $rows, '');
        }

        $section = $this->focusedSectionFrom($visible);
        if ($section === null) {
            return array_fill(0, $rows, '');
        }

        // Preview rows that recompute from the live draft (§2.4 final size)
        // read it here, so what renders is exactly what collect() would fold.
        if ($section instanceof DraftContextSection) {
            $section = $section->withDraftContext($this->draft);
        }

        $labels = $section->rows();
        $offset = $this->listOffset($rows);
        $labelW = min(14, max(8, intdiv(max(1, $width), 3)));
        $active = Style::new()->foreground($theme->shellForeground);
        $muted = Style::new()->foreground($theme->shellMuted);

        $lines = [];
        for ($r = 0; $r < $rows; $r++) {
            $index = $offset + $r;
            if ($index >= \count($labels)) {
                $lines[] = '';
                continue;
            }

            $selected = $index === $this->state->row;
            $value = $section->valueAt($index);
            $lines[] = ($selected ? $active : $muted)->render(($selected ? '▸ ' : '  ') . $labels[$index])
                . str_repeat(' ', max(1, $labelW - Width::string($labels[$index])))
                . ($value === Lang::t(GenFormKeys::NOT_SET) ? $muted : $active)->render($value);
        }

        return $lines;
    }

    private function listOffset(int $body): int
    {
        return max(0, $this->state->row - $body + 1);
    }

    /** @return list<string> */
    private function footer(): array
    {
        if ($this->editing !== null) {
            return [Lang::t(GenFormKeys::KEY_COMMIT), Lang::t(GenFormKeys::KEY_CANCEL)];
        }

        return [
            Lang::t(GenFormKeys::KEY_GENERATE),
            Lang::t(GenFormKeys::KEY_EDIT),
            Lang::t(GenFormKeys::KEY_MOVE),
            Lang::t(GenFormKeys::KEY_SECTIONS),
            Lang::t(GenFormKeys::KEY_CLOSE),
        ];
    }

    /**
     * @param list<Section> $visible
     *
     * @return list<string> the strip labels, visible sections only
     */
    private function tabLabelsFrom(array $visible): array
    {
        return array_values(array_map(static fn (Section $s): string => $s->label(), $visible));
    }

    /** @return list<Section> */
    private function visibleSections(): array
    {
        return array_values(array_filter(
            $this->sections,
            fn (Section $section): bool => $section->visible($this->state),
        ));
    }

    /** @param list<Section> $visible */
    private function focusedSectionFrom(array $visible): ?Section
    {
        return $visible[$this->state->section] ?? null;
    }

    /** @param list<Section> $visible */
    private function focusedRowsFrom(array $visible): int
    {
        return \count($this->focusedSectionFrom($visible)?->rows() ?? []);
    }

    private function focusedSection(): ?Section
    {
        return $this->focusedSectionFrom($this->visibleSections());
    }

    private function focusedRows(): int
    {
        return $this->focusedRowsFrom($this->visibleSections());
    }

    /**
     * Hints joined with ` · `, as many as fit in `$width` cells — the last
     * one (closing) is kept whenever anything fits at all (settings' law).
     *
     * @param list<string> $hints
     */
    private static function hints(array $hints, int $width): string
    {
        $all = implode(' · ', $hints);
        if (Width::string($all) <= $width || $hints === []) {
            return Width::truncate($all, $width);
        }

        $last = (string) array_pop($hints);
        $kept = [];
        foreach ($hints as $hint) {
            if (Width::string(implode(' · ', [...$kept, $hint, $last])) > $width) {
                break;
            }

            $kept[] = $hint;
        }

        return Width::truncate(implode(' · ', [...$kept, $last]), $width);
    }

    /** A styled line cut or padded to exactly `$width` cells. */
    private static function cell(string $line, int $width): string
    {
        $fitted = Width::string($line) > $width ? Width::truncateAnsi($line, $width) : $line;

        return $fitted . str_repeat(' ', max(0, $width - Width::string($fitted)));
    }

    /**
     * @param list<string> $lines
     */
    private static function fit(array $lines, int $cols, int $rows): string
    {
        $lines = \array_slice($lines, 0, $rows);
        while (\count($lines) < $rows) {
            $lines[] = '';
        }

        return implode("\n", array_map(static fn (string $l): string => self::cell($l, $cols), $lines));
    }

    /**
     * A copy with `$changes` applied (named by constructor parameter). An
     * open field is NOT carried unless named: every change of state other
     * than the ones that keep it answers or abandons it (settings' law).
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...($changes + [
            'sections' => $this->sections,
            'draft' => $this->draft,
            'state' => $this->state,
            'editing' => null,
            'status' => $this->status,
            'presets' => $this->presets,
            'persistPresets' => $this->persistPresets,
        ]));
    }
}

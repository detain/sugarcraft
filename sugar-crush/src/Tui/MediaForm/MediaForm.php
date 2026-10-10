<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Theme;
use SugarCraft\Forms\Field;
use SugarCraft\Sprinkles\Style;

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
     * @param list<Section> $sections tab order; visible ones form the strip
     */
    private function __construct(
        private readonly array $sections,
        private readonly MediaRequest $draft,
        private readonly MediaFormState $state,
        private readonly ?Field $editing = null,
        private readonly ?string $status = null,
    ) {
    }

    /** Open the form over `$request` (a fresh draft when null) with the W3.4
     *  registry sections: Prompt and Params. `$runtimeChoices` is an optional
     *  {@see ChoiceFeed} map — the HTTP hop lives in the App seam, never
     *  here, so this constructor stays deterministic and network-free.
     *  `$scriptDescriptors` is the same doctrine for W3.6: the raw
     *  /sdapi/v1/script-info payload fetched upstream, null until supplied.
     *
     * @param array<string, list<string>>|null $runtimeChoices
     * @param list<mixed>|null $scriptDescriptors
     */
    public static function open(?MediaRequest $request = null, ?array $runtimeChoices = null, ?array $scriptDescriptors = null): self
    {
        $draft = $request ?? MediaRequest::new();
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
            // W3.6 scripts tab (descriptors from the caller; undiscovered
            // today renders the fail-open note). Lane d appends the
            // Presets/Output rows after these lines.
            Sections\ScriptsSection::forDraft($draft, $scriptDescriptors),
        ];

        return new self($sections, $draft, MediaFormState::forSection(0, \count($sections[0]->rows())));
    }

    /**
     * Open over a caller-supplied section list (W3.4's registry seam): the
     * draft seeds every section's widgets exactly as {@see open()} seeds the
     * placeholder.
     *
     * @param list<Section> $sections
     */
    public static function fromSections(array $sections, ?MediaRequest $request = null): self
    {
        $draft = $request ?? MediaRequest::new();
        if ($sections === []) {
            $sections = [ParamsSection::forDraft($draft)];
        }

        return new self($sections, $draft, MediaFormState::forSection(0, \count($sections[0]->rows())));
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
        if ($this->editing !== null) {
            return match ($msg->type) {
                KeyType::Enter => [$this->commitEdit(), null],
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
            // SavePreset (`s`) and ImportPng (`i`) get their bindings in
            // W3.7/W4.2; the action kinds exist now so the shell's
            // consumption arm is total from day one.
            'g' => [null, MediaFormAction::generate($this->collect())],
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
        foreach ($sections as $i => $candidate) {
            if ($candidate === $section) {
                $sections[$i] = $replacement;
            }
        }

        // The draft stays the single wire truth: committing a field folds its
        // section's collect() into it right away, not only at Generate.
        return $this->mutate([
            'sections' => $sections,
            'draft' => $replacement->collect($this->draft),
            'editing' => null,
            'state' => $this->state->withEditing(false),
        ]);
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
            return self::fit([Style::new()->foreground($theme->shellMuted)->render(Lang::t('tui.genform.too_small'))], $cols, $rows);
        }

        $border = Style::new()->foreground($theme->shellPrimary);
        $muted = Style::new()->foreground($theme->shellMuted);

        $title = Width::truncate(' 🎨 ' . Lang::t('tui.genform.title') . ' ', max(0, $cols - 3));
        $topFill = max(0, $cols - 3 - Width::string($title));
        $lines = [$border->render('╭─' . $title . str_repeat('─', $topFill) . '╮')];

        [$tabLine] = FormTabs::render($this->tabLabels(), $this->state->section, $width, $theme);

        $rule = $muted->render(str_repeat('─', $width));
        $statusLine = $this->status !== null
            ? Style::new()->foreground(str_starts_with($this->status, '! ') ? $theme->shellWarning : $theme->shellSuccess)
                ->render(Width::truncate(ltrim($this->status, '! '), $width))
            : $muted->render(self::hints($this->footer(), $width));

        $inner = [$tabLine, $rule, ...$this->bodyLines($theme, $width, $body), $rule, $statusLine];

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
        [, $tabSpans] = FormTabs::render($this->tabLabels(), $this->state->section, $inner, $theme);
        foreach ($tabSpans as [$from, $to, $index]) {
            $zones[] = [1, 2 + $from, 2 + $to, self::TAB_ZONE . $index];
        }

        // Rows are clickable only while the list is what the body shows.
        if ($this->editing === null) {
            $offset = $this->listOffset($body);
            $count = $this->focusedRows();
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
     * @return list<string>
     */
    private function bodyLines(Theme $theme, int $width, int $rows): array
    {
        if ($this->editing !== null) {
            return array_pad(\array_slice(explode("\n", $this->editing->view()), 0, $rows), $rows, '');
        }

        $section = $this->focusedSection();
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
                . ($value === Lang::t('tui.genform.not_set') ? $muted : $active)->render($value);
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
            return [Lang::t('tui.genform.key.commit'), Lang::t('tui.genform.key.cancel')];
        }

        return [
            Lang::t('tui.genform.key.generate'),
            Lang::t('tui.genform.key.edit'),
            Lang::t('tui.genform.key.move'),
            Lang::t('tui.genform.key.sections'),
            Lang::t('tui.genform.key.close'),
        ];
    }

    /**
     * @return list<string> the strip labels, visible sections only
     */
    private function tabLabels(): array
    {
        return array_values(array_map(static fn (Section $s): string => $s->label(), $this->visibleSections()));
    }

    /** @return list<Section> */
    private function visibleSections(): array
    {
        return array_values(array_filter(
            $this->sections,
            fn (Section $section): bool => $section->visible($this->state),
        ));
    }

    private function focusedSection(): ?Section
    {
        return $this->visibleSections()[$this->state->section] ?? null;
    }

    private function focusedRows(): int
    {
        return \count($this->focusedSection()?->rows() ?? []);
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
        ]));
    }
}

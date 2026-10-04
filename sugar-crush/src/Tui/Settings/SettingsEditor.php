<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Config\Settings\ResolvedSetting;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Config\Settings\SettingSource;
use SugarCraft\Crush\Config\Settings\SettingsTier;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Components\PaneLabel;
use SugarCraft\Sprinkles\Style;

/**
 * The full-band settings view (roadmap N-P1): every key in
 * {@see SettingsSchema}, by category, with the value this launch runs with,
 * where that value came from, whether something locks it, and when a change to
 * it would take effect.
 *
 * IT ANSWERS "what am I running with, and why", and since N-P2 it also holds
 * an EDIT SET: {@see stage()} / {@see stageReset()} record changes against a
 * {@see SettingsTier}, the row shows the staged value, and
 * {@see withPreview()} shows the {@see SettingsSavePreview} the shell built for
 * them. Nothing here writes a file — the write is the shell's Cmd
 * ({@see \SugarCraft\Crush\App\App::confirmSettingsSave()}), answered with a
 * {@see SettingsSavedMsg} ({@see withSaved()}). No key is bound to those yet
 * (decision D7: no Ctrl+S / Ctrl+R — both are shell chords already), so until
 * the editor's keys land the view still says "read-only". Labels are literal
 * English (D7): the schema's `labelKey`/`helpKey` are kept for i18n, which
 * arrives later.
 *
 * HELD BY THE SHELL ({@see \SugarCraft\Crush\App\App::$settingsEditor}), not by
 * `Chat`: it writes no history and sends nothing to the model, which is also
 * why it may be open while a turn runs. Pure TEA-style state — {@see update()}
 * returns the next editor or null for "closed", {@see view()} renders it, and
 * neither does I/O: the resolved values were read once, when it opened.
 */
final class SettingsEditor
{
    /** Every mouse zone the view records starts with this. */
    public const ZONE_PREFIX = 'settings:';
    public const TAB_ZONE = 'settings:tab:';
    public const ROW_ZONE = 'settings:row:';

    /** The last tab: the files the layers come from, not a schema category. */
    public const FILES_TAB = 'Files';

    /** Inner width from which the detail column sits beside the list. */
    private const DETAIL_BESIDE_COLS = 90;

    /** Rows one wheel notch moves the highlight — the transcript's own step. */
    private const WHEEL_ROWS = 3;

    /**
     * @param array<string, ResolvedSetting> $resolved by key, schema order
     * @param array<string, mixed> $set staged values, by key
     * @param list<string> $unset staged resets (the key is removed from the tier's file)
     */
    private function __construct(
        public readonly SettingsSources $sources,
        public readonly array $resolved,
        public readonly int $tab,
        public readonly int $cursor,
        public readonly string $query,
        public readonly bool $searching,
        public readonly SettingsTier $tier = SettingsTier::You,
        public readonly array $set = [],
        public readonly array $unset = [],
        public readonly ?SettingsSavePreview $preview = null,
        public readonly ?string $status = null,
    ) {
    }

    /** Open on the first category, or on `$query`'s matches when one is given. */
    public static function open(SettingsSources $sources, string $query = ''): self
    {
        return new self($sources, $sources->resolver->resolveAll(), 0, 0, PaneLabel::of($query), false);
    }

    // ── the edit set (N-P2) ─────────────────────────────────────────────

    /** Stage `$key` = `$value` for the next save; a staged reset of it is dropped. */
    public function stage(string $key, mixed $value): self
    {
        $set = $this->set;
        $set[$key] = $value;

        return $this->edited($this->tier, $set, array_values(array_diff($this->unset, [$key])));
    }

    /** Stage a reset: the save removes `$key` from the tier's file. */
    public function stageReset(string $key): self
    {
        $set = $this->set;
        unset($set[$key]);

        return $this->edited($this->tier, $set, array_values(array_unique([...$this->unset, $key])));
    }

    /** Drop whatever is staged for `$key`. */
    public function unstage(string $key): self
    {
        $set = $this->set;
        unset($set[$key]);

        return $this->edited($this->tier, $set, array_values(array_diff($this->unset, [$key])));
    }

    /** Write to `$tier` instead; the staged changes are kept. */
    public function withTier(SettingsTier $tier): self
    {
        return $this->edited($tier, $this->set, $this->unset);
    }

    public function hasChanges(): bool
    {
        return $this->set !== [] || $this->unset !== [];
    }

    /** Show (or, with null, close) the save preview. */
    public function withPreview(?SettingsSavePreview $preview): self
    {
        return new self($this->sources, $this->resolved, $this->tab, $this->cursor, $this->query, $this->searching, $this->tier, $this->set, $this->unset, $preview, $this->status);
    }

    /**
     * A changed edit set: any open preview and any earlier save message no
     * longer describe it, so both go.
     *
     * @param array<string, mixed> $set
     * @param list<string> $unset
     */
    private function edited(SettingsTier $tier, array $set, array $unset): self
    {
        return new self($this->sources, $this->resolved, $this->tab, $this->cursor, $this->query, $this->searching, $tier, $set, $unset, null, null);
    }

    /** Values re-resolved after a save, so the rows show what the files now say. */
    public function withResolved(SettingsSources $sources): self
    {
        return new self($sources, $sources->resolver->resolveAll(), $this->tab, $this->cursor, $this->query, $this->searching, $this->tier, $this->set, $this->unset, $this->preview, $this->status);
    }

    /**
     * The save's outcome: on success the saved keys leave the edit set and the
     * preview closes; on failure everything stays staged, so nothing typed is
     * lost, and the status line says why.
     */
    public function withSaved(SettingsSavedMsg $msg): self
    {
        if (!$msg->ok()) {
            return new self($this->sources, $this->resolved, $this->tab, $this->cursor, $this->query, $this->searching, $this->tier, $this->set, $this->unset, $this->preview, 'Not saved: ' . $msg->error);
        }

        $set = $this->set;
        foreach ($msg->changed as $key) {
            unset($set[$key]);
        }

        $count = \count($msg->changed);
        $status = sprintf('Saved %d setting%s to %s', $count, $count === 1 ? '' : 's', (string) $msg->path);

        return new self(
            $this->sources,
            $this->resolved,
            $this->tab,
            $this->cursor,
            $this->query,
            $this->searching,
            $this->tier,
            $set,
            array_values(array_diff($this->unset, $msg->changed)),
            null,
            $status,
        );
    }

    // ── what is listed ──────────────────────────────────────────────────

    /**
     * The categories that hold at least one listed key, in tab order.
     *
     * @return list<SettingCategory>
     */
    public function categories(): array
    {
        $present = [];
        foreach (self::listed() as $definition) {
            $present[$definition->category->value] = $definition->category;
        }

        return array_values(array_filter(
            SettingCategory::cases(),
            static fn (SettingCategory $c): bool => isset($present[$c->value]),
        ));
    }

    /** @return list<string> the tab labels: each category, then {@see FILES_TAB} */
    public function tabLabels(): array
    {
        return [...array_map(static fn (SettingCategory $c): string => $c->label(), $this->categories()), self::FILES_TAB];
    }

    public function onFilesTab(): bool
    {
        return $this->query === '' && $this->tab === \count($this->categories());
    }

    /**
     * The rows the list shows: the search matches across every category while
     * a query is set, else the active tab's keys (or files).
     *
     * @return list<SettingDefinition>|list<SettingsFile>
     */
    public function rows(): array
    {
        if ($this->query !== '') {
            return SettingsSearch::matches($this->query, self::listed());
        }

        if ($this->onFilesTab()) {
            return $this->sources->files;
        }

        $category = $this->categories()[$this->tab] ?? null;

        return $category === null ? [] : array_values(array_filter(
            self::listed(),
            static fn (SettingDefinition $d): bool => $d->category === $category,
        ));
    }

    /** The highlighted row, or null on an empty list. */
    public function selected(): SettingDefinition|SettingsFile|null
    {
        return $this->rows()[$this->cursor] ?? null;
    }

    /** What a key resolved to when the view opened. */
    public function resolvedFor(SettingDefinition $definition): ResolvedSetting
    {
        return $this->resolved[$definition->key] ?? ResolvedSetting::new($definition->key, $definition->default, SettingSource::Default);
    }

    // ── input ───────────────────────────────────────────────────────────

    /**
     * Answer a key. Null means the view closed.
     *
     * While the search box has focus every printable key types into it;
     * otherwise `/` focuses it, `↑`/`↓` (or `k`/`j`) move, `←`/`→` (or `h`/`l`)
     * switch category, and `Esc` clears a filter before it closes the view.
     */
    public function update(KeyMsg $msg): ?self
    {
        if ($msg->type === KeyType::Escape) {
            if ($this->searching || $this->query !== '') {
                return $this->mutate(query: '', searching: false, cursor: 0);
            }

            return null;
        }

        if ($msg->type === KeyType::Up) {
            return $this->move(-1);
        }

        if ($msg->type === KeyType::Down) {
            return $this->move(1);
        }

        if ($msg->type === KeyType::Backspace) {
            return $this->query === ''
                ? $this
                : $this->mutate(query: mb_substr($this->query, 0, -1), cursor: 0);
        }

        if ($this->searching) {
            if ($msg->type === KeyType::Enter) {
                return $this->mutate(searching: false);
            }

            if ($msg->type === KeyType::Space) {
                return $this->typed(' ');
            }

            if ($msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt && $msg->rune !== '') {
                return $this->typed($msg->rune);
            }

            return $this;
        }

        if ($msg->type === KeyType::Left) {
            return $this->switchTab(-1);
        }

        if ($msg->type === KeyType::Right) {
            return $this->switchTab(1);
        }

        if ($msg->type !== KeyType::Char || $msg->ctrl || $msg->alt) {
            return $this;
        }

        return match ($msg->rune) {
            '/' => $this->mutate(searching: true),
            'k' => $this->move(-1),
            'j' => $this->move(1),
            'h' => $this->switchTab(-1),
            'l' => $this->switchTab(1),
            default => $this,
        };
    }

    /** A completed click on one of the view's zones; anything else is ignored. */
    public function click(string $zoneId): self
    {
        if (str_starts_with($zoneId, self::TAB_ZONE)) {
            $tab = (int) substr($zoneId, \strlen(self::TAB_ZONE));

            return $tab >= 0 && $tab < \count($this->tabLabels())
                ? $this->mutate(tab: $tab, cursor: 0, query: '', searching: false)
                : $this;
        }

        if (str_starts_with($zoneId, self::ROW_ZONE)) {
            $row = (int) substr($zoneId, \strlen(self::ROW_ZONE));

            return $row >= 0 && $row < \count($this->rows()) ? $this->mutate(cursor: $row) : $this;
        }

        return $this;
    }

    /** One wheel notch: positive scrolls down the list. */
    public function wheel(int $notch): self
    {
        return $this->move($notch * self::WHEEL_ROWS, wrap: false);
    }

    // ── rendering ───────────────────────────────────────────────────────

    /**
     * The view, exactly `$rows` lines of exactly `$cols` cells.
     */
    public function view(Theme $theme, int $cols, int $rows): string
    {
        $cols = max(1, $cols);
        $rows = max(1, $rows);
        $g = $this->geometry($theme, $cols, $rows);

        if ($g === null) {
            return self::fit([Style::new()->foreground($theme->shellMuted)->render('settings: terminal too small')], $cols, $rows);
        }

        $border = Style::new()->foreground($theme->shellPrimary);
        $muted = Style::new()->foreground($theme->shellMuted);
        $w = $g['inner'];

        $title = $this->hasChanges()
            ? sprintf(' ⚙ settings · %d unsaved · %s ', \count($this->set) + \count($this->unset), $this->tier->label())
            : ' ⚙ settings · read-only ';
        $topFill = max(0, $cols - 3 - Width::string($title));
        $lines = [$border->render('╭─' . $title . str_repeat('─', $topFill) . '╮')];

        $inner = [];
        $inner[] = $this->searchLine($theme, $w);
        $inner[] = $this->query === ''
            ? $g['tabLine']
            : $muted->render(sprintf('%d %s across every category', \count($this->rows()), \count($this->rows()) === 1 ? 'match' : 'matches'));
        $inner[] = $muted->render(str_repeat('─', $w));
        $body = $this->bodyLines($theme, $g);
        if ($this->preview !== null) {
            // The preview takes the body's rows, exactly as many.
            $body = array_pad(\array_slice($this->preview->lines($theme, $w), 0, \count($body)), \count($body), '');
        }

        foreach ($body as $line) {
            $inner[] = $line;
        }
        $inner[] = $muted->render(str_repeat('─', $w));
        $inner[] = $this->status !== null
            ? Style::new()->foreground(str_starts_with($this->status, 'Not saved') ? $theme->shellError : $theme->shellSuccess)->render($this->status)
            : $muted->render($this->footer());

        foreach ($inner as $line) {
            $lines[] = $border->render('│') . ' ' . self::cell($line, $w) . ' ' . $border->render('│');
        }

        $lines[] = $border->render('╰' . str_repeat('─', max(0, $cols - 2)) . '╯');

        return self::fit($lines, $cols, $rows);
    }

    /**
     * The click targets {@see view()} paints, in view-local cells (row 0 is the
     * top border), as `[row, from, to (exclusive), zone id]`.
     *
     * Computed by the same geometry the view is drawn with, so a zone is where
     * its tab or row is painted.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: string}>
     */
    public function zones(Theme $theme, int $cols, int $rows): array
    {
        $g = $this->geometry($theme, max(1, $cols), max(1, $rows));
        if ($g === null) {
            return [];
        }

        $zones = [];
        if ($this->query === '') {
            foreach ($g['tabSpans'] as [$from, $to, $index]) {
                $zones[] = [2, 2 + $from, 2 + $to, self::TAB_ZONE . $index];
            }
        }

        $count = \count($this->rows());
        for ($r = 0; $r < $g['listRows']; $r++) {
            $index = $g['offset'] + $r;
            if ($index >= $count) {
                break;
            }

            $zones[] = [4 + $r, 2, 2 + $g['listWidth'], self::ROW_ZONE . $index];
        }

        return $zones;
    }

    /**
     * Where everything sits for a `$cols`×`$rows` view, or null when the box
     * cannot be drawn at all.
     *
     * @return ?array{inner: int, listWidth: int, detailWidth: int, listRows: int, detailRows: int, offset: int, tabLine: string, tabSpans: list<array{0: int, 1: int, 2: int}>}
     */
    private function geometry(Theme $theme, int $cols, int $rows): ?array
    {
        $inner = $cols - 4;
        // Border (2) + search + tabs + rule + rule + footer (5), and a body.
        $body = $rows - 7;
        if ($inner < 16 || $body < 1) {
            return null;
        }

        $beside = $inner >= self::DETAIL_BESIDE_COLS;
        $detailWidth = $beside ? min(48, intdiv($inner, 3)) : $inner;
        $listWidth = $beside ? $inner - $detailWidth - 3 : $inner;

        $detailRows = 0;
        $listRows = $body;
        if (!$beside && $body >= 12) {
            $detailRows = min(10, intdiv($body, 2));
            $listRows = $body - $detailRows - 1;
        }

        $offset = max(0, $this->cursor - $listRows + 1);
        [$tabLine, $tabSpans] = SettingsTabStrip::render($this->tabLabels(), $this->tab, $inner, $theme);

        return [
            'inner' => $inner,
            'listWidth' => $listWidth,
            'detailWidth' => $beside ? $detailWidth : ($detailRows > 0 ? $inner : 0),
            'listRows' => $listRows,
            'detailRows' => $beside ? $body : $detailRows,
            'beside' => $beside,
            'offset' => $offset,
            'tabLine' => $tabLine,
            'tabSpans' => $tabSpans,
        ];
    }

    /**
     * @param array<string, mixed> $g
     * @return list<string>
     */
    private function bodyLines(Theme $theme, array $g): array
    {
        $list = $this->listLines($theme, $g['listWidth'], $g['listRows'], $g['offset']);
        $detail = $g['detailWidth'] > 0 ? $this->detailLines($theme, $g['detailWidth'], $g['detailRows']) : [];

        if ($g['beside']) {
            $sep = Style::new()->foreground($theme->shellSeparator)->render('│');
            $out = [];
            for ($i = 0; $i < $g['listRows']; $i++) {
                $out[] = self::cell($list[$i] ?? '', $g['listWidth']) . ' ' . $sep . ' ' . self::cell($detail[$i] ?? '', $g['detailWidth']);
            }

            return $out;
        }

        $out = $list;
        if ($detail !== []) {
            $out[] = Style::new()->foreground($theme->shellSeparator)->render(str_repeat('─', $g['inner']));
            foreach ($detail as $line) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** @return list<string> exactly `$rows` lines */
    private function listLines(Theme $theme, int $width, int $rows, int $offset): array
    {
        $all = $this->rows();
        $muted = Style::new()->foreground($theme->shellMuted);

        if ($all === []) {
            $empty = $this->query !== ''
                ? 'No setting matches "' . $this->query . '".'
                : 'Nothing in this category.';

            return array_pad([$muted->render(Width::truncate($empty, $width))], $rows, '');
        }

        $lines = [];
        for ($r = 0; $r < $rows; $r++) {
            $index = $offset + $r;
            $row = $all[$index] ?? null;
            if ($row === null) {
                $lines[] = '';
                continue;
            }

            $lines[] = $row instanceof SettingsFile
                ? $this->fileRow($theme, $row, $width, $index === $this->cursor)
                : $this->settingRow($theme, $row, $width, $index === $this->cursor);
        }

        return $lines;
    }

    private function settingRow(Theme $theme, SettingDefinition $definition, int $width, bool $selected): string
    {
        $resolved = $this->resolvedFor($definition);
        $labelW = min(26, max(12, intdiv($width, 3)));
        $showSource = $width >= 50;
        $showApply = $width >= 70;
        $sourceW = 21;
        $applyW = 13;
        $valueW = max(4, $width - 2 - $labelW - 1 - ($showSource ? $sourceW + 1 : 0) - ($showApply ? $applyW + 1 : 0));

        $source = SettingsDetailPanel::sourceGlyph($resolved->source) . ' '
            . SettingsDetailPanel::sourceShort($resolved->source)
            . ($resolved->locked ? ' (locked)' : '');

        $staged = \array_key_exists($definition->key, $this->set);
        $reset = \in_array($definition->key, $this->unset, true);
        $value = match (true) {
            $staged => '• ' . SettingsDetailPanel::value($definition, $this->set[$definition->key]),
            $reset => '• reset to default',
            default => SettingsDetailPanel::value($definition, $resolved->value),
        };

        $label = Style::new()->foreground($selected ? $theme->shellPrimary : $theme->shellForeground)->bold($selected);
        $line = ($selected ? $label->render('▸ ') : '  ')
            . $label->render(self::pad($definition->label, $labelW)) . ' '
            . Style::new()->foreground(match (true) {
                $staged || $reset => $theme->shellWarning,
                $resolved->isDefault() => $theme->shellMuted,
                default => $theme->shellForeground,
            })->render(self::pad($value, $valueW));

        if ($showSource) {
            $line .= ' ' . Style::new()->foreground($resolved->locked ? $theme->shellWarning : $theme->shellMuted)
                ->render(self::pad($source, $sourceW));
        }

        if ($showApply) {
            $line .= ' ' . Style::new()->foreground($theme->shellInfo)
                ->render(self::pad('[' . $definition->applyMode->badge() . ']', $applyW));
        }

        return $line;
    }

    private function fileRow(Theme $theme, SettingsFile $file, int $width, bool $selected): string
    {
        $roleW = min(18, max(10, intdiv($width, 4)));
        $statusW = 12;
        $label = Style::new()->foreground($selected ? $theme->shellPrimary : $theme->shellForeground)->bold($selected);

        return ($selected ? $label->render('▸ ') : '  ')
            . $label->render(self::pad($file->role, $roleW)) . ' '
            . Style::new()->foreground($file->status === 'read' ? $theme->shellSuccess : $theme->shellMuted)
                ->render(self::pad($file->status, $statusW)) . ' '
            . Style::new()->foreground($theme->shellForeground)
                ->render(Width::truncateMiddle(PaneLabel::of($file->path), max(4, $width - 2 - $roleW - 1 - $statusW - 1)));
    }

    /** @return list<string> */
    private function detailLines(Theme $theme, int $width, int $rows): array
    {
        $selected = $this->selected();
        if ($selected === null || $rows <= 0) {
            return [];
        }

        $pairs = $selected instanceof SettingsFile
            ? SettingsDetailPanel::fileLines($selected, $width)
            : SettingsDetailPanel::lines($selected, $this->resolvedFor($selected), $width);

        if ($selected instanceof SettingDefinition && $this->query !== '') {
            array_splice($pairs, 2, 0, [['category', $selected->category->label()]]);
        }

        $muted = Style::new()->foreground($theme->shellMuted);
        $head = Style::new()->foreground($theme->shellPrimary)->bold();
        $text = Style::new()->foreground($theme->shellForeground);
        $valueWidth = max(1, $width - SettingsDetailPanel::LABEL_WIDTH - 1);

        $lines = [];
        foreach ($pairs as $i => [$label, $value]) {
            $lines[] = match (true) {
                $i === 0 => $head->render(Width::truncate($value, $width)),
                $label === '' => $text->render(Width::truncate($value, $width)),
                $label === SettingsDetailPanel::CONTINUED => str_repeat(' ', SettingsDetailPanel::LABEL_WIDTH + 1)
                    . $text->render(Width::truncateMiddle($value, $valueWidth)),
                default => $muted->render(self::pad($label, SettingsDetailPanel::LABEL_WIDTH)) . ' '
                    . $text->render(Width::truncate($value, $valueWidth)),
            };
        }

        return \array_slice($lines, 0, $rows);
    }

    private function searchLine(Theme $theme, int $width): string
    {
        $label = Style::new()->foreground($this->searching ? $theme->shellPrimary : $theme->shellMuted);
        $caret = $this->searching ? '▏' : '';
        $hint = $this->query === '' && !$this->searching ? 'press / to search' : '';

        return $label->render('Search: ')
            . Style::new()->foreground($theme->shellForeground)->render(Width::truncate($this->query, max(1, $width - 10)) . $caret)
            . Style::new()->foreground($theme->shellMuted)->render($hint);
    }

    private function footer(): string
    {
        return $this->searching
            ? 'type to filter · Enter keep matches · ↑↓ move · Esc clear'
            : '↑↓ move · ←→ category · / search · Esc close · read-only — /theme and /model change live';
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * The keys the view lists: every schema key the schema does not hide.
     *
     * @return list<SettingDefinition>
     */
    private static function listed(): array
    {
        return array_values(array_filter(
            SettingsSchema::all(),
            static fn (SettingDefinition $d): bool => $d->ui !== UiEditability::Hidden,
        ));
    }

    private function typed(string $text): self
    {
        // Sanitised per keystroke rather than as a whole: PaneLabel::of() trims,
        // which would eat the space between two words as it is typed.
        $clean = $text === ' ' ? ' ' : PaneLabel::of($text);

        return $clean === '' ? $this : $this->mutate(query: $this->query . $clean, cursor: 0);
    }

    private function move(int $delta, bool $wrap = true): self
    {
        $count = \count($this->rows());
        if ($count === 0) {
            return $this;
        }

        $next = $this->cursor + $delta;
        $next = $wrap ? (($next % $count) + $count) % $count : max(0, min($count - 1, $next));

        return $this->mutate(cursor: $next);
    }

    private function switchTab(int $delta): self
    {
        if ($this->query !== '') {
            return $this;
        }

        $count = \count($this->tabLabels());

        return $this->mutate(tab: (($this->tab + $delta) % $count + $count) % $count, cursor: 0);
    }

    private function mutate(
        ?int $tab = null,
        ?int $cursor = null,
        ?string $query = null,
        ?bool $searching = null,
    ): self {
        return new self(
            $this->sources,
            $this->resolved,
            $tab ?? $this->tab,
            $cursor ?? $this->cursor,
            $query ?? $this->query,
            $searching ?? $this->searching,
            $this->tier,
            $this->set,
            $this->unset,
            $this->preview,
            $this->status,
        );
    }

    /** Plain text cut or padded to exactly `$width` cells. */
    private static function pad(string $text, int $width): string
    {
        return Width::padRight(Width::truncate($text, $width), $width);
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
}

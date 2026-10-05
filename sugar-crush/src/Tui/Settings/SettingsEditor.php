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
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Components\PaneLabel;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Input;
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
 * {@see SettingsSavedMsg} ({@see withSaved()}). The editor's keys are plain
 * letters (decision D7: no Ctrl+S / Ctrl+R — both are shell chords already):
 * `Enter` edits the highlighted key (`Enter` stages, `Esc` cancels), `r`
 * stages a reset, `t` switches the tier, `s` asks the shell for the save
 * preview and `y` confirms it. `Enter` on a trust list opens the confirmed
 * trust action instead ({@see CONFIRM_TRUST}), and `Esc` with changes staged
 * asks before it throws them away ({@see CONFIRM_DISCARD}). The keys that
 * need the writer (`s`, `y`) are the shell's, because the writer is. Tab
 * and key labels arrive translated from the schema (`labelKey` through
 * `Lang::t()`, audit 15b-14).
 *
 * FILES AND PROFILES (N-P5). `e` opens a settings file in `$EDITOR` — the
 * highlighted one on the Files tab, else the file the chosen tier saves to —
 * and the view re-reads the layers when the editor exits. `x` exports a
 * settings profile ({@see SettingsProfile}) and `p` imports one into the edit
 * set, each after a path prompt ({@see PROFILE_EXPORT}, {@see PROFILE_IMPORT}).
 * All three are the shell's keys, because each is I/O.
 *
 * POLISH (N-P5). Below {@see SINGLE_COLUMN_COLS} columns or
 * {@see SINGLE_COLUMN_ROWS} rows the view is ONE column: the list fills the
 * body and `i` swaps it for the highlighted key's details (and back). A locked
 * key (an environment variable or a flag sets it) is read-only here, and says
 * which; a key with no field says where it IS changed. A staged change the
 * chosen tier would refuse is marked `✗` on its row and named on the status
 * line as soon as it is staged or the tier changes, not first at the save
 * preview — which scrolls (`↑`/`↓`) when it is taller than the body. The
 * footer's key hints follow the highlighted row and are cut to the width.
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

    /** {@see $confirm}: `Esc` with changes staged — discard them, or keep editing. */
    public const CONFIRM_DISCARD = 'discard';

    /** {@see $confirm}: grant this project the highlighted trust list (the confirmed trust action). */
    public const CONFIRM_TRUST = 'trust';

    /**
     * The path prompts' field keys (N-P5): export the profile to, or import it
     * from, the path typed. `Enter` on either is the shell's, which does the I/O.
     */
    public const PROFILE_EXPORT = 'profile:export';
    public const PROFILE_IMPORT = 'profile:import';

    /** Lang key of the last tab: the files the layers come from, not a schema category. */
    public const FILES_TAB = 'tui.settings.files_tab';

    /**
     * Lang keys of the heads a failed action's status opens with ("Not
     * saved", …), {@see failure()}. The status line is painted in the error
     * colour when it opens with one of them, translated — so the colour does
     * not hang on the English word "Not".
     */
    public const FAILURE_HEADS = [
        'tui.settings.failed.applied',
        'tui.settings.failed.done',
        'tui.settings.failed.exported',
        'tui.settings.failed.imported',
        'tui.settings.failed.opened',
        'tui.settings.failed.reread',
        'tui.settings.failed.saved',
        'tui.settings.failed.staged',
    ];

    /** Inner width from which the detail column sits beside the list. */
    private const DETAIL_BESIDE_COLS = 90;

    /**
     * Below this many columns, or {@see SINGLE_COLUMN_ROWS} rows, the view is
     * a single column and `i` toggles the details in the list's place
     * (Appendix N §4.7 "Layout").
     */
    public const SINGLE_COLUMN_COLS = 70;
    public const SINGLE_COLUMN_ROWS = 18;

    /** A status line that warns rather than reports; painted in the warning colour. */
    private const WARN = '! ';

    /**
     * A failed action's status line: the head {@see FAILURE_HEADS} names,
     * then why. Shared with the shell, whose file and profile actions report
     * through {@see withNotice()}.
     */
    public static function failure(string $headKey, string $reason): string
    {
        return Lang::t($headKey) . ': ' . $reason;
    }

    private static function isFailure(string $status): bool
    {
        foreach (self::FAILURE_HEADS as $headKey) {
            if (str_starts_with($status, Lang::t($headKey) . ': ')) {
                return true;
            }
        }

        return false;
    }

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
        public readonly ?Field $editing = null,
        /**
         * The question the view is waiting on, or null: {@see CONFIRM_DISCARD}
         * or {@see CONFIRM_TRUST}. Every other change of state drops it — a
         * question is answered or abandoned, never carried along.
         */
        public readonly ?string $confirm = null,
        /** Single-column layout: the details are shown in the list's place (`i`). */
        public readonly bool $detail = false,
        /** The first save-preview line in view, when the preview is taller than the body. */
        public readonly int $previewOffset = 0,
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

    /**
     * Start editing the highlighted setting in its candy-forms field
     * ({@see SettingsFieldFactory}), pre-filled with its staged value, else the
     * value this launch resolved. A key with no inline field leaves the view
     * as it was and says why. `models` edits the entry for the provider this
     * launch resolved.
     */
    public function beginEdit(): self
    {
        $selected = $this->selected();
        if (!$selected instanceof SettingDefinition) {
            return $this;
        }

        $locked = $this->lockedStatus($selected);
        if ($locked !== null) {
            return $this->withStatus($locked);
        }

        $field = SettingsFieldFactory::field($selected, $this->currentValue($selected), $this->sources->options, $this->activeProvider());
        if ($field === null) {
            return $this->withStatus(SettingsFieldFactory::whyNotEditable($selected) ?? Lang::t('tui.settings.not_edited_here', ['label' => $selected->label]));
        }

        [$focused] = $field->focus();

        return $this->withEditing($focused, null);
    }

    /** A key for the field being edited; ignored when nothing is. */
    public function editKey(KeyMsg $msg): self
    {
        if ($this->editing === null) {
            return $this;
        }

        [$field] = $this->editing->update($msg);

        return $this->withEditing($field, $this->status);
    }

    /**
     * Stage what the field holds. Text that is not a value of the key's type is
     * refused here, the field stays open, and the status line says why; whether
     * the value may be WRITTEN is the writer's call, at preview time.
     */
    public function commitEdit(): self
    {
        $field = $this->editing;
        $selected = $this->selected();
        if ($field === null || !$selected instanceof SettingDefinition || $field->key() !== $selected->key) {
            return $this->cancelEdit();
        }

        try {
            $value = SettingsFieldFactory::value($selected, $field, $this->currentValue($selected), $this->activeProvider());
        } catch (\InvalidArgumentException $e) {
            return $this->withEditing($field, self::failure('tui.settings.failed.staged', $e->getMessage()));
        }

        return $this->stage($selected->key, $value);
    }

    public function cancelEdit(): self
    {
        return $this->editing === null ? $this : $this->withEditing(null, $this->status);
    }

    /**
     * Stage a reset of the highlighted setting; a files row or an empty list
     * stays as it is, and a locked key says what locks it.
     */
    public function resetSelected(): self
    {
        $selected = $this->selected();
        if (!$selected instanceof SettingDefinition) {
            return $this;
        }

        return $this->lockedStatus($selected) !== null
            ? $this->withStatus((string) $this->lockedStatus($selected))
            : $this->stageReset($selected->key);
    }

    /**
     * Why a key cannot be changed from this view while this launch runs, or
     * null: an environment variable or a flag sets it, and outranks every file
     * a save could write (design §4.1: "an env or flag lock makes the field
     * read-only, with the variable named").
     */
    private function lockedStatus(SettingDefinition $definition): ?string
    {
        $resolved = $this->resolvedFor($definition);

        return $resolved->locked
            ? self::WARN . Lang::t('tui.settings.locked', ['label' => $definition->label, 'reason' => PaneLabel::of((string) $resolved->lockReason)])
            : null;
    }

    /**
     * What the view should warn about a tier and an edit set before any save:
     * the staged keys that tier refuses ({@see SettingsWriter::keyRefusal()}),
     * then — on a project tier — a project this launch knows to be missing or
     * untrusted. Null when there is nothing to say.
     *
     * @param array<string, mixed> $set
     * @param list<string> $unset
     */
    private function tierWarning(SettingsTier $tier, array $set, array $unset): ?string
    {
        $refused = [];
        foreach ([...array_map('strval', array_keys($set)), ...$unset] as $key) {
            $reason = SettingsWriter::keyRefusal($tier, $key);
            if ($reason !== null) {
                $refused[] = $reason;
            }
        }

        if ($refused !== []) {
            $more = \count($refused) > 1 ? ' ' . Lang::t('tui.settings.refused_more', ['count' => \count($refused) - 1]) : '';

            return self::WARN . Lang::t('tui.settings.refused_switch_tier', ['reason' => $refused[0] . $more]);
        }

        if (!$tier->isProject()) {
            return null;
        }

        return match (true) {
            $this->sources->root === null => self::WARN . Lang::t('tui.settings.no_project'),
            $this->sources->projectTrusted === false
                => self::WARN . Lang::t('tui.settings.untrusted_project'),
            default => null,
        };
    }

    /** Whether the tier this view saves to would refuse a staged `$key`. */
    private function refusedHere(string $key): bool
    {
        return SettingsWriter::keyRefusal($this->tier, $key) !== null;
    }

    /** The trust list the confirmed trust action would grant, while that question is up. */
    public function pendingTrustKey(): ?string
    {
        $selected = $this->selected();

        return $this->confirm === self::CONFIRM_TRUST && $selected instanceof SettingDefinition && self::isTrustKey($selected)
            ? $selected->key
            : null;
    }

    /**
     * Open the path prompt for a profile export or import
     * ({@see PROFILE_EXPORT} / {@see PROFILE_IMPORT}), pre-filled with `$path`.
     */
    public function withProfilePrompt(string $action, string $path): self
    {
        $export = $action === self::PROFILE_EXPORT;
        $field = Input::new($export ? self::PROFILE_EXPORT : self::PROFILE_IMPORT)
            ->withTitle(Lang::t($export ? 'tui.settings.profile.export_title' : 'tui.settings.profile.import_title'))
            ->withDescription($export
                ? Lang::t('tui.settings.profile.export_description')
                : Lang::t('tui.settings.profile.import_description', ['tier' => $this->tier->label()]))
            ->withValue($path);
        [$focused] = $field->focus();

        return $this->withEditing($focused, null);
    }

    /** The profile prompt that is open ({@see PROFILE_EXPORT} or {@see PROFILE_IMPORT}), or null. */
    public function profileAction(): ?string
    {
        $key = $this->editing?->key();

        return $key === self::PROFILE_EXPORT || $key === self::PROFILE_IMPORT ? $key : null;
    }

    /** The path typed into the open profile prompt, or null when none is open. */
    public function profilePath(): ?string
    {
        return $this->profileAction() === null ? null : trim((string) $this->editing?->value());
    }

    /**
     * A profile export or import finished. An import STAGES what the chosen
     * tier may take ({@see SettingsProfile::staged()}) — nothing is written
     * until the save — and says what it left out.
     */
    public function withProfileResult(SettingsProfileMsg $msg): self
    {
        if (!$msg->ok()) {
            return $this->mutate(['status' => self::failure($msg->action === SettingsProfileMsg::EXPORT ? 'tui.settings.failed.exported' : 'tui.settings.failed.imported', (string) $msg->error)]);
        }

        if ($msg->action === SettingsProfileMsg::EXPORT) {
            $count = \count($msg->values);

            return $this->mutate(['status' => Lang::t($count === 1 ? 'tui.settings.profile.exported_one' : 'tui.settings.profile.exported', ['count' => $count, 'path' => $msg->path])]);
        }

        ['set' => $set, 'skipped' => $skipped, 'same' => $same] = SettingsProfile::staged($msg->values, $this->resolved, $this->tier);
        $next = $this->edited($this->tier, array_merge($this->set, $set), array_values(array_diff($this->unset, array_keys($set))));
        $parts = [Lang::t(\count($set) === 1 ? 'tui.settings.profile.staged_one' : 'tui.settings.profile.staged', ['count' => \count($set), 'path' => $msg->path])];
        if ($same !== []) {
            $parts[] = Lang::t('tui.settings.profile.already_in_force', ['count' => \count($same)]);
        }
        if ($skipped !== []) {
            $parts[] = Lang::t('tui.settings.profile.skipped', ['count' => \count($skipped), 'reason' => array_values($skipped)[0] . (\count($skipped) > 1 ? ', …' : '')]);
        }
        if ($set !== []) {
            $parts[] = Lang::t('tui.settings.profile.previews_save');
        }

        return $next->mutate(['status' => ($skipped !== [] ? self::WARN : '') . implode(' · ', $parts)]);
    }

    /** A one-line status from the shell (an editor that exited, a file re-read). */
    public function withNotice(string $status): self
    {
        return $this->mutate(['status' => $status]);
    }

    /**
     * The file `e` opens: the highlighted one on the Files tab, else null —
     * the shell then opens the file the chosen tier saves to.
     */
    public function selectedFilePath(): ?string
    {
        $selected = $this->selected();

        return $selected instanceof SettingsFile && str_starts_with($selected->path, '/') ? $selected->path : null;
    }

    /** Whether the view is between questions: no search, edit, preview or confirmation open. */
    public function isIdle(): bool
    {
        return !$this->searching && $this->editing === null && $this->preview === null && $this->confirm === null;
    }

    /** Ask (or with null, stop asking) one of the CONFIRM_* questions. */
    public function withConfirm(?string $confirm): self
    {
        return $this->mutate(['confirm' => $confirm, 'editing' => $this->editing]);
    }

    /** A trust list: written only through the confirmed trust action, never staged. */
    private static function isTrustKey(SettingDefinition $definition): bool
    {
        return \in_array($definition->key, \SugarCraft\Crush\Config\Settings\SettingsWriter::trustKeys(), true);
    }

    /** The staged value of a key, else the value this launch resolved. */
    private function currentValue(SettingDefinition $definition): mixed
    {
        return \array_key_exists($definition->key, $this->set) ? $this->set[$definition->key] : $this->resolvedFor($definition)->value;
    }

    private function activeProvider(): ?string
    {
        $provider = $this->resolved['provider']->value ?? null;

        return \is_string($provider) && $provider !== '' ? $provider : null;
    }

    private function withEditing(?Field $field, ?string $status): self
    {
        return $this->mutate(['status' => $status, 'editing' => $field]);
    }

    private function withStatus(string $status): self
    {
        return $this->mutate(['status' => $status, 'editing' => $this->editing]);
    }

    /** Show (or, with null, close) the save preview, scrolled to its top. */
    public function withPreview(?SettingsSavePreview $preview): self
    {
        return $this->mutate(['preview' => $preview, 'previewOffset' => 0]);
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
        return $this->mutate([
            'tier' => $tier,
            'set' => $set,
            'unset' => $unset,
            'preview' => null,
            'status' => $this->tierWarning($tier, $set, $unset),
        ]);
    }

    /** Values re-resolved after a save, so the rows show what the files now say. */
    public function withResolved(SettingsSources $sources): self
    {
        return $this->mutate(['sources' => $sources, 'resolved' => $sources->resolver->resolveAll()]);
    }

    /**
     * The save's outcome: on success the saved keys leave the edit set and the
     * preview closes; on failure everything stays staged, so nothing typed is
     * lost, and the status line says why.
     */
    public function withSaved(SettingsSavedMsg $msg): self
    {
        if (!$msg->ok()) {
            return $this->mutate(['status' => self::failure('tui.settings.failed.saved', (string) $msg->error)]);
        }

        $set = $this->set;
        foreach ($msg->changed as $key) {
            unset($set[$key]);
        }

        $count = \count($msg->changed);
        $status = Lang::t($count === 1 ? 'tui.settings.saved_one' : 'tui.settings.saved', ['count' => $count, 'path' => (string) $msg->path]);

        return $this->mutate([
            'set' => $set,
            'unset' => array_values(array_diff($this->unset, $msg->changed)),
            'preview' => null,
            'status' => $status,
        ]);
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
        return [...array_map(static fn (SettingCategory $c): string => Lang::t($c->labelKey()), $this->categories()), Lang::t(self::FILES_TAB)];
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
        $plainRune = $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt ? $msg->rune : null;

        // An open question owns the keyboard. The answers that need the
        // writer (`y` on a trust grant, `s` on the discard question) are the
        // shell's ({@see \SugarCraft\Crush\App\App}), which reaches them first.
        if ($this->confirm !== null) {
            if ($msg->type === KeyType::Escape || $plainRune === 'n' || $plainRune === 'k') {
                return $this->withConfirm(null);
            }

            return $this->confirm === self::CONFIRM_DISCARD && $plainRune === 'd' ? null : $this;
        }

        // An open field takes every key: Enter stages it, Esc drops it.
        if ($this->editing !== null) {
            return match ($msg->type) {
                KeyType::Enter => $this->commitEdit(),
                KeyType::Escape => $this->cancelEdit(),
                default => $this->editKey($msg),
            };
        }

        // The save preview: `y`/Enter saves (the shell's), Esc/`n` goes back,
        // `↑`/`↓` (or `k`/`j`) scroll a preview taller than the body.
        if ($this->preview !== null) {
            return match (true) {
                $msg->type === KeyType::Escape, $plainRune === 'n' => $this->withPreview(null),
                $msg->type === KeyType::Up, $plainRune === 'k' => $this->scrollPreview(-1),
                $msg->type === KeyType::Down, $plainRune === 'j' => $this->scrollPreview(1),
                default => $this,
            };
        }

        if ($msg->type === KeyType::Escape) {
            if ($this->searching || $this->query !== '') {
                return $this->mutate(['query' => '', 'searching' => false, 'cursor' => 0]);
            }

            // Closing would throw the edit set away, so it asks first.
            return $this->hasChanges() ? $this->withConfirm(self::CONFIRM_DISCARD) : null;
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
                : $this->mutate(['query' => mb_substr($this->query, 0, -1), 'cursor' => 0]);
        }

        if ($this->searching) {
            if ($msg->type === KeyType::Enter) {
                return $this->mutate(['searching' => false]);
            }

            if ($msg->type === KeyType::Space) {
                return $this->typed(' ');
            }

            if ($msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt && $msg->rune !== '') {
                return $this->typed($msg->rune);
            }

            return $this;
        }

        if ($msg->type === KeyType::Enter) {
            $selected = $this->selected();

            return $selected instanceof SettingDefinition && self::isTrustKey($selected)
                ? $this->withConfirm(self::CONFIRM_TRUST)
                : $this->beginEdit();
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
            '/' => $this->mutate(['searching' => true]),
            'k' => $this->move(-1),
            'j' => $this->move(1),
            'h' => $this->switchTab(-1),
            'l' => $this->switchTab(1),
            'r' => $this->resetSelected(),
            't' => $this->withTier($this->tier->next()),
            'i' => $this->mutate(['detail' => !$this->detail]),
            default => $this,
        };
    }

    /** A completed click on one of the view's zones; anything else is ignored. */
    public function click(string $zoneId): self
    {
        if (str_starts_with($zoneId, self::TAB_ZONE)) {
            $tab = (int) substr($zoneId, \strlen(self::TAB_ZONE));

            return $tab >= 0 && $tab < \count($this->tabLabels())
                ? $this->mutate(['tab' => $tab, 'cursor' => 0, 'query' => '', 'searching' => false])
                : $this;
        }

        if (str_starts_with($zoneId, self::ROW_ZONE)) {
            $row = (int) substr($zoneId, \strlen(self::ROW_ZONE));

            return $row >= 0 && $row < \count($this->rows()) ? $this->mutate(['cursor' => $row]) : $this;
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
            return self::fit([Style::new()->foreground($theme->shellMuted)->render(Lang::t('tui.settings.too_small'))], $cols, $rows);
        }

        $border = Style::new()->foreground($theme->shellPrimary);
        $muted = Style::new()->foreground($theme->shellMuted);
        $w = $g['inner'];

        $title = $this->title($cols - 3);
        $topFill = max(0, $cols - 3 - Width::string($title));
        $lines = [$border->render('╭─' . $title . str_repeat('─', $topFill) . '╮')];

        $inner = [];
        $inner[] = $this->searchLine($theme, $w);
        $inner[] = $this->query === ''
            ? $g['tabLine']
            : $muted->render(Lang::t(\count($this->rows()) === 1 ? 'tui.settings.search.match_one' : 'tui.settings.search.matches', ['count' => \count($this->rows())]));
        $inner[] = $muted->render(str_repeat('─', $w));
        $body = $this->bodyLines($theme, $g);
        if ($this->preview !== null) {
            // The preview takes the body's rows, exactly as many.
            $body = $this->previewLines($theme, $w, \count($body));
        } elseif ($this->editing !== null) {
            $body = array_pad(\array_slice(explode("\n", $this->editing->view()), 0, \count($body)), \count($body), '');
        } elseif ($this->confirm !== null) {
            $body = array_pad(\array_slice($this->confirmLines($theme, $w), 0, \count($body)), \count($body), '');
        }

        foreach ($body as $line) {
            $inner[] = $line;
        }
        $inner[] = $muted->render(str_repeat('─', $w));
        $inner[] = $this->status !== null
            ? Style::new()->foreground(match (true) {
                self::isFailure($this->status) => $theme->shellError,
                str_starts_with($this->status, self::WARN) => $theme->shellWarning,
                default => $theme->shellSuccess,
            })->render(Width::truncate($this->status, $w))
            : $muted->render(self::hints($this->footer($g), $w));

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

        // Rows are clickable only while the list is what the body shows: not
        // under a preview, a field or a question, and not while the
        // single-column view shows the details in the list's place.
        $listShown = $this->preview === null && $this->editing === null && $this->confirm === null
            && !($g['single'] && $this->detail);
        $count = $listShown ? \count($this->rows()) : 0;
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
     * Three layouts: the details BESIDE the list (wide), BELOW it (tall
     * enough), or a SINGLE column below {@see SINGLE_COLUMN_COLS} ×
     * {@see SINGLE_COLUMN_ROWS} — or wherever neither fits — where `i` shows
     * the details in the list's place.
     *
     * @return ?array{inner: int, listWidth: int, detailWidth: int, listRows: int, detailRows: int, beside: bool, single: bool, offset: int, tabLine: string, tabSpans: list<array{0: int, 1: int, 2: int}>}
     */
    private function geometry(Theme $theme, int $cols, int $rows): ?array
    {
        $inner = $cols - 4;
        // Border (2) + search + tabs + rule + rule + footer (5), and a body.
        $body = $rows - 7;
        if ($inner < 16 || $body < 1) {
            return null;
        }

        $roomy = $cols >= self::SINGLE_COLUMN_COLS && $rows >= self::SINGLE_COLUMN_ROWS;
        $beside = $roomy && $inner >= self::DETAIL_BESIDE_COLS;
        $below = $roomy && !$beside && $body >= 12;
        $single = !$beside && !$below;
        $detailWidth = $beside ? min(48, intdiv($inner, 3)) : $inner;
        $listWidth = $beside ? $inner - $detailWidth - 3 : $inner;

        $detailRows = 0;
        $listRows = $body;
        if ($below) {
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
            'single' => $single,
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
        if ($g['single'] && $this->detail) {
            return array_pad($this->detailLines($theme, $g['inner'], $g['listRows']), $g['listRows'], '');
        }

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
                ? Lang::t('tui.settings.search.no_match', ['query' => $this->query])
                : Lang::t('tui.settings.empty_category');

            $wrapped = array_map(
                static fn (string $line): string => $muted->render(Width::truncate($line, $width)),
                explode("\n", Width::wrap($empty, max(1, $width))),
            );

            return array_pad(\array_slice($wrapped, 0, $rows), $rows, '');
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
            . ($resolved->locked ? ' ' . Lang::t('tui.settings.row.locked') : '');

        $staged = \array_key_exists($definition->key, $this->set);
        $reset = \in_array($definition->key, $this->unset, true);
        // A staged change the chosen tier would refuse is marked ✗, not •, so
        // it is seen on its row before the preview refuses it.
        $refused = ($staged || $reset) && $this->refusedHere($definition->key);
        $mark = $refused ? '✗ ' : '• ';
        $value = match (true) {
            $staged => $mark . SettingsDetailPanel::value($definition, $this->set[$definition->key]),
            $reset => $mark . Lang::t('tui.settings.row.reset'),
            default => SettingsDetailPanel::value($definition, $resolved->value),
        };

        $label = Style::new()->foreground($selected ? $theme->shellPrimary : $theme->shellForeground)->bold($selected);
        $line = ($selected ? $label->render('▸ ') : '  ')
            . $label->render(self::pad($definition->label, $labelW)) . ' '
            . Style::new()->foreground(match (true) {
                $refused => $theme->shellError,
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
                ->render(self::pad(SettingsDetailPanel::fileStatus($file->status), $statusW)) . ' '
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
            array_splice($pairs, 2, 0, [[Lang::t('tui.settings.detail.category'), Lang::t($selected->category->labelKey())]]);
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
        $hint = $this->query === '' && !$this->searching ? Lang::t('tui.settings.search.hint') : '';

        return $label->render(Lang::t('tui.settings.search.label') . ' ')
            . Style::new()->foreground($theme->shellForeground)->render(Width::truncate($this->query, max(1, $width - 10)) . $caret)
            . Style::new()->foreground($theme->shellMuted)->render($hint);
    }

    /**
     * The key hints for what is on screen, most useful first: the keys that
     * act on the highlighted row lead, and a key that would do nothing there
     * (`Enter` on a locked key, `r` on a file, `s` with nothing staged) is not
     * offered. {@see hints()} cuts the list to the width.
     *
     * @param array<string, mixed> $g
     * @return list<string>
     */
    private function footer(array $g): array
    {
        if ($this->searching) {
            return [Lang::t('tui.settings.keys.type_to_filter'), Lang::t('tui.settings.keys.keep_matches'), Lang::t('tui.settings.keys.move'), Lang::t('tui.settings.keys.clear')];
        }

        if ($this->confirm === self::CONFIRM_TRUST) {
            return [Lang::t('tui.settings.keys.trust_yes'), Lang::t('tui.settings.keys.trust_no')];
        }

        if ($this->confirm === self::CONFIRM_DISCARD) {
            return [Lang::t('tui.settings.keys.discard'), Lang::t('tui.settings.keys.keep_editing'), Lang::t('tui.settings.keys.save')];
        }

        if ($this->editing !== null) {
            return match ($this->profileAction()) {
                self::PROFILE_EXPORT => [Lang::t('tui.settings.keys.export'), Lang::t('tui.settings.keys.cancel')],
                self::PROFILE_IMPORT => [Lang::t('tui.settings.keys.import'), Lang::t('tui.settings.keys.cancel')],
                default => [Lang::t('tui.settings.keys.stage'), Lang::t('tui.settings.keys.cancel')],
            };
        }

        if ($this->preview !== null) {
            return [Lang::t('tui.settings.keys.preview_save'), Lang::t('tui.settings.keys.preview_back'), Lang::t('tui.settings.keys.scroll')];
        }

        $hints = [];
        $selected = $this->selected();
        if ($g['single']) {
            $hints[] = Lang::t($this->detail ? 'tui.settings.keys.list' : 'tui.settings.keys.details');
        }

        if ($selected instanceof SettingDefinition) {
            $resolved = $this->resolvedFor($selected);
            if ($resolved->locked) {
                $hints[] = Lang::t('tui.settings.keys.locked', ['reason' => PaneLabel::of((string) $resolved->lockReason)]);
            } elseif (self::isTrustKey($selected)) {
                $hints[] = Lang::t('tui.settings.keys.trust');
            } elseif (SettingsFieldFactory::editable($selected)) {
                $hints[] = Lang::t('tui.settings.keys.edit');
                $hints[] = Lang::t('tui.settings.keys.reset');
            } else {
                $hints[] = Lang::t('tui.settings.keys.why_not');
            }
        }

        if ($this->hasChanges()) {
            $hints[] = Lang::t('tui.settings.keys.save');
        }

        $hints[] = Lang::t($selected instanceof SettingsFile ? 'tui.settings.keys.open_in_editor' : 'tui.settings.keys.edit_file');
        $hints[] = Lang::t('tui.settings.keys.profile');

        // While a search filters the list, ←/→ do nothing and Esc clears it.
        return $this->query !== ''
            ? [...$hints, Lang::t('tui.settings.keys.tier'), Lang::t('tui.settings.keys.move'), Lang::t('tui.settings.keys.edit_search'), Lang::t('tui.settings.keys.clear_search')]
            : [...$hints, Lang::t('tui.settings.keys.tier'), Lang::t('tui.settings.keys.search'), Lang::t('tui.settings.keys.move'), Lang::t('tui.settings.keys.category'), Lang::t('tui.settings.keys.close')];
    }

    /**
     * Hints joined with ` · `, as many as fit in `$width` cells — the last
     * one (closing or going back) is kept whenever anything fits at all.
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

    /**
     * The title bar's text in at most `$width` cells: the tier's full label
     * when it fits, its short one when it does not.
     */
    private function title(int $width): string
    {
        if (!$this->hasChanges()) {
            return Width::truncate(' ⚙ ' . Lang::t('tui.settings.title.clean') . ' ', max(0, $width));
        }

        $count = \count($this->set) + \count($this->unset);
        $full = ' ⚙ ' . Lang::t('tui.settings.title.unsaved', ['count' => $count, 'tier' => $this->tier->label()]) . ' ';
        if (Width::string($full) <= $width) {
            return $full;
        }

        return Width::truncate(' ⚙ ' . Lang::t('tui.settings.title.unsaved_short', ['count' => $count, 'tier' => $this->tier->shortLabel()]) . ' ', max(0, $width));
    }

    /**
     * The save preview in exactly `$rows` lines. A preview taller than that
     * shows `$rows - 1` of its lines from {@see $previewOffset} and, last,
     * where it is and that `↑`/`↓` scroll it.
     *
     * @return list<string>
     */
    private function previewLines(Theme $theme, int $width, int $rows): array
    {
        $all = $this->preview?->lines($theme, $width) ?? [];
        if (\count($all) <= $rows || $rows < 2) {
            return array_pad(\array_slice($all, 0, $rows), $rows, '');
        }

        $window = $rows - 1;
        $offset = max(0, min($this->previewOffset, \count($all) - $window));
        $lines = \array_slice($all, $offset, $window);
        $lines[] = Style::new()->foreground($theme->shellMuted)->render(Width::truncate(
            Lang::t('tui.settings.preview.window', ['from' => $offset + 1, 'to' => $offset + $window, 'count' => \count($all)]),
            $width,
        ));

        return $lines;
    }

    /**
     * The question's own rows, in the body's place: what is about to be
     * thrown away, or what the trust grant does.
     *
     * @return list<string>
     */
    private function confirmLines(Theme $theme, int $width): array
    {
        $head = Style::new()->foreground($theme->shellWarning)->bold();
        $text = Style::new()->foreground($theme->shellForeground);
        $muted = Style::new()->foreground($theme->shellMuted);
        $cut = static fn (Style $style, string $line): string => $style->render(Width::truncate($line, $width));

        if ($this->confirm === self::CONFIRM_DISCARD) {
            $count = \count($this->set) + \count($this->unset);
            $keys = [...array_keys($this->set), ...$this->unset];

            return [
                $cut($head, Lang::t($count === 1 ? 'tui.settings.confirm.discard_one' : 'tui.settings.confirm.discard', ['count' => $count])),
                $cut($text, implode(', ', $keys)),
                '',
                $cut($muted, Lang::t('tui.settings.confirm.discard_keys')),
            ];
        }

        $key = $this->pendingTrustKey() ?? '';
        $definition = SettingsSchema::byKey($key);

        return [
            $cut($head, Lang::t('tui.settings.confirm.trust', ['key' => $key])),
            $cut($text, Lang::t('tui.settings.confirm.trust_adds', ['key' => $key])),
            $cut($text, $definition?->help ?? ''),
            $cut($muted, Lang::t('tui.settings.confirm.trust_next_launch')),
            '',
            $cut($muted, Lang::t('tui.settings.confirm.trust_keys')),
        ];
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

        return $clean === '' ? $this : $this->mutate(['query' => $this->query . $clean, 'cursor' => 0]);
    }

    private function move(int $delta, bool $wrap = true): self
    {
        $count = \count($this->rows());
        if ($count === 0) {
            return $this;
        }

        $next = $this->cursor + $delta;
        $next = $wrap ? (($next % $count) + $count) % $count : max(0, min($count - 1, $next));

        // A status line is about the row it was said on; moving retires it.
        return $this->mutate(['cursor' => $next, 'status' => null]);
    }

    /** Move the save preview by `$delta` lines, never past its first or last line. */
    private function scrollPreview(int $delta): self
    {
        if ($this->preview === null) {
            return $this;
        }

        // The line count does not depend on the width or the theme: one line per entry.
        $last = max(0, \count($this->preview->lines(Theme::default(), 80)) - 1);

        return $this->mutate([
            'preview' => $this->preview,
            'previewOffset' => max(0, min($last, $this->previewOffset + $delta)),
        ]);
    }

    private function switchTab(int $delta): self
    {
        if ($this->query !== '') {
            return $this;
        }

        $count = \count($this->tabLabels());

        return $this->mutate(['tab' => (($this->tab + $delta) % $count + $count) % $count, 'cursor' => 0, 'status' => null]);
    }

    /**
     * A copy with `$changes` applied (named by constructor parameter). An open
     * field and an open question are NOT carried unless named: every change
     * of state other than the ones that keep them answers or abandons them.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...($changes + [
            'sources' => $this->sources,
            'resolved' => $this->resolved,
            'tab' => $this->tab,
            'cursor' => $this->cursor,
            'query' => $this->query,
            'searching' => $this->searching,
            'tier' => $this->tier,
            'set' => $this->set,
            'unset' => $this->unset,
            'preview' => $this->preview,
            'status' => $this->status,
            'editing' => null,
            'confirm' => null,
            'detail' => $this->detail,
            'previewOffset' => $this->previewOffset,
        ]));
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

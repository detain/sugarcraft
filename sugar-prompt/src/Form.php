<?php

declare(strict_types=1);

namespace SugarCraft\Prompt;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Top-level form container.
 *
 * Holds an ordered list of {@see Field}s, exactly one of which is
 * focused at a time (skippable fields are passed over). Tab / Down /
 * Shift+Tab / Up move the focus; Enter on the last non-skippable field
 * submits; Esc / Ctrl+C aborts.
 *
 * After submit (or abort), the form stops absorbing keystrokes and
 * caller code can collect {@see values()} keyed by each field's key.
 */
final class Form implements Model
{
    /**
     * @param list<Group>     $groups
     * @param array<int,list<Field>> $fieldsByGroup  cached for re-render
     */
    private function __construct(
        public readonly array $groups,
        public readonly int $groupIndex,
        public readonly array $fieldsByGroup,
        public readonly int $focusedIndex,
        public readonly bool $submitted,
        public readonly bool $aborted,
        public readonly Theme $theme,
        public readonly bool $accessible,
        private readonly ?\Closure $initCmd = null,
        public readonly bool $showHelp = true,
        public readonly bool $showErrors = true,
        public readonly int $width = 0,
        public readonly int $height = 0,
        public readonly int $timeoutMs = 0,
        public readonly ?KeyMap $keyMap = null,
    ) {}

    /**
     * Single-page form. Equivalent to `Form::groups(Group::new(...$fields))`
     * — kept as the primary factory for backwards compatibility.
     */
    public static function new(Field ...$fields): self
    {
        return self::groups(Group::new(...$fields));
    }

    /**
     * Multi-page form. Each {@see Group} renders on its own page; the
     * user advances with Tab past the last field on the page and pops
     * back with Shift-Tab. Mirrors huh's multi-group flow.
     */
    public static function groups(Group ...$groups): self
    {
        $list = array_values($groups);
        if ($list === []) {
            $list = [Group::new()];
        }
        $fieldsByGroup = [];
        foreach ($list as $i => $group) {
            $fieldsByGroup[$i] = $group->fields;
        }
        // Find first focusable in first non-hidden group.
        $startGroup = self::firstVisibleGroup($list, [], 0, +1);
        $startGroup = $startGroup ?? 0;
        $startField = self::firstNonSkippable($fieldsByGroup[$startGroup], 0, +1);
        $initCmd = null;
        if ($startField !== null) {
            [$focused, $cmd] = $fieldsByGroup[$startGroup][$startField]->focus();
            $fieldsByGroup[$startGroup][$startField] = $focused;
            $initCmd = $cmd;
        }
        return new self(
            groups:         $list,
            groupIndex:     $startGroup,
            fieldsByGroup:  $fieldsByGroup,
            focusedIndex:   $startField ?? 0,
            submitted:      false,
            aborted:        false,
            theme:          Theme::ansi(),
            accessible:     false,
            initCmd:        $initCmd,
        );
    }

    /**
     * Cmd returned the first time the runtime polls — typically the
     * focused field's focus-Cmd (cursor blink, autocomplete preload).
     * Mirrors candy-core's {@see Model::init()}.
     */
    public function init(): ?\Closure
    {
        return $this->initCmd;
    }

    /**
     * Set the global theme. Per-{@see Group} overrides take priority
     * via {@see activeTheme()}. Mirrors huh's `WithTheme`.
     */
    public function withTheme(Theme $theme): self
    {
        return $this->mutate(theme: $theme);
    }

    /**
     * Toggle accessibility mode. When on, the Form's view() degrades
     * to a single-line "label: value" plain-text rendering for the
     * focused field — designed for screen readers / non-TUI contexts.
     * Mirrors huh's `WithAccessible`.
     */
    public function withAccessible(bool $on = true): self
    {
        return $this->mutate(accessible: $on);
    }

    /**
     * Show / hide the help footer rendered below the focused field.
     * Default on. Mirrors huh's `WithShowHelp`.
     */
    public function withShowHelp(bool $on = true): self
    {
        return $this->mutate(showHelp: $on);
    }

    /**
     * Show / hide the inline `! error` line under fields with active
     * validation errors. Default on. Mirrors huh's `WithShowErrors`.
     */
    public function withShowErrors(bool $on = true): self
    {
        return $this->mutate(showErrors: $on);
    }

    /**
     * Pin the rendered width to `$cells` cells (clamped to 0). The
     * field views are wrapped at this budget when they support
     * width caps. Default 0 = no cap. Mirrors huh's `WithWidth`.
     */
    public function withWidth(int $cells): self
    {
        return $this->mutate(width: max(0, $cells));
    }

    /**
     * Pin the rendered height. Currently advisory — fields decide
     * how to use the budget. Default 0 = no cap. Mirrors huh's
     * `WithHeight`.
     */
    public function withHeight(int $rows): self
    {
        return $this->mutate(height: max(0, $rows));
    }

    /**
     * Auto-abort after `$ms` milliseconds of wall-clock time. The form
     * stores the budget for the runtime to consult — it does not start
     * a timer itself. Default 0 = no timeout. Mirrors huh's
     * `WithTimeout(time.Duration)`.
     */
    public function withTimeout(int $ms): self
    {
        return $this->mutate(timeoutMs: max(0, $ms));
    }

    /**
     * Replace the form's {@see KeyMap} — the bindings for next / prev /
     * submit / abort. Pass null to fall back to {@see KeyMap::default()}.
     *
     * Mirrors upstream charmbracelet/huh #272 ("Overriding KeyMaps and
     * KeyBinds"). Use to rebind nav keys without forking the runtime —
     * e.g. swap Tab for `j` / `k`, or add Ctrl-Enter as a submit shortcut.
     */
    public function withKeyMap(?KeyMap $keyMap): self
    {
        return $this->mutate(keyMap: $keyMap, keyMapSet: true);
    }

    /** Resolved keymap — the configured override if set, else the default. */
    public function activeKeyMap(): KeyMap
    {
        return $this->keyMap ?? KeyMap::default();
    }

    // Short-form aliases.
    public function theme(Theme $t): self          { return $this->withTheme($t); }
    public function accessible(bool $on = true): self { return $this->withAccessible($on); }
    public function showHelp(bool $on = true): self   { return $this->withShowHelp($on); }
    public function showErrors(bool $on = true): self { return $this->withShowErrors($on); }
    public function width(int $cells): self        { return $this->withWidth($cells); }
    public function height(int $rows): self        { return $this->withHeight($rows); }
    public function timeout(int $ms): self         { return $this->withTimeout($ms); }

    /** Configured timeout in milliseconds; 0 means none. */
    public function timeoutMs(): int { return $this->timeoutMs; }

    /**
     * Resolved theme for the active group: the group's
     * {@see Group::withTheme()} override when set, otherwise the
     * form-level theme.
     */
    public function activeTheme(): Theme
    {
        return $this->groups[$this->groupIndex]->theme ?? $this->theme;
    }

    /**
     * Advance to the next non-hidden group. No-op (returns the same
     * instance) when already on the last group. Validators / hide-funcs
     * on the current group are evaluated before the move.
     */
    public function nextGroup(): self
    {
        [$next, ] = $this->advanceGroup(+1);
        return $next;
    }

    /** Mirror of {@see nextGroup()} stepping backwards. */
    public function prevGroup(): self
    {
        [$next, ] = $this->advanceGroup(-1);
        return $next;
    }

    /** Index of the active group, 0-based. */
    public function activeGroupIndex(): int { return $this->groupIndex; }

    /** Total number of groups (including hidden ones). */
    public function totalGroups(): int { return count($this->groups); }

    /** The {@see Group} struct that owns the currently-focused field. */
    public function activeGroup(): Group
    {
        return $this->groups[$this->groupIndex];
    }

    /**
     * Field list for the active group, in declaration order.
     *
     * @return list<Field>
     */
    public function activeFields(): array
    {
        return $this->fieldsByGroup[$this->groupIndex];
    }

    /**
     * Bubble-Tea {@see Model} update entry point. Routes the message
     * to the focused field, applies form-level navigation (Tab /
     * Shift-Tab / Up / Down) + abort (Esc / Ctrl-C) + submit (Enter
     * on the last field), and returns `[$next, $cmd]`.
     *
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($this->submitted || $this->aborted) {
            return [$this, null];
        }

        $idx           = $this->focusedIndex;
        $fields        = $this->fieldsByGroup[$this->groupIndex];
        $focusedField  = $fields[$idx] ?? null;

        // Let the focused field eat keys it claims to consume (e.g. Select
        // in filter mode wants Enter / Escape) before applying form-level
        // navigation, submit, or abort.
        if ($focusedField !== null && $focusedField->consumes($msg)) {
            return $this->forward($msg);
        }

        if ($msg instanceof KeyMsg) {
            $keyMap = $this->activeKeyMap();

            // Abort.
            if ($keyMap->isAbort($msg)) {
                return [$this->mutate(aborted: true), Cmd::quit()];
            }

            // Navigation: forward bindings first, then back.
            if ($keyMap->isNext($msg)) {
                return $this->advance(+1);
            }
            if ($keyMap->isPrev($msg)) {
                return $this->advance(-1);
            }

            // Submission: any key in the submit binding list, but only
            // when the form is on the last interactive field of the
            // last visible group. Default keymap binds Enter here.
            if ($keyMap->isSubmit($msg)) {
                $last = self::firstNonSkippable($fields, count($fields) - 1, -1);
                if ($last !== null && $this->focusedIndex === $last) {
                    $isLastGroup = self::firstVisibleGroup(
                        $this->groups, $this->collectValues(),
                        $this->groupIndex + 1, +1,
                    ) === null;
                    if ($isLastGroup) {
                        return [$this->mutate(submitted: true), Cmd::quit()];
                    }
                    return $this->advanceGroup(+1);
                }
                return $this->advance(+1);
            }
        }

        return $this->forward($msg);
    }

    /**
     * Render the form as a multi-line ANSI string. Honours the
     * {@see withAccessible()} switch — when on, degrades to a single
     * "label: value" line for the focused field (screen-reader
     * friendly).
     */
    public function view(): string
    {
        if ($this->accessible) {
            return $this->accessibleView();
        }
        $group = $this->groups[$this->groupIndex];
        $theme = $group->theme ?? $this->theme;
        $blocks = [];
        if ($group->title !== '') {
            $blocks[] = $theme->title->render($group->title);
        }
        if ($group->description !== '') {
            $blocks[] = $theme->description->render($group->description);
        }
        foreach ($this->fieldsByGroup[$this->groupIndex] as $f) {
            $blocks[] = $f->view();
        }
        if (count($this->groups) > 1 && $this->showHelp && $group->showHelp) {
            $blocks[] = $theme->help->render(
                sprintf('Step %d of %d', $this->groupIndex + 1, count($this->groups))
            );
        }
        $body = implode("\n\n", $blocks);
        if ($this->submitted) {
            return $body . "\n\n[submitted]";
        }
        if ($this->aborted) {
            return $body . "\n\n[aborted]";
        }
        return $body;
    }

    /** Plain-text fallback for screen readers / non-TUI contexts. */
    private function accessibleView(): string
    {
        $field = $this->focusedField();
        if ($field === null) {
            return '';
        }
        $title = $field->getTitle();
        $value = (string) $field->value();
        $err   = $field->getError();
        $line  = $title === '' ? $value : ($title . ': ' . $value);
        return $err !== null ? $line . "\n! " . $err : $line;
    }

    /**
     * Final value map keyed on each field's {@see Field::key()}.
     * Hidden groups and skippable fields (notes, separators) are
     * excluded — only fields that participate in the user-driven flow
     * appear in the result.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $out = [];
        $accumulated = [];
        foreach ($this->groups as $i => $group) {
            if ($group->isHidden($accumulated)) {
                continue;
            }
            foreach ($this->fieldsByGroup[$i] as $f) {
                if ($f->skippable()) {
                    continue;
                }
                $out[$f->key()] = $f->value();
                $accumulated[$f->key()] = $f->value();
            }
        }
        return $out;
    }

    /**
     * Untyped value lookup by key. Returns the field's raw `value()`
     * for the given key, or `$default` when the key is unknown or the
     * containing group is hidden.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->values();
        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    /**
     * String accessor — coerces scalar or stringable values; arrays are
     * imploded with `, ` to mirror huh's `GetString`. Returns `$default`
     * on missing key or values that don't coerce sensibly.
     */
    public function getString(string $key, string $default = ''): string
    {
        $v = $this->get($key);
        if ($v === null) {
            return $default;
        }
        if (is_string($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_array($v)) {
            return implode(', ', array_map(static fn ($x) => (string) $x, $v));
        }
        if (is_object($v) && method_exists($v, '__toString')) {
            return (string) $v;
        }
        return $default;
    }

    /**
     * Int accessor — coerces numeric values via `(int) $v`. Strings
     * that aren't numeric return `$default`.
     */
    public function getInt(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        return $default;
    }

    /**
     * Bool accessor — true / false / "true" / "false" / "yes" / "no" /
     * "1" / "0" / 1 / 0. Anything else returns `$default`.
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $v = $this->get($key);
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_string($v)) {
            $low = strtolower(trim($v));
            return match ($low) {
                'true', 'yes', 'y', '1', 'on'   => true,
                'false', 'no', 'n', '0', 'off' => false,
                default                          => $default,
            };
        }
        return $default;
    }

    /**
     * Array accessor — returns a list when the underlying value is an
     * array (multi-select); a single-element list when the value is
     * a non-empty scalar; an empty list otherwise.
     *
     * @return list<mixed>
     */
    public function getArray(string $key): array
    {
        $v = $this->get($key);
        if (is_array($v)) {
            return array_values($v);
        }
        if ($v === null || $v === '' || $v === false) {
            return [];
        }
        return [$v];
    }

    /** True after the last interactive field's Enter — `values()` is final. */
    public function isSubmitted(): bool { return $this->submitted; }
    /** True after Esc / Ctrl-C — partial values may still be available via `values()`. */
    public function isAborted(): bool   { return $this->aborted; }

    /** The focused field, or null when the form is empty / submitted / aborted. */
    public function focusedField(): ?Field
    {
        return $this->fieldsByGroup[$this->groupIndex][$this->focusedIndex] ?? null;
    }

    /**
     * Alias for {@see focusedField()} matching huh's `GetFocusedField`.
     */
    public function getFocusedField(): ?Field
    {
        return $this->focusedField();
    }

    /**
     * Validation errors keyed by field key. Hidden groups and skippable
     * fields (Note) are excluded. Empty when every visible field
     * validates cleanly. Mirrors huh's `Errors()`.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        $out = [];
        $accumulated = [];
        foreach ($this->groups as $i => $group) {
            if ($group->isHidden($accumulated)) {
                continue;
            }
            foreach ($this->fieldsByGroup[$i] as $f) {
                if ($f->skippable()) {
                    continue;
                }
                $accumulated[$f->key()] = $f->value();
                $err = $f->getError();
                if ($err !== null && $err !== '') {
                    $out[$f->key()] = $err;
                }
            }
        }
        return $out;
    }

    /**
     * True when any visible field has a non-empty validation error.
     */
    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    /**
     * Currently-applicable key bindings rendered as `[label, keys]` rows
     * suitable for a status / help bar. The bindings reflect form-level
     * navigation (Tab, Shift-Tab, Enter, Esc/Ctrl-C) plus any extras
     * the focused field exposes via {@see Field::keyBindings()} when
     * implemented (currently only used by the standard navigation set
     * since per-field bindings vary). Mirrors the surface of huh's
     * `KeyBinds()`.
     *
     * @return list<array{0:string, 1:string}>
     */
    public function keyBinds(): array
    {
        $binds = [
            ['next',   'tab / ↓'],
            ['prev',   'shift+tab / ↑'],
            ['submit', 'enter'],
            ['quit',   'esc / ctrl+c'],
        ];
        if (count($this->groups) > 1) {
            $binds[] = ['next page', 'tab past last field'];
            $binds[] = ['prev page', 'shift+tab on first field'];
        }
        return $binds;
    }

    /**
     * Single-line help string composed from {@see keyBinds()}. Useful
     * for status bars / footers. Mirrors huh's `Help()`.
     */
    public function help(): string
    {
        $parts = [];
        foreach ($this->keyBinds() as [$label, $keys]) {
            $parts[] = $label . ' ' . $keys;
        }
        return implode(' • ', $parts);
    }

    /**
     * Forward a Msg to the focused field and return the resulting Form.
     *
     * @return array{0:self, 1:?\Closure}
     */
    private function forward(Msg $msg): array
    {
        $idx = $this->focusedIndex;
        $fields = $this->fieldsByGroup[$this->groupIndex];
        if (!isset($fields[$idx])) {
            return [$this, null];
        }
        [$updated, $cmd] = $fields[$idx]->update($msg);
        $newFields = $fields;
        $newFields[$idx] = $updated;
        $newByGroup = $this->fieldsByGroup;
        $newByGroup[$this->groupIndex] = $newFields;
        return [$this->mutate(fieldsByGroup: $newByGroup), $cmd];
    }

    /**
     * Advance focus within the current group; if we run off either end,
     * jump to the previous / next visible group.
     *
     * @return array{0:self, 1:?\Closure}
     */
    private function advance(int $direction): array
    {
        $fields = $this->fieldsByGroup[$this->groupIndex];
        $next = self::firstNonSkippable($fields, $this->focusedIndex + $direction, $direction);
        if ($next === null) {
            // Off the end of this group — try the next/prev group.
            return $this->advanceGroup($direction);
        }
        if ($next === $this->focusedIndex) {
            return [$this, null];
        }
        $newFields = $fields;
        $newFields[$this->focusedIndex] = $newFields[$this->focusedIndex]->blur();
        [$focused, $cmd] = $newFields[$next]->focus();
        $newFields[$next] = $focused;
        $newByGroup = $this->fieldsByGroup;
        $newByGroup[$this->groupIndex] = $newFields;
        return [$this->mutate(fieldsByGroup: $newByGroup, focusedIndex: $next), $cmd];
    }

    /**
     * @return array{0:self, 1:?\Closure}
     */
    private function advanceGroup(int $direction): array
    {
        $values = $this->collectValues();
        $nextGroup = self::firstVisibleGroup(
            $this->groups, $values,
            $this->groupIndex + $direction, $direction,
        );
        if ($nextGroup === null) {
            return [$this, null];
        }
        // Blur current focused field.
        $fieldsByGroup = $this->fieldsByGroup;
        $curFields = $fieldsByGroup[$this->groupIndex];
        if (isset($curFields[$this->focusedIndex])) {
            $curFields[$this->focusedIndex] = $curFields[$this->focusedIndex]->blur();
            $fieldsByGroup[$this->groupIndex] = $curFields;
        }
        // Focus the first non-skippable in the new group.
        $newFields = $fieldsByGroup[$nextGroup];
        $first = self::firstNonSkippable($newFields, 0, +1) ?? 0;
        $cmd = null;
        if (isset($newFields[$first])) {
            [$focused, $cmd] = $newFields[$first]->focus();
            $newFields[$first] = $focused;
            $fieldsByGroup[$nextGroup] = $newFields;
        }
        return [$this->mutate(
            fieldsByGroup: $fieldsByGroup,
            groupIndex:    $nextGroup,
            focusedIndex:  $first,
        ), $cmd];
    }

    /**
     * Snapshot of all values collected up to (but not including) the
     * current group. Used as input for `Group::isHidden()` checks.
     *
     * @return array<string,mixed>
     */
    private function collectValues(): array
    {
        $out = [];
        foreach ($this->groups as $i => $group) {
            if ($i >= $this->groupIndex) {
                break;
            }
            foreach ($this->fieldsByGroup[$i] as $f) {
                if (!$f->skippable()) {
                    $out[$f->key()] = $f->value();
                }
            }
        }
        return $out;
    }

    /**
     * @param list<Group>           $groups
     * @param array<string,mixed>   $values  collected so far for hideFunc
     */
    private static function firstVisibleGroup(array $groups, array $values, int $start, int $step): ?int
    {
        $n = count($groups);
        for ($i = $start; $i >= 0 && $i < $n; $i += $step) {
            if (!$groups[$i]->isHidden($values)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * @param list<Field> $fields
     * @param int         $start  starting index (may be out of range)
     * @param int         $step   +1 or -1
     */
    private static function firstNonSkippable(array $fields, int $start, int $step): ?int
    {
        $n = count($fields);
        for ($i = $start; $i >= 0 && $i < $n; $i += $step) {
            if (!$fields[$i]->skippable()) {
                return $i;
            }
        }
        return null;
    }

    /** @param array<int,list<Field>>|null $fieldsByGroup */
    private function mutate(
        ?array $fieldsByGroup = null,
        ?int $groupIndex = null,
        ?int $focusedIndex = null,
        ?bool $submitted = null,
        ?bool $aborted = null,
        ?Theme $theme = null,
        ?bool $accessible = null,
        ?bool $showHelp = null,
        ?bool $showErrors = null,
        ?int $width = null,
        ?int $height = null,
        ?int $timeoutMs = null,
        ?KeyMap $keyMap = null, bool $keyMapSet = false,
    ): self {
        return new self(
            groups:         $this->groups,
            groupIndex:     $groupIndex     ?? $this->groupIndex,
            fieldsByGroup:  $fieldsByGroup  ?? $this->fieldsByGroup,
            focusedIndex:   $focusedIndex   ?? $this->focusedIndex,
            submitted:      $submitted      ?? $this->submitted,
            aborted:        $aborted        ?? $this->aborted,
            theme:          $theme          ?? $this->theme,
            accessible:     $accessible     ?? $this->accessible,
            initCmd:        null,
            showHelp:       $showHelp       ?? $this->showHelp,
            showErrors:     $showErrors     ?? $this->showErrors,
            width:          $width          ?? $this->width,
            height:         $height         ?? $this->height,
            timeoutMs:      $timeoutMs      ?? $this->timeoutMs,
            keyMap:         $keyMapSet      ? $keyMap : $this->keyMap,
        );
    }
}

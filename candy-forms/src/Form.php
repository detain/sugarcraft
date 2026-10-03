<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Layout\Constraint\Constraint;
use SugarCraft\Layout\Direction;
use SugarCraft\Layout\LayoutSolver;
use SugarCraft\Layout\Region;

/**
 * Top-level form container.
 *
 * Holds an ordered list of {@see Field}s, exactly one of which is
 * focused at a time (skippable fields are passed over). Tab / Down /
 * Shift+Tab / Up move the focus; Enter on the last non-skippable field
 * submits; Esc / Ctrl+C aborts.
 *
 * Fields whose {@see Field::isHidden()} predicate fires are treated as
 * absent: navigation and the submit gate pass over them, they do not
 * render, and {@see values()} / {@see errors()} / {@see validateAll()} /
 * {@see validateAsync()} leave them out. The predicate sees the values of
 * the visible fields BEFORE it (earlier groups, then earlier fields of the
 * same group) — the same progressive convention groups use. A group
 * whose every field is hidden is passed over like a hidden group, and a
 * page with no interactive field (only notes) moves on / submits on Enter.
 * When the active group has nothing to focus, {@see $focusedIndex} is -1
 * and {@see focusedField()} returns null.
 *
 * After submit (or abort), the form stops absorbing keystrokes and
 * caller code can collect {@see values()} keyed by each field's key.
 */
final class Form implements Model
{
    /**
     * Lazy memo of {@see values()} (E736 plan 3.3). Sound because every
     * Form snapshot is immutable — all state changes flow through
     * mutate(), which builds a fresh instance with a fresh (empty) memo.
     * The hide predicates it walks are contractually pure over the
     * accumulated values, so re-deriving could only repeat work.
     *
     * @var array<string, mixed>|null
     */
    private ?array $valuesMemo = null;

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
        public readonly bool $errorSummary = false,
        public readonly int $width = 0,
        public readonly int $height = 0,
        public readonly int $timeoutMs = 0,
        public readonly ?KeyMap $keyMap = null,
        public readonly ?array $constraints = null,
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
        // Find first focusable in the first navigable group. The probe is an
        // unfocused snapshot: navigability needs the instance-level hidden
        // mask (seeded from earlier groups' visible values).
        $probe = new self(
            groups:         $list,
            groupIndex:     0,
            fieldsByGroup:  $fieldsByGroup,
            focusedIndex:   0,
            submitted:      false,
            aborted:        false,
            theme:          Theme::ansi(),
            accessible:     false,
        );
        $startGroup = $probe->firstNavigableGroup(0, +1) ?? 0;
        $hidden     = $probe->hiddenFieldsIn($startGroup);
        $startField = self::firstNonSkippable($fieldsByGroup[$startGroup], 0, +1, $hidden);
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
            focusedIndex:   $startField ?? self::restingIndex($fieldsByGroup[$startGroup], $hidden),
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
     * Show / hide an error summary at the end of the form after all
     * fields are rendered. When enabled and validation errors exist,
     * displays a list of field titles with their error messages.
     * Default off. Mirrors huh's `WithErrorSummary`.
     */
    public function withErrorSummary(bool $on = true): self
    {
        return $this->mutate(errorSummary: $on);
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
     * submit / abort. Pass null to fall back to {@see KeyMap::new()}.
     *
     * Mirrors upstream charmbracelet/huh #272 ("Overriding KeyMaps and
     * KeyBinds"). Use to rebind nav keys without forking the runtime —
     * e.g. swap Tab for `j` / `k`, or add Ctrl-Enter as a submit shortcut.
     *
     * @param KeyMap|null $keyMap pass null to reset to the default key map
     *                            (E736 4.6: null-to-reset is the contract —
     *                            the set-sentinel distinguishes "cleared"
     *                            from "never configured", both resolving to
     *                            {@see KeyMap::new()} via {@see activeKeyMap()})
     */
    public function withKeyMap(?KeyMap $keyMap): self
    {
        return $this->mutate(keyMap: $keyMap, keyMapSet: true);
    }

    /**
     * Apply an explicit constraint set from candy-layout to govern how
     * the form's fields are sized and positioned. When set, the form's
     * {@see view()} uses {@see LayoutSolver} to compute a region per
     * field before rendering. Pass null (or leave unset) to keep the
     * default greedy-vertical stacking.
     *
     * @param list<Constraint>|null $constraints
     * @see LayoutSolver
     */
    public function withConstraints(?array $constraints): self
    {
        return $this->mutate(constraints: $constraints, constraintsSet: true);
    }

    /** Resolved keymap — the configured override if set, else the default. */
    public function activeKeyMap(): KeyMap
    {
        return $this->keyMap ?? KeyMap::new();
    }

    // Short-form aliases.
    public function theme(Theme $t): self          { return $this->withTheme($t); }
    public function accessible(bool $on = true): self { return $this->withAccessible($on); }
    public function showHelp(bool $on = true): self   { return $this->withShowHelp($on); }
    public function showErrors(bool $on = true): self { return $this->withShowErrors($on); }
    public function errorSummary(bool $on = true): self { return $this->withErrorSummary($on); }
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
            // E736-F2/6.8: every late-settling async suggestion (and any
            // other message) is discarded at this door — the pending-async
            // surface of all fields turns inert once the form resolves, so
            // no explicit cancel-on-submit bookkeeping is required.
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
            // last navigable group. Default keymap binds Enter here. A
            // group with no interactive field at all (only notes, or every
            // field hidden by host focus) has no "last field" to reach, so
            // Enter there moves on / submits directly instead of dead-ending.
            // Focus PAST the last interactive field counts as being on it:
            // withFocus() may park focus on a hidden trailing field, and
            // advance(+1) from there finds nothing to move to.
            if ($keyMap->isSubmit($msg)) {
                $last = self::firstNonSkippable(
                    $fields, count($fields) - 1, -1, $this->hiddenFieldsIn($this->groupIndex),
                );
                if ($last === null || $this->focusedIndex >= $last) {
                    $isLastGroup = $this->firstNavigableGroup($this->groupIndex + 1, +1) === null;
                    if ($isLastGroup) {
                        return $this->submitOrGateLastGroup();
                    }
                    return $this->advanceGroup(+1);
                }
                return $this->advance(+1);
            }
        }

        return $this->forward($msg);
    }

    /**
     * Enter on the last field of the last visible group: revalidate the current
     * group and either submit (every field clean) or keep the form open, moving
     * focus to the first erroring field. Extracted from {@see update()} to keep
     * the submit path flat.
     *
     * When a field errors we blur the field that was focused and focus() the
     * target so the cursor — and its blink Cmd — follow the error rather than
     * stranding on the last field, which never submitted. (A bare focusedIndex
     * hop would leave the old field rendering its cursor and the new one with
     * none.)
     *
     * @return array{0:Model, 1:?\Closure}
     */
    private function submitOrGateLastGroup(): array
    {
        // Revalidate every field in the current group so that untouched-but-
        // required fields surface their errors, tracking the first that fails.
        $revalidated = [];
        $firstErrorIdx = null;
        $hidden = $this->hiddenFieldsIn($this->groupIndex);
        foreach ($this->fieldsByGroup[$this->groupIndex] as $i => $f) {
            // A hidden field does not exist for the submit gate: its
            // (possibly stale, possibly required-and-empty) value must
            // neither block submission nor steal focus.
            if (isset($hidden[$i])) {
                $revalidated[$i] = $f;
                continue;
            }
            $rf = $f->revalidate();
            $revalidated[$i] = $rf;
            if ($firstErrorIdx === null && $rf->getError() !== null && $rf->getError() !== '') {
                $firstErrorIdx = $i;
            }
        }
        if ($firstErrorIdx === null) {
            return [$this->mutate(submitted: true), Cmd::quit()];
        }

        $cmd = null;
        if ($firstErrorIdx !== $this->focusedIndex) {
            if (isset($revalidated[$this->focusedIndex])) {
                $revalidated[$this->focusedIndex] = $revalidated[$this->focusedIndex]->blur();
            }
            [$focused, $cmd] = $revalidated[$firstErrorIdx]->focus();
            $revalidated[$firstErrorIdx] = $focused;
        }
        $newByGroup = $this->fieldsByGroup;
        $newByGroup[$this->groupIndex] = $revalidated;

        return [$this->mutate(fieldsByGroup: $newByGroup, focusedIndex: $firstErrorIdx), $cmd];
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
        $hidden = $this->hiddenFieldsIn($this->groupIndex);
        foreach ($this->fieldsByGroup[$this->groupIndex] as $i => $f) {
            // Hidden fields do not render — except a field the host focused
            // by name via withFocus()/focusField(), so the caret is never
            // invisible.
            if (isset($hidden[$i]) && $i !== $this->focusedIndex) {
                continue;
            }
            $blocks[] = $f->view();
        }
        if (count($this->groups) > 1 && $this->showHelp && $group->showHelp) {
            $blocks[] = $theme->help->render(
                sprintf('Step %d of %d', $this->groupIndex + 1, count($this->groups))
            );
        }
        if ($this->errorSummary && $this->hasErrors()) {
            $blocks[] = $this->renderErrorSummary($theme);
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
        $value = self::coerceString($field->value()) ?? '';
        $err   = $field->getError();
        $line  = $title === '' ? $value : ($title . ': ' . $value);
        return $err !== null ? $line . "\n! " . $err : $line;
    }

    /**
     * Render error summary as a formatted block using the theme's
     * error styling for each entry.
     */
    private function renderErrorSummary(Theme $theme): string
    {
        $lines = [];
        foreach ($this->errors() as $key => $error) {
            $field = $this->findFieldByKey($key);
            $title = $field !== null ? $field->getTitle() : $key;
            $lines[] = $theme->error->render($title . ': ' . $error);
        }
        return implode("\n", $lines);
    }

    /**
     * Find a field by its key across all groups.
     */
    private function findFieldByKey(string $key): ?Field
    {
        foreach ($this->fieldsByGroup as $fields) {
            foreach ($fields as $f) {
                if ($f->key() === $key) {
                    return $f;
                }
            }
        }
        return null;
    }

    /**
     * Final value map keyed on each field's {@see Field::key()}.
     * Hidden groups, hidden fields and skippable fields (notes,
     * separators) are excluded — only fields that participate in the user-driven flow
     * appear in the result.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        if ($this->valuesMemo !== null) {
            return $this->valuesMemo;
        }
        // E736-F2/2.9 (round 85): the walk shape is shared with
        // collectValues() (navigation's hide-predicate input) via valueWalk().
        return $this->valuesMemo = $this->valueWalk(stopBeforeGroup: null);
    }

    /**
     * Shared group-walk used by {@see values()} and {@see collectValues()}
     * (E736-F2/2.9). Collects non-skippable field values per group,
     * optionally stopping before a group index, and skips groups AND fields
     * whose hideFunc fires against the progressively accumulated map — the
     * progressive convention every consumer shares. Hidden fields are never
     * part of the map, so no later predicate (group or field) can see them.
     *
     * @return array<string,mixed>
     */
    private function valueWalk(?int $stopBeforeGroup): array
    {
        $out         = [];
        $accumulated = [];
        foreach ($this->groups as $i => $group) {
            if ($stopBeforeGroup !== null && $i >= $stopBeforeGroup) {
                break;
            }
            if ($group->isHidden($accumulated)) {
                continue;
            }
            foreach ($this->fieldsByGroup[$i] as $f) {
                if ($f->skippable()) {
                    continue;
                }
                if ($f->isHidden($accumulated)) {
                    continue;
                }
                $out[$f->key()]         = $f->value();
                $accumulated[$f->key()] = $f->value();
            }
        }
        return $out;
    }

    /**
     * Restore values from a persisted snapshot (E736 5.9, round 89) — the
     * symmetric counterpart of {@see values()}: `hydrate($form->values())`
     * round-trips every hydratable field. Dispatch is on the CONCRETE final
     * field classes (the {@see Field} interface stays un-widened): Input /
     * Text / Color / Select take stringables, MultiSelect takes its option
     * string list, Confirm takes a bool, Slider takes int|float, Date takes
     * null|string.
     *
     * Fail loud, atomically: every key is located and type-checked BEFORE any
     * field is touched, so a bad map cannot half-hydrate. An unknown key, a
     * key belonging to a skippable field, a value whose type the field cannot
     * accept, or a field with no hydration surface (FilePicker holds directory
     * navigation state, not a persisted value; so do third-party {@see Field}
     * implementations) all throw an {@see \InvalidArgumentException} naming the
     * offending key.
     *
     * Hydration deliberately bypasses per-keystroke guards (Input char limits
     * still clamp inside the {@see TextInput}, MultiSelect caps are surfaced as
     * the constraint error instead of dropping picks) — the snapshot is the
     * caller's trusted saved state; validation answers it via
     * {@see validateAll()} / the submit gate afterwards.
     *
     * @param array<string,mixed> $values
     */
    public function hydrate(array $values): self
    {
        if ($values === []) {
            return $this;
        }

        // Pass 1 — parse the whole map to trusted setter closures before
        // mutating anything (Law: boundary parsing, atomic application).
        $applies = [];
        foreach ($values as $key => $raw) {
            $key    = (string) $key;
            $target = $this->locateField($key);
            if ($target === null) {
                throw new \InvalidArgumentException(Lang::t('form.hydrate_unknown_key', ['key' => $key]));
            }
            [$group, $index] = $target;
            $field = $this->fieldsByGroup[$group][$index];
            if ($field->skippable()) {
                // Notes / separators never appear in values(); a key colliding
                // with one is a caller mistake, not a silent no-op.
                throw new \InvalidArgumentException(Lang::t('form.hydrate_skippable', ['key' => $key]));
            }
            $applies[] = [$group, $index, self::hydrateSetter($field, $key, $raw)];
        }

        // Pass 2 — apply onto one working copy so the fluent chain costs a
        // single mutate() (each setter returns the new field instance).
        $fieldsByGroup = $this->fieldsByGroup;
        foreach ($applies as [$group, $index, $setter]) {
            $fieldsByGroup[$group][$index] = $setter($fieldsByGroup[$group][$index]);
        }

        return $this->mutate(fieldsByGroup: $fieldsByGroup);
    }

    /**
     * Resolve the per-class hydration setter after type-checking the raw
     * snapshot value. Returns a closure Field→Field so application stays
     * decoupled from resolution (pass 1 judges, pass 2 writes).
     *
     * @return \Closure(Field): Field
     */
    private static function hydrateSetter(Field $field, string $key, mixed $raw): \Closure
    {
        return match (true) {
            $field instanceof Field\Input,
            $field instanceof Field\Text,
            $field instanceof Field\Color
                => static function (Field $f) use ($key, $raw): Field {
                    $v = self::hydrateString($key, $raw);
                    \assert($f instanceof Field\Input || $f instanceof Field\Text || $f instanceof Field\Color);
                    return $f->withValue($v);
                },

            $field instanceof Field\Select
                => static function (Field $f) use ($key, $raw): Field {
                    return $f->withSelected(self::hydrateString($key, $raw));
                },

            $field instanceof Field\MultiSelect
                => static function (Field $f) use ($key, $raw): Field {
                    if (!is_array($raw)) {
                        throw new \InvalidArgumentException(Lang::t('form.hydrate_type', [
                            'key' => $key, 'expected' => 'list<string>', 'actual' => get_debug_type($raw),
                        ]));
                    }
                    return $f->withValue(array_map(static fn ($o): string => self::hydrateString($key, $o), array_values($raw)));
                },

            $field instanceof Field\Confirm
                => static function (Field $f) use ($key, $raw): Field {
                    if (!is_bool($raw)) {
                        throw new \InvalidArgumentException(Lang::t('form.hydrate_type', [
                            'key' => $key, 'expected' => 'bool', 'actual' => get_debug_type($raw),
                        ]));
                    }
                    return $f->withDefault($raw);
                },

            $field instanceof Field\Slider
                => static function (Field $f) use ($key, $raw): Field {
                    if (!is_int($raw) && !is_float($raw)) {
                        throw new \InvalidArgumentException(Lang::t('form.hydrate_type', [
                            'key' => $key, 'expected' => 'int|float', 'actual' => get_debug_type($raw),
                        ]));
                    }
                    return $f->withValue($raw);
                },

            $field instanceof Field\Date
                => static function (Field $f) use ($key, $raw): Field {
                    if ($raw !== null && !is_string($raw)) {
                        throw new \InvalidArgumentException(Lang::t('form.hydrate_type', [
                            'key' => $key, 'expected' => 'string|null', 'actual' => get_debug_type($raw),
                        ]));
                    }
                    return $f->withValue($raw);
                },

            default => throw new \InvalidArgumentException(Lang::t('form.hydrate_not_hydratable', [
                'key' => $key, 'type' => get_debug_type($field),
            ])),
        };
    }

    /**
     * Stringable coercion for the text-shaped hydrate targets: strings,
     * numbers, stringable objects and BackedEnum cases (Select in enum mode
     * round-trips its own value()). Bools are refused — silently turning
     * `true` into `'1'` in a name field is how persistence bugs are born.
     */
    private static function hydrateString(string $key, mixed $raw): string
    {
        if (is_string($raw)) {
            return $raw;
        }
        if ($raw instanceof \BackedEnum) {
            return (string) $raw->value;
        }
        if (is_int($raw) || is_float($raw)) {
            return (string) $raw;
        }
        if (is_object($raw) && method_exists($raw, '__toString')) {
            return (string) $raw;
        }
        throw new \InvalidArgumentException(Lang::t('form.hydrate_type', [
            'key' => $key, 'expected' => 'string', 'actual' => get_debug_type($raw),
        ]));
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
        return self::coerceString($this->get($key)) ?? $default;
    }

    /**
     * The string coercion shared by {@see getString()} and the accessible
     * view: scalars cast, bools spell `true`/`false`, arrays (MultiSelect)
     * implode with `, ` as huh's `GetString` does. Null when the value has
     * no sensible string form (null, non-stringable objects).
     */
    private static function coerceString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
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
        return null;
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
     * Move focus to the field with the given key (E736 5.3, round 89).
     *
     * Fluent builder mirroring {@see nextGroup()}: performs the full
     * blur-old / focus-new dance — including the cross-group jump when the
     * key lives on another page — and discards the focus Cmd (cursor blink
     * etc.), exactly like the group-jump verbs. Use {@see focusField()}
     * instead when you need the Cmd, e.g. when driving focus from inside
     * the host's own update() handler.
     *
     * Early-exit (same instance returned) when the key matches no field,
     * when the match is already focused, or when the target is skippable —
     * notes and separators do not take focus, and honouring the request
     * would strand an un-focusable cursor. Hidden groups and hidden fields
     * are deliberately NOT refused: this is the programmatic escape hatch,
     * the host asked for it by name (a focused hidden field still renders).
     */
    public function withFocus(string $key): self
    {
        [$next, ] = $this->focusField($key);
        return $next;
    }

    /**
     * Programmatic focus with the TEA-shaped return (E736 5.3, round 89).
     * Same rules as {@see withFocus()}; additionally surfaces the focused
     * field's focus Cmd (blink / preload) so the runtime can schedule it.
     *
     * @return array{0:self, 1:?\Closure}
     */
    public function focusField(string $key): array
    {
        $target = $this->locateField($key);
        if ($target === null) {
            return [$this, null];
        }
        [$group, $index] = $target;
        if ($group === $this->groupIndex && $index === $this->focusedIndex) {
            return [$this, null];
        }
        if ($this->fieldsByGroup[$group][$index]->skippable()) {
            return [$this, null];
        }

        $fieldsByGroup = $this->fieldsByGroup;
        // Blur the currently focused field (identity-safe on an empty page).
        $cur = $fieldsByGroup[$this->groupIndex];
        if (isset($cur[$this->focusedIndex])) {
            $cur[$this->focusedIndex] = $cur[$this->focusedIndex]->blur();
            $fieldsByGroup[$this->groupIndex] = $cur;
        }
        // Focus the target.
        $targetFields      = $fieldsByGroup[$group];
        [$focused, $cmd]   = $targetFields[$index]->focus();
        $targetFields[$index] = $focused;
        $fieldsByGroup[$group] = $targetFields;

        return [
            $this->mutate(fieldsByGroup: $fieldsByGroup, groupIndex: $group, focusedIndex: $index),
            $cmd,
        ];
    }

    /**
     * Locate a field by key across all groups, returning its
     * [group, field] indices or null when no field carries the key.
     * Index-shaped sibling of the key lookup in {@see renderErrorSummary()}.
     *
     * @return ?array{0:int, 1:int}
     */
    private function locateField(string $key): ?array
    {
        foreach ($this->fieldsByGroup as $group => $fields) {
            foreach ($fields as $index => $f) {
                if ($f->key() === $key) {
                    return [$group, $index];
                }
            }
        }
        return null;
    }

    /**
     * Validation errors keyed by field key. Hidden groups, hidden fields
     * and skippable fields (Note) are excluded. Empty when every visible field
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
                if ($f->skippable() || $f->isHidden($accumulated)) {
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
     * Run all field validators and return the full error map.
     * Unlike {@see errors()} which only reports per-field inline errors
     * (min/max violations, format errors), this also triggers any
     * attached validators so cross-field constraints are evaluated.
     * Returns `array<fieldKey, errorMessage>` for fields that fail.
     * Mirrors huh's `ValidateAll()`.
     *
     * E736-F2/2.1 (round 85): single progressive pass. The old shape
     * walked every group twice — once to build the FULL accumulated value
     * map, once to revalidate while testing group visibility against it —
     * i.e. O(2n) plus a convention mismatch: visibility was decided from
     * values collected across ALL groups (including not-yet-visited ones),
     * while {@see values()}/pagination decide it progressively. The pass
     * now accumulates as it goes (the house progressive convention) and
     * calls revalidate() inline; validators are self-contained per field,
     * so first-error ordering and the error map are unchanged.
     *
     * @return array<string, string>
     */
    public function validateAll(): array
    {
        $out         = [];
        $accumulated = [];
        foreach ($this->groups as $i => $group) {
            if ($group->isHidden($accumulated)) {
                continue;
            }
            foreach ($this->fieldsByGroup[$i] as $f) {
                if ($f->skippable() || $f->isHidden($accumulated)) {
                    continue;
                }
                $accumulated[$f->key()] = $f->value();
                // revalidate() forces validators/constraints to run even on
                // untouched fields; the recomputed field is query-only.
                $err = $f->revalidate()->getError();
                if ($err !== null && $err !== '') {
                    $out[$f->key()] = $err;
                }
            }
        }
        return $out;
    }

    /**
     * Async counterpart to {@see validateAll()} (plan 5.2, round 89 lane y3):
     * walks the SAME progressive pass (hidden groups skipped, skippable
     * fields skipped, values accumulated as it goes) but resolves through
     * the event loop instead of returning inline. Fields implementing
     * {@see AsyncValidatable} answer with a promise; every other field is
     * resolved immediately from its synchronous revalidate() — so a form of
     * purely synchronous fields settles in one microtask with EXACTLY the
     * map `validateAll()` returns (parity pinned in FormAsyncValidateTest).
     *
     * The library adds no timeout around the caller's promise (E646: a
     * hanging validator hangs only its own promise; bounding it is the
     * consumer's product decision, e.g. via candy-async AsyncOps).
     * A rejected field promise rejects the aggregate map: "could not
     * answer" never masquerades as "valid" or "invalid".
     *
     * Mirrors no upstream symbol: charmbracelet/huh validates synchronously
     * only; this is the SugarCraft async extension of `ValidateAll()`.
     *
     * @return PromiseInterface<array<string,string>>
     */
    public function validateAsync(): PromiseInterface
    {
        $promises    = [];
        $accumulated = [];
        foreach ($this->groups as $i => $group) {
            if ($group->isHidden($accumulated)) {
                continue;
            }
            foreach ($this->fieldsByGroup[$i] as $f) {
                if ($f->skippable() || $f->isHidden($accumulated)) {
                    continue;
                }
                $accumulated[$f->key()] = $f->value();
                if ($f instanceof AsyncValidatable) {
                    $promises[$f->key()] = $f->validateAsync();
                    continue;
                }
                // Synchronous field: identical recompute to validateAll(); a
                // throwing validator propagates synchronously, never swallowed.
                $err       = $f->revalidate()->getError();
                $promises[$f->key()] = \React\Promise\resolve($err !== null && $err !== '' ? $err : null);
            }
        }
        return \React\Promise\all($promises)->then(
            /** @param array<string,?string> $errors */
            static function (array $errors): array {
                $out = [];
                foreach ($errors as $key => $err) {
                    if ($err !== null && $err !== '') {
                        $out[$key] = $err;
                    }
                }
                return $out;
            }
        );
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
        $next = self::firstNonSkippable(
            $fields, $this->focusedIndex + $direction, $direction,
            $this->hiddenFieldsIn($this->groupIndex),
        );
        if ($next === null) {
            // Off the end of this group — try the next/prev group.
            return $this->advanceGroup($direction);
        }
        if ($next === $this->focusedIndex) {
            return [$this, null];
        }
        $newFields = $fields;
        if (isset($newFields[$this->focusedIndex])) {
            $newFields[$this->focusedIndex] = $newFields[$this->focusedIndex]->blur();
        }
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
        $nextGroup = $this->firstNavigableGroup($this->groupIndex + $direction, $direction);
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
        $hidden    = $this->hiddenFieldsIn($nextGroup);
        $first     = self::firstNonSkippable($newFields, 0, +1, $hidden)
            ?? self::restingIndex($newFields, $hidden);
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
     * The values view every hide predicate for group `$group` — the group's
     * own {@see Group::isHidden()} and its fields' {@see Field::isHidden()}
     * seeds — is evaluated against: the visible fields of every visible
     * group BEFORE `$group`, exactly the prefix {@see values()} accumulates
     * when it reaches that group.
     *
     * It is deliberately per-candidate rather than "everything before the
     * current group": navigation used to hand every candidate the raw map up
     * to the page being left, so a field hidden by its own hide func still
     * leaked into a later group's predicate, the page being left was
     * invisible to the very next group's predicate, and stepping backwards a
     * candidate saw values from its own future — each time disagreeing with
     * values() about which page exists. Upstream huh has one shared state
     * that navigation and rendering both evaluate a group's hide func
     * against; this is that single view.
     *
     * @return array<string,mixed>
     */
    private function collectValues(int $beforeGroup): array
    {
        return $this->valueWalk(stopBeforeGroup: $beforeGroup);
    }

    /**
     * Whether group `$group`'s own hide predicate fires against its
     * {@see collectValues()} view — the same verdict {@see values()},
     * {@see errors()} and the validators reach for that group.
     */
    private function isGroupHidden(int $group): bool
    {
        return $this->groups[$group]->isHidden($this->collectValues($group));
    }

    /**
     * First group from `$start` (stepping `$step`) that navigation may land
     * on: its own {@see Group::isHidden()} does not fire (see
     * {@see isGroupHidden()}), and its field-level hiding has not emptied
     * it. A group whose every field is hidden has nothing to show or focus,
     * so it is passed over exactly like a hidden group — otherwise Enter
     * would strand on an empty page (or, as the last group, never submit).
     * A group declared with no fields at all stays navigable: that is a
     * deliberate title-only page.
     */
    private function firstNavigableGroup(int $start, int $step): ?int
    {
        $n = count($this->groups);
        for ($i = $start; $i >= 0 && $i < $n; $i += $step) {
            if ($this->isGroupHidden($i)) {
                continue;
            }
            $fields = $this->fieldsByGroup[$i] ?? [];
            if ($fields !== [] && count($this->hiddenFieldsIn($i)) === count($fields)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /**
     * Where focus rests in a group that has no focusable field: the first
     * field that is not hidden (a passive note, which renders), else -1 —
     * "nothing focused" — so a hidden field never becomes the focused index,
     * which would render it and route keystrokes into it. A field-less group
     * keeps index 0, which addresses nothing either.
     *
     * @param list<Field>     $fields
     * @param array<int,true> $hidden
     */
    private static function restingIndex(array $fields, array $hidden): int
    {
        foreach ($fields as $i => $_) {
            if (!isset($hidden[$i])) {
                return $i;
            }
        }
        return $fields === [] ? 0 : -1;
    }

    /**
     * @param list<Field>     $fields
     * @param int             $start   starting index (may be out of range)
     * @param int             $step    +1 or -1
     * @param array<int,true> $hidden  indices to pass over (see {@see hiddenFieldMask()})
     */
    private static function firstNonSkippable(array $fields, int $start, int $step, array $hidden = []): ?int
    {
        $n = count($fields);
        for ($i = $start; $i >= 0 && $i < $n; $i += $step) {
            if (!$fields[$i]->skippable() && !isset($hidden[$i])) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Indices of the fields in `$fields` whose {@see Field::isHidden()} fires,
     * walking progressively: each predicate sees `$accumulated` plus the
     * values of the visible fields before it — never a hidden field's value.
     *
     * @param list<Field>         $fields
     * @param array<string,mixed> $accumulated values of the visible fields preceding this group
     * @return array<int,true>
     */
    private static function hiddenFieldMask(array $fields, array $accumulated): array
    {
        $hidden = [];
        foreach ($fields as $i => $f) {
            // A skippable field (a passive note) can be hidden too — it must
            // then not render — but it never contributes a value.
            if ($f->isHidden($accumulated)) {
                $hidden[$i] = true;
                continue;
            }
            if (!$f->skippable()) {
                $accumulated[$f->key()] = $f->value();
            }
        }
        return $hidden;
    }

    /**
     * {@see hiddenFieldMask()} for group `$group`, seeded with the visible
     * values of every earlier group — the same map {@see values()} builds.
     *
     * @return array<int,true>
     */
    private function hiddenFieldsIn(int $group): array
    {
        return self::hiddenFieldMask(
            $this->fieldsByGroup[$group] ?? [],
            $this->collectValues($group),
        );
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
        ?bool $errorSummary = null,
        ?int $width = null,
        ?int $height = null,
        ?int $timeoutMs = null,
        ?KeyMap $keyMap = null, bool $keyMapSet = false,
        ?array $constraints = null, bool $constraintsSet = false,
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
            errorSummary:   $errorSummary   ?? $this->errorSummary,
            width:          $width          ?? $this->width,
            height:         $height         ?? $this->height,
            timeoutMs:      $timeoutMs      ?? $this->timeoutMs,
            keyMap:         $keyMapSet      ? $keyMap : $this->keyMap,
            constraints:    $constraintsSet ? $constraints : $this->constraints,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
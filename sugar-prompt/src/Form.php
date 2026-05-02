<?php

declare(strict_types=1);

namespace CandyCore\Prompt;

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

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

    public function init(): ?\Closure
    {
        return $this->initCmd;
    }

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

    public function nextGroup(): self
    {
        [$next, ] = $this->advanceGroup(+1);
        return $next;
    }

    public function prevGroup(): self
    {
        [$next, ] = $this->advanceGroup(-1);
        return $next;
    }

    /** Index of the active group, 0-based. */
    public function activeGroupIndex(): int { return $this->groupIndex; }

    /** Total number of groups (including hidden ones). */
    public function totalGroups(): int { return count($this->groups); }

    public function activeGroup(): Group
    {
        return $this->groups[$this->groupIndex];
    }

    /** Field list for the active group. */
    public function activeFields(): array
    {
        return $this->fieldsByGroup[$this->groupIndex];
    }

    /**
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
            // Abort.
            if ($msg->type === KeyType::Escape
                || ($msg->ctrl && $msg->rune === 'c')) {
                return [$this->mutate(aborted: true), Cmd::quit()];
            }

            // Navigation: Tab / Shift-Tab / Down / Up.
            if (!$msg->ctrl) {
                if ($msg->type === KeyType::Tab && !$msg->alt) {
                    return $this->advance(+1);
                }
                if ($msg->type === KeyType::Down) {
                    return $this->advance(+1);
                }
                if ($msg->type === KeyType::Up) {
                    return $this->advance(-1);
                }
            }
            if ($msg->type === KeyType::Tab && $msg->alt) {
                return $this->advance(-1);
            }

            // Submission: Enter on the last interactive field of the
            // last visible group.
            if ($msg->type === KeyType::Enter) {
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

    public function view(): string
    {
        if ($this->accessible) {
            return $this->accessibleView();
        }
        $group = $this->groups[$this->groupIndex];
        $blocks = [];
        if ($group->title !== '') {
            $blocks[] = $this->theme->title->render($group->title);
        }
        if ($group->description !== '') {
            $blocks[] = $this->theme->description->render($group->description);
        }
        foreach ($this->fieldsByGroup[$this->groupIndex] as $f) {
            $blocks[] = $f->view();
        }
        if (count($this->groups) > 1) {
            $blocks[] = $this->theme->help->render(
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

    /** @return array<string, mixed> */
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

    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }
    public function focusedField(): ?Field
    {
        return $this->fieldsByGroup[$this->groupIndex][$this->focusedIndex] ?? null;
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
        );
    }
}

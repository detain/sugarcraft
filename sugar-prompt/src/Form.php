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
    /** @param list<Field> $fields */
    private function __construct(
        public readonly array $fields,
        public readonly int $focusedIndex,
        public readonly bool $submitted,
        public readonly bool $aborted,
    ) {}

    public static function new(Field ...$fields): self
    {
        $list  = array_values($fields);
        $first = self::firstNonSkippable($list, 0, +1);
        if ($first !== null) {
            [$focused, $_] = $list[$first]->focus();
            $list[$first] = $focused;
        }
        return new self(
            fields:       $list,
            focusedIndex: $first ?? 0,
            submitted:    false,
            aborted:      false,
        );
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($this->submitted || $this->aborted) {
            return [$this, null];
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

            // Submission: Enter on the last interactive field.
            if ($msg->type === KeyType::Enter) {
                $last = self::firstNonSkippable($this->fields, count($this->fields) - 1, -1);
                if ($last !== null && $this->focusedIndex === $last) {
                    return [$this->mutate(submitted: true), Cmd::quit()];
                }
                // Otherwise advance.
                return $this->advance(+1);
            }
        }

        // Forward to the focused field.
        $idx = $this->focusedIndex;
        if (!isset($this->fields[$idx])) {
            return [$this, null];
        }
        [$updated, $cmd] = $this->fields[$idx]->update($msg);
        $newFields = $this->fields;
        $newFields[$idx] = $updated;
        return [$this->mutate(fields: $newFields), $cmd];
    }

    public function view(): string
    {
        $blocks = [];
        foreach ($this->fields as $f) {
            $blocks[] = $f->view();
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

    /** @return array<string, mixed> */
    public function values(): array
    {
        $out = [];
        foreach ($this->fields as $f) {
            if ($f->skippable()) {
                continue;
            }
            $out[$f->key()] = $f->value();
        }
        return $out;
    }

    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }
    public function focusedField(): ?Field
    {
        return $this->fields[$this->focusedIndex] ?? null;
    }

    /**
     * @return array{0:self, 1:?\Closure}
     */
    private function advance(int $direction): array
    {
        $next = self::firstNonSkippable($this->fields, $this->focusedIndex + $direction, $direction);
        if ($next === null || $next === $this->focusedIndex) {
            return [$this, null];
        }
        $newFields = $this->fields;
        $newFields[$this->focusedIndex] = $newFields[$this->focusedIndex]->blur();
        [$focused, $cmd] = $newFields[$next]->focus();
        $newFields[$next] = $focused;
        return [$this->mutate(fields: $newFields, focusedIndex: $next), $cmd];
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

    /** @param list<Field>|null $fields */
    private function mutate(
        ?array $fields = null,
        ?int $focusedIndex = null,
        ?bool $submitted = null,
        ?bool $aborted = null,
    ): self {
        return new self(
            fields:       $fields       ?? $this->fields,
            focusedIndex: $focusedIndex ?? $this->focusedIndex,
            submitted:    $submitted    ?? $this->submitted,
            aborted:      $aborted      ?? $this->aborted,
        );
    }
}

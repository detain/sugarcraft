<?php

declare(strict_types=1);

namespace SugarCraft\Sql\App;

/**
 * SQL-O23 (once-030): the per-parameter prompt line's session slice.
 *
 * Opened by the run path when the editor text carries placeholders: the
 * statement travels to the server BOUND (prepare/execute), never hand-
 * concatenated. One prompt at a time — `index` walks `labels`, `buffer`
 * holds the typed-but-unsubmitted text, `values` the committed legs
 * (null = the SQL NULL an empty submit means). Small value object, dated
 * exemption from mutate()-style carriers.
 */
final class ParamPrompt
{
    /**
     * @param list<string>            $labels display label per placeholder
     * @param list<string|null>       $values committed values so far (null = NULL)
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $labels,
        public readonly int $index = 0,
        public readonly string $buffer = '',
        public readonly array $values = [],
    ) {}

    public function withBuffer(string $buffer): self
    {
        return new self($this->sql, $this->labels, $this->index, $buffer, $this->values);
    }

    public function withStepped(array $values): self
    {
        return new self($this->sql, $this->labels, $this->index + 1, '', $values);
    }

    /**
     * The PDO-shaped bind array for the completed walk: a positional list
     * when every label is a `#n` slot, an association when the statement
     * spoke `:name` placeholders (labels are the bare names then).
     *
     * @param list<string|null> $values
     * @return array<array-key, string|null>
     */
    public function bindForm(array $values): array
    {
        if ($this->labels !== [] && !str_starts_with($this->labels[0], '#')) {
            $bound = [];
            foreach ($this->labels as $pos => $name) {
                // Labels are unique upstream (a repeated :name prompts once);
                // the offset guard is belt-and-braces, never a silent drop.
                $bound[$name] = $values[$pos] ?? null;
            }

            return $bound;
        }

        return $values;
    }
}

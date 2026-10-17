<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm;

/**
 * The ordered row model behind the Advanced tab (crush-media W3.8, plan
 * §4.2/§5.5): `override_settings` is a free dictionary of ANY core or
 * extension option applied for one request, and the server re-applies its
 * saved settings afterwards — so the client must neither clamp nor validate
 * beyond the TYPE the user declared (uni_pc_order: 50, wildly out of the
 * normal 1-50 span, round-trips untouched; the server is the validator).
 *
 * Each row is `{key, type, text}`: the text is exactly what the human typed
 * and it never mutates under the model's hands — parsing happens on demand
 * ({@see parse()}) so a half-finished row can live on screen while
 * collect() simply refuses to serialize it. The json type is the raw
 * escape hatch: passed through `json_decode` on commit with a loud row
 * error on failure, never a silent drop.
 *
 * The empty editor emits NO key at all (DTO unset law — an empty
 * `override_settings` object would still ride the wire); rows with a key but
 * no value are skipped the same way, mirroring the hidden-input behaviour
 * where an unset option means "keep the server's".
 */
final class OverrideRows
{
    /** Wire spellings of the value types, in dropdown order. */
    public const TYPES = ['int', 'float', 'bool', 'string', 'json'];

    /**
     * @param list<array{key: string, type: string, text: string}> $rows
     */
    private function __construct(private readonly array $rows)
    {
    }

    /** @return self no rows — the empty editor that must emit nothing */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Seed rows from a draft's wire dictionary, inferring each value's type.
     *
     * @param array<string, mixed> $settings
     */
    public static function fromAssoc(array $settings): self
    {
        $rows = [];
        foreach ($settings as $key => $value) {
            $type = self::inferType($value);
            $rows[] = ['key' => (string) $key, 'type' => $type, 'text' => self::stringify($value)];
        }

        return new self($rows);
    }

    /** @return list<array{key: string, type: string, text: string}> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /** @return array{key: string, type: string, text: string} */
    public function row(int $index): array
    {
        $row = $this->rows[$index] ?? null;
        if ($row === null) {
            throw new \OutOfRangeException('no override row ' . $index);
        }

        return $row;
    }

    /** @return self */
    public function appended(): self
    {
        return new self([...$this->rows, ['key' => '', 'type' => 'string', 'text' => '']]);
    }

    public function dropped(int $index): self
    {
        $this->row($index);
        $rows = $this->rows;
        array_splice($rows, $index, 1);

        return new self($rows);
    }

    /** Swap a row with its neighbour; out-of-range moves are no-ops. */
    public function moved(int $index, int $delta): self
    {
        $target = $index + $delta;
        if (!isset($this->rows[$index], $this->rows[$target])) {
            return $this;
        }

        $rows = $this->rows;
        [$rows[$index], $rows[$target]] = [$rows[$target], $rows[$index]];

        return new self($rows);
    }

    public function withKey(int $index, string $key): self
    {
        return $this->patch($index, ['key' => $key]);
    }

    public function withType(int $index, string $type): self
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('unknown override type ' . $type);
        }

        return $this->patch($index, ['type' => $type]);
    }

    public function withText(int $index, string $text): self
    {
        return $this->patch($index, ['text' => $text]);
    }

    /**
     * Parse one text into its declared type, failing loud with a short
     * human reason (the row error the UI shows). The bool trap this pins:
     * the STRING 'false' must parse to FALSE — plain PHP truthiness would
     * post true, and the server would obey it.
     */
    public static function parse(string $type, string $text): mixed
    {
        return match ($type) {
            'int' => filter_var($text, FILTER_VALIDATE_INT) === false
                ? throw new \InvalidArgumentException('not a whole number')
                : (int) $text,
            'float' => is_numeric($text)
                ? $text + 0.0
                : throw new \InvalidArgumentException('not a number'),
            'bool' => self::parseBool($text),
            'json' => self::parseJson($text),
            'string' => $text,
            default => throw new \InvalidArgumentException('unknown override type ' . $type),
        };
    }

    /**
     * The wire dictionary the rows contribute: unparseable rows are skipped
     * (their text stays on screen LOUD — the section renders the reason) so
     * a half-typed row never poisons a saveable one, and valueless or
     * keyless rows contribute nothing.
     *
     * @return array<string, mixed>
     */
    public function toAssoc(): array
    {
        $settings = [];
        foreach ($this->rows as $row) {
            if ($row['key'] === '' || $row['text'] === '') {
                continue;
            }

            try {
                $settings[$row['key']] = self::parse($row['type'], $row['text']);
            } catch (\InvalidArgumentException) {
                // Row-level error visibility is the section's job; the fold
                // simply refuses to serialize what the human has not fixed.
                continue;
            }
        }

        return $settings;
    }

    /** The row's current parse failure message, or null when it is clean. */
    public static function errorText(string $type, string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        try {
            self::parse($type, $text);
        } catch (\InvalidArgumentException $reason) {
            return $reason->getMessage();
        }

        return null;
    }

    /** Type the options map suggests for a value (arrays/objects go to json). */
    public static function inferType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'int',
            is_bool($value) => 'bool',
            is_float($value) => 'float',
            is_string($value) => 'string',
            default => 'json',
        };
    }

    /** The editor text for a wire value (inverse of parse, type-for-type). */
    public static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /** @return self */
    private function patch(int $index, array $changes): self
    {
        $rows = $this->rows;
        $this->row($index);
        $rows[$index] = [...$rows[$index], ...$changes];

        return new self($rows);
    }

    private static function parseBool(string $text): bool
    {
        $parsed = filter_var($text, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? throw new \InvalidArgumentException('not a yes/no value');
    }

    private static function parseJson(string $text): mixed
    {
        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('not valid JSON');
        }
    }
}

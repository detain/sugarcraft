<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;

/**
 * Renders the output of SQLite's `EXPLAIN QUERY PLAN` as a
 * readable ANSI tree with colour-coded operation types.
 *
 * SQLite's raw output is a flat list of (detail) lines. This class
 * parses the tree-structure implicit in the `|`/`--` prefix and
 * applies:
 *
 *   - indentation for nesting depth
 *   - colour by op type (SEARCH=cyan, SCAN=yellow, SUBQUERY=magenta, etc.)
 *   - bold headers / labels
 *
 * Immutable — factory methods return new instances.
 */
final class ExplainView
{
    /** Colour tokens used to classify each detail line. */
    private const TAG_SEARCH  = 'SEARCH';
    private const TAG_SCAN    = 'SCAN';
    private const TAG_USING   = 'USING';
    private const TAG_JOIN    = 'JOIN';
    private const TAG_SUBQUERY = 'SUBQUERY';
    private const TAG_COMPOUND = 'COMPOUND';

    /**
     * @readonly
     * @var list<ExplainRow>
     */
    public readonly array $rows;

    /** Raw PDO fetch-all result from `EXPLAIN QUERY PLAN`. */
    private readonly array $raw;

    /**
     * @param list<array{.detail:string}> $raw
     */
    public function __construct(array $raw)
    {
        $this->raw = $raw;
        $this->rows = $this->parse($raw);
    }

    /**
     * Run `EXPLAIN QUERY PLAN` against $db and return a new ExplainView.
     */
    public static function run(Database $db, string $sql): self
    {
        $stmt = $db->pdo->prepare("EXPLAIN QUERY PLAN {$sql}");
        $stmt->execute();
        /** @var list<array{ detail:string }> */
        $raw = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return new self($raw);
    }

    /**
     * Render the plan as an ANSI string for TUI display.
     */
    public function render(): string
    {
        if ($this->rows === []) {
            return Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('(no query plan — query returned no rows)');
        }

        $lines = [];
        foreach ($this->rows as $row) {
            $lines[] = $this->renderRow($row);
        }

        $header = Style::new()->bold()->foreground(Color::hex('#fde68a'))
            ->render(' QUERY PLAN ');
        $body   = implode("\n", $lines);

        return $header . "\n" . $body;
    }

    /**
     * Return a JSON-serialisable array of the plan rows.
     *
     * @return list<array{depth:int,tag:string,detail:string,indent:string}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn(ExplainRow $r): array => [
                'depth'   => $r->depth,
                'tag'     => $r->tag,
                'detail'  => $r->detail,
                'indent'  => $r->indent,
            ],
            $this->rows,
        );
    }

    private function renderRow(ExplainRow $row): string
    {
        $indent = $row->indent;
        $label  = Style::new()->bold()->foreground($this->tagColor($row->tag))
            ->render($row->tag);
        $detail = Style::new()->foreground(Color::hex('#e2e8f0'))
            ->render($row->detail);

        return "{$indent}{$label}  {$detail}";
    }

    private function tagColor(string $tag): Color
    {
        return match ($tag) {
            self::TAG_SEARCH    => Color::hex('#7dd3fc'),  // cyan
            self::TAG_SCAN      => Color::hex('#fde68a'),  // yellow
            self::TAG_USING     => Color::hex('#6ee7b7'),  // green
            self::TAG_JOIN      => Color::hex('#c084fc'),  // purple
            self::TAG_SUBQUERY  => Color::hex('#f9a8d4'),  // pink
            self::TAG_COMPOUND  => Color::hex('#fb923c'),  // orange
            default             => Color::hex('#e2e8f0'),  // light gray
        };
    }

    /**
     * @param list<array{detail:string}> $raw
     * @return list<ExplainRow>
     */
    private function parse(array $raw): array
    {
        $rows = [];
        foreach ($raw as $index => $row) {
            if (!isset($row['detail']) || $row['detail'] === '') {
                continue;
            }
            $detail = (string) $row['detail'];
            $depth  = $this->depthFromDetail($detail);
            $tag    = $this->tagFromDetail($detail);
            $indent = $this->indent($depth);

            $rows[] = new ExplainRow(
                detail: $detail,
                depth:  $depth,
                tag:    $tag,
                indent: $indent,
                line:   $index + 1,
            );
        }
        return $rows;
    }

    /**
     * Extract tree depth from SQLite detail line.
     *
     * SQLite uses |--  (depth 1), |----  (depth 2) etc.
     * and \`--  for the last child at that depth.
     */
    private function depthFromDetail(string $detail): int
    {
        // Count the number of pipe+hyphen or backtick+hyphen segments.
        // Leading whitespace may precede the tree characters.
        if (preg_match('/^\s*(?:\|--|\`--)/', $detail, $m)) {
            $prefix = $m[0];
            // Each "|--" or "`--" pair counts as depth 1.
            return (int) (mb_strlen($prefix) / 2);
        }
        return 0;
    }

    /**
     * Classify the operation type from the detail text.
     *
     * More specific tags are checked first to avoid early matches
     * on general keywords that appear in compound descriptions.
     */
    private function tagFromDetail(string $detail): string
    {
        $lower = mb_strtolower($detail);

        if (mb_stripos($lower, 'compound') !== false || mb_stripos($lower, 'union') !== false) {
            return self::TAG_COMPOUND;
        }
        if (mb_stripos($lower, 'subquery') !== false || mb_stripos($lower, 'correlated') !== false) {
            return self::TAG_SUBQUERY;
        }
        if (mb_stripos($lower, 'join') !== false) {
            return self::TAG_JOIN;
        }
        if (mb_stripos($lower, 'search') !== false) {
            return self::TAG_SEARCH;
        }
        if (mb_stripos($lower, 'using') !== false) {
            return self::TAG_USING;
        }
        if (mb_stripos($lower, 'scan') !== false) {
            return self::TAG_SCAN;
        }

        return self::TAG_SCAN;
    }

    private function indent(int $depth): string
    {
        if ($depth === 0) {
            return '';
        }
        // Two spaces per depth level.
        return str_repeat('  ', $depth);
    }
}

/**
 * A single parsed row from `EXPLAIN QUERY PLAN`.
 *
 * @readonly
 */
final class ExplainRow
{
    public function __construct(
        public readonly string $detail,
        public readonly int $depth,
        public readonly string $tag,
        public readonly string $indent,
        public readonly int $line,
    ) {}
}

<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Core\Util\Color;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Explain\ExplainProviderInterface;
use SugarCraft\Query\Explain\SqliteExplainProvider;
use SugarCraft\Query\Explain\MysqlExplainProvider;
use SugarCraft\Query\Explain\PostgresExplainProvider;
use SugarCraft\Sprinkles\Style;

/**
 * Renders the output of database EXPLAIN commands as a
 * readable ANSI tree with colour-coded operation types.
 *
 * Uses driver-specific ExplainProvider implementations to execute
 * the appropriate EXPLAIN syntax for each database driver.
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

    /** Raw PDO fetch-all result from EXPLAIN. */
    private readonly array $raw;

    /** @var Flavor Database flavor for driver detection */
    private readonly Flavor $flavor;

    /** @var ExplainProviderInterface The active explain provider */
    private readonly ExplainProviderInterface $provider;

    /**
     * @param list<array{.detail:string}> $raw
     */
    public function __construct(array $raw, Flavor $flavor, ExplainProviderInterface $provider)
    {
        $this->raw = $raw;
        $this->flavor = $flavor;
        $this->provider = $provider;
        $this->rows = $this->parse($raw);
    }

    /**
     * Run EXPLAIN against $db and return a new ExplainView.
     */
    public static function run(DatabaseInterface $db, string $sql): self
    {
        $flavor = Flavor::detectFromVersionString($db->serverVersion());
        $provider = self::createProvider($db, $flavor);
        $raw = $provider->explain($sql);

        return new self($raw, $flavor, $provider);
    }

    /**
     * Create the appropriate explain provider based on database flavor.
     *
     * Uses strategy pattern to select driver-specific implementation.
     */
    private static function createProvider(DatabaseInterface $db, Flavor $flavor): ExplainProviderInterface
    {
        return match ($flavor) {
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona => new MysqlExplainProvider($db),
            Flavor::Postgres => new PostgresExplainProvider($db),
            Flavor::Sqlite => new SqliteExplainProvider($db),
        };
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
                'tag'      => $r->tag,
                'detail'   => $r->detail,
                'indent'   => $r->indent,
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
            self::TAG_JOIN     => Color::hex('#c084fc'),  // purple
            self::TAG_SUBQUERY => Color::hex('#f9a8d4'),  // pink
            self::TAG_COMPOUND => Color::hex('#fb923c'),  // orange
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
     * Extract tree depth from detail line.
     *
     * For SQLite: |--  (depth 1), |----  (depth 2) etc.
     * and `--  for the last child at that depth.
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
 * A single parsed row from EXPLAIN output.
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

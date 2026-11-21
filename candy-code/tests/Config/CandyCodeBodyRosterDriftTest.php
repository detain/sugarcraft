<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Tests\Support\PinsEnglishLocaleTrait;
use SugarCraft\Code\Tools\Catalog\CatalogEntry;
use SugarCraft\Code\Tools\Catalog\ToolCatalog;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;

/**
 * The public candy-code page carries three hand-typed rosters: the slash
 * command table (76 rows) and the two tool tables (38 unconditional rows, 6
 * conditional). Nothing regenerates them, so a spec file added, removed or
 * reworded, or a tool added or moved to another permission class, would leave
 * the page behind without a red. This class pins every row to its source and
 * every quoted total to the same count.
 *
 * @internal
 */
final class CandyCodeBodyRosterDriftTest extends TestCase
{
    use PinsEnglishLocaleTrait;

    private const PUBLIC_PAGE = __DIR__ . '/../../../docs/_data/candy-code.body.html';

    /**
     * The page's words for each permission class. ToolPermissionClass::label()
     * says "ask" for the mode-default class; the page's Permission class column
     * says "mode default", as its permissions table does.
     */
    private const PERMISSION_WORDS = [
        'read' => 'read-only',
        'write' => 'write-capable',
        'ask' => 'mode default',
        'no-ask' => 'no-ask',
    ];

    public function testTheCommandTableHasOneRowPerRegistrySpecInRegistryOrder(): void
    {
        $rows = self::tableRows(self::section('commands', 'tools'), 0);
        $specs = CommandRegistry::all();

        self::assertCount(\count($specs), $rows, 'the command table row count differs from the registry');

        foreach ($specs as $i => $spec) {
            [$name, $takes, $description] = $rows[$i];
            self::assertSame('/' . $spec->name, $name, "command row {$i} names the wrong command");
            self::assertSame($spec->argumentHint ?? '—', $takes, "the /{$spec->name} row's Takes column differs from its spec");
            // The page renders the spec's Markdown code spans as plain text.
            self::assertSame(
                str_replace('`', '', $spec->description),
                $description,
                "the /{$spec->name} row's description differs from its spec",
            );
        }
    }

    public function testTheQuotedCommandTotalIsTheRegistryCount(): void
    {
        self::assertSame(
            1,
            preg_match('#<p[^>]*>(\d+) slash commands#', self::section('commands', 'tools'), $quoted),
            'the commands section no longer quotes a slash-command total',
        );
        self::assertSame(\count(CommandRegistry::all()), (int) $quoted[1]);
    }

    public function testTheUnconditionalToolTableNamesEveryPlainLaunchToolWithItsClassAndPermission(): void
    {
        $expected = array_map(self::toolLine(...), ToolCatalog::builtUnconditionally());

        self::assertSame($expected, self::toolRows(0));
    }

    public function testTheConditionalToolTableNamesEveryCatalogToolThatIsNotInAPlainLaunch(): void
    {
        $plain = array_map(static fn (CatalogEntry $e): string => $e->name, ToolCatalog::builtUnconditionally());
        $conditional = array_values(array_filter(
            ToolCatalog::entries(),
            static fn (CatalogEntry $e): bool => !\in_array($e->name, $plain, true),
        ));

        self::assertSame(array_map(self::toolLine(...), $conditional), self::toolRows(1));
    }

    public function testTheQuotedPlainLaunchToolTotalIsTheCatalogCount(): void
    {
        self::assertSame(
            1,
            preg_match('#<strong>(\d+) built-in tools</strong>#', self::section('tools', 'sessions'), $quoted),
            'the tools section no longer quotes a plain-launch tool total',
        );
        self::assertSame(\count(ToolCatalog::builtUnconditionally()), (int) $quoted[1]);
    }

    /**
     * The "Takes" and description cells as [command, takes, description] for
     * each data row of one table, with HTML and entities stripped.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function tableRows(string $section, int $index): array
    {
        preg_match_all('#<table class="api-table">(.*?)</table>#s', $section, $tables);
        self::assertArrayHasKey($index, $tables[1], 'the section no longer carries the table this leg reads');
        $table = $tables[1][$index];

        preg_match_all('#<tr\b[^>]*>(.*?)</tr>#s', $table, $all);
        $dataRows = array_filter($all[1], static fn (string $inner): bool => !str_contains($inner, '<th'));

        preg_match_all('#<tr><td><code>(.*?)</code></td><td>(.*?)</td><td>(.*?)</td></tr>#s', $table, $found, PREG_SET_ORDER);
        self::assertCount(
            \count($dataRows),
            $found,
            'a data row of the table changed shape and this scan cannot read it — re-pin the row pattern',
        );

        return array_map(
            static fn (array $row): array => [self::plain($row[1]), self::plain($row[2]), self::plain($row[3])],
            $found,
        );
    }

    /**
     * The tool table at the given index as "name|file|permission" lines.
     *
     * @return list<string>
     */
    private static function toolRows(int $index): array
    {
        preg_match_all('#<table class="api-table">(.*?)</table>#s', self::section('tools', 'sessions'), $tables);
        self::assertArrayHasKey($index, $tables[1], 'the tools section no longer carries the table this leg reads');
        $table = $tables[1][$index];

        preg_match_all('#<tr\b[^>]*>(.*?)</tr>#s', $table, $all);
        $dataRows = array_filter($all[1], static fn (string $inner): bool => !str_contains($inner, '<th'));

        preg_match_all(
            '#<tr><td><code>(.*?)</code></td><td><code>(.*?)</code></td><td>(.*?)</td><td>.*?</td></tr>#s',
            $table,
            $found,
            PREG_SET_ORDER,
        );
        self::assertCount(
            \count($dataRows),
            $found,
            'a tool row changed shape and this scan cannot read it — re-pin the row pattern',
        );

        return array_map(
            static fn (array $row): string => self::plain($row[1]) . '|' . self::plain($row[2]) . '|' . self::plain($row[3]),
            $found,
        );
    }

    private static function toolLine(CatalogEntry $entry): string
    {
        return $entry->name . '|' . $entry->fileName() . '|' . self::PERMISSION_WORDS[$entry->permission->value];
    }

    /**
     * The text between the heading with id $from and the heading with id $to.
     */
    private static function section(string $from, string $to): string
    {
        $body = self::body();
        $start = strpos($body, '<h3 id="' . $from . '"');
        self::assertNotFalse($start, "the candy-code body no longer has a <h3 id=\"{$from}\"> heading");
        $end = strpos($body, '<h3 id="' . $to . '"', $start);
        self::assertNotFalse($end, "the candy-code body no longer has a <h3 id=\"{$to}\"> heading");

        return substr($body, $start, $end - $start);
    }

    private static function plain(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function body(): string
    {
        self::assertFileExists(self::PUBLIC_PAGE, 'docs/_data/candy-code.body.html is missing from the repository');

        return (string) file_get_contents(self::PUBLIC_PAGE);
    }
}

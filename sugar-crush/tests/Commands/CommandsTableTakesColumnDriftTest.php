<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Tests\Support\PinsEnglishLocaleTrait;

/**
 * docs/COMMANDS.md says the built-in table's *Takes* column "is the row's own
 * `argumentHint`, verbatim where it has one and `—` where it does not", and
 * its last column is the row's `description`. The surface-table guard in
 * DocFigureProseDriftTest pins names and the S/CP ticks only, so a row whose
 * hint changed (or, as with `/rewind`, never existed for an argument the
 * handler accepts) drifted unseen (audit 15b-25). This re-derives both cells
 * from the live {@see CommandRegistry::all()}.
 *
 * @internal
 */
final class CommandsTableTakesColumnDriftTest extends TestCase
{
    // The registries answer through Lang::t(); the pages are English (audit 15b-14).
    use PinsEnglishLocaleTrait;

    /** @return array<string, array{takes: string, says: string}> */
    private static function documentedRows(): array
    {
        $raw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/COMMANDS.md');
        // Only the built-in table: other tables on the page name commands too.
        $header = '| Command | S | CP | Takes | What the row says |';
        $start = strpos($raw, $header);
        self::assertNotFalse($start, 'the built-in table header changed — this guard parses its five columns');
        $end = strpos($raw, "\n\n", $start);
        $raw = substr($raw, $start, $end === false ? null : $end - $start);
        // A table row: | `/name` | S | CP | Takes | What the row says |.
        // Cells may hold `\|`, so split on a pipe that is not escaped.
        preg_match_all('/^\| `\/([a-z-]+)` \|.*$/m', $raw, $lines, PREG_SET_ORDER);
        $rows = [];
        foreach ($lines as $line) {
            $cells = array_map('trim', preg_split('/(?<!\\\\)\|/', trim($line[0], " \t")) ?: []);
            // Leading and trailing pipes leave an empty first and last cell.
            $cells = array_slice($cells, 1, -1);
            self::assertCount(5, $cells, "the /{$line[1]} row no longer has the five documented columns");
            $rows[$line[1]] = [
                'takes' => str_replace('\\|', '|', $cells[3]),
                'says' => str_replace('\\|', '|', $cells[4]),
            ];
        }

        return $rows;
    }

    public function testEveryTakesCellIsTheRowsOwnArgumentHint(): void
    {
        $rows = self::documentedRows();
        self::assertNotEmpty($rows, 'the built-in table shape changed — this guard parses its rows');

        foreach (CommandRegistry::all() as $spec) {
            self::assertArrayHasKey($spec->name, $rows, "/{$spec->name} has no row in the built-in table");
            $expected = $spec->argumentHint === null ? '—' : '`' . $spec->argumentHint . '`';
            self::assertSame(
                $expected,
                $rows[$spec->name]['takes'],
                "the Takes cell on /{$spec->name} is not its registry argumentHint — the page says the column is that hint verbatim",
            );
        }
    }

    public function testEveryDescriptionCellIsTheRowsOwnDescription(): void
    {
        $rows = self::documentedRows();

        foreach (CommandRegistry::all() as $spec) {
            self::assertSame(
                $spec->description,
                $rows[$spec->name]['says'] ?? null,
                "the last cell on /{$spec->name} is not its registry description",
            );
        }
    }

    public function testRewindAdvertisesTheOptionalStepCountItsHandlerAccepts(): void
    {
        $rewind = null;
        foreach (CommandRegistry::all() as $spec) {
            if ($spec->name === 'rewind') {
                $rewind = $spec;
            }
        }

        self::assertNotNull($rewind);
        // Item 3.A-2 added the scope word; the usage line names both halves.
        self::assertSame(
            '[n] [--chat|--files|--both]',
            $rewind->argumentHint,
            '/rewind takes an optional checkpoint count (`/rewind 3`, `/rewind:3`) and an optional scope word, and its usage line says `/rewind [n] [--chat|--files|--both]` — the hint the popup and the table show must say so too',
        );
    }
}

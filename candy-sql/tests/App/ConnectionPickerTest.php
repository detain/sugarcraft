<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sql\App\ConnectionPicker;

/**
 * SQL-O02b (candy-sql-once-002): the launch picker as a plain Model, driven
 * through update() exactly like the page tests drive pages. The Program run
 * itself needs a live terminal — the bin wiring (tty gate, run, pickedName
 * read-back) is source-pinned in BinWiringTest instead.
 */
final class ConnectionPickerTest extends TestCase
{
    /** @var array<string, array<string, string>> */
    private array $entries = [
        'dev' => ['driver' => 'mysql', 'host' => 'localhost', 'port' => '3306', 'user' => 'root', 'db' => 'dev'],
        'prod' => ['driver' => 'pgsql', 'host' => 'db.example.com', 'port' => '5432', 'user' => 'app', 'db' => 'shop'],
        'scratch' => ['driver' => 'sqlite', 'db' => '/tmp/scratch.sqlite'],
    ];

    private function key(string $rune, KeyType $type = KeyType::Char): KeyMsg
    {
        return new KeyMsg($type, $rune);
    }

    private static function sgrStrip(string $s): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $s);
    }

    /** The row the cursor marker sits on, without the marker itself. */
    private static function cursorRow(string $view): string
    {
        foreach (explode("\n", self::sgrStrip($view)) as $line) {
            if (str_starts_with($line, '▸ ')) {
                return $line;
            }
        }
        $this->fail('no cursor row in view: ' . $view);
    }

    public function testViewListsEverySavedNameWithADialPreview(): void
    {
        $view = self::sgrStrip((new ConnectionPicker($this->entries))->view());

        $this->assertStringContainsString('dev', $view);
        $this->assertStringContainsString('prod', $view);
        $this->assertStringContainsString('scratch', $view);
        $this->assertStringContainsString('mysql · root@localhost:3306/dev', $view);
        $this->assertStringContainsString('pgsql · app@db.example.com:5432/shop', $view);
        $this->assertStringContainsString('sqlite · /tmp/scratch.sqlite', $view);
        $this->assertStringContainsString('[Enter] connect', $view);
        $this->assertStringNotContainsString('▸ prod', $view);
    }

    public function testCursorMarksTheFirstEntryByDefault(): void
    {
        $this->assertStringStartsWith('▸ dev', self::cursorRow((new ConnectionPicker($this->entries))->view()));
    }

    public function testDownMovesAndClampsAtTheEnd(): void
    {
        $picker = new ConnectionPicker($this->entries);
        for ($i = 0; $i < 3; $i++) {
            [$picker, $cmd] = $picker->update($this->key('j'));
            \assert($picker instanceof ConnectionPicker);
        }

        $this->assertNull($cmd, 'movement past the last entry must not quit');
        $this->assertStringStartsWith('▸ scratch', self::cursorRow($picker->view()));
    }

    public function testUpMovesWithK(): void
    {
        $picker = new ConnectionPicker($this->entries, 2);
        [$picker,] = $picker->update($this->key('k'));
        \assert($picker instanceof ConnectionPicker);

        $this->assertStringStartsWith('▸ prod', self::cursorRow($picker->view()));
    }

    public function testEnterRecordsTheChoiceAndQuits(): void
    {
        $picker = new ConnectionPicker($this->entries, 1);
        [$picked, $cmd] = $picker->update(new KeyMsg(KeyType::Enter));
        \assert($picked instanceof ConnectionPicker);

        $this->assertNotNull($cmd, 'Enter must hand the runtime a quit command');
        $this->assertSame('prod', $picked->pickedName());
    }

    public function testSpaceAlsoPicks(): void
    {
        $picker = new ConnectionPicker($this->entries, 0);
        [$picked,] = $picker->update(new KeyMsg(KeyType::Space));
        \assert($picked instanceof ConnectionPicker);

        $this->assertSame('dev', $picked->pickedName());
    }

    public function testEscapeCancelsWithoutAPick(): void
    {
        $picker = new ConnectionPicker($this->entries, 2);
        [$next, $cmd] = $picker->update(new KeyMsg(KeyType::Escape));
        \assert($next instanceof ConnectionPicker);

        $this->assertNull($next->pickedName());
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testQCharCancelsWithoutAPick(): void
    {
        $picker = new ConnectionPicker($this->entries);
        [$next,] = $picker->update($this->key('q'));
        \assert($next instanceof ConnectionPicker);

        $this->assertNull($next->pickedName());
    }

    public function testKeysOnAnEmptyStoreDoNothing(): void
    {
        $picker = new ConnectionPicker([]);
        [$next, $cmd] = $picker->update(new KeyMsg(KeyType::Enter));
        \assert($next instanceof ConnectionPicker);

        $this->assertNull($next->pickedName());
        $this->assertNull($cmd, 'an empty picker must not quit behind the launcher');
    }

    public function testUnrelatedMsgsArePassThrough(): void
    {
        $picker = new ConnectionPicker($this->entries);
        [$next,] = $picker->update($this->key('x'));
        \assert($next instanceof ConnectionPicker);

        $this->assertNull($next->pickedName());
        $this->assertStringStartsWith('▸ dev', self::cursorRow($next->view()));
    }

    public function testNothingSensitiveAppearsInPreview(): void
    {
        // The picker prints hosts and users by design, never a secret value;
        // the schema itself cannot carry one (store refuses plaintext pass),
        // so a stray env-resolved password would have to be invented here.
        $view = self::sgrStrip((new ConnectionPicker([
            'p' => ['driver' => 'mysql', 'host' => 'h', 'user' => 'app', 'db' => 'd', 'pass-env' => 'PROD_PW'],
        ]))->view());

        $this->assertStringContainsString('mysql · app@h/d', $view);
        $this->assertStringNotContainsString('PROD_PW', $view, 'the variable NAME is file data, not dial output');
    }
}

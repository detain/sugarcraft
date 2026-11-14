<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\App;
use SugarCraft\Sql\App\BrowseState;
use SugarCraft\Sql\App\ConnectionState;
use SugarCraft\Sql\App\QueryState;
use SugarCraft\Sql\App\UiState;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Lang;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-O20 (once-027): the `y` cell yank and its honest two-layer degradation.
 *
 * There was no copy path at all — an undisclosed gap versus the upstream this
 * family mirrors. These legs pin the wire contract at the Cmd seam (the
 * closure resolves to a RawMsg whose bytes ARE what the runtime writes, so no
 * terminal is needed): the yanked payload is the CELL under the two cursors,
 * base64-wrapped so hostile cell bytes cannot inject escape sequences, and a
 * TERM that cannot carry OSC 52 (dumb, the Linux console, unset) never sees
 * the bytes at all — it gets the plain cell text on the status line instead.
 * The oversized-payload arm proves a blob cell refuses with a count instead of
 * letting the core emitter's InvalidArgumentException fatal a key press.
 */
final class CellYankTest extends TestCase
{
    /** @var string|false */
    private $oldTerm;

    protected function setUp(): void
    {
        $this->oldTerm = getenv('TERM');
        putenv('TERM=xterm-256color');
    }

    protected function tearDown(): void
    {
        $this->oldTerm === false ? putenv('TERM') : putenv('TERM=' . $this->oldTerm);
        parent::tearDown();
    }

    /** @param array<int, array<string, string|null>> $rows */
    private function rowsApp(array $rows, int $rowCursor = 0, int $colCursor = 0): App
    {
        $browse = (new BrowseState())
            ->withSelectedTable('users')
            ->withRows($rows)
            ->withRowCursor($rowCursor)
            ->withColCursor($colCursor);

        return new App(
            ConnectionState::new(new FakeDatabase(), Flavor::Sqlite),
            $browse,
            ui: new UiState(Pane::Rows),
        );
    }

    /** @return array{0:App, 1:?\Closure} */
    private function press(App $app, string $rune): array
    {
        return $app->update(new KeyMsg(KeyType::Char, $rune));
    }

    private function bytes(\Closure $cmd): string
    {
        $msg = $cmd();
        $this->assertInstanceOf(RawMsg::class, $msg);

        return $msg->bytes;
    }

    public function testYCablesTheCellUnderTheCursorAsAnOsc52Frame(): void
    {
        $app = $this->rowsApp(
            [['id' => '1', 'name' => 'ada', 'mail' => 'a@x'], ['id' => '2', 'name' => 'bob', 'mail' => 'c@x']],
            rowCursor: 1,
            colCursor: 1,
        );
        [$next, $cmd] = $this->press($app, 'y');
        $this->assertNotNull($cmd, 'OSC-52-capable TERM must yank');
        $this->assertSame(Ansi::setClipboard('bob'), $this->bytes($cmd), 'the cell under both cursors — not the row');
        $this->assertStringContainsString(Lang::t('yank.osc52'), (string) $next->ui->status);
    }

    public function testHostileCellBytesRideBase64AndCannotInjectFrames(): void
    {
        $poison = "a\x1b]0;pwned\x07b";
        [$next, $cmd] = $this->press($this->rowsApp([['name' => $poison]]), 'y');
        $wire = $this->bytes($cmd);
        $this->assertSame(Ansi::setClipboard($poison), $wire);
        $payload = base64_decode(substr($wire, strlen("\x1b]52;c;"), -1), true);
        $this->assertSame($poison, $payload, 'the decoded payload is byte-exact');
        $this->assertStringContainsString("\x1b]52;", $wire, 'exactly one OSC header on the wire');
        $this->assertSame(substr_count($wire, "\x1b"), 1, 'the cell may not smuggle a second escape');
    }

    public function testDumbTerminalGetsThePlainCellTextOnTheStatusLine(): void
    {
        putenv('TERM=dumb');
        $app = $this->rowsApp([['id' => '1', 'name' => 'ada'], ['id' => '2', 'name' => 'bob']], rowCursor: 1, colCursor: 1);
        [$next, $cmd] = $this->press($app, 'y');
        $this->assertNull($cmd, 'dumb TERM must never see the escape bytes');
        $this->assertSame(Lang::t('yank.plain', ['text' => 'bob']), $next->ui->status);
    }

    public function testUnsetTerminalTakesTheSamePlainFallback(): void
    {
        putenv('TERM');
        [$next, $cmd] = $this->press($this->rowsApp([['name' => 'ada']]), 'y');
        $this->assertNull($cmd);
        $this->assertSame(Lang::t('yank.plain', ['text' => 'ada']), $next->ui->status);
    }

    public function testANullCellYanksEmptyTextAndSaysSoPlainly(): void
    {
        [$next, $cmd] = $this->press($this->rowsApp([['name' => null]]), 'y');
        $this->assertSame(Ansi::setClipboard(''), $this->bytes($cmd));
        putenv('TERM=linux');
        [$plainNext, $plainCmd] = $this->press($this->rowsApp([['name' => null]]), 'y');
        $this->assertNull($plainCmd);
        $this->assertSame(Lang::t('yank.plain_null'), $plainNext->ui->status);
    }

    public function testAnOverCapCellRefusesWithItsSizeInsteadOfFatalling(): void
    {
        $blob = str_repeat('x', Ansi::MAX_CLIPBOARD_BYTES + 1);
        [$next, $cmd] = $this->press($this->rowsApp([['payload' => $blob]]), 'y');
        $this->assertNull($cmd, 'the key press must not reach the emitter that throws');
        $this->assertStringContainsString(
            Lang::t('yank.too_big', ['bytes' => (string) strlen($blob)]),
            (string) $next->ui->status,
        );
    }

    public function testYankWithoutARowUnderTheCursorRefuses(): void
    {
        [$next, $cmd] = $this->press($this->rowsApp([['name' => 'ada']], rowCursor: 5), 'y');
        $this->assertNull($cmd);
        $this->assertSame(Lang::t('yank.refuse_no_cell'), $next->ui->status);
    }

    public function testYStaysATypeableRuneOutsideTheRowsPane(): void
    {
        $app = new App(
            ConnectionState::new(new FakeDatabase(), Flavor::Sqlite),
            new BrowseState(),
            query: new QueryState(editor: TextArea::new()->setValue('')->focus()[0]),
            ui: new UiState(Pane::Query),
        );
        [$next, $cmd] = $this->press($app, 'y');
        $this->assertNull($cmd, 'the yank is Rows-pane only');
        $this->assertSame('y', $next->query->editor?->value(), 'the Query editor still takes the letter');
    }
}

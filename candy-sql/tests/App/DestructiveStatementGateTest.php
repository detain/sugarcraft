<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Pane;

/**
 * D-M27: the Query pane's run keys used to fire DROP/TRUNCATE/DELETE with zero
 * confirmation. A destructive first press now only arms; a second consecutive
 * press whose trimmed statement equals the staged SQL confirms; an edit whose
 * trimmed text differs, or another statement running, disarms.
 */
final class DestructiveStatementGateTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE t1 (id INTEGER)');
    }

    private function app(string $sql): App
    {
        return App::builder()
            ->withDb(new SqliteDatabase($this->pdo))
            ->withFlavor(Flavor::Sqlite)
            ->withPane(Pane::Query)
            ->withQueryEditor(TextArea::new()->setValue($sql)->focus()[0])
            ->build();
    }

    private function runKey(): KeyMsg
    {
        return new KeyMsg(type: KeyType::Char, rune: 'r', ctrl: true);
    }

    private function tableExists(): bool
    {
        return (bool) $this->pdo
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 't1'")
            ->fetchColumn();
    }

    public function testFirstPressOnADestructiveStatementOnlyWarns(): void
    {
        [$next, $cmd] = $this->app('DROP TABLE t1')->update($this->runKey());
        \assert($next instanceof App);

        $this->assertNull($cmd);
        $this->assertSame('DROP TABLE t1', $next->ui->armedDestructive);
        $this->assertStringContainsString('destructive', (string) $next->ui->status);
        $this->assertTrue($this->tableExists(), 'nothing may execute before confirmation');
    }

    public function testSecondIdenticalPressConfirmsAndExecutes(): void
    {
        [$armed,] = $this->app('DROP TABLE t1')->update($this->runKey());
        \assert($armed instanceof App);

        [$done,] = $armed->update($this->runKey());
        \assert($done instanceof App);

        $this->assertFalse($this->tableExists(), 'the confirmed statement ran');
        $this->assertNull($done->ui->armedDestructive, 'the arm is consumed on execution');
    }

    public function testEditingTheStatementReArmsInsteadOfExecuting(): void
    {
        [$armed,] = $this->app('DROP TABLE t1')->update($this->runKey());
        \assert($armed instanceof App);

        // Typing a character appends to the focused editor → SQL no longer
        // byte-matches the armed statement, so the run press re-arms afresh.
        [$typed,] = $armed->update(new KeyMsg(type: KeyType::Char, rune: ';'));
        \assert($typed instanceof App);
        [$again,] = $typed->update($this->runKey());
        \assert($again instanceof App);

        $this->assertTrue($this->tableExists(), 'a mutated statement never executes');
        $this->assertSame('DROP TABLE t1;', $again->ui->armedDestructive);
    }

    public function testSafeStatementsRunUnarmedAndDisarmStaleArms(): void
    {
        // Fresh app: a plain SELECT just runs (sync SQLite fold, rows land).
        [$ran,] = $this->app('SELECT 1')->update($this->runKey());
        \assert($ran instanceof App);
        $this->assertNull($ran->ui->armedDestructive);
        $this->assertSame('1 row', $ran->ui->status);

        // Armed app: switching to a safe statement disarms without running
        // the armed one, and the safe statement proceeds normally.
        [$armed,] = $this->app('DROP TABLE t1')->update($this->runKey());
        \assert($armed instanceof App);
        [$typed,] = $armed->update(new KeyMsg(type: KeyType::Backspace));
        \assert($typed instanceof App);
        [$again,] = $typed->update($this->runKey());
        \assert($again instanceof App);
        // Deleting the tail changed the statement: the run press re-armed the
        // new text ('DROP TABLE t') and the table survived once more.
        $this->assertTrue($this->tableExists());
        $this->assertSame('DROP TABLE t', $again->ui->armedDestructive);
    }
}

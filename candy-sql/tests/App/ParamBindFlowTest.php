<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\MysqlDatabase;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Tests\Support\ScriptablePdo;

/**
 * SQL-O23 (once-030): the run path prompts for `?`/`:name` values and ships
 * the statement BOUND (prepare/execute) instead of shipping whatever the
 * user concatenated. The flow legs prove the whole promise: the prompt
 * owns the keyboard (even Ctrl+C answers the entry, not the app), values
 * reach the server as data (hostile strings cannot smuggle statements),
 * empty submits bind NULL like the cell editor's unset, mixed placeholder
 * styles are refused, and the D-M27 destructive gate still sees the
 * placeholder text — arming before the bind may execute.
 */
final class ParamBindFlowTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE t1 (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec("INSERT INTO t1 (id, name) VALUES (1, 'ada'), (2, 'bob')");
    }

    private function app(string $sql, ?PDO $pdo = null): App
    {
        return App::builder()
            ->withDb(new SqliteDatabase($pdo ?? $this->pdo))
            ->withFlavor(Flavor::Sqlite)
            ->withPane(Pane::Query)
            ->withQueryEditor(TextArea::new()->setValue($sql)->focus()[0])
            ->build();
    }

    private function runKey(): KeyMsg
    {
        return new KeyMsg(type: KeyType::Char, rune: 'r', ctrl: true);
    }

    /**
     * @param list<array{op:string, sql:string, params:array<mixed>|null}> $log
     * @return list<array{op:string, sql:string, params:array<mixed>|null}>
     */
    private function ops(array $log, string $op): array
    {
        return array_values(array_filter($log, static fn (array $e): bool => $e['op'] === $op));
    }

    public function testRunOnPlaceholdersOpensThePromptWithoutExecuting(): void
    {
        [$next, $cmd] = $this->app('SELECT id FROM t1 WHERE name = ?')->update($this->runKey());
        \assert($next instanceof App);

        $this->assertNull($cmd);
        $this->assertNotNull($next->query->paramPrompt, 'placeholders must prompt, not run bare');
        $this->assertSame(['#1'], $next->query->paramPrompt->labels);
        $this->assertStringContainsString('1/1', (string) $next->ui->status);
        $this->assertFalse($next->browse->holdsSyntheticResult(), 'nothing executed');
    }

    public function testTypedValueBindsAndExecutes(): void
    {
        [$prompted,] = $this->app('SELECT id FROM t1 WHERE name = ?')->update($this->runKey());
        \assert($prompted instanceof App);

        foreach (['a', 'd', 'a'] as $ch) {
            [$prompted,] = $prompted->update(new KeyMsg(type: KeyType::Char, rune: $ch));
            \assert($prompted instanceof App);
        }
        [$done, $cmd] = $prompted->update(new KeyMsg(type: KeyType::Enter));
        \assert($done instanceof App);

        $this->assertNull($cmd);
        $this->assertNull($done->query->paramPrompt);
        $this->assertNull($done->query->paramValues, 'the bind slot is consumed by the run that used it');
        $this->assertTrue($done->browse->holdsSyntheticResult());
        $this->assertSame([['id' => 1]], $done->browse->rows);
    }

    public function testHostileValueRidesTheDataLegAndCannotExecute(): void
    {
        [$prompted,] = $this->app('SELECT id FROM t1 WHERE name = ?')->update($this->runKey());
        \assert($prompted instanceof App);

        [$typed,] = $prompted->update(new PasteMsg("'; DROP TABLE t1; --"));
        \assert($typed instanceof App);
        $this->assertSame("'; DROP TABLE t1; --", $typed->query->paramPrompt?->buffer);

        [$done,] = $typed->update(new KeyMsg(type: KeyType::Enter));
        \assert($done instanceof App);

        $exists = (bool) $this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 't1'")->fetchColumn();
        $this->assertTrue($exists, 'the injected statement must not have reached the server as code');
        $this->assertSame([], $done->browse->rows, 'no user named the payload — empty result, no error');
        $this->assertNull($done->ui->error);
    }

    public function testWireFormIsPrepareExecuteNeverConcatenation(): void
    {
        $pdo = new ScriptablePdo();
        $pdo->on('SELECT id FROM t1', [['id' => 1]]);

        [$prompted,] = $this->app('SELECT id FROM t1 WHERE name = ?', $pdo)->update($this->runKey());
        \assert($prompted instanceof App);
        [$typed,] = $prompted->update(new PasteMsg('ada'));
        \assert($typed instanceof App);
        $typed->update(new KeyMsg(type: KeyType::Enter));

        $this->assertCount(1, $this->ops($pdo->log, 'prepare'));
        $executes = $this->ops($pdo->log, 'execute');
        $this->assertCount(1, $executes);
        $this->assertSame(['ada'], $executes[0]['params']);
        $this->assertStringContainsString('?', $executes[0]['sql'], 'the statement text keeps its placeholder');
        $this->assertStringNotContainsString('ada', $executes[0]['sql'], 'the value never enters the SQL string');
        $this->assertSame([], $this->ops($pdo->log, 'query'), 'the plain-text path may not run alongside the bind');
    }

    public function testEmptySubmitBindsNull(): void
    {
        $pdo = new ScriptablePdo();
        $pdo->on('SELECT', []);

        [$prompted,] = $this->app('SELECT id FROM t1 WHERE name = ?', $pdo)->update($this->runKey());
        \assert($prompted instanceof App);
        $prompted->update(new KeyMsg(type: KeyType::Enter));;

        $executes = $this->ops($pdo->log, 'execute');
        $this->assertCount(1, $executes);
        $this->assertSame([null], $executes[0]['params']);
    }

    public function testNamedPlaceholdersPromptByLabelAndBindAssoc(): void
    {
        $pdo = new ScriptablePdo();
        $pdo->on('SELECT id FROM t1', [['id' => 1]]);

        [$p1,] = $this->app('SELECT id FROM t1 WHERE name = :who AND id > :min', $pdo)->update($this->runKey());
        \assert($p1 instanceof App);
        $this->assertSame(['who', 'min'], $p1->query->paramPrompt?->labels);

        [$p2,] = $p1->update(new PasteMsg('ada'));
        \assert($p2 instanceof App);
        [$p3,] = $p2->update(new KeyMsg(type: KeyType::Enter));
        \assert($p3 instanceof App);
        $this->assertSame('min', $p3->query->paramPrompt?->labels[$p3->query->paramPrompt->index]);
        $this->assertStringContainsString('2/2', (string) $p3->ui->status);

        [$p4,] = $p3->update(new PasteMsg('0'));
        \assert($p4 instanceof App);
        $p4->update(new KeyMsg(type: KeyType::Enter));;

        $executes = $this->ops($pdo->log, 'execute');
        $this->assertSame(['who' => 'ada', 'min' => '0'], $executes[0]['params']);
    }

    public function testRepeatedNamePromptsOnce(): void
    {
        $pdo = new ScriptablePdo();
        $pdo->on('SELECT', []);

        [$p1,] = $this->app('SELECT id FROM t1 WHERE name = :who OR name = :who', $pdo)->update($this->runKey());
        \assert($p1 instanceof App);
        $this->assertSame(['who'], $p1->query->paramPrompt?->labels);

        [$p2,] = $p1->update(new PasteMsg('ada'));
        \assert($p2 instanceof App);
        $p2->update(new KeyMsg(type: KeyType::Enter));;

        $executes = $this->ops($pdo->log, 'execute');
        $this->assertSame(['who' => 'ada'], $executes[0]['params']);
    }

    public function testEscapeCancelsTheEntryWithoutRunning(): void
    {
        $pdo = new ScriptablePdo();

        [$p1,] = $this->app('SELECT id FROM t1 WHERE name = ?', $pdo)->update($this->runKey());
        \assert($p1 instanceof App);
        [$p2, $cmd] = $p1->update(new KeyMsg(type: KeyType::Escape));
        \assert($p2 instanceof App);

        $this->assertNull($cmd);
        $this->assertNull($p2->query->paramPrompt);
        $this->assertNull($p2->query->paramValues);
        $this->assertStringContainsString('cancelled', (string) $p2->ui->status);
        $this->assertSame([], $this->ops($pdo->log, 'execute'));
    }

    public function testCtrlCCancelsTheEntryAndNeverQuits(): void
    {
        [$p1,] = $this->app('SELECT id FROM t1 WHERE name = ?')->update($this->runKey());
        \assert($p1 instanceof App);

        [$p2, $cmd] = $p1->update(new KeyMsg(type: KeyType::Char, rune: 'c', ctrl: true));
        \assert($p2 instanceof App);

        $this->assertNull($cmd, 'the prompt owns Ctrl+C — it answers the entry, not the app');
        $this->assertNull($p2->query->paramPrompt);
    }

    public function testEditorKeysCannotDivertWhileThePromptIsOpen(): void
    {
        [$p1,] = $this->app('SELECT id FROM t1 WHERE name = ?')->update($this->runKey());
        \assert($p1 instanceof App);

        [$p2,] = $p1->update(new KeyMsg(type: KeyType::Char, rune: 'y'));
        \assert($p2 instanceof App);

        $this->assertSame('y', $p2->query->paramPrompt?->buffer, "'y' joins the value, it does not re-open anything");
        $this->assertStringContainsString('SELECT id FROM t1 WHERE name = ?', $p2->view());
    }

    public function testMixedPlaceholderStylesAreRefused(): void
    {
        $pdo = new ScriptablePdo();

        [$next, $cmd] = $this->app('WHERE a = ? AND b = :x', $pdo)->update($this->runKey());
        \assert($next instanceof App);

        $this->assertNull($cmd);
        $this->assertNull($next->query->paramPrompt, 'PDO rejects the mix — the app refuses before the wire');
        $this->assertStringContainsString('mixing', (string) $next->ui->status);
        $this->assertSame([], $this->ops($pdo->log, 'prepare'));
    }

    public function testDestructiveBoundStatementArmsBeforeTheBindMayExecute(): void
    {
        $pdo = new ScriptablePdo();
        $pdo->on('DELETE', [], 1);

        [$p1,] = $this->app('DELETE FROM t1 WHERE name = ?', $pdo)->update($this->runKey());
        \assert($p1 instanceof App);
        [$p2,] = $p1->update(new PasteMsg('ada'));
        \assert($p2 instanceof App);
        [$armed,] = $p2->update(new KeyMsg(type: KeyType::Enter));
        \assert($armed instanceof App);

        $this->assertSame('DELETE FROM t1 WHERE name = ?', $armed->ui->armedDestructive);
        $this->assertStringContainsString('destructive', (string) $armed->ui->status);
        $this->assertSame([], $this->ops($pdo->log, 'execute'), 'binding does not bypass the confirm gate');

        [$done,] = $armed->update($this->runKey());
        \assert($done instanceof App);

        $executes = $this->ops($pdo->log, 'execute');
        $this->assertCount(1, $executes);
        $this->assertSame(['ada'], $executes[0]['params']);
        $this->assertNull($done->ui->armedDestructive);
    }

    public function testLiveMySqlServerRoundTripsBoundStatements(): void
    {
        $dsn = getenv('CANDY_SQL_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('live leg requires CANDY_SQL_MYSQL_DSN');
        }

        $db = new MysqlDatabase(new PDO($dsn));
        $stmt = $db->prepare('SELECT 1 AS one WHERE ? = 1');
        $this->assertNotNull($stmt);
        $stmt->execute(['1']);
        $this->assertSame('1', (string) $stmt->fetchAll()[0]['one']);
    }
}

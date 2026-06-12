<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Connections;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Query\Admin\Connections\ConnectionsPage;
use SugarCraft\Query\Admin\ServerContext;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Covers the KILL / KILL QUERY wiring in ConnectionsPage::update().
 *
 * The audit (candy_query_audit.md §D) flagged that ConnectionActions::kill()
 * had correct SQL but no caller, so a user could not kill a connection from the
 * UI. These tests drive the confirm-then-execute key path end to end.
 */
final class ConnectionsKillTest extends TestCase
{
    private FakeDatabase $db;
    private ServerContext $context;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->context = new ServerContext($this->db);
    }

    /**
     * Seed one SHOW-style processlist row. FakeDatabase never reports
     * @@performance_schema = 1, so the provider takes the SHOW path and reads
     * Id/User keys (empty user => background/system thread).
     */
    private function seedConnection(string $id = '42', string $user = 'app'): void
    {
        $this->db->setQueryResult([
            [
                'Id' => $id, 'User' => $user, 'Host' => 'web1', 'db' => 'shop',
                'Command' => 'Query', 'Time' => '5', 'State' => 'executing', 'Info' => 'SELECT 1',
            ],
        ]);
    }

    private function key(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune);
    }

    public function testKillArmsAConfirmationPrompt(): void
    {
        $this->seedConnection();
        $page = ConnectionsPage::new($this->context);

        [$armed, $cmd] = $page->update($this->key('K'));

        $this->assertNull($cmd, 'arming a kill must not run a command yet');
        $this->assertStringContainsString('KILL connection 42', $armed->view());
        $this->assertStringContainsString('[y] confirm', $armed->view());
        $this->assertSame([], $this->db->execLog(), 'no KILL until confirmed');
    }

    public function testConfirmingKillIssuesKillConnection(): void
    {
        $this->seedConnection('42');
        $page = ConnectionsPage::new($this->context);

        [$armed] = $page->update($this->key('K'));
        [$done, $cmd] = $armed->update($this->key('y'));

        $this->assertContains('KILL CONNECTION 42', $this->db->execLog());
        $this->assertNotNull($cmd, 'a confirmed kill refreshes the list');
        $this->assertStringNotContainsString('[y] confirm', $done->view());
        $this->assertStringContainsString('Kill sent to connection 42', $done->view());
    }

    public function testKillQueryIssuesKillQuery(): void
    {
        $this->seedConnection('7');
        $page = ConnectionsPage::new($this->context);

        [$armed] = $page->update($this->key('X'));
        $armed->update($this->key('y'));

        $this->assertContains('KILL QUERY 7', $this->db->execLog());
    }

    public function testAnyOtherKeyCancelsThePendingKill(): void
    {
        $this->seedConnection('42');
        $page = ConnectionsPage::new($this->context);

        [$armed] = $page->update($this->key('K'));
        [$cancelled, $cmd] = $armed->update($this->key('n'));

        $this->assertSame([], $this->db->execLog(), 'cancelling must not issue any KILL');
        $this->assertNull($cmd);
        $this->assertStringContainsString('Kill cancelled', $cancelled->view());
        $this->assertStringNotContainsString('[y] confirm', $cancelled->view());
    }

    public function testKillRefusesBackgroundThread(): void
    {
        // Empty user => fromShowProcesslist marks it a background/system thread.
        $this->seedConnection('1', '');
        $page = ConnectionsPage::new($this->context);

        [$result] = $page->update($this->key('K'));
        $result->update($this->key('y'));

        $this->assertSame([], $this->db->execLog());
        $this->assertStringContainsString('Refusing to kill a background thread', $result->view());
    }

    public function testKillWithNoConnectionsReportsNoSelection(): void
    {
        $this->db->setQueryResult([]);
        $page = ConnectionsPage::new($this->context);

        [$result] = $page->update($this->key('K'));

        $this->assertStringContainsString('No connection selected', $result->view());
        $this->assertSame([], $this->db->execLog());
    }
}

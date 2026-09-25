<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\ServerStatus;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Query\Admin\ServerContext;
use SugarCraft\Query\Admin\ServerStatus\ServerStatusPage;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Pins for the revived GTID-mode dialog path (review MAJOR-1).
 *
 * Before the keyType→type fix both the Escape-close and Enter-execute
 * branches were dead code, and the resurrected Enter path closed the
 * dialog identically on success AND failure — a silent-failure admin
 * mutation. These tests pin the two distinct outcomes: success closes,
 * failure keeps the dialog open with the error text rendered.
 */
final class ServerStatusGtidDialogTest extends TestCase
{
    private FakeDatabase $db;
    private ServerStatusPage $page;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        // gtid_mode rides the same Variable_name/Value rows the page reads
        // for server + status variables (FakeDatabase answers every query
        // with the configured set).
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
            ['Variable_name' => 'gtid_mode', 'Value' => 'ON'],
        ]);
        $this->db->setServerVersion('MySQL version 8.0.33');
        $this->page = new ServerStatusPage(new ServerContext($this->db));
    }

    public function testEnterWithWhitelistedModeExecutesAndClosesDialog(): void
    {
        [$open,] = $this->page->update($this->char('g'));
        $this->assertIsServerStatusPage($open);
        $this->assertStringContainsString('GTID_MODE [ON]', self::plain($open->view()));

        [$closed,] = $open->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertIsServerStatusPage($closed);

        $this->assertSame(['SET @@GLOBAL.GTID_MODE = ON'], $this->db->execLog());
        $this->assertStringNotContainsString('GTID_MODE [', self::plain($closed->view()));
        $this->assertStringNotContainsString('Error: GTID_MODE', self::plain($closed->view()));
    }

    public function testEnterFailureKeepsDialogOpenAndSurfacesErrorLine(): void
    {
        [$open,] = $this->page->update($this->char('g'));

        $this->db->setExecThrows(new \RuntimeException("GTID_MODE\nchange is   not permitted while replicas are running"));

        [$failed,] = $open->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertIsServerStatusPage($failed);

        // The mutation attempt is recorded even though it failed...
        $this->assertSame(['SET @@GLOBAL.GTID_MODE = ON'], $this->db->execLog());

        $rendered = self::plain($failed->view());
        // ...the dialog stays open (mode still on screen)...
        $this->assertStringContainsString('GTID_MODE [ON]', $rendered);
        // ...and the whitespace-flattened reason is visible (never silent).
        $this->assertStringContainsString(
            'Error: GTID_MODE = ON: GTID_MODE change is not permitted while replicas are running',
            $rendered,
        );
    }

    public function testEscapeClosesDialogAndDropsPendingError(): void
    {
        [$open,] = $this->page->update($this->char('g'));
        $this->db->setExecThrows(new \RuntimeException('denied'));
        [$failed,] = $open->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertStringContainsString('Error: GTID_MODE', self::plain($failed->view()));

        [$closed,] = $failed->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertIsServerStatusPage($closed);

        $rendered = self::plain($closed->view());
        $this->assertStringNotContainsString('GTID_MODE [', $rendered);
        $this->assertStringNotContainsString('Error: GTID_MODE', $rendered);
    }

    public function testReopeningTheDialogStartsCleanNotOnTheStaleError(): void
    {
        [$open,] = $this->page->update($this->char('g'));
        $this->db->setExecThrows(new \RuntimeException('denied'));
        [$failed,] = $open->update(new KeyMsg(KeyType::Enter, ''));
        $this->db->setExecThrows(null);
        [$closedAgain,] = $failed->update(new KeyMsg(KeyType::Escape, ''));

        // Escape-then-'g' is the only reopen path: while the dialog is open
        // every non-Esc/c/Enter key is swallowed by the dialog handler, and
        // a fresh open must not leak the previous session's failure line.
        [$reopened,] = $closedAgain->update($this->char('g'));
        $this->assertIsServerStatusPage($reopened);

        $rendered = self::plain($reopened->view());
        $this->assertStringContainsString('GTID_MODE [ON]', $rendered);
        $this->assertStringNotContainsString('Error: GTID_MODE', $rendered);
    }

    private function char(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune, false, false, false);
    }

    private function assertIsServerStatusPage(mixed $page): void
    {
        $this->assertInstanceOf(ServerStatusPage::class, $page);
    }

    /** Strip SGR sequences so substring asserts read against plain text. */
    private static function plain(string $rendered): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
    }
}

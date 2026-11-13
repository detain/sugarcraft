<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\ServerStatus;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sql\Admin\ServerContext;
use SugarCraft\Sql\Admin\ServerStatus\ServerStatusPage;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

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
        // ...and the sanitized reason is visible (never silent). Fixed 2026-11-03
        // (D-C8): the newline now surfaces as the ↵ control glyph (the old
        // invisible whitespace-fold could not be told apart from an injected
        // sequence) and remaining space runs collapse.
        $this->assertStringContainsString(
            'Error: GTID_MODE = ON: GTID_MODE↵change is not permitted while replicas are running',
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

    public function testCycleVisitsExactlyTheFourWhitelistedModes(): void
    {
        // SQL-O06: the 'c' ring must be OFF → OFF_PERMISSIVE → ON_PERMISSIVE
        // → ON. The earlier five-slot ring included OFF_SECURE, which real
        // servers refuse with ERROR 1231.
        [$open,] = $this->page->update($this->char('g'));
        $this->assertIsServerStatusPage($open);

        $seen = [];
        $current = $open;
        for ($i = 0; $i < 4; $i++) {
            [$next,] = $current->update($this->char('c'));
            $this->assertIsServerStatusPage($next);
            $this->assertMatchesRegularExpression(
                '/GTID_MODE \[(OFF|OFF_PERMISSIVE|ON_PERMISSIVE|ON)\]/',
                self::plain($next->view()),
            );
            $seen[] = self::plain($next->view());
            $current = $next;
        }

        foreach ($seen as $rendered) {
            $this->assertStringNotContainsString('OFF_SECURE', $rendered);
        }
        // A full ring from ON lands back on ON.
        $this->assertStringContainsString('GTID_MODE [ON]', $seen[3]);
    }

    public function testMariaDBNamesTheRefusalInsteadOfOpeningADeadDialog(): void
    {
        // SQL-O06: MariaDB normalises past the 5.7.6 version gate (11.x >
        // 5.7.6) but has no gtid_mode variable — opening the dialog there
        // was an ERROR 1193 factory. The refusal must name the reason.
        $db = new FakeDatabase();
        $db->setQueryResult([['Variable_name' => 'Uptime', 'Value' => '3600']]);
        $db->setServerVersion('5.5.5-11.4.4-MariaDB-1:11.4.4+maria~ubu2404');
        $page = new ServerStatusPage(new ServerContext($db));

        [$refused,] = $page->update($this->char('g'));
        $this->assertIsServerStatusPage($refused);
        $this->assertFalse($refused->isModal());

        $rendered = self::plain($refused->view());
        $this->assertStringContainsString('MariaDB has no gtid_mode', $rendered);
        $this->assertStringNotContainsString('GTID_MODE [', $rendered);
    }

    public function testOldMysqlNamesTheVersionFloorRefusal(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([['Variable_name' => 'Uptime', 'Value' => '3600']]);
        $db->setServerVersion('MySQL version 5.6.51');
        $page = new ServerStatusPage(new ServerContext($db));

        [$refused,] = $page->update($this->char('g'));
        $this->assertIsServerStatusPage($refused);
        $this->assertFalse($refused->isModal());

        $rendered = self::plain($refused->view());
        $this->assertStringContainsString('requires MySQL 5.7.6 or newer', $rendered);
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

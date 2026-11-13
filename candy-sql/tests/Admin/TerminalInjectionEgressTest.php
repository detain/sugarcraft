<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\Connections\ProcesslistResult;
use SugarCraft\Sql\Admin\QueryLogger;
use SugarCraft\Sql\Admin\Reports\ReportsPage;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Database;
use SugarCraft\Sql\Renderer;
use SugarCraft\Sql\ResultTable;

/**
 * D-C8 (review 1102): every terminal egress for server-controlled strings
 * must route through CellValue::sanitize (sugar-core Sanitize::cellValue).
 *
 * A hostile or compromised server can put raw ESC (0x1B) bytes into table
 * names, column headers, SHOW PROCESSLIST fields, version banners, and error
 * text. Un-sanitized, those bytes paint bogus escape sequences through the
 * TUI — OSC-8 hyperlinks, cursor moves, or palette writes — a terminal
 * injection channel. Each test plants a byte-fingerprint payload and asserts
 * the raw control sequence never survives into the rendered output while the
 * visible stand-in (·) does.
 */
final class TerminalInjectionEgressTest extends TestCase
{
    /** OSC-8 open hyperlink, terminated by BEL — the classic injection probe. */
    private const OSC8 = "\x1b]8;;http://attacked.invalid\x07";

    /** CSI red, with the real ESC lead — a color-injection probe. */
    private const OSC_CSI = "\x1b[31m";

    private function evilDb(): Database
    {
        // SQLite quoted identifiers accept arbitrary bytes between the quotes.
        $db = new Database(new \PDO('sqlite::memory:'));
        $db->exec('CREATE TABLE "tab' . self::OSC8 . 'le" (id INTEGER, "co' . self::OSC_CSI . 'l" INTEGER)');
        $db->exec('INSERT INTO "tab' . self::OSC8 . 'le" VALUES (1, 2)');
        return $db;
    }

    public function testFrameNeverCarriesRawControlBytesFromTableAndColumnNames(): void
    {
        $out = Renderer::render(App::start($this->evilDb()));

        self::assertStringNotContainsString(self::OSC8, $out, 'OSC-8 payload bytes reached the frame');
        self::assertStringNotContainsString("co\x1b[31ml", $out, 'CSI sequence reached the frame');
        // The visible stand-ins prove the names rendered THROUGH the sanitizer:
        // each control byte became a middle dot, the text around it survived.
        self::assertStringContainsString('tab·]8;;http://attacked.invalid·le', $out);
        self::assertStringContainsString('co·[31ml', $out);
    }

    public function testStatusLineSanitizesServerSourcedErrorMessage(): void
    {
        $app = App::builder()
            ->withDb($this->evilDb())
            ->withError("boom\x1b[1mred\x00\x07")
            ->build();

        $out = Renderer::render($app);

        self::assertStringNotContainsString("boom\x1b[1mred", $out);
        self::assertStringContainsString('error: boom·[1mred··', $out);
    }

    public function testProcesslistResultSanitizesEveryServerStringField(): void
    {
        $row = new ProcesslistResult(
            processId: 42,
            threadId: 43,
            user: "ro\x1bot",
            host: "h\x07ost.invalid",
            database: "d\x1b]8;;evil\x07b",
            command: "Que\x00ry",
            time: 5,
            state: "wait\x0bing",
            info: "SELECT \x1b[2J",
            connectionAttr: "at\x0btr",
            isPS: false,
            isBackground: false,
            infoTruncated: false,
        );

        foreach ([$row->user, $row->host, $row->database, $row->command, $row->state, $row->info, $row->connectionAttr] as $field) {
            self::assertStringNotContainsString("\x1b", $field);
            self::assertStringNotContainsString("\x00", $field);
            self::assertStringNotContainsString("\x07", $field);
            self::assertStringNotContainsString("\x0b", $field);
        }
        self::assertSame('ro·ot', $row->user);
        self::assertSame('SELECT ·[2J', $row->info);
    }

    public function testQueryLoggerDisplayLinesNeutralizeSqlAndErrorBytes(): void
    {
        $needle = 'inject' . self::OSC8 . 'probe-' . uniqid();
        QueryLogger::log('query', $needle, 1, "driven\x1b[5merror");

        $hit = null;
        foreach (QueryLogger::displayLines() as $line) {
            if (str_contains($line, 'inject')) {
                $hit = $line;
                break;
            }
        }

        self::assertNotNull($hit, 'logged entry missing from displayLines()');
        self::assertStringNotContainsString("\x1b]8;;", $hit);
        self::assertStringNotContainsString("driven\x1b[5m", $hit);
        self::assertStringContainsString('driven·[5merror', $hit);
    }

    public function testResultTableHeaderSanitizesWireColumnNames(): void
    {
        $table = new ResultTable([["co" . self::OSC_CSI . "l" => 7]]);

        $out = $table->render();

        self::assertStringNotContainsString("co\x1b[31ml", $out);
        self::assertStringContainsString('co·[31ml', $out);
    }

    public function testReportsPageErrorScreenSanitizesServerText(): void
    {
        $db = $this->makeDb();
        $context = $this->createMock(\SugarCraft\Sql\Admin\ServerContextInterface::class);
        $context->method('connection')->willReturn($db);
        $page = ReportsPage::new($context, $db);
        $prop = new \ReflectionProperty($page, 'errorMessage');
        $prop->setValue($page, "lost: \x1b[31mconnection\x07 died\nretry?");

        $out = (string) $this->callProtected($page, 'renderErrorScreen');

        self::assertStringNotContainsString("\x1b[31mconnection", $out);
        self::assertStringNotContainsString("\x07", $out);
        self::assertStringContainsString('lost: ·[31mconnection· died↵retry?', $out);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * Invoke a protected method without exposing it on the production class.
     */
    private function callProtected(object $obj, string $method): mixed
    {
        $m = new \ReflectionMethod($obj, $method);
        $m->setAccessible(true);
        return $m->invoke($obj);
    }

    private function makeDb(): \SugarCraft\Sql\Db\DatabaseInterface
    {
        return new class implements \SugarCraft\Sql\Db\DatabaseInterface {
            public function tables(): array
            {
                return [];
            }

            public function rows(string $table, int $limit = 100, int $offset = 0): array
            {
                return [];
            }

            public function query(string $sql): array|null
            {
                return [];
            }

            public function lastInsertId(): string|int
            {
                return 0;
            }

            public function quote(string $value): string
            {
                return "'" . addslashes($value) . "'";
            }

            public function exec(string $sql): int
            {
                return 0;
            }

            public function close(): void {}

            public function serverVersion(): string
            {
                return '8.0.32';
            }

            public function driverName(): string
            {
                return 'mysql';
            }

            public function ping(): bool
            {
                return true;
            }

            public function databases(): array
            {
                return ['test'];
            }

            public function prepare(string $sql): ?\SugarCraft\Sql\Db\PreparedStatementInterface
            {
                return null;
            }

            public function dsn(): string
            {
                return '';
            }

            public function username(): string
            {
                return '';
            }
        };
    }
}

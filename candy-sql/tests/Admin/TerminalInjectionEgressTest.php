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

    // ── Admin-page egress legs (adminpages-006/-026, SQL-P04) ──────────────

    /**
     * The three live-proven plain-string channels the review census named:
     * driver error text, SHOW VARIABLES values (paths fed through
     * strlen/substr), and the VERSION() banner. The final admin-frame wrap
     * clips ANSI-aware but does NOT sanitize, so each page leg must neutralize
     * at its own concatenation point — pinned here per leg, can-fail per leg.
     */
    private function evilContext(): \PHPUnit\Framework\MockObject\MockObject
    {
        $context = $this->createMock(\SugarCraft\Sql\Admin\ServerContextInterface::class);
        $context->method('connection')->willReturn($this->makeDb());
        $context->method('flavor')->willReturn(\SugarCraft\Sql\Db\Flavor::MySQL);
        $context->method('version')->willReturn(\SugarCraft\Sql\Db\ServerVersion::parse('8.0.32'));
        $context->method('versionString')->willReturn('8.0.32' . self::OSC8);
        $context->method('statusVariables')->willReturn(['Uptime' => '3600']);
        $context->method('serverVariables')->willReturn([
            'Hostname' => 'srv' . self::OSC_CSI . 'er.invalid',
            'Socket'   => '/run/' . self::OSC8 . 'mysqld.sock',
            'datadir'  => '/var/lib/' . self::OSC_CSI . 'mysql',
            'tmpdir'   => str_repeat('x', 50) . '/MySQL' . self::OSC8 . 'tmp',
        ]);

        return $context;
    }

    public function testDashboardQueryLogRowSanitizesSqlAndErrorChannels(): void
    {
        $ref = new \ReflectionClass(\SugarCraft\Sql\Admin\Dashboard\DashboardPage::class);
        $page = $ref->newInstanceWithoutConstructor();

        // Short row: whole hostile strings must arrive stand-in-neutralized.
        $row = $this->callProtected($page, 'renderQueryLogRow', [[
            'timestamp' => 1700000000.5,
            'type' => 'error',
            'sql' => 'SELECT ' . self::OSC8,
            'rows' => 0,
            'error' => "Access denied" . self::OSC_CSI,
        ], 80]);
        self::assertStringNotContainsString(self::OSC8, $row, 'OSC-8 payload survived the dashboard query-log row');
        self::assertStringNotContainsString("denied\x1b[31m", $row, 'CSI payload survived the row');
        self::assertStringContainsString('·]8;;', $row);

        // Long row: the byte-budget cut lands mid-UTF-8 INSIDE hostile text —
        // the codified order is cut→sanitize, and the result must be valid
        // UTF-8 with no escape bytes (DebugPage's order, now pinned here too).
        $row = $this->callProtected($page, 'renderQueryLogRow', [[
            'timestamp' => 1700000000.5,
            'type' => 'query',
            'sql' => str_repeat('ä', 200) . self::OSC8,
            'rows' => 0,
            'error' => null,
        ], 50]);
        self::assertStringNotContainsString(self::OSC8, $row, 'cut→sanitize must leave no payload escape');
        self::assertTrue(mb_check_encoding($row, 'UTF-8'), 'a byte-cut mid-UTF-8 must be repaired by the sanitizer');
    }

    public function testDashboardHeaderSanitizesVersionBanner(): void
    {
        $page = new \SugarCraft\Sql\Admin\Dashboard\DashboardPage($this->evilContext());

        $out = (string) $this->callProtected($page, 'renderHeader');

        self::assertStringNotContainsString(self::OSC8, $out);
        self::assertStringContainsString('Performance Dashboard', $out);
        self::assertStringContainsString('8.0.32·]8;;', $out, 'the sanitized banner keeps its visible stand-in');
    }

    public function testServerStatusHeaderSanitizesVersionBanner(): void
    {
        $page = new \SugarCraft\Sql\Admin\ServerStatus\ServerStatusPage($this->evilContext());

        $out = (string) $this->callProtected($page, 'renderHeader');

        self::assertStringNotContainsString(self::OSC8, $out);
        self::assertStringContainsString('8.0.32·]8;;', $out);
    }

    public function testServerStatusResolveLegsSanitizeServerPaths(): void
    {
        $ref = new \ReflectionClass(\SugarCraft\Sql\Admin\ServerStatus\ServerStatusPage::class);
        $page = $ref->newInstanceWithoutConstructor();

        $short = (string) $this->callProtected($page, 'resolveDir', ['/var/lib/' . self::OSC_CSI . 'mysql']);
        self::assertStringNotContainsString("\x1b", $short, 'a CSI inside a directory path reached the row');
        self::assertStringContainsString('/var/lib/·[31mmysql', $short);

        // Long path exercises the tail cut first: 40-byte budget, 92 raw bytes.
        $long = (string) $this->callProtected($page, 'resolveDir', [str_repeat('x', 50) . '/MySQL' . self::OSC8 . 'tmp']);
        self::assertStringStartsWith('...', $long, 'the abbreviation leg still fires after sanitizing');
        self::assertStringNotContainsString("\x1b", $long);

        self::assertNull($this->callProtected($page, 'resolveDir', ['']));
        self::assertSame('/plain/path', $this->callProtected($page, 'resolveDir', ['/plain/path']),
            'benign values ride through byte-identical');

        $value = (string) $this->callProtected($page, 'resolveValue', ['TLS' . self::OSC_CSI . 'v1.3']);
        self::assertStringNotContainsString("\x1b", $value);
        self::assertStringContainsString('TLS·[31mv1.3', $value);
    }

    public function testServerInfoCardSanitizesHostSocketAndVersion(): void
    {
        $out = \SugarCraft\Sql\Admin\ServerStatus\ServerInfoCard::new($this->evilContext())->render();

        self::assertStringNotContainsString(self::OSC8, $out, 'hostname/socket/version payloads must not reach the card');
        self::assertStringNotContainsString("srv\x1b[31m", $out);
        self::assertStringContainsString('srv·[31mer.invalid', $out, 'hostname must render THROUGH the sanitizer');
        self::assertStringContainsString('8.0.32·]8;;', $out);
    }

    public function testPerfSchemaCommitPreviewSanitizesStatementText(): void
    {
        $page = \SugarCraft\Sql\Admin\PerfSchema\PerfSchemaPage::new($this->evilContext());
        $pending = new \ReflectionProperty($page, 'pendingCommit');
        $pending->setValue($page, [['sql' => 'UPDATE performance_schema.setup SET enabled="YES"' . self::OSC8]]);

        $out = (string) $this->callProtected($page, 'build');

        self::assertStringNotContainsString(self::OSC8, $out, 'the y-confirm preview must not paint an escape the user cannot see');
        self::assertStringContainsString('·]8;;', $out, 'the statement text itself must stay visible through the sanitizer');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * Invoke a protected method without exposing it on the production class.
     *
     * @param list<mixed> $args
     */
    private function callProtected(object $obj, string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod($obj, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
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

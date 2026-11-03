<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\History\HistoryQuery;
use SugarCraft\Sql\Admin\History\HistoryRecorder;
use SugarCraft\Sql\Admin\History\SqliteHistoryStore;
use SugarCraft\Sql\Admin\Sampler;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\ConnectionFactory;
use SugarCraft\Sql\ExplainView;
use SugarCraft\Sql\SchemaBrowser;

/**
 * The public site body (docs/_data/candy-sql.body.html) once shipped an
 * invented API universe — a StatusPoller class, a provideStatusSnapshot()
 * method, HistoryQuery metric getters, an auto-prune cap, space-form flags.
 * D-C6 (review 2026-11) erased it; this guard keeps it erased by pinning
 * that every documented symbol actually resolves and that the fabricated
 * tokens never come back.
 *
 * The body file lives at repository root, outside a standalone split-repo
 * checkout of this lib — absence degrades to a skip, never a false failure.
 */
final class SiteBodyTruthTest extends TestCase
{
    private const PHANTOM_TOKENS = [
        'StatusPoller',
        'registerUptime',
        'provideStatusSnapshot',
        'queriesPerSecond',
        'averageDuration(',
        'errorRate(',
        'topQueries',
        'auto-pruned',
        '--db-driver',
        '--db-host',
        'SQLSRV',
        'Smoke Testing',
        'blob/master',
        'forFlavor',
        'SqliteHistoryStore</td><td>open(path)',
    ];

    private function body(): ?string
    {
        $path = __DIR__ . '/../../docs/_data/candy-sql.body.html';
        if (!is_file($path)) {
            return null; // standalone split-repo checkout — no site tree here
        }

        return (string) file_get_contents($path);
    }

    public function testNoFabricatedSymbolsSurviveInTheSiteBody(): void
    {
        $body = $this->body();
        if ($body === null) {
            $this->markTestSkipped('repository docs/_data tree not present');
        }

        foreach (self::PHANTOM_TOKENS as $token) {
            $this->assertStringNotContainsString(
                $token,
                $body,
                "fabricated site token reappeared: {$token}"
            );
        }
    }

    public function testEveryDocumentedApiRowResolvesInCode(): void
    {
        $body = $this->body();
        if ($body === null) {
            $this->markTestSkipped('repository docs/_data tree not present');
        }

        // Named classes the API table describes must exist...
        foreach ([
            App::class,
            SchemaBrowser::class,
            ExplainView::class,
            HistoryRecorder::class,
            HistoryQuery::class,
            SqliteHistoryStore::class,
            ConnectionFactory::class,
        ] as $class) {
            self::assertTrue(
                class_exists($class) && str_contains($body, '<td>' . (new \ReflectionClass($class))->getShortName() . '</td>'),
                "site body must carry an API row for {$class}"
            );
        }
        // Sampler is described in the feature grid, not the API table.
        self::assertTrue(class_exists(Sampler::class) && str_contains($body, 'Sampler'));

        // ...and the exact signatures the body now teaches must be real.
        $start = new \ReflectionMethod(App::class, 'start');
        self::assertSame(
            ['db', 'flavor', 'historyRecorder', 'sslMode'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $start->getParameters()),
            'App::start() parameter list drifted from the documented four'
        );
        self::assertTrue((new \ReflectionMethod(SchemaBrowser::class, 'new'))->isStatic());
        self::assertTrue((new \ReflectionMethod(ExplainView::class, 'run'))->isStatic());
        self::assertSame(
            ['since', 'until'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new \ReflectionMethod(SqliteHistoryStore::class, 'query'))->getParameters()),
        );
        self::assertContains('rate', array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(HistoryQuery::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        ));
    }

    public function testDocumentedEqualsFormFlagsAreTheRealArgvOptions(): void
    {
        $body = $this->body();
        if ($body === null) {
            $this->markTestSkipped('repository docs/_data tree not present');
        }

        // The body advertises exactly these equals-form flags; derive the
        // live set from parseArgv's accepted list via behavior probes.
        foreach (['--dsn=', '--driver=', '--db=', '--host=', '--port=', '--user=', '--pass=', '--ssl-mode='] as $flag) {
            self::assertStringContainsString($flag, $body, "documented flag vanished: {$flag}");
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option');
        /** @phpstan-ignore-next-line deliberately probing the refusal */
        ConnectionFactory::fromArgv(['candy-sql', '--db-driver=mysql']);
    }
}

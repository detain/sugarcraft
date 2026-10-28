<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\InsightsHostCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionKind;
use SugarCraft\Code\Usage;

/**
 * Insights (C-SES-8.c, GAP-OBS-06): the gathering half — roster fold,
 * shared ledger rollup, the 0600 HTML file beside the `/export` shelf, and
 * the summary-plus-path reply.
 *
 * Everything runs against a REAL temporary database: the spend grids are the
 * store's own SQL (via the shared `rollupFacts` read) and the session-shaped
 * sections fold from the real roster columns, so the test seeds through the
 * public store API and reaches raw PDO only to backdate one row outside the
 * window — an external write the fresh roster SELECT must not see as
 * yesterday's session (the window law `rollupFacts` states for the ledger).
 */
final class InsightsHostCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/c8c_insightshost_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->tempDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->tempDir . '/' . $entry;
            if (is_dir($path)) {
                foreach (scandir($path) ?: [] as $inner) {
                    if ($inner !== '.' && $inner !== '..') {
                        @unlink($path . '/' . $inner);
                    }
                }
                @rmdir($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    private function insightsStore(string $name): EnhancedSessionStore
    {
        return new EnhancedSessionStore($this->tempDir . '/' . $name);
    }

    private function insightsContext(EnhancedSessionStore $store): CommandContext
    {
        return CommandContext::new(sessionStore: $store);
    }

    /** @return list<string> names of the files in the exports seam */
    private function insightsShelfFiles(string $dir): array
    {
        $names = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $names[] = $entry;
            }
        }

        return $names;
    }

    public function testTheRunWritesTheShelfFileAtZeroSixZeroAndRepliesPagePlusPath(): void
    {
        $shelf = $this->tempDir . '/shelf';
        mkdir($shelf, 0700, true);
        $store = $this->insightsStore('insights.db');
        $store->createSession('ins-a', 'candy-code', 'model-one', null, null, '/home/me/alpha');
        $store->forkSession('ins-a', SessionKind::Branch);
        $store->createSession('ins-c', 'import:opencode', 'model-one', null, null, '/home/me/imported');
        $store->createSession('ins-old', 'candy-code', 'model-one', null, null, '/home/me/stale');
        $pdo = new \PDO('sqlite:' . $this->tempDir . '/insights.db');
        $pdo->prepare("UPDATE sessions SET created_at = '2020-01-01 00:00:00' WHERE id = ?")->execute(['ins-old']);
        $store->appendUsageRecord('ins-a', Usage::new(1000, 0.5, unpricedModel: null), 'model-one');
        $store->appendUsageRecord('ins-a', Usage::new(7, 0.0, unpricedModel: 'model-blind'), 'model-blind');
        $store->recordTurn('ins-a', 'first');
        $store->recordTurn('ins-a', 'second');

        $reply = (new InsightsHostCommand($shelf))->run($this->insightsContext($store), 'insights')->rows[1]->content;

        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');
        $target = $shelf . '/insights-' . $stamp . '.html';
        self::assertFileExists($target, 'the reply carries the page; the file carries the page\'s figures');
        self::assertStringContainsString($target, str_replace('\\', '/', $reply));
        self::assertStringStartsWith(Lang::t('cmd.insights.title'), $reply, 'the page prints first, the path line after');
        self::assertSame('0600', substr(sprintf('%o', fileperms($target)), -4));

        self::assertStringContainsString('Window: last 30 whole UTC days', $reply);
        self::assertStringContainsString('Totals: 3 sessions, 2 turns', $reply, 'the backdated row leaves the window; turns ride the roster column');
        self::assertStringContainsString('Spend by model: 2 groups', $reply);
        self::assertStringContainsString('≥$', $reply, 'the unpriced record folds a lower bound through the shared read');
        self::assertStringContainsString('Imported sessions: 1', $reply);

        $html = (string) file_get_contents($target);
        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('Window: last 30 whole UTC days', $html, 'file and page answer from ONE fact map');
        self::assertStringContainsString('≥$0.5000', $html);
        self::assertStringNotContainsString('2020-01-01', $html);
    }

    public function testAnAskedWindowNarrowsThePageAndAJunkWindowIsAUsageErrorNotADefault(): void
    {
        $shelf = $this->tempDir . '/shelf2';
        mkdir($shelf, 0700, true);
        $store = $this->insightsStore('window.db');
        $store->createSession('w-a', 'candy-code', 'm', null, null, '/home/me/alpha');

        $command = new InsightsHostCommand($shelf);
        $two = $command->run($this->insightsContext($store), 'insights 2')->rows[1]->content;
        self::assertStringContainsString('Window: last 2 whole UTC days', $two);

        foreach (['insights 0', 'insights -5', 'insights nope'] as $line) {
            $reply = $command->run($this->insightsContext($store), $line)->rows[1]->content;
            self::assertSame(Lang::t('cmd.insights.usage'), $reply, $line . ' must not silently print a different window');
        }

        self::assertCount(1, $this->insightsShelfFiles($shelf), 'the usage refusals write no file');
    }

    public function testAHostWithoutADurableStoreSaysUnavailableAndWritesNothing(): void
    {
        $shelf = $this->tempDir . '/shelf3';
        mkdir($shelf, 0700, true);

        $reply = (new InsightsHostCommand($shelf))->run(CommandContext::new(), 'insights')->rows[1]->content;

        self::assertSame(Lang::t('cmd.insights.unavailable', ['reason' => Lang::t('cmd.insights.no-store')]), $reply);
        self::assertSame([], $this->insightsShelfFiles($shelf), 'no file lands over an unreadable store');
    }

    public function testAnUnwritableShelfKeepsThePageAndStatesWhyNoFileLanded(): void
    {
        $store = $this->insightsStore('shelffail.db');
        $store->createSession('sf-a', 'candy-code', 'm', null, null, '/home/me/alpha');
        // A FILE where the shelf path needs a directory — the one shape
        // AtomicFileWriter cannot mkdir its way out of.
        file_put_contents($this->tempDir . '/blocker', 'not a directory');

        $reply = (new InsightsHostCommand($this->tempDir . '/blocker/deeper'))
            ->run($this->insightsContext($store), 'insights')->rows[1]->content;

        self::assertStringStartsWith(Lang::t('cmd.insights.title'), $reply, 'the measurement is not lost because the copy could not land');
        self::assertStringContainsString('Report not written:', $reply);
        self::assertStringContainsString('blocker', $reply, 'the refusal names the path that failed');
    }
}

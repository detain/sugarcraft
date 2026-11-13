<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\Connections;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Sql\Admin\AsyncCachingServerContext;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\Connections\ConnectionsPage;
use SugarCraft\Sql\Admin\ServerContext;
use SugarCraft\Sql\Core\Msg\ReloadReportMsg;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-P08 (adminpages-009) pins: the filtered-processlist memo is keyed on
 * (filter identity, provider identity). A pure cursor move — withSelectedIndex
 * and its navigation callers — must not re-fetch or re-wrap the DTO list,
 * while every state that CAN change the answer (filter toggle, arrival,
 * provider swap) must still re-derive it.
 *
 * The witness is the memo array's === identity as read off the property:
 * a recompute rebuilds every ProcesslistResult DTO from the raw rows, so a
 * re-derived list can never be the same array of the same objects — while a
 * memo hit returns the literal array the previous render produced.
 */
final class ConnectionsPageMemoTest extends TestCase
{
    protected function setUp(): void
    {
        AdminQueryCache::reset();
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
    }

    private function asyncContext(): AsyncCachingServerContext
    {
        $inner = new ServerContext(new FakeDatabase(), Flavor::MySQL);

        return new AsyncCachingServerContext($inner, null, ['max_connections' => '151'], false);
    }

    /**
     * @return array<string, string|int|null>
     */
    private function showRow(string $id, string $command = 'Query'): array
    {
        return [
            'Id' => $id,
            'User' => 'u' . $id,
            'Host' => 'localhost',
            'db' => 'my',
            'Command' => $command,
            'Time' => '0',
            'State' => '',
            'Info' => 'SELECT 1',
        ];
    }

    /**
     * Current memo state straight off the page (never triggers a fetch).
     *
     * @return list<\SugarCraft\Sql\Admin\Connections\ProcesslistResult>|null
     */
    private function memoOf(ConnectionsPage $page): ?array
    {
        return (new ReflectionProperty(ConnectionsPage::class, 'cachedFilteredProcesslist'))->getValue($page);
    }

    private function wiredPage(): ConnectionsPage
    {
        AdminQueryCache::instance()->store('SHOW FULL PROCESSLIST', [
            $this->showRow('1', 'Sleep'),
            $this->showRow('2', 'Query'),
        ]);

        $page = ConnectionsPage::new($this->asyncContext());
        $rows = $page->filteredProcesslist();
        $this->assertCount(2, $rows, 'warm-up render must see both seeded rows');
        $memo = $this->memoOf($page);
        $this->assertNotNull($memo, 'warm-up render must leave the memo populated');
        return $page;
    }

    public function testCursorBurstKeepsTheMemoWarm(): void
    {
        $page = $this->wiredPage();
        $before = $this->memoOf($page);

        $current = $page;
        for ($i = 0; $i < 8; $i++) {
            [$current, $cmd] = $current->update(new KeyMsg(KeyType::Char, $i % 2 === 0 ? 'j' : 'k'));
            $this->assertNull($cmd);
            $current->table();
        }

        $this->assertSame($current->filteredProcesslist(), $before, 'pure cursor moves must keep the memo warm: the same array of the same DTO instances — any re-derive would rebuild every ProcesslistResult (=== could not hold)');
        $this->assertSame(0, $current->selectedIndex(), 'the burst itself must still have moved the cursor (j/k alternation over 2 rows lands back on 0, guarding against a vacuous no-op burst)');
    }

    public function testWithSelectedIndexKeepsTheMemoWarm(): void
    {
        $page = $this->wiredPage();
        $before = $this->memoOf($page);

        $moved = $page->withSelectedIndex(1);
        $this->assertSame($before, $this->memoOf($moved), 'withSelectedIndex — the defect site itself — must not invalidate the memo');
        $this->assertSame(1, $moved->selectedIndex());
    }

    public function testFilterChangeStillRefilters(): void
    {
        $page = $this->wiredPage();
        $current = $page->withFilters($page->filters()->withHideSleeping(true));
        $rows = $current->filteredProcesslist();

        $this->assertCount(1, $rows, 'hide-sleeping must re-derive: the seeded Sleep row drops out');
        $this->assertSame('2', (string) $rows[0]->processId);
    }

    public function testArrivalStillInvalidatesTheMemo(): void
    {
        $page = $this->wiredPage();
        $before = $this->memoOf($page);

        [$reloaded, $cmd] = $page->update(new ReloadReportMsg());
        $this->assertNull($cmd);
        $this->assertInstanceOf(ConnectionsPage::class, $reloaded);

        /** @var ConnectionsPage $reloaded */
        $rows = $reloaded->filteredProcesslist();
        $this->assertCount(2, $rows, 'the re-derive must still serve the same data');
        $this->assertNotSame($this->memoOf($reloaded), $before, 'a data arrival must re-derive even though no cursor move happened');
    }

    public function testProviderSwapReDerivesWithoutAnyMessage(): void
    {
        $page = $this->wiredPage();
        $before = $this->memoOf($page);

        $refreshed = $page->refresh();
        $rows = $refreshed->filteredProcesslist();
        $this->assertCount(2, $rows, 'the re-derive must still serve the same data');
        $this->assertNotSame($before, $this->memoOf($refreshed), 'swapping the provider changes the data identity — the memo belt must catch it without a ReloadReportMsg');
    }

    public function testUnwiredPageFailsLoudInsteadOfEmptyGrid(): void
    {
        $page = new ConnectionsPage($this->asyncContext());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('processlist provider was wired');
        $page->filteredProcesslist();
    }
}

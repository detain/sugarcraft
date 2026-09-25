<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Query\Admin\AdminQueryCache;
use SugarCraft\Query\Admin\ServerStatus\ServerStatusPage;
use SugarCraft\Query\App;
use SugarCraft\Query\Core\Msg\AdminDataLoadedMsg;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * The lazily-built admin page must be pinned into model state the moment
 * async data arrives — not only when some keypress happened to delegate to
 * it first.
 *
 * WHY: adminPage() never persisted its build, so on an un-touched pane every
 * renderer call minted a throwaway page (and with it a throwaway
 * MetricsColumn/Sampler). Rolling-window widgets could never accumulate and
 * every rate read zero on the live Server Status page (Phase 2b smoke).
 */
final class AdminPagePersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AdminQueryCache::reset();
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
        parent::tearDown();
    }

    public function testFirstAdminDataLoadedMsgPinsTheLazyPage(): void
    {
        $app = $this->statusApp();
        $this->assertNull($app->admin->page, 'pane switch alone must not build the page');

        [$pinned,] = $app->update(new AdminDataLoadedMsg($this->vars('100'), $this->serverVars(), microtime(true)));

        $this->assertInstanceOf(ServerStatusPage::class, $pinned->admin->page);
        $this->assertSame($pinned->admin->page, $pinned->adminPage(), 'the getter must reuse the pinned instance');
    }

    public function testThePageInstanceSurvivesSuccessiveDataLoads(): void
    {
        $app = $this->statusApp();
        [$app,] = $app->update(new AdminDataLoadedMsg($this->vars('100'), $this->serverVars(), 1000.0));
        $first = $app->admin->page;

        [$app,] = $app->update(new AdminDataLoadedMsg($this->vars('110'), $this->serverVars(), 1001.0));
        $second = $app->admin->page;

        $this->assertSame($first, $second);
    }

    public function testWindowsAccumulateAcrossDataLoadsOnThePinnedPage(): void
    {
        // Two arrivals one cadence apart must land in ONE column: the whole
        // point of pinning is that the graph windows keep history. Store to
        // the shared AdminQueryCache first, exactly as App's fetch promise
        // does before resolving AdminDataLoadedMsg — the page's reload arm
        // adopts from that cache, not from the message payload.
        $app = $this->statusApp();
        AdminQueryCache::instance()->store('status', $this->vars('100'));
        [$app,] = $app->update(new AdminDataLoadedMsg($this->vars('100'), $this->serverVars(), 1000.0));
        AdminQueryCache::instance()->store('status', $this->vars('117'));
        [$app,] = $app->update(new AdminDataLoadedMsg($this->vars('117'), $this->serverVars(), 1001.0));

        $page = $app->admin->page;
        $this->assertInstanceOf(ServerStatusPage::class, $page);

        $column = (new \ReflectionProperty(ServerStatusPage::class, 'column'));
        $column->setAccessible(true);
        $col = $column->getValue($page);
        $this->assertNotNull($col);

        $cells = (new \ReflectionProperty(get_class($col), 'cells'));
        $cells->setAccessible(true);
        $connections = $cells->getValue($col)['connections'];

        $window = (new \ReflectionProperty(get_class($connections), 'windows'));
        $window->setAccessible(true);
        // msg1 pins + builds the page (its construction polls 100; its own
        // ReloadReportMsg arm re-polls the identical frame and the column's
        // idempotence guard drops it — no duplicate point); msg2's changed
        // frame adds 117. The point is the window GROWS ACROSS arrivals on
        // one instance — a throwaway page per render could never hold more
        // than one value.
        $this->assertSame([100.0, 117.0], $window->getValue($connections)['threads']);
    }

    /** @return App navigated to the Admin pane with the Server Status sidebar entry selected. */
    private function statusApp(): App
    {
        [$a,] = App::start(new FakeDatabase(), Flavor::MySQL)->update(new KeyMsg(KeyType::Tab, ''));
        [$a,] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a,] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a,] = $a->update(new KeyMsg(KeyType::Char, '3'));
        $this->assertInstanceOf(ServerStatusPage::class, $a->adminPage());

        // Pane selection nulled the pin; the assertion above built a throwaway.
        return $a;
    }

    /** @return array<string, string> */
    private function vars(string $threads): array
    {
        return [
            'Uptime' => '3600',
            'Threads_connected' => $threads,
            'Threads_running' => '2',
            'Questions' => '500',
            'Com_select' => '200',
            'Bytes_received' => '100000',
            'Bytes_sent' => '900000',
        ];
    }

    /** @return array<string, string> */
    private function serverVars(): array
    {
        return ['max_connections' => '151'];
    }
}

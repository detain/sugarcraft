<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\Dashboard\DashboardPage;
use SugarCraft\Sql\Admin\Dashboard\WidgetRegistry;
use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\Version;

/**
 * Tests for DashboardPage.
 */
final class DashboardPageTest extends TestCase
{
    public function testConstruction(): void
    {
        $context = $this->createMock(ServerContextInterface::class);
        $context->method('flavor')->willReturn(Flavor::MySQL);
        $context->method('statusVariables')->willReturn([
            'Bytes_received' => '1000',
            'Bytes_sent' => '500',
            'Threads_connected' => '10',
            'Com_select' => '100',
            'Uptime' => '3600',
        ]);
        $context->method('serverVariables')->willReturn([
            'max_connections' => '100',
        ]);
        $context->method('statusVariablesTs')->willReturn(microtime(true));
        $context->method('version')->willReturn(Version::parse('8.0.36'));
        $context->method('versionString')->willReturn('8.0.36');

        $page = new DashboardPage($context);

        $this->assertFalse($page->isPaused());
    }

    public function testWithTogglePause(): void
    {
        $context = $this->createMock(ServerContextInterface::class);
        $context->method('flavor')->willReturn(Flavor::MySQL);
        $context->method('statusVariables')->willReturn([]);
        $context->method('serverVariables')->willReturn([]);
        $context->method('statusVariablesTs')->willReturn(0.0);
        $context->method('version')->willReturn(Version::parse('8.0.36'));

        $page = new DashboardPage($context);

        $paused = $page->withTogglePause();
        $this->assertTrue($paused->isPaused());

        $unpaused = $paused->withTogglePause();
        $this->assertFalse($unpaused->isPaused());
    }

    public function testWithReset(): void
    {
        $context = $this->createMock(ServerContextInterface::class);
        $context->method('flavor')->willReturn(Flavor::MySQL);
        $context->method('statusVariables')->willReturn([
            'Bytes_received' => '1000',
            'Bytes_sent' => '500',
            'Threads_connected' => '10',
            'Com_select' => '100',
            'Uptime' => '3600',
        ]);
        $context->method('serverVariables')->willReturn([
            'max_connections' => '100',
        ]);
        $context->method('statusVariablesTs')->willReturn(microtime(true));
        $context->method('version')->willReturn(Version::parse('8.0.36'));
        $context->method('versionString')->willReturn('8.0.36');

        $page = new DashboardPage($context);

        $page->withReset();

        $this->assertFalse($page->isPaused());
    }

    public function testUpdateWithKeyMsg(): void
    {
        $context = $this->createMock(ServerContextInterface::class);
        $context->method('flavor')->willReturn(Flavor::MySQL);
        $context->method('statusVariables')->willReturn([]);
        $context->method('serverVariables')->willReturn([]);
        $context->method('statusVariablesTs')->willReturn(0.0);
        $context->method('version')->willReturn(Version::parse('8.0.36'));

        $page = new DashboardPage($context);

        $msg = new \SugarCraft\Core\Msg\KeyMsg(
            type: \SugarCraft\Core\KeyType::Char,
            rune: 'p',
            ctrl: false,
            shift: false,
        );

        [$newPage] = $page->update($msg);

        $this->assertTrue($newPage->isPaused());
    }

    public function testUpdateNonKeyMsgReturnsUnchanged(): void
    {
        $context = $this->createMock(ServerContextInterface::class);
        $context->method('flavor')->willReturn(Flavor::MySQL);
        $context->method('statusVariables')->willReturn([]);
        $context->method('serverVariables')->willReturn([]);
        $context->method('statusVariablesTs')->willReturn(0.0);
        $context->method('version')->willReturn(Version::parse('8.0.36'));

        $page = new DashboardPage($context);

        $msg = new \SugarCraft\Core\Msg\KeyMsg(
            type: \SugarCraft\Core\KeyType::Escape,
            rune: '',
            ctrl: false,
            shift: false,
        );

        [$newPage] = $page->update($msg);

        $this->assertSame($page, $newPage);
    }

    public function testAlertBadgeInFooter(): void
    {
        $context = $this->createMock(ServerContextInterface::class);
        $context->method('flavor')->willReturn(Flavor::MySQL);
        $context->method('statusVariables')->willReturn([
            'Bytes_received' => '1000',
            'Bytes_sent' => '500',
            'Threads_connected' => '10',
            'Com_select' => '100',
            'Uptime' => '3600',
        ]);
        $context->method('serverVariables')->willReturn([
            'max_connections' => '100',
        ]);
        $context->method('statusVariablesTs')->willReturn(microtime(true));
        $context->method('version')->willReturn(Version::parse('8.0.36'));
        $context->method('versionString')->willReturn('8.0.36');

        $page = new DashboardPage($context);

        // Use reflection to inject pending alerts since there's no public setter
        $reflection = new \ReflectionClass($page);
        $property = $reflection->getProperty('pendingAlerts');
        $property->setAccessible(true);
        $property->setValue($page, [
            \SugarCraft\Sql\Admin\Alerts\Alert::warning('test', 'Test alert', 0.85, 0.8),
            \SugarCraft\Sql\Admin\Alerts\Alert::critical('cpu', 'CPU high', 0.95, 0.9),
        ]);

        $view = $page->view();

        $this->assertStringContainsString('[!] 2 alerts', $view);
        $this->assertStringContainsString('[a] dismiss', $view);
    }

    /**
     * The dashboard surfaces a compact, live query-log strip (fed by
     * QueryLogger) so the queries actually hitting the server are visible
     * without navigating to the standalone Debug pane.
     */
    public function testQueryLogStripRendersRecentQueries(): void
    {
        \SugarCraft\Sql\Admin\QueryLogger::clear();
        \SugarCraft\Sql\Admin\QueryLogger::log('query', 'SELECT * FROM processlist', 7);

        $context = $this->createMock(ServerContextInterface::class);
        $context->method('flavor')->willReturn(Flavor::MySQL);
        $context->method('statusVariables')->willReturn([
            'Bytes_received' => '1000',
            'Bytes_sent' => '500',
            'Threads_connected' => '10',
            'Com_select' => '100',
            'Uptime' => '3600',
        ]);
        $context->method('serverVariables')->willReturn(['max_connections' => '100']);
        $context->method('statusVariablesTs')->willReturn(microtime(true));
        $context->method('version')->willReturn(Version::parse('8.0.36'));
        $context->method('versionString')->willReturn('8.0.36');

        $view = (new DashboardPage($context))->view();

        $this->assertStringContainsString('Recent Queries', $view);
        $this->assertStringContainsString('SELECT * FROM processlist', $view);
        $this->assertStringContainsString('7 rows', $view);

        \SugarCraft\Sql\Admin\QueryLogger::clear();
    }

    public function testQueryLogStripShowsPlaceholderWhenEmpty(): void
    {
        \SugarCraft\Sql\Admin\QueryLogger::clear();

        $context = $this->createMock(ServerContextInterface::class);
        $context->method('flavor')->willReturn(Flavor::MySQL);
        $context->method('statusVariables')->willReturn([
            'Bytes_received' => '1000',
            'Uptime' => '3600',
        ]);
        $context->method('serverVariables')->willReturn(['max_connections' => '100']);
        $context->method('statusVariablesTs')->willReturn(microtime(true));
        $context->method('version')->willReturn(Version::parse('8.0.36'));
        $context->method('versionString')->willReturn('8.0.36');

        $view = (new DashboardPage($context))->view();

        $this->assertStringContainsString('Recent Queries', $view);
        $this->assertStringContainsString('(no queries yet)', $view);
    }

    public function testKeyboardShortcutADismissesAlerts(): void
    {
        $context = $this->createMock(ServerContextInterface::class);
        $context->method('flavor')->willReturn(Flavor::MySQL);
        $context->method('statusVariables')->willReturn([]);
        $context->method('serverVariables')->willReturn([]);
        $context->method('statusVariablesTs')->willReturn(0.0);
        $context->method('version')->willReturn(Version::parse('8.0.36'));

        $page = new DashboardPage($context);

        // Inject a pending alert via reflection
        $reflection = new \ReflectionClass($page);
        $property = $reflection->getProperty('pendingAlerts');
        $property->setAccessible(true);
        $property->setValue($page, [
            \SugarCraft\Sql\Admin\Alerts\Alert::warning('test', 'Test alert', 0.85, 0.8),
        ]);

        $this->assertSame(1, $page->alertCount());

        $msg = new \SugarCraft\Core\Msg\KeyMsg(
            type: \SugarCraft\Core\KeyType::Char,
            rune: 'a',
            ctrl: false,
            shift: false,
        );

        [$newPage] = $page->update($msg);

        $this->assertSame(0, $newPage->alertCount());
        $this->assertNotSame($page, $newPage);
    }

    public function testAlertCountAndPendingAlertsAccessors(): void
    {
        $context = $this->createMock(ServerContextInterface::class);
        $context->method('flavor')->willReturn(Flavor::MySQL);
        $context->method('statusVariables')->willReturn([]);
        $context->method('serverVariables')->willReturn([]);
        $context->method('statusVariablesTs')->willReturn(0.0);
        $context->method('version')->willReturn(Version::parse('8.0.36'));

        $page = new DashboardPage($context);

        $this->assertSame(0, $page->alertCount());
        $this->assertSame([], $page->pendingAlerts());

        // Inject alerts via reflection
        $alerts = [
            \SugarCraft\Sql\Admin\Alerts\Alert::warning('conn', 'Connections high', 0.85, 0.8),
            \SugarCraft\Sql\Admin\Alerts\Alert::info('cache', 'Cache hit ratio', 0.75, 0.7),
        ];

        $reflection = new \ReflectionClass($page);
        $property = $reflection->getProperty('pendingAlerts');
        $property->setAccessible(true);
        $property->setValue($page, $alerts);

        $this->assertSame(2, $page->alertCount());
        $this->assertSame($alerts, $page->pendingAlerts());
    }
}

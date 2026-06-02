<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Dashboard\DashboardPage;
use SugarCraft\Query\Admin\Dashboard\WidgetRegistry;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;

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
}

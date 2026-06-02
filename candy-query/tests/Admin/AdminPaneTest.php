<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AdminPane;
use SugarCraft\Query\Admin\AdminSection;

/**
 * Tests for AdminPane and AdminSection enums.
 */
final class AdminPaneTest extends TestCase
{
    public function testAdminPaneLabels(): void
    {
        $this->assertSame('Process List', AdminPane::ProcessList->label());
        $this->assertSame('Variables', AdminPane::Variables->label());
        $this->assertSame('Status', AdminPane::Status->label());
        $this->assertSame('Query Stats', AdminPane::QueryStats->label());
        $this->assertSame('Connection Stats', AdminPane::ConnStats->label());
        $this->assertSame('Table Stats', AdminPane::TableStats->label());
    }

    public function testAdminPaneSections(): void
    {
        $this->assertSame(AdminSection::Management, AdminPane::ProcessList->section());
        $this->assertSame(AdminSection::Management, AdminPane::Variables->section());
        $this->assertSame(AdminSection::Management, AdminPane::Status->section());
        $this->assertSame(AdminSection::Performance, AdminPane::QueryStats->section());
        $this->assertSame(AdminSection::Performance, AdminPane::ConnStats->section());
        $this->assertSame(AdminSection::Performance, AdminPane::TableStats->section());
    }

    public function testAdminPaneNextCycles(): void
    {
        $this->assertSame(AdminPane::Variables, AdminPane::ProcessList->next());
        $this->assertSame(AdminPane::Status, AdminPane::Variables->next());
        $this->assertSame(AdminPane::QueryStats, AdminPane::Status->next());
        $this->assertSame(AdminPane::ConnStats, AdminPane::QueryStats->next());
        $this->assertSame(AdminPane::TableStats, AdminPane::ConnStats->next());
        $this->assertSame(AdminPane::ProcessList, AdminPane::TableStats->next());
    }

    public function testAdminPaneAllReturnsAllPanes(): void
    {
        $all = AdminPane::all();
        $this->assertCount(6, $all);
        $this->assertContainsOnly(AdminPane::class, $all);
    }

    public function testAdminSectionLabels(): void
    {
        $this->assertSame('Management', AdminSection::Management->label());
        $this->assertSame('Performance', AdminSection::Performance->label());
    }
}

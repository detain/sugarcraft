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
        $this->assertSame('Dashboard', AdminPane::Dashboard->label());
        $this->assertSame('Table Stats', AdminPane::TableStats->label());
        $this->assertSame('Performance Schema', AdminPane::PerfSchema->label());
        $this->assertSame('Debug', AdminPane::Debug->label());
    }

    public function testAdminPaneSections(): void
    {
        $this->assertSame(AdminSection::Management, AdminPane::ProcessList->section());
        $this->assertSame(AdminSection::Management, AdminPane::Variables->section());
        $this->assertSame(AdminSection::Management, AdminPane::Status->section());
        $this->assertSame(AdminSection::Management, AdminPane::Debug->section());
        $this->assertSame(AdminSection::Performance, AdminPane::QueryStats->section());
        $this->assertSame(AdminSection::Performance, AdminPane::Dashboard->section());
        $this->assertSame(AdminSection::Performance, AdminPane::TableStats->section());
        $this->assertSame(AdminSection::Performance, AdminPane::PerfSchema->section());
    }

    public function testAdminPaneNextCycles(): void
    {
        $this->assertSame(AdminPane::Variables, AdminPane::ProcessList->next());
        $this->assertSame(AdminPane::Status, AdminPane::Variables->next());
        $this->assertSame(AdminPane::QueryStats, AdminPane::Status->next());
        $this->assertSame(AdminPane::Dashboard, AdminPane::QueryStats->next());
        $this->assertSame(AdminPane::TableStats, AdminPane::Dashboard->next());
        $this->assertSame(AdminPane::PerfSchema, AdminPane::TableStats->next());
        $this->assertSame(AdminPane::Debug, AdminPane::PerfSchema->next());
        $this->assertSame(AdminPane::ProcessList, AdminPane::Debug->next());
    }

    public function testAdminPaneAllReturnsAllPanes(): void
    {
        $all = AdminPane::all();
        $this->assertCount(8, $all);
        $this->assertContainsOnly(AdminPane::class, $all);
    }

    public function testAdminSectionLabels(): void
    {
        $this->assertSame('Management', AdminSection::Management->label());
        $this->assertSame('Performance', AdminSection::Performance->label());
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

/**
 * An organizational chart node with optional reports.
 */
final class OrgChartNode
{
    /** @var list<OrgChartNode> */
    public array $reports = [];

    public function __construct(
        public readonly string $name,
        public readonly ?string $title = null,
        public readonly ?string $department = null,
        public readonly ?Color $color = null,
        public readonly ?string $avatar = null,
    ) {}

    /**
     * Add a direct report.
     */
    public function withReport(OrgChartNode $report): self
    {
        $clone = clone $this;
        $clone->reports[] = $report;
        return $clone;
    }

    /**
     * Add a direct report by name.
     */
    public function withReportByName(string $name, ?string $title = null, ?string $department = null): self
    {
        $report = new OrgChartNode($name, $title, $department, $this->color);
        return $this->withReport($report);
    }
}

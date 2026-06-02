<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

use SugarCraft\Query\Admin\Format;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Version;
use SugarCraft\Layout\Region;
use SugarCraft\Layout\Direction;
use SugarCraft\Layout\GreedySolver;
use SugarCraft\Layout\Constraint\Constraint;

/**
 * Performance Dashboard page with 3-column layout.
 *
 * Shows Network, MySQL, and InnoDB panels with live metrics,
 * timeline graphs, counters, and meters. Updates every 3 seconds
 * by sampling the ServerContext cache.
 *
 * Keyboard shortcuts:
 *   [p] - pause/resume auto-refresh
 *   [r] - reset all counters and graphs
 *   [1] - focus Network panel
 *   [2] - focus MySQL panel
 *   [3] - focus InnoDB panel
 *   [q] - quit to previous view
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard
 */
final class DashboardPage extends PageBase
{
    private bool $paused = false;

    private int $focusedPanel = 0;

    private ?float $lastPollAt = null;

    /** @var array<string, TimeSeriesCell> */
    private array $timelineCells = [];

    /** @var array<string, CounterCell> */
    private array $counterCells = [];

    /** @var array<string, MeterCell> */
    private array $meterCells = [];

    /** @var array<Widget> */
    private array $allWidgets = [];

    private ?string $previousSnapshot = null;

    public function __construct(
        ServerContextInterface $context,
        ?Version $version = null,
    ) {
        parent::__construct($context);
        $this->allWidgets = WidgetRegistry::build($version ?? $this->context->version());
        $this->initializeCells();
    }

    protected function validate(): bool
    {
        try {
            $vars = $this->context->statusVariables();
            return count($vars) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function build(): string
    {
        $this->pollAndUpdateCells();

        $width = 80;
        $height = 24;

        $region = Region::fromSize($width, $height);

        $colConstraints = [
            Constraint::percentage(33),
            Constraint::percentage(34),
            Constraint::percentage(33),
        ];

        $solver = GreedySolver::new();
        $columns = $solver->solve($region, Direction::Horizontal, $colConstraints);

        $networkCol = $columns[0] ?? $region;
        $mysqlCol = $columns[1] ?? $region;
        $innodbCol = $columns[2] ?? $region;

        $networkContent = $this->renderPanel('Network', $networkCol, 'network');
        $mysqlContent = $this->renderPanel('MySQL', $mysqlCol, 'mysql');
        $innodbContent = $this->renderPanel('InnoDB', $innodbCol, 'innodb');

        $header = $this->renderHeader();
        $footer = $this->renderFooter();

        return $this->assembleLayout($header, $networkContent, $mysqlContent, $innodbContent, $footer);
    }

    public function update(\SugarCraft\Core\Msg $msg): array
    {
        if (!$msg instanceof \SugarCraft\Core\Msg\KeyMsg) {
            return [$this, null];
        }

        $ch = $msg->rune ?? '';

        return match (true) {
            $ch === 'p' => [$this->withTogglePause(), null],
            $ch === 'r' => [$this->withReset(), null],
            $ch === '1' => [$this->withFocusedPanel(0), null],
            $ch === '2' => [$this->withFocusedPanel(1), null],
            $ch === '3' => [$this->withFocusedPanel(2), null],
            $ch === 'q' => [$this->withQuit(), null],
            default => [$this, null],
        };
    }

    private function pollAndUpdateCells(): void
    {
        if ($this->paused) {
            return;
        }

        $now = microtime(true);

        if ($this->lastPollAt !== null && ($now - $this->lastPollAt) < 3.0) {
            return;
        }

        $this->lastPollAt = $now;

        $current = $this->context->statusVariables();
        $previous = $this->previousSnapshot !== null
            ? (array) json_decode($this->previousSnapshot, true)
            : $current;
        $serverVars = $this->context->serverVariables();

        $elapsed = 3.0;

        foreach ($this->timelineCells as $cell) {
            $cell->ingest($current, $previous, $elapsed);
        }

        foreach ($this->counterCells as $cell) {
            $cell->ingest($current, $previous, $elapsed);
        }

        foreach ($this->meterCells as $cell) {
            $cell->ingest($current, $previous, $elapsed, $serverVars);
        }

        $this->previousSnapshot = json_encode($current);
    }

    private function initializeCells(): void
    {
        foreach ($this->allWidgets as $widget) {
            $id = $this->widgetId($widget);

            match ($widget->kind) {
                WidgetRegistry::KIND_TIMELINE => $this->timelineCells[$id] = new TimeSeriesCell($widget),
                WidgetRegistry::KIND_COUNTER => $this->counterCells[$id] = new CounterCell($widget),
                WidgetRegistry::KIND_ROUND, WidgetRegistry::KIND_LEVEL => $this->meterCells[$id] = new MeterCell($widget),
                default => null,
            };
        }
    }

    private function widgetId(Widget $widget): string
    {
        return $widget->caption . ':' . $widget->kind;
    }

    private function renderPanel(string $title, Region $region, string $section): string
    {
        $lines = [];
        $lines[] = "\x1b[1;36m" . $title . "\x1b[0m";

        $widgets = $this->getWidgetsForSection($section);

        foreach ($widgets as $widget) {
            $id = $this->widgetId($widget);
            $kind = $widget->kind;

            $value = match (true) {
                isset($this->timelineCells[$id]) => $this->timelineCells[$id]->view(),
                isset($this->counterCells[$id]) => $this->counterCells[$id]->view(),
                isset($this->meterCells[$id]) => $this->meterCells[$id]->view(),
                default => '',
            };

            $color = $widget->color;
            $colorCode = sprintf("\x1b[38;2;%d;%d;%dm", $color['r'], $color['g'], $color['b']);

            $lines[] = $colorCode . $widget->caption . "\x1b[0m: " . $value;
        }

        $padding = $region->height - count($lines);
        for ($i = 0; $i < $padding; $i++) {
            $lines[] = '';
        }

        return implode("\n", array_slice($lines, 0, $region->height));
    }

    /**
     * @return list<Widget>
     */
    private function getWidgetsForSection(string $section): array
    {
        $networkWidgets = WidgetRegistry::network();
        $mysqlWidgets = WidgetRegistry::mysql($this->context->version());
        $innodbWidgets = WidgetRegistry::innodb();

        return match ($section) {
            'network' => $networkWidgets,
            'mysql' => $mysqlWidgets,
            'innodb' => $innodbWidgets,
            default => [],
        };
    }

    private function renderHeader(): string
    {
        $version = $this->context->versionString();
        $uptime = $this->context->statusVariables()['Uptime'] ?? '0';
        $uptimeStr = Format::duration((float) $uptime);
        $status = $this->paused ? ' [PAUSED]' : '';

        return sprintf(
            "Performance Dashboard%s | MySQL %s | Uptime: %s\n",
            $status,
            $version,
            $uptimeStr,
        );
    }

    private function renderFooter(): string
    {
        return "[p] pause  [r] reset  [1-3] panels  [q] quit";
    }

    private function assembleLayout(
        string $header,
        string $network,
        string $mysql,
        string $innodb,
        string $footer,
    ): string {
        $headerLines = explode("\n", $header);
        $footerLines = explode("\n", $footer);

        $networkLines = explode("\n", $network);
        $mysqlLines = explode("\n", $mysql);
        $innodbLines = explode("\n", $innodb);

        $contentHeight = max(count($networkLines), count($mysqlLines), count($innodbLines));

        $networkPad = array_fill(0, $contentHeight - count($networkLines), '');
        $mysqlPad = array_fill(0, $contentHeight - count($mysqlLines), '');
        $innodbPad = array_fill(0, $contentHeight - count($innodbLines), '');

        $networkLines = array_merge($networkLines, $networkPad);
        $mysqlLines = array_merge($mysqlLines, $mysqlPad);
        $innodbLines = array_merge($innodbLines, $innodbPad);

        $separator = "\x1b[36m│\x1b[0m";

        $bodyLines = [];
        for ($i = 0; $i < $contentHeight; $i++) {
            $bodyLines[] = $networkLines[$i] . ' ' . $separator . ' ' . $mysqlLines[$i] . ' ' . $separator . ' ' . $innodbLines[$i];
        }

        return implode("\n", array_merge($headerLines, $bodyLines, $footerLines));
    }

    public function withTogglePause(): self
    {
        $clone = clone $this;
        $clone->paused = !$clone->paused;
        return $clone;
    }

    public function withReset(): self
    {
        $clone = clone $this;
        foreach ($clone->timelineCells as $cell) {
            $cell->reset();
        }
        foreach ($clone->counterCells as $cell) {
            $cell->reset();
        }
        foreach ($clone->meterCells as $cell) {
            $cell->reset();
        }
        $clone->previousSnapshot = null;
        $clone->lastPollAt = null;
        return $clone;
    }

    public function withFocusedPanel(int $panel): self
    {
        $clone = clone $this;
        $clone->focusedPanel = $panel;
        return $clone;
    }

    public function withQuit(): self
    {
        $clone = clone $this;
        return $clone;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function focusedPanel(): int
    {
        return $this->focusedPanel;
    }
}

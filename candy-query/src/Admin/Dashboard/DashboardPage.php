<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

use SugarCraft\Core\Util\Color;
use SugarCraft\Forms\Spinner\Spinner;
use SugarCraft\Forms\Spinner\Style as SpinnerStyle;
use SugarCraft\Query\Admin\CachingServerContext;
use SugarCraft\Query\Admin\Format;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;
use SugarCraft\Query\Renderer;
use SugarCraft\Layout\Region;
use SugarCraft\Layout\Direction;
use SugarCraft\Layout\GreedySolver;
use SugarCraft\Layout\Constraint\Constraint;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;
use SugarCraft\Sprinkles\Style;

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
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard
 */
final class DashboardPage extends PageBase
{
    private bool $paused = false;

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

    private bool $isPostgres = false;

    public function __construct(
        ServerContextInterface $context,
        ?Version $version = null,
    ) {
        parent::__construct($context);
        $this->isPostgres = $this->context->flavor() === Flavor::Postgres;
        $this->allWidgets = $this->isPostgres
            ? WidgetRegistry::buildForPostgres()
            : WidgetRegistry::build($version ?? $this->context->version());
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

    public function view(): string
    {
        // Show loading screen when fetch is in flight and no cached data available
        if ($this->context instanceof CachingServerContext
            && $this->context->isLoading()
            && !$this->context->hasCachedData()) {
            return $this->renderLoadingScreen();
        }
        if (!$this->validate()) {
            return $this->errorScreen();
        }
        return $this->build();
    }

    private function renderLoadingScreen(): string
    {
        $glyph = Spinner::new(SpinnerStyle::dot())->view();
        $muted = Style::new()->foreground(Color::hex('#6b7280'));

        return implode("\n", [
            Style::new()->bold()->foreground(Color::hex('#22d3ee'))->render('  Performance Dashboard'),
            '',
            Style::new()->foreground(Color::hex('#fbbf24'))->render("    {$glyph} Fetching server metrics..."),
            '',
            '  ' . $muted->render('SHOW GLOBAL STATUS / pg_stat_database'),
            '',
            '  ' . $muted->render('Press [q] to return to browse mode'),
        ]);
    }

    protected function build(): string
    {
        $this->pollAndUpdateCells();

        // C3: size the dashboard from the live terminal (the Program's
        // WindowSizeMsg, forwarded into Renderer::setSize) instead of a fixed
        // 80×24, so it tracks resizes. The dashboard fills the admin pane's
        // content column; Renderer::adminContentWidth() is the single source of
        // truth for that width (shared with Renderer::adminPane()), so the two
        // can no longer drift apart.
        $size = Renderer::getTerminalSize();
        $width = Renderer::adminContentWidth($size['cols']);
        $height = max(12, $size['rows'] - 4);

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

        $networkContent = $this->renderPanel($this->isPostgres ? 'I/O' : 'Network', $networkCol, 'network');
        $mysqlContent = $this->renderPanel($this->isPostgres ? 'Transactions' : 'MySQL', $mysqlCol, 'mysql');
        $innodbContent = $this->renderPanel($this->isPostgres ? 'Cache' : 'InnoDB', $innodbCol, 'innodb');

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
        $lines[] = Style::new()->bold()->foreground(Color::hex('#22d3ee'))->render($title);

        $widgets = $this->getWidgetsForSection($section);

        foreach ($widgets as $widget) {
            $id = $this->widgetId($widget);

            $value = match (true) {
                isset($this->timelineCells[$id]) => $this->timelineCells[$id]->view(),
                isset($this->counterCells[$id]) => $this->counterCells[$id]->view(),
                isset($this->meterCells[$id]) => $this->meterCells[$id]->view(),
                default => '',
            };

            $color = $widget->color;
            $caption = Style::new()
                ->foreground(Color::rgb($color['r'], $color['g'], $color['b']))
                ->render($widget->caption);

            $lines[] = $caption . ': ' . $value;
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
        if ($this->isPostgres) {
            return match ($section) {
                'network' => WidgetRegistry::postgresIo(),
                'mysql' => WidgetRegistry::postgresTransactions(),
                'innodb' => WidgetRegistry::postgresCache(),
                default => [],
            };
        }

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
        $status = $this->paused ? ' [PAUSED]' : '';

        if ($this->isPostgres) {
            return sprintf(
                "Performance Dashboard%s | PostgreSQL %s | Uptime: N/A\n",
                $status,
                $version,
            );
        }

        $uptime = $this->context->statusVariables()['Uptime'] ?? '0';
        $uptimeStr = Format::duration((float) $uptime);

        return sprintf(
            "Performance Dashboard%s | MySQL %s | Uptime: %s\n",
            $status,
            $version,
            $uptimeStr,
        );
    }

    private function renderFooter(): string
    {
        return Style::new()->foreground(Color::hex('#6b7280'))->render('[p] pause  [r] reset');
    }

    private function assembleLayout(
        string $header,
        string $network,
        string $mysql,
        string $innodb,
        string $footer,
    ): string {
        // renderPanel pads every column to the region height, so a separator
        // sized to the tallest column spans the whole body. Sprinkles\Layout
        // joins the columns ANSI-width-aware (and aligns the dividers into a
        // straight vertical rule, which the old per-row concat did not).
        $contentHeight = max(
            substr_count($network, "\n"),
            substr_count($mysql, "\n"),
            substr_count($innodb, "\n"),
        ) + 1;

        $sepLine = ' ' . Style::new()->foreground(Color::hex('#22d3ee'))->render('│') . ' ';
        $separator = implode("\n", array_fill(0, $contentHeight, $sepLine));

        $body = Layout::joinHorizontal(Position::TOP, $network, $separator, $mysql, $separator, $innodb);

        return implode("\n", array_merge(
            explode("\n", $header),
            explode("\n", $body),
            explode("\n", $footer),
        ));
    }

    public function withTogglePause(): self
    {
        $clone = clone $this;
        $clone->paused = !$clone->paused;
        return $clone;
    }

    public function withPaused(bool $paused): self
    {
        if ($this->paused === $paused) {
            return $this;
        }
        $clone = clone $this;
        $clone->paused = $paused;
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

    public function isPaused(): bool
    {
        return $this->paused;
    }

}

<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Plot\Chart\Gauge;
use SugarCraft\Query\Admin\AsyncCachingServerContext;
use SugarCraft\Query\Admin\CacheTtl;
use SugarCraft\Query\Admin\Calc\HostLoadMode;
use SugarCraft\Query\Admin\Calc\HostLoadSampler;
use SugarCraft\Query\Admin\Sampler;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;
use SugarCraft\Sprinkles\Style;

/**
 * Right-hand metric column of the Server Status page — the Workbench
 * Management :: Server Status parity target (query_dashboard.md 42-69).
 *
 * Replaces the older five-gauge sidebar rendering while reusing its data
 * plumbing: the same Sampler instance feeds rates, and the two percentage
 * labels that Workbench shows as cumulative ratios (key efficiency, buffer
 * usage) reuse SidebarGaugeSet's exact formulas rather than inventing
 * variants, so the number a user compared against the old gauges is stable.
 *
 * Frame order mirrors Workbench: CPU/Load first, then connections, traffic,
 * key efficiency, selects per second, and the three InnoDB panels. Polls
 * self-throttle at CacheTtl::DASHBOARD (1 s), the same cadence App's admin
 * tick delivers data at; ReloadReportMsg from App bypasses the gate because
 * fresh data just landed in the shared cache.
 *
 * Mutable by contract like the DashboardPage cells it parallels: frames
 * accumulate rolling windows, so the column is a sampler-side accumulator,
 * not a value object.
 */
final class MetricsColumn
{
    /** Rows<12 cannot host a readable vertical bar — fall back to a horizontal gauge. */
    public const int MIN_ROWS_FOR_VERTICAL_BAR = 12;

    /** Narrowest frame that still fits the longest label ("busy proxy 100.0%"). */
    private const int MIN_FRAME_WIDTH = 18;

    /** Width at which the third column earns its space (labels + ~14-char graphs). */
    private const int THREE_COLUMN_WIDTH = 64;

    private const int TWO_COLUMN_WIDTH = 42;

    /** @var array<string, StatusGraphCell> keyed by frame name, Workbench order */
    private array $cells;

    private HostLoadSampler $hostLoad;

    /** @var array<string, string>|null last status frame polled (label source) */
    private ?array $lastStatusVars = null;

    /** @var array<string, float> last computed rates */
    private array $lastRates = [];

    /** @var array<string, string>|null raw previous status frame for rate fallback */
    private ?array $previousSnapshot = null;

    private ?float $lastPollAt = null;

    private ?float $lastSnapshotAt = null;

    public function __construct(
        private readonly ServerContextInterface $context,
        private readonly ?Sampler $sampler,
        ?HostLoadSampler $hostLoad = null,
    ) {
        $this->hostLoad = $hostLoad ?? HostLoadSampler::forDsn($this->dsnOrNull());
        $this->cells = self::buildCells();
    }

    /**
     * @param array<string, string>|null $serverVars
     */
    public static function forContext(
        ServerContextInterface $context,
        ?Sampler $sampler,
        ?HostLoadSampler $hostLoad = null,
    ): self {
        return new self($context, $sampler, $hostLoad);
    }

    /**
     * Advance every rolling window at most once per second.
     *
     * @param float|null $now injectable clock for deterministic tests
     */
    public function poll(?float $now = null): void
    {
        $now ??= microtime(true);

        if ($this->lastPollAt !== null && ($now - $this->lastPollAt) < CacheTtl::DASHBOARD) {
            return;
        }

        $statusVars = $this->context->statusVariables();

        if ($statusVars === []) {
            // Cold async cache: the fetch loop has not landed data yet. Do not
            // ingest zeros — a flat zero trace would lie about an idle server
            // exactly as loudly as real data would tell the truth. Stamp the
            // gate so retries stay at the cadence, but leave lastSnapshotAt
            // alone so elapsed still spans real frames when data arrives.
            $this->lastPollAt = $now;
            return;
        }

        $serverVars = $this->context->serverVariables();

        if ($statusVars === $this->previousSnapshot) {
            // A re-poll that sees the byte-identical status frame carries no
            // new information: both AdminDataLoadedMsg and AdminDrainCompletedMsg
            // forward ReloadReportMsg for the SAME arrival, and re-deriving rates
            // ~10us later divides a zero delta by a near-zero elapsed time —
            // overwriting the true rates with all-zeros and poisoning every rate
            // window with a fake valley right before render. Keep the last real
            // rates and windows untouched; only the OS sampler advances, since
            // /proc CPU is alive even while an idle server's counters stand still.
            $this->lastPollAt = $now;
            $this->hostLoad->sample($statusVars, $serverVars);
            return;
        }

        $elapsed = $this->lastSnapshotAt === null
            ? CacheTtl::DASHBOARD
            : max(0.001, $now - $this->lastSnapshotAt);

        $rates = $this->resolveRates($statusVars, $elapsed);

        $this->lastPollAt = $now;
        $this->lastSnapshotAt = $now;
        $this->previousSnapshot = $statusVars;
        $this->lastStatusVars = $statusVars;
        $this->lastRates = $rates;

        $this->hostLoad->sample($statusVars, $serverVars);

        $this->cells['connections']->ingest([
            'threads' => (float) ($statusVars['Threads_connected'] ?? 0),
        ]);

        $this->cells['traffic']->ingest([
            'in' => $rates['Bytes_received'] ?? 0.0,
            'out' => $rates['Bytes_sent'] ?? 0.0,
        ]);

        $this->cells['key_efficiency']->ingest([
            'hit' => max(0.0, ($rates['Key_read_requests'] ?? 0.0) - ($rates['Key_reads'] ?? 0.0)),
            'miss' => $rates['Key_reads'] ?? 0.0,
        ]);

        $this->cells['selects']->ingest([
            'select' => $rates['Com_select'] ?? 0.0,
            'other' => max(0.0, ($rates['Questions'] ?? 0.0) - ($rates['Com_select'] ?? 0.0)),
        ]);

        $pagesTotal = (int) ($statusVars['Innodb_buffer_pool_pages_total'] ?? 0);
        $pagesFree = max(0, min($pagesTotal, (int) ($statusVars['Innodb_buffer_pool_pages_free'] ?? 0)));
        $this->cells['buffer_usage']->ingest([
            'used' => (float) max(0, $pagesTotal - $pagesFree),
            'free' => (float) $pagesFree,
        ]);

        $physicalReads = $rates['Innodb_buffer_pool_reads'] ?? 0.0;
        $this->cells['reads']->ingest([
            'hit' => max(0.0, ($rates['Innodb_buffer_pool_read_requests'] ?? 0.0) - $physicalReads),
            'physical' => $physicalReads,
        ]);

        $flushed = $rates['Innodb_buffer_pool_pages_flushed'] ?? 0.0;
        $this->cells['writes']->ingest([
            'write' => max(0.0, ($rates['Innodb_buffer_pool_write_requests'] ?? 0.0) - $flushed),
            'flush' => $flushed,
        ]);
    }

    /**
     * Drop the throttle so the next poll() reads immediately (fresh data
     * arrived through the shared async cache, or the user hit [r]). Opening
     * the cadence gate does not fabricate data: a poll that still sees the
     * identical status frame stays a no-op for rates and windows.
     */
    public function forcePoll(): void
    {
        $this->lastPollAt = null;
    }

    public function hostLoadSampler(): HostLoadSampler
    {
        return $this->hostLoad;
    }

    public function cell(string $name): StatusGraphCell
    {
        return $this->cells[$name];
    }

    /**
     * Render the whole column inside $width cells, sized for $rows of
     * vertical space.
     */
    public function view(int $width, int $rows = 24): string
    {
        $columns = match (true) {
            $width >= self::THREE_COLUMN_WIDTH => 3,
            $width >= self::TWO_COLUMN_WIDTH => 2,
            default => 1,
        };
        $frameWidth = max(self::MIN_FRAME_WIDTH, intdiv($width - 2 * ($columns - 1), $columns));
        $chartHeight = max(4, min(8, intdiv($rows, 3)));

        $frames = [];
        $frames[] = $this->cpuFrame($frameWidth, $chartHeight, $rows);

        foreach ($this->cells as $name => $cell) {
            $frames[] = $this->graphFrame($cell, $frameWidth, $chartHeight);
        }
        $perColumn = (int) ceil(count($frames) / $columns);
        $blocks = [];
        foreach (array_chunk($frames, $perColumn) as $chunk) {
            $blocks[] = Layout::joinVertical(Position::TOP, ...$chunk);
        }

        return Layout::joinHorizontal(Position::TOP, ...$blocks);
    }

    /**
     * CPU/Load first, per Workbench. Vertical block bar + honest label:
     * "cpu load x.xx" only when /proc really fed us, "busy proxy" always
     * when the server is remote (the SQL wire carries no OS counters).
     */
    private function cpuFrame(int $frameWidth, int $chartHeight, int $rows): string
    {
        $ratio = $this->hostLoad->latestBusy() ?? 0.0;
        $isProxy = $this->hostLoad->mode() === HostLoadMode::BusyProxy;

        $label = match (true) {
            $isProxy => sprintf('busy proxy %.1f%%', $ratio * 100),
            ($load = $this->hostLoad->latestLoad()) !== null => sprintf('cpu load %.2f', $load),
            default => 'no samples yet',
        };

        $bar = $rows < self::MIN_ROWS_FOR_VERTICAL_BAR
            ? (new Gauge($ratio, widthConstraint: $frameWidth, showPercentage: false))->render()
            : $this->verticalBar($ratio, $chartHeight);

        return $this->frameShell('CPU/Load', $label, $bar, $frameWidth);
    }

    /**
     * @param float $ratio 0..1 busy fraction
     */
    private function verticalBar(float $ratio, int $height): string
    {
        $color = SidebarGauge::thresholdColor($ratio);
        $muted = Color::hex('#45475a');
        $filledRows = (int) round($ratio * $height);

        $lines = [];
        for ($row = 0; $row < $height; $row++) {
            // Row zero is the top: paint from the bottom up like a thermometer.
            $isFill = $height - $row <= $filledRows;
            $glyph = $isFill ? '█' : '░';
            $line = '';
            for ($col = 0; $col < 3; $col++) {
                $line .= ($isFill ? $color : $muted)->toFg(\SugarCraft\Core\Util\ColorProfile::TrueColor)
                    . $glyph
                    . \SugarCraft\Core\Util\Ansi::reset();
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function graphFrame(StatusGraphCell $cell, int $frameWidth, int $chartHeight): string
    {
        return $this->frameShell(
            $cell->caption,
            $this->labelFor($cell),
            $cell->view($frameWidth, $chartHeight),
            $frameWidth,
        );
    }

    /**
     * Caption/label are truncated before styling so the ANSI-wrapped result
     * still measures within the frame: Width::truncate counts cells, SGR
     * sequences would only confuse it if applied first.
     */
    private function frameShell(string $caption, string $label, string $chart, int $frameWidth): string
    {
        $bold = Style::new()->bold()->render(Width::truncate($caption, $frameWidth));
        $muted = Style::new()
            ->foreground(Color::hex('#6c7086'))
            ->render(Width::truncate($label, $frameWidth));

        return $bold . "\n" . $muted . "\n" . $chart;
    }

    /**
     * Headline number under each graph, per the Workbench spec lines:
     * counts for the traffic-light widgets, and for key efficiency / buffer
     * usage the SAME cumulative ratio formulas the gauges used, so the two
     * surfaces can never disagree.
     */
    private function labelFor(StatusGraphCell $cell): string
    {
        if (!$cell->hasSamples()) {
            return 'no samples yet';
        }

        return $this->labelForName($cell, $this->lastStatusVars ?? [], $this->lastRates, $cell->latest());
    }

    /**
     * @param array<string, string> $vars
     * @param array<string, float>  $rates
     * @param array<string, float>  $latest
     */
    private function labelForName(StatusGraphCell $cell, array $vars, array $rates, array $latest): string
    {
        return match ($cell->caption) {
            'Connections' => sprintf('%d connections', (int) ($latest['threads'] ?? 0)),
            'Traffic' => sprintf(
                '%.2f kb/s',
                (($rates['Bytes_received'] ?? 0.0) + ($rates['Bytes_sent'] ?? 0.0)) / 1024.0,
            ),
            'Key Efficiency' => sprintf(
                '%.1f%%',
                SidebarGaugeSet::computeKeyEfficiencyRatio($vars) * 100,
            ),
            'Selects per Second' => sprintf('%.1f/s', $rates['Com_select'] ?? 0.0),
            'InnoDB Buffer Usage' => sprintf(
                '%.1f%%',
                SidebarGaugeSet::computeInnoDBRatio($vars) * 100,
            ),
            'InnoDB Reads per Second' => sprintf('%.1f/s', $rates['Innodb_buffer_pool_read_requests'] ?? 0.0),
            'InnoDB Writes per Second' => sprintf('%.1f/s', $rates['Innodb_buffer_pool_write_requests'] ?? 0.0),
            default => '',
        };
    }

    /**
     * Rates from the page's Sampler when present (it owns counter-reset
     * detection); otherwise derived locally so pages constructed without a
     * sampler — tests, static snapshots — still fill their windows instead
     * of rendering forever-empty graphs.
     *
     * @param array<string, string> $statusVars
     *
     * @return array<string, float>
     */
    private function resolveRates(array $statusVars, float $elapsed): array
    {
        if ($this->sampler !== null) {
            return $this->sampler->sample() ?? [];
        }

        $previous = $this->previousSnapshot;
        if ($previous === null) {
            return [];
        }

        $rates = [];
        foreach ($statusVars as $key => $value) {
            $old = $previous[$key] ?? null;
            if ($old === null || !is_numeric($old) || !is_numeric($value)) {
                continue;
            }
            $rates[$key] = max(0.0, ((float) $value - (float) $old) / $elapsed);
        }

        return $rates;
    }

    private function dsnOrNull(): string
    {
        try {
            return $this->context->connection()->dsn();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, StatusGraphCell>
     */
    private static function buildCells(): array
    {
        return [
            'connections' => new StatusGraphCell('Connections', ['threads'], ['#89b4fa'], false),
            'traffic' => new StatusGraphCell('Traffic', ['in', 'out'], ['#a6e3a1', '#89b4fa'], true),
            'key_efficiency' => new StatusGraphCell('Key Efficiency', ['hit', 'miss'], ['#a6e3a1', '#f38ba8'], true),
            'selects' => new StatusGraphCell('Selects per Second', ['select', 'other'], ['#f9e2af', '#89b4fa'], true),
            'buffer_usage' => new StatusGraphCell('InnoDB Buffer Usage', ['used', 'free'], ['#f38ba8', '#a6e3a1'], true),
            'reads' => new StatusGraphCell(
                'InnoDB Reads per Second',
                ['hit', 'physical'],
                ['#a6e3a1', '#f38ba8'],
                true,
            ),
            'writes' => new StatusGraphCell(
                'InnoDB Writes per Second',
                ['write', 'flush'],
                ['#89b4fa', '#f9e2af'],
                true,
            ),
        ];
    }
}

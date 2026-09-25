<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\ServerStatus;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Query\Admin\Calc\HostLoadSampler;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Admin\ServerStatus\MetricsColumn;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Rendering pins for the Workbench Server Status parity column: label
 * wording from query_dashboard.md lines 49-68, stacked-band colors, the
 * honest CPU/Load label per mode, and a width sweep that must never let a
 * line exceed the allotted cells.
 */
final class MetricsColumnTest extends TestCase
{
    private ColumnFakeContext $context;

    protected function setUp(): void
    {
        $this->context = new ColumnFakeContext();
    }

    public function testConnectionsLabelCountsOpenThreads(): void
    {
        $column = $this->column(HostLoadSampler::proxy());
        $column->poll(1000.0);

        self::assertStringContainsString('12 connections', $this->plain($column->view(72, 24)));
    }

    public function testTrafficLabelIsKilobytesPerSecondOfBothDirections(): void
    {
        $column = $this->column(HostLoadSampler::proxy());
        $column->poll(1000.0);
        // +10240 received, +5120 sent over exactly one second => 15.00 kb/s
        $this->context->statusVars['Bytes_received'] = '11240';
        $this->context->statusVars['Bytes_sent'] = '7120';
        $column->poll(1001.0);

        self::assertStringContainsString('15.00 kb/s', $this->plain($column->view(72, 24)));
    }

    public function testKeyEfficiencyLabelReusesTheGaugeFormula(): void
    {
        $column = $this->column(HostLoadSampler::proxy());
        $column->poll(1000.0);

        // Gauge formula Key_reads/(Key_reads+Key_write_requests) = 5/405.
        $rendered = $this->plain($column->view(72, 24));
        self::assertStringContainsString('1.2%', $rendered);
        self::assertStringContainsString('Key Efficiency', $rendered);
    }

    public function testBufferUsageLabelReusesTheGaugeFormula(): void
    {
        $column = $this->column(HostLoadSampler::proxy());
        $column->poll(1000.0);

        // 1 - 250/1000 = 75.0%.
        self::assertStringContainsString('75.0%', $this->plain($column->view(72, 24)));
        self::assertStringContainsString('InnoDB Buffer Usage', $this->plain($column->view(72, 24)));
    }

    public function testPerSecondLabelsReadFromTheRightCounters(): void
    {
        $column = $this->column(HostLoadSampler::proxy());
        $column->poll(1000.0);
        $this->context->statusVars['Com_select'] = '37';
        $this->context->statusVars['Innodb_buffer_pool_read_requests'] = '240';
        $this->context->statusVars['Innodb_buffer_pool_write_requests'] = '80';
        $column->poll(1001.0);

        $rendered = $this->plain($column->view(72, 24));
        self::assertStringContainsString('7.0/s', $rendered);
        self::assertStringContainsString('40.0/s', $rendered);
        self::assertStringContainsString('20.0/s', $rendered);
    }

    public function testLocalModeLabelsCpuLoadFromProc(): void
    {
        $loadavg = tempnam(sys_get_temp_dir(), 'cq-load');
        self::assertIsString($loadavg);
        file_put_contents($loadavg, "0.52 0.58 0.59 2/389 12345\n");
        $stat = tempnam(sys_get_temp_dir(), 'cq-stat');
        self::assertIsString($stat);
        file_put_contents($stat, "cpu  100 10 20 800 60 5 5 10 0 0\nintr 0\n");

        try {
            $column = $this->column(HostLoadSampler::local($loadavg, $stat));
            $column->poll(1000.0);

            self::assertStringContainsString('cpu load 0.52', $this->plain($column->view(72, 24)));
        } finally {
            @unlink($loadavg);
            @unlink($stat);
        }
    }

    public function testProxyModeLabelIsExplicitlyNotOsCpu(): void
    {
        $column = $this->column(HostLoadSampler::proxy());
        $column->poll(1000.0);

        $rendered = $this->plain($column->view(72, 24));
        self::assertStringContainsString('busy proxy', $rendered);
        self::assertStringNotContainsStringNormalized('cpu load', $rendered);
    }

    public function testVerticalBarAndHorizontalFallbackBothRender(): void
    {
        $column = $this->column(HostLoadSampler::proxy());
        $column->poll(1000.0);

        $tall = $this->plain($column->view(72, 24));
        self::assertStringContainsString('░', $tall, '3-wide vertical track at >=12 rows');

        $short = $this->plain($column->view(72, 8));
        self::assertStringNotContainsStringNormalized("░\n░", $short, 'stacked track must collapse to one gauge line');
    }

    public function testColdCacheIngestsNoLyingZeros(): void
    {
        $this->context->statusVars = [];
        $column = $this->column(HostLoadSampler::proxy());
        $column->poll(1000.0);

        self::assertFalse($column->cell('connections')->hasSamples());
        self::assertStringContainsString('no samples yet', $this->plain($column->view(72, 24)));
    }

    public function testPollThrottleMatchesDashboardCadenceAndForceBypassesIt(): void
    {
        $column = $this->column(HostLoadSampler::proxy());
        $column->poll(1000.0);
        $column->poll(1000.5);

        self::assertCount(1, $column->cell('connections')->series('threads'));

        $column->forcePoll();
        $column->poll(1000.6);

        self::assertCount(2, $column->cell('connections')->series('threads'));
    }

    /**
     * The parity promise: every allotted width from a phone-ish pane to a
     * wall-mounted terminal keeps every line inside the budget.
     */
    public function testWidthSweepNeverOverflows(): void
    {
        $column = $this->column(HostLoadSampler::proxy());
        $column->poll(1000.0);
        $this->context->advance();
        $column->poll(1001.0);

        foreach (range(22, 200, 6) as $width) {
            foreach ([8, 14, 24, 40] as $rows) {
                foreach (explode("\n", $column->view($width, $rows)) as $line) {
                    self::assertLessThanOrEqual(
                        $width,
                        Width::of($this->plain($line)),
                        sprintf('overflow at width %d rows %d: %s', $width, $rows, $this->plain($line)),
                    );
                }
            }
        }
    }

    public function testCpuFrameComesFirstLikeWorkbench(): void
    {
        $column = $this->column(HostLoadSampler::proxy());
        $column->poll(1000.0);

        $rendered = $this->plain($column->view(72, 24));
        self::assertLessThan(
            strpos($rendered, 'Connections'),
            strpos($rendered, 'CPU/Load'),
            'CPU/Load leads the stack per query_dashboard.md line 47',
        );
    }

    private function column(HostLoadSampler $hostLoad): MetricsColumn
    {
        return MetricsColumn::forContext($this->context, null, $hostLoad);
    }

    private function plain(string $text): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $text);
    }

    /**
     * ANSI-normalized "does not contain": SGR codes can split words across
     * resets, so strip before negated searches.
     */
    private function assertStringNotContainsStringNormalized(string $needle, string $haystack): void
    {
        self::assertStringNotContainsString($needle, $this->plain($haystack));
    }
}

/**
 * Minimal context double whose counters advance() bumps by one second's
 * worth of activity; the column only ever reads variable snapshots.
 */
final class ColumnFakeContext implements ServerContextInterface
{
    /** @var array<string, string> */
    public array $statusVars = [
        'Threads_connected' => '12',
        'Threads_running' => '4',
        'Bytes_received' => '1000',
        'Bytes_sent' => '2000',
        'Key_read_requests' => '100',
        'Key_reads' => '5',
        'Key_write_requests' => '400',
        'Questions' => '50',
        'Com_select' => '30',
        'Innodb_buffer_pool_pages_total' => '1000',
        'Innodb_buffer_pool_pages_free' => '250',
        'Innodb_buffer_pool_read_requests' => '200',
        'Innodb_buffer_pool_reads' => '8',
        'Innodb_buffer_pool_write_requests' => '60',
        'Innodb_buffer_pool_pages_flushed' => '3',
        'Uptime' => '1000',
    ];

    /** @var array<string, string> */
    public array $serverVars = ['max_connections' => '151'];

    public function advance(): void
    {
        $bumps = [
            'Bytes_received' => 0, 'Bytes_sent' => 0, 'Uptime' => 1,
        ];
        foreach ($this->statusVars as $key => $value) {
            $this->statusVars[$key] = (string) ((int) $value + ($bumps[$key] ?? 10));
        }
    }

    public function connection(): DatabaseInterface
    {
        return new FakeDatabase();
    }

    public function serverVariables(): array
    {
        return $this->serverVars;
    }

    public function statusVariables(): array
    {
        return $this->statusVars;
    }

    public function statusVariablesTs(): float
    {
        return microtime(true);
    }

    public function plugins(): array
    {
        return [];
    }

    public function version(): Version
    {
        return Version::parse('8.0.33');
    }

    public function flavor(): Flavor
    {
        return Flavor::MySQL;
    }

    public function versionString(): string
    {
        return '8.0.33';
    }

    public function password(): string
    {
        return '';
    }

    public function wasReset(): bool
    {
        return false;
    }

    public function refresh(): void
    {
    }
}

<?php

declare(strict_types=1);

namespace CandyCore\Metrics\Tests\Backend;

use CandyCore\Metrics\Backend\PrometheusFileBackend;
use PHPUnit\Framework\TestCase;

final class PrometheusFileBackendTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/candy-metrics-' . uniqid() . '.prom';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '.tmp'] as $f) {
            if ($f !== '' && is_file($f)) {
                unlink($f);
            }
        }
    }

    public function testEmitsCounterAndGaugeAndSummary(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('hits', 5);
        $b->counter('hits', 2);
        $b->gauge('queue_depth', 17);
        $b->histogram('lat', 0.1);
        $b->histogram('lat', 0.3);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('# TYPE hits counter',           $content);
        $this->assertStringContainsString("hits 7\n",                       $content);
        $this->assertStringContainsString('# TYPE queue_depth gauge',       $content);
        $this->assertStringContainsString("queue_depth 17\n",               $content);
        $this->assertStringContainsString('# TYPE lat summary',             $content);
        $this->assertStringContainsString("lat_count 2\n",                  $content);
        $this->assertStringContainsString('lat_sum 0.400000',               $content);
    }

    public function testTagsRenderAsLabels(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('hits', 1, ['route' => '/x', 'method' => 'GET']);
        $b->counter('hits', 2, ['route' => '/y', 'method' => 'GET']);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('hits{method="GET",route="/x"} 1', $content);
        $this->assertStringContainsString('hits{method="GET",route="/y"} 2', $content);
    }

    public function testLabelEscaping(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->gauge('msg', 1, ['note' => 'has "quotes" and \\back']);
        $b->flush();
        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('has \\"quotes\\" and \\\\back', $content);
    }

    public function testFlushIsAtomicReplacement(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('first', 1);
        $b->flush();
        $first = (string) file_get_contents($this->path);

        $b2 = new PrometheusFileBackend($this->path);
        $b2->counter('second', 1);
        $b2->flush();
        $second = (string) file_get_contents($this->path);

        $this->assertStringContainsString('first', $first);
        $this->assertStringNotContainsString('first',  $second);
        $this->assertStringContainsString('second',    $second);
    }
}

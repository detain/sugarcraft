<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Backend;

use SugarCraft\Metrics\Backend\PrometheusFileBackend;
use SugarCraft\Metrics\Descriptor;
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

    public function testEmitsCounterAndGaugeAndHistogram(): void
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
        $this->assertStringContainsString('# TYPE lat histogram',             $content);
        $this->assertStringContainsString("lat_count 2\n",                  $content);
        $this->assertStringContainsString('lat_sum 0.400000',               $content);
        // Verify bucket lines are present
        $this->assertStringContainsString('lat_bucket{le="0.1"} 1', $content);
        $this->assertStringContainsString('lat_bucket{le="+Inf"} 2', $content);
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

    public function testDottedNameSanitizedToUnderscores(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('http.request.duration', 1);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('http_request_duration 1', $content);
        $this->assertStringContainsString('# TYPE http_request_duration counter', $content);
        $this->assertStringNotContainsString('http.request.duration', $content);
    }

    public function testNameInjectionNeutralized(): void
    {
        $b = new PrometheusFileBackend($this->path);
        // A name containing newline + brace chars could inject extra metric lines
        // if not sanitized. The sanitizer replaces \n and { } with underscores.
        $b->counter("test\n{metric}\naline", 1);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        // Exactly one sample line should exist under the sanitized name.
        // Raw newlines in the name become underscores, so no line-break injection.
        $this->assertStringContainsString('test__metric__aline 1', $content);
        // The raw problematic name patterns must not appear literally.
        $this->assertStringNotContainsString("test\n{", $content);
        $this->assertStringNotContainsString("\n{", $content);
        // Should have exactly one TYPE line (not one per injected line).
        $this->assertSame(1, substr_count($content, '# TYPE test__metric__aline counter'));
    }

    public function testTypeEmittedOncePerFamily(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->counter('hits', 1, ['a' => '1']);
        $b->counter('hits', 2, ['a' => '2']);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        // Exactly one TYPE line for the 'hits' family despite two label sets.
        $this->assertSame(1, substr_count($content, '# TYPE hits counter'));
        // Both series lines must still be present.
        $this->assertStringContainsString('hits{a="1"} 1', $content);
        $this->assertStringContainsString('hits{a="2"} 2', $content);
    }

    public function testRegisteredDescriptorEmitsHelpBeforeSamples(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->describe(new Descriptor('request_latency', 'HTTP request latency in seconds', 'histogram'));
        // Record nothing — this is a zero-sample descriptor.
        $b->flush();

        $content = (string) file_get_contents($this->path);
        $this->assertStringContainsString('# HELP request_latency HTTP request latency in seconds', $content);
        $this->assertStringContainsString('# TYPE request_latency histogram', $content);
    }

    public function testDescriptorHelpForSampledMetric(): void
    {
        $b = new PrometheusFileBackend($this->path);
        $b->describe(new Descriptor('hits', 'Total HTTP request count', 'counter'));
        $b->counter('hits', 5);
        $b->flush();

        $content = (string) file_get_contents($this->path);
        // Exactly one HELP and one TYPE for the 'hits' family.
        $this->assertSame(1, substr_count($content, '# HELP hits Total HTTP request count'));
        $this->assertSame(1, substr_count($content, '# TYPE hits counter'));
        // Sample line must still be present.
        $this->assertStringContainsString('hits 5', $content);
    }
}

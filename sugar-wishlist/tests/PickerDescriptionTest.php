<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\Endpoint;
use SugarCraft\Wishlist\Picker;
use PHPUnit\Framework\TestCase;

/**
 * Picker description rendering test exercised against in-memory streams.
 */
final class PickerDescriptionTest extends TestCase
{
    /**
     * @return array{0:resource,1:resource,2:Picker}
     */
    private function makePicker(string $keys): array
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        fwrite($in, $keys);
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { /* noop in tests */ }
        };
        return [$in, $out, $p];
    }

    private function endpointsWithDescriptions(): array
    {
        return [
            new Endpoint(name: 'production', host: 'prod.example.com', description: 'Primary production server'),
            new Endpoint(name: 'staging',    host: 'stage.example.com', description: 'Staging environment'),
            new Endpoint(name: 'dev',        host: 'dev.example.com'),
        ];
    }

    public function testDescriptionRenderedInDrawOutput(): void
    {
        [, $out, $p] = $this->makePicker("\r");
        $endpoints = $this->endpointsWithDescriptions();
        $p->pick([$endpoints[0]]);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringContainsString('Primary production server', $output);
        // Description is wrapped in ANSI dim codes
        $this->assertStringContainsString("\x1b[0m", $output); // reset
    }

    public function testDescriptionNotRenderedWhenNull(): void
    {
        [, $out, $p] = $this->makePicker("\r");
        $endpoint = new Endpoint(name: 'dev', host: 'dev.example.com', description: null);
        $p->pick([$endpoint]);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringNotContainsString("\x1b[2m", $output);
    }

    public function testDescriptionNotRenderedWhenEmpty(): void
    {
        [, $out, $p] = $this->makePicker("\r");
        $endpoint = new Endpoint(name: 'dev', host: 'dev.example.com', description: '');
        $p->pick([$endpoint]);
        rewind($out);
        $output = stream_get_contents($out);
        $this->assertStringNotContainsString("\x1b[2m", $output);
    }

    public function testDescriptionAppendedAfterDisplayLine(): void
    {
        [, $out, $p] = $this->makePicker("\r");
        $endpoint = new Endpoint(name: 'prod', host: 'prod.example.com', description: 'Test description');
        $p->pick([$endpoint]);
        rewind($out);
        $output = stream_get_contents($out);
        // Should contain displayLine followed by dim description
        $this->assertStringContainsString('prod  ─  prod.example.com', $output);
        $this->assertStringContainsString('Test description', $output);
    }
}
<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests;

use SugarCraft\Metrics\Descriptor;
use PHPUnit\Framework\TestCase;

final class DescriptorTest extends TestCase
{
    public function testConstructorAcceptsValidArguments(): void
    {
        $d = new Descriptor('http_requests_total', 'Total HTTP requests', 'counter', ['method', 'route']);
        $this->assertSame('http_requests_total', $d->name);
        $this->assertSame('Total HTTP requests', $d->help);
        $this->assertSame('counter', $d->type);
        $this->assertSame(['method', 'route'], $d->labelKeys);
    }

    public function testConstructorDefaultsToEmptyLabelKeys(): void
    {
        $d = new Descriptor('app_uptime_seconds', 'Application uptime in seconds', 'gauge');
        $this->assertSame([], $d->labelKeys);
    }

    /**
     * @param non-empty-string $type
     * @dataProvider validTypesProvider
     */
    public function testValidTypesAreAccepted(string $type): void
    {
        $d = new Descriptor('test_metric', 'Test help', $type);
        $this->assertSame($type, $d->type);
    }

    /** @return array<string, array{0:non-empty-string}> */
    public static function validTypesProvider(): array
    {
        return [
            'counter'   => ['counter'],
            'gauge'     => ['gauge'],
            'histogram' => ['histogram'],
            'summary'   => ['summary'],
        ];
    }

    public function testEmptyNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty');
        new Descriptor('', 'help', 'counter');
    }

    public function testEmptyHelpThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty');
        new Descriptor('name', '', 'counter');
    }

    public function testInvalidTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('counter, gauge, histogram, summary');
        new Descriptor('name', 'help', 'bad_type');
    }

    public function testEmptyLabelKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty');
        new Descriptor('name', 'help', 'counter', ['valid', '']);
    }
}

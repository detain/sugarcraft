<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Components\Card\DefinitionList;

final class DefinitionListTest extends TestCase
{
    public function testNewIsEmpty(): void
    {
        $this->assertSame('', DefinitionList::new()->render());
    }

    public function testRowAppendsImmutably(): void
    {
        $list = DefinitionList::new();
        $next = $list->row('Host', 'localhost');
        $this->assertNotSame($list, $next);
        $this->assertSame('', $list->render());
        $this->assertStringContainsString('localhost', $next->render());
    }

    public function testRendersLabelValuePairs(): void
    {
        $out = DefinitionList::new()
            ->row('Host', 'localhost')
            ->row('Port', '3306')
            ->render();
        $this->assertStringContainsString('Host', $out);
        $this->assertStringContainsString('localhost', $out);
        $this->assertStringContainsString('Port', $out);
        $this->assertStringContainsString('3306', $out);
        $this->assertSame(2, substr_count($out, "\n") + 1);
    }

    public function testNullValueRendersPlaceholder(): void
    {
        $out = DefinitionList::new()->row('Replication', null)->render();
        $this->assertStringContainsString('—', $out);
    }

    public function testCustomPlaceholder(): void
    {
        $out = DefinitionList::new()
            ->withPlaceholder('Unknown')
            ->row('SSL', null)
            ->render();
        $this->assertStringContainsString('Unknown', $out);
    }

    public function testCustomSeparator(): void
    {
        $out = DefinitionList::new()
            ->withSeparator(' = ')
            ->row('key', 'val')
            ->render();
        $this->assertStringContainsString('key = val', $this->strip($out));
    }

    public function testLabelColumnAligns(): void
    {
        // The shorter label is padded so both separators sit at the same column.
        $out = $this->strip(
            DefinitionList::new()
                ->withSeparator(' : ')
                ->row('A', '1')
                ->row('Long', '2')
                ->render()
        );
        $lines = explode("\n", $out);
        $col0 = strpos($lines[0], ' : ');
        $col1 = strpos($lines[1], ' : ');
        $this->assertSame($col0, $col1);
    }

    public function testFromMapBuildsRows(): void
    {
        $out = $this->strip(DefinitionList::fromMap([
            'Version' => '8.0',
            'Uptime' => null,
        ])->render());
        $this->assertStringContainsString('Version', $out);
        $this->assertStringContainsString('8.0', $out);
        $this->assertStringContainsString('Uptime', $out);
        $this->assertStringContainsString('—', $out);
    }

    public function testGetInnerSizeReflectsContent(): void
    {
        [$w, $h] = DefinitionList::new()
            ->row('Host', 'localhost')
            ->row('Port', '3306')
            ->getInnerSize();
        $this->assertGreaterThan(0, $w);
        $this->assertSame(2, $h);
    }

    public function testSetSizeTruncatesValueToWidth(): void
    {
        $list = DefinitionList::new()
            ->withSeparator(' : ')
            ->row('K', 'a-very-long-value-that-overflows');
        $sized = $list->setSize(10, 1);
        $this->assertNotSame($list, $sized);
        foreach (explode("\n", $sized->render()) as $line) {
            $this->assertLessThanOrEqual(10, Width::string($this->strip($line)));
        }
    }

    public function testWithRowsReplaces(): void
    {
        $list = DefinitionList::new()->row('old', 'x');
        $out = $this->strip($list->withRows([['new', 'y']])->render());
        $this->assertStringNotContainsString('old', $out);
        $this->assertStringContainsString('new', $out);
    }

    public function testColorWithersReturnNewInstance(): void
    {
        $list = DefinitionList::new();
        $this->assertNotSame($list, $list->withLabelColor(null));
        $this->assertNotSame($list, $list->withValueColor(null));
        $this->assertNotSame($list, $list->withPlaceholderColor(null));
    }

    private function strip(string $s): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $s) ?? $s;
    }
}

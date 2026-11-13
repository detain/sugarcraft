<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Core\Util\Width;

/** Clips are display-cell budgets (wide CJK = 2 cells), never byte or char counts. */
final class TranscriptClipCellWidthTest extends TestCase
{
    private static function call(string $class, string $method, string ...$args): string
    {
        $m = new \ReflectionMethod($class, $method);

        return (string) $m->invoke(null, ...$args);
    }

    public function testExcerptClipsByCellsNotChars(): void
    {
        $out = self::call(EngineExecutor::class, 'excerpt', str_repeat('漢', 100));
        $this->assertLessThanOrEqual(120, Width::string($out));
        $this->assertStringEndsWith('…', $out);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    public function testExcerptShortTextUntouched(): void
    {
        $this->assertSame('hello world', self::call(EngineExecutor::class, 'excerpt', "hello\n world"));
    }

    public function testOneLineAndItemLabelClipByCells(): void
    {
        $wide = str_repeat('漢', 100);
        $this->assertLessThanOrEqual(160, Width::string(self::call(WorkflowEngine::class, 'oneLine', $wide)));
        $label = self::call(WorkflowEngine::class, 'itemLabel', $wide);
        $this->assertLessThanOrEqual(120, Width::string($label));
        $this->assertTrue(mb_check_encoding($label, 'UTF-8'));
    }

    public function testMatchedLineClipsByCells(): void
    {
        $out = self::call(WorkflowEngine::class, 'matchedLine', str_repeat('漢', 100), '0');
        $this->assertLessThanOrEqual(160, Width::string($out));
    }
}

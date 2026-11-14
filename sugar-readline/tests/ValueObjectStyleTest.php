<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\ConfirmationPrompt;
use SugarCraft\Readline\MultiSelectPrompt;
use SugarCraft\Readline\SelectionPrompt;
use SugarCraft\Readline\TextareaPrompt;
use SugarCraft\Readline\TextPrompt;

/**
 * Finding 027: the lib hand-rolled `clone $this` in every fluent setter.
 * The house idiom is now one private mutate() per carrier (the
 * SelectionEngine trait ships the one both list prompts compile in),
 * because sugar-core's generic Concerns\Mutable constructor-spreads
 * promoted properties and none of these carriers store their state in
 * constructor parameters. These pins keep the idiom from drifting back
 * and keep the null-carries-meaning tri-states (displayWidth,
 * viewportRows: null = unset/adoption-eligible, 0 = explicit unbounded)
 * sentinel-free per the AGENTS pairing law.
 */
final class ValueObjectStyleTest extends TestCase
{
    /** @return array<string, array{class-string}> */
    public static function carriers(): array
    {
        return [
            'TextPrompt'       => [TextPrompt::class],
            'TextareaPrompt'   => [TextareaPrompt::class],
            'ConfirmationPrompt' => [ConfirmationPrompt::class],
            'SelectionPrompt'  => [SelectionPrompt::class],
            'MultiSelectPrompt' => [MultiSelectPrompt::class],
        ];
    }

    /** @param class-string $class */
    #[\PHPUnit\Framework\Attributes\DataProvider('carriers')]
    public function testEveryCarrierDeclaresThePrivateMutate(string $class): void
    {
        $method = new \ReflectionMethod($class, 'mutate');
        $this->assertTrue($method->isPrivate(), "{$class}::mutate must stay a private detail");
    }

    public function testNoFluentSetterHandRollsCloneThis(): void
    {
        $dir = \dirname(__DIR__) . '/src/';
        foreach (['TextPrompt.php', 'TextareaPrompt.php', 'ConfirmationPrompt.php', 'MultiSelectPrompt.php', 'Selection/SelectionEngine.php'] as $relative) {
            $source = \file_get_contents($dir . $relative);
            $this->assertIsString($source, $relative);
            $count = \preg_match_all(
                '/\n    public function (with[A-Z]\w*)\([^)]*\)[^{]*\{\n(.*?)\n    \}\n/s',
                $source,
                $methods,
                \PREG_SET_ORDER,
            );
            $this->assertGreaterThan(0, $count, "expected fluent setters in {$relative}");
            foreach ($methods as [, $name, $body]) {
                $this->assertStringNotContainsString(
                    'clone $this',
                    $body,
                    "{$name}() in {$relative} must route through mutate()",
                );
                $this->assertMatchesRegularExpression(
                    '/mutate\(|applyChoices\(/',
                    $body,
                    "{$name}() in {$relative} must use the value-object idiom",
                );
            }
        }
    }

    public function testSettersReturnFreshInstancesAndLeaveTheOriginal(): void
    {
        $text = TextPrompt::new('> ');
        $with = $text->withDefault('hi');
        $this->assertNotSame($text, $with);
        $this->assertSame('', $text->value());
        $this->assertSame('hi', $with->value());
        $this->assertSame($text, $text->withCursor(0), 'a no-op caret set keeps identity');

        $area = TextareaPrompt::new('> ');
        $tall = $area->withDefault("x\ny");
        $this->assertSame([''], $area->lines(), 'a fresh textarea carries one empty line');
        $this->assertCount(2, $tall->lines());

        $confirm = ConfirmationPrompt::new('? ');
        $view    = $confirm->view();
        $confirm->withConfirmLabel('ZZZ');
        $this->assertSame($view, $confirm->view());

        $multi = MultiSelectPrompt::new('pick: ', ['a', 'b']);
        $raised = $multi->withMinSelections(2);
        $this->assertNotSame($multi, $raised);
        $property = new \ReflectionProperty(MultiSelectPrompt::class, 'minSelect');
        $this->assertSame(0, $property->getValue($multi));
        $this->assertSame(2, $property->getValue($raised));

        $list  = SelectionPrompt::new('pick: ', ['a', 'b', 'c']);
        $paged = $list->withPageSize(2);
        $property = new \ReflectionProperty(SelectionPrompt::class, 'pageSize');
        $this->assertSame(10, $property->getValue($list), 'the untouched carrier keeps its page size');
        $this->assertSame(2, $property->getValue($paged));
    }

    public function testTriStateWidthsStaySentinelFree(): void
    {
        // null = unset (the adoption-eligible state), 0 = explicit
        // unbounded, >0 = bound. Null carries the meaning, so AGENTS law
        // forbids a paired bool here; pinning the accessor tri-state is
        // the sentinel.
        $prompt = TextPrompt::new('> ');
        $this->assertNull($prompt->displayWidth());
        $this->assertSame(0, $prompt->withDisplayWidth(0)->displayWidth());
        $this->assertSame(0, $prompt->withDisplayWidth(-5)->displayWidth());
        $this->assertSame(40, $prompt->withDisplayWidth(40)->displayWidth());
        $this->assertNull($prompt->withDisplayWidth(40)->withDisplayWidth(null)->displayWidth());

        $area = TextareaPrompt::new('> ');
        $this->assertNull($area->viewportRows());
        $this->assertSame(0, $area->withViewportRows(0)->viewportRows());
        $this->assertSame(8, $area->withViewportRows(8)->viewportRows());
    }
}

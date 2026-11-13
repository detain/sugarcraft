<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use SugarCraft\DatePicker\DatePicker;
use PHPUnit\Framework\TestCase;

/**
 * dp-02 — the camelCase house-API rename keeps every upstream PascalCase
 * spelling alive.
 *
 * PHP resolves method names case-insensitively, so renaming the PascalCase
 * declarations to camelCase gives the house API without a delegate layer:
 * the PascalCase vocabulary (upstream bubble-datepicker parity) resolves to
 * the very same compiled methods. This suite pins three things:
 *
 *  1. NO public method is declared PascalCase any more (house rule);
 *  2. every deprecated PascalCase spelling still resolves and behaves
 *     identically to its camelCase twin (delegation by dispatch);
 *  3. the deprecated list itself does not drift silently — the provider is
 *     the machine-readable copy of the README deprecation table.
 */
final class ApiAliasTest extends TestCase
{
    /**
     * Deprecated PascalCase vocabulary → canonical camelCase spelling.
     * Representative arguments mirror the signature: a date for the setters
     * that take one, a style string for the With*Style family.
     *
     * @return array<string, array{0: string, 1: string, 2: array}>
     */
    public static function aliasProvider(): array
    {
        $date  = new \DateTimeImmutable('2026-05-10');
        $style = '1;31';

        return [
            'GoToPreviousMonth'      => ['GoToPreviousMonth', 'goToPreviousMonth', []],
            'GoToNextMonth'          => ['GoToNextMonth', 'goToNextMonth', []],
            'GoToPreviousYear'       => ['GoToPreviousYear', 'goToPreviousYear', []],
            'GoToNextYear'           => ['GoToNextYear', 'goToNextYear', []],
            'GoToToday'              => ['GoToToday', 'goToToday', []],
            'SetTime'                => ['SetTime', 'setTime', [$date]],
            'MoveToDate'             => ['MoveToDate', 'moveToDate', [$date]],
            'MoveCursorLeft'         => ['MoveCursorLeft', 'moveCursorLeft', []],
            'MoveCursorRight'        => ['MoveCursorRight', 'moveCursorRight', []],
            'MoveCursorUp'           => ['MoveCursorUp', 'moveCursorUp', []],
            'MoveCursorDown'         => ['MoveCursorDown', 'moveCursorDown', []],
            'SelectDate'             => ['SelectDate', 'selectDate', []],
            'ClearDate'              => ['ClearDate', 'clearDate', []],
            'ToggleSelection'        => ['ToggleSelection', 'toggleSelection', []],
            'SelectedDate'           => ['SelectedDate', 'selectedDate', []],
            'IsSelecting'            => ['IsSelecting', 'isSelecting', []],
            'CursorIndex'            => ['CursorIndex', 'cursorIndex', []],
            'ViewMonth'              => ['ViewMonth', 'viewMonth', []],
            'ViewYear'               => ['ViewYear', 'viewYear', []],
            'View'                   => ['View', 'view', [30, true]],
            'WithHeaderStyle'        => ['WithHeaderStyle', 'withHeaderStyle', [$style]],
            'WithDayNameStyle'       => ['WithDayNameStyle', 'withDayNameStyle', [$style]],
            'WithTodayStyle'         => ['WithTodayStyle', 'withTodayStyle', [$style]],
            'WithSelectedStyle'      => ['WithSelectedStyle', 'withSelectedStyle', [$style]],
            'WithSelectedTodayStyle' => ['WithSelectedTodayStyle', 'withSelectedTodayStyle', [$style]],
            'WithCursorStyle'        => ['WithCursorStyle', 'withCursorStyle', [$style]],
            'WithRangeStyle'         => ['WithRangeStyle', 'withRangeStyle', [$style]],
        ];
    }

    public function testNoPublicMethodIsDeclaredPascalCase(): void
    {
        foreach ((new \ReflectionClass(DatePicker::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            $this->assertMatchesRegularExpression(
                '/^[a-z_]/',
                $m->getName(),
                "public method {$m->getName()}() must be declared camelCase (dp-02)"
            );
        }
    }

    /**
     * @dataProvider aliasProvider
     *
     * @param array<int, mixed> $args
     */
    public function testDeprecatedSpellingResolvesToCamelTwin(string $pascal, string $camel, array $args): void
    {
        $ref = new \ReflectionClass(DatePicker::class);
        $this->assertTrue($ref->hasMethod($camel), "canonical camelCase twin $camel() must exist");
        $this->assertSame(
            $camel,
            $ref->getMethod($pascal)->getName(),
            "the deprecated $pascal() spelling must resolve to the single $camel() declaration"
        );

        $viaPascal = $this->fingerprint($this->basePicker()->$pascal(...$args));
        $viaCamel  = $this->fingerprint($this->basePicker()->$camel(...$args));
        $this->assertSame(
            $viaCamel,
            $viaPascal,
            "$pascal() and $camel() must be the same behaviour (case-insensitive dispatch)"
        );
    }

    /** Full observable state of a picker, as text. */
    private function fingerprint(DatePicker|bool|int|string|null $result): string
    {
        if (!$result instanceof DatePicker) {
            return var_export($result, true);
        }
        return $result->view(30) . "\n#\n" . $result->view(30, true)
            . '|' . $result->cursorIndex()
            . '|' . ($result->selectedDate()?->format('c') ?? '-')
            . '|' . var_export($result->isSelecting(), true)
            . '|' . $result->viewMonth() . '/' . $result->viewYear();
    }

    private function basePicker(): DatePicker
    {
        return DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-01'));
    }
}

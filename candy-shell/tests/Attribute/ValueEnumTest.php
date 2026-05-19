<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Attribute\ValueEnum;
use InvalidArgumentException;
use ReflectionClass;

final class ValueEnumTest extends TestCase
{
    public function testValidValuePasses(): void
    {
        $attr = new ValueEnum(['json', 'yaml', 'toml']);
        $result = ValueEnum::validate('json', $attr, 'format');
        $this->assertSame('json', $result);
    }

    public function testInvalidValueThrowsClearError(): void
    {
        $attr = new ValueEnum(['json', 'yaml', 'toml']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Invalid value for --format: 'csv'. Allowed: json|yaml|toml."
        );
        ValueEnum::validate('csv', $attr, 'format');
    }

    public function testEmptyAllowedListThrowsOnAnyValue(): void
    {
        $attr = new ValueEnum([]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid value for --output: 'anything'. Allowed: .");
        ValueEnum::validate('anything', $attr, 'output');
    }

    public function testValueEnumAttributeIsDiscoverable(): void
    {
        $ref = new ReflectionClass(ValueEnumCommand::class);
        $attrs = $ref->getAttributes(ValueEnum::class);

        $this->assertCount(1, $attrs);

        $attr = $attrs[0]->newInstance();
        $this->assertSame(['json', 'yaml'], $attr->values);
    }

    public function testValidateRejectsNonMatchingCase(): void
    {
        $attr = new ValueEnum(['json', 'yaml', 'toml']);

        $this->expectException(InvalidArgumentException::class);
        ValueEnum::validate('JSON', $attr, 'format');
    }
}

#[ValueEnum(['json', 'yaml'])]
class ValueEnumCommand
{
}

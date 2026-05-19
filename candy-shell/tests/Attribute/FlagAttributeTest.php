<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Attribute\Flag;
use ReflectionClass;

final class FlagAttributeTest extends TestCase
{
    public function testFlagAttributeStoresProperties(): void
    {
        $flag = new Flag(
            name: 'verbose',
            short: 'v',
            description: 'Enable verbose output.',
            required: false,
            isFlag: true,
            default: null,
        );

        $this->assertSame('verbose', $flag->name);
        $this->assertSame('v', $flag->short);
        $this->assertSame('Enable verbose output.', $flag->description);
        $this->assertFalse($flag->required);
        $this->assertTrue($flag->isFlag);
        $this->assertNull($flag->default);
    }

    public function testFlagAttributeWithEnumClass(): void
    {
        $flag = new Flag(
            name: 'format',
            short: 'f',
            description: 'Output format.',
            enum: FormatType::class,
        );

        $this->assertSame('format', $flag->name);
        $this->assertSame('f', $flag->short);
        $this->assertSame(FormatType::class, $flag->enum);
    }

    public function testFlagAttributeIsDiscoverableViaReflection(): void
    {
        $ref = new ReflectionClass(FlaggedCommand::class);
        $attrs = $ref->getAttributes(Flag::class);

        $this->assertCount(2, $attrs);

        $verbose = $attrs[0]->newInstance();
        $this->assertSame('verbose', $verbose->name);
        $this->assertSame('v', $verbose->short);
        $this->assertTrue($verbose->isFlag);

        $format = $attrs[1]->newInstance();
        $this->assertSame('format', $format->name);
        $this->assertSame('f', $format->short);
    }

    public function testFlagDefaultValue(): void
    {
        $flag = new Flag(name: 'timeout', default: 30);
        $this->assertSame(30, $flag->default);
    }
}

#[Flag(name: 'verbose', short: 'v', isFlag: true)]
#[Flag(name: 'format', short: 'f', enum: FormatType::class)]
class FlaggedCommand
{
}

enum FormatType: string
{
    case json = 'json';
    case yaml = 'yaml';
}

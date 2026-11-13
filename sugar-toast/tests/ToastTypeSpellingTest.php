<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Toast, ToastType};
use PHPUnit\Framework\TestCase;

final class ToastCustomTypeTest extends TestCase
{
    public function testAlertWithStringTypeSuccess(): void
    {
        $t = Toast::new(50)->alert('success', 'It worked!');
        $this->assertTrue($t->hasActiveAlert());
    }

    public function testAlertWithStringTypeError(): void
    {
        $t = Toast::new(50)->alert('error', 'Something broke');
        $this->assertTrue($t->hasActiveAlert());
    }

    public function testAlertWithStringTypeWarning(): void
    {
        $t = Toast::new(50)->alert('warning', 'Low memory');
        $this->assertTrue($t->hasActiveAlert());
    }

    public function testAlertWithStringTypeInfo(): void
    {
        $t = Toast::new(50)->alert('info', 'FYI');
        $this->assertTrue($t->hasActiveAlert());
    }

    public function testAlertWithStringTypeCaseInsensitive(): void
    {
        $t1 = Toast::new(50)->alert('SUCCESS', 'Works');
        $t2 = Toast::new(50)->alert('Success', 'Works');
        $t3 = Toast::new(50)->alert('success', 'Works');

        $this->assertTrue($t1->hasActiveAlert());
        $this->assertTrue($t2->hasActiveAlert());
        $this->assertTrue($t3->hasActiveAlert());
    }

    public function testAlertWithUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown toast type: bogus');

        Toast::new(50)->alert('bogus', 'Should fail');
    }

    public function testAlertWithToastTypeStillWorks(): void
    {
        // Ensure backward compatibility with enum usage
        $t = Toast::new(50)->alert(ToastType::Success, 'Still works');
        $this->assertTrue($t->hasActiveAlert());
    }

    public function testStringTypeAlertRendered(): void
    {
        $t = Toast::new(50)
            ->withPosition(\SugarCraft\Toast\Position::TopLeft)
            ->alert('success', 'Rendered correctly');

        $bg = \str_repeat("line\n", 10);
        $result = $t->View($bg);

        $this->assertStringContainsString('Rendered correctly', $result);
    }

    public function testMixedStringAndEnumTypes(): void
    {
        $t = Toast::new(50)
            ->alert('error', 'string error')
            ->alert(ToastType::Success, 'enum success')
            ->alert('warning', 'string warning');

        $this->assertCount(3, $this->getQueue($t));
    }

    // Helper to access private queue
    private function getQueue(Toast $t): array
    {
        $ref = (new \ReflectionClass($t))->getProperty('queue');
        $ref->setAccessible(true);
        return $ref->getValue($t);
    }
}

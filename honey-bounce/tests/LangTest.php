<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests;

use SugarCraft\Bounce\Lang;
use SugarCraft\Bounce\Easing\EasingFunction;
use SugarCraft\Bounce\Easing\Easing;
use SugarCraft\Bounce\Easing\CubicBezier;
use PHPUnit\Framework\TestCase;

final class LangTest extends TestCase
{
    public function testLangClassExists(): void
    {
        $this->assertTrue(class_exists(Lang::class));
    }

    public function testLangExtendsBaseLang(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertSame('SugarCraft\\Core\\I18n\\Lang', $reflection->getParentClass()->getName());
    }

    public function testLangNamespaceConstant(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $namespaceConst = $reflection->getConstant('NAMESPACE');
        $this->assertSame('bounce', $namespaceConst);
    }

    public function testLangDirConstant(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $dirConst = $reflection->getConstant('DIR');
        $this->assertStringContainsString('/lang', $dirConst);
        $this->assertStringContainsString('honey-bounce', $dirConst);
    }

    public function testEasingFunctionInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(EasingFunction::class));
    }

    public function testEasingImplementsEasingFunction(): void
    {
        $reflection = new \ReflectionClass(Easing::class);
        $interfaces = $reflection->getInterfaceNames();
        $this->assertContains(EasingFunction::class, $interfaces);
    }

    public function testEasingFunctionContract(): void
    {
        // Verify EasingFunction interface has the ease method
        $reflection = new \ReflectionClass(EasingFunction::class);
        $this->assertTrue($reflection->hasMethod('ease'));

        $easeMethod = $reflection->getMethod('ease');
        $params = $easeMethod->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('t', $params[0]->getName());
        $this->assertSame('float', $params[0]->getType()->getName());
        $this->assertSame('float', $easeMethod->getReturnType()->getName());
    }

    public function testEasingEnumHasCases(): void
    {
        $cases = Easing::cases();
        $this->assertGreaterThan(0, count($cases));
    }

    public function testCubicBezierHasEvaluateMethod(): void
    {
        $reflection = new \ReflectionClass(CubicBezier::class);
        $this->assertTrue($reflection->hasMethod('evaluate'));

        $evaluateMethod = $reflection->getMethod('evaluate');
        $params = $evaluateMethod->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('t', $params[0]->getName());
        $this->assertSame('float', $params[0]->getType()->getName());
        $this->assertSame('float', $evaluateMethod->getReturnType()->getName());
    }

    public function testCubicBezierStaticFactoriesReturnInstance(): void
    {
        $ease = CubicBezier::ease();
        $this->assertInstanceOf(CubicBezier::class, $ease);

        $linear = CubicBezier::linear();
        $this->assertInstanceOf(CubicBezier::class, $linear);
    }

    public function testCubicBezierRejectsInvalidControlPoints(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CubicBezier control points must have x-values in [0, 1]');
        new CubicBezier(-0.5, 0.0, 1.0, 1.0);
    }

    public function testLangCanBeInstantiated(): void
    {
        $lang = new Lang();
        $this->assertInstanceOf(Lang::class, $lang);
    }
}

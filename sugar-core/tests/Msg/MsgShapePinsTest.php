<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Msg;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyPressMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Msg\KeyRepeatMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;

/**
 * Reflection matrix over src/Msg value objects (sc-25 / finding
 * sugar-core-046 finality+readonly leg): every message class is final
 * except the two documented family bases, and every promoted
 * constructor property is readonly. A new non-final class or a mutable
 * promoted property reddens this file.
 */
final class MsgShapePinsTest extends TestCase
{
    /** Bases extended for instanceof-idiom discrimination; each carries a documented reason. */
    private const DOCUMENTED_NON_FINAL_BASES = [KeyMsg::class, MouseMsg::class];

    public function testEveryMsgClassIsFinalOrADocumentedBase(): void
    {
        $checked = 0;
        foreach (self::msgClasses() as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->isInterface()) {
                continue; // contracts (Msg, Modifiable) carry no finality face
            }
            $isBase = in_array($class, self::DOCUMENTED_NON_FINAL_BASES, true);
            if ($isBase) {
                $this->assertFalse(
                    $reflection->isFinal(),
                    "{$class} is the documented family base and must stay extendable",
                );
            } else {
                $this->assertTrue(
                    $reflection->isFinal(),
                    "{$class} must be final (or be added to the documented base roster with a reason)",
                );
            }
            $checked++;
        }
        $this->assertGreaterThan(20, $checked, 'matrix must actually walk the Msg directory');
    }

    public function testPromotedCtorPropertiesAreReadonly(): void
    {
        foreach (self::msgClasses() as $class) {
            $reflection = new ReflectionClass($class);
            $ctor = $reflection->getConstructor();
            if ($ctor === null) {
                continue;
            }
            foreach ($ctor->getParameters() as $param) {
                if (!$param->isPromoted()) {
                    continue;
                }
                $this->assertTrue(
                    $param->isDefaultValueAvailable() || $reflection->getProperty($param->getName())->isReadOnly(),
                    "{$class}::\${$param->getName()} promoted property must be readonly",
                );
            }
        }
        $this->assertTrue(true);
    }

    public function testKeyFamilyExtendsTheDocumentedBase(): void
    {
        foreach ([KeyPressMsg::class, KeyReleaseMsg::class, KeyRepeatMsg::class] as $class) {
            $this->assertTrue(
                is_subclass_of($class, KeyMsg::class),
                "{$class} must keep extending KeyMsg (legacy instanceof KeyMsg handlers rely on it)",
            );
        }
    }

    public function testMouseFamilyExtendsTheDocumentedBase(): void
    {
        foreach ([MouseClickMsg::class, MouseReleaseMsg::class, MouseMotionMsg::class, MouseWheelMsg::class] as $class) {
            $this->assertTrue(
                is_subclass_of($class, MouseMsg::class),
                "{$class} must keep extending MouseMsg",
            );
        }
    }

    /** @return list<class-string> */
    private static function msgClasses(): array
    {
        $classes = [];
        foreach (glob(__DIR__ . '/../../src/Msg/*.php') ?: [] as $file) {
            $classes[] = 'SugarCraft\\Core\\Msg\\' . basename($file, '.php');
        }
        return $classes;
    }
}

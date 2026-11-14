<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\ClassMember;
use SugarCraft\Dashboard\Components\Tree\UMLClass;

/**
 * D-143: three independent append channels (attributes/methods/
 * templateParams) share one clone-and-push helper pattern; the pins
 * prove each channel grows ONLY its own list and never mutates the
 * source class — the corruption mode a shared-array refactor introduces.
 */
final class UMLClassTest extends TestCase
{
    public function testEmptyClassStartsWithThreeCleanChannels(): void
    {
        $uml = new UMLClass('c1', 'Widget');

        self::assertSame([], $uml->attributes());
        self::assertSame([], $uml->methods());
        self::assertSame([], $uml->templateParams());
        self::assertFalse($uml->isAbstract);
        self::assertFalse($uml->isInterface);
    }

    public function testAttributeAppendGrowsOnlyTheAttributeChannel(): void
    {
        $uml = new UMLClass('c1', 'Widget');
        $attr = ClassMember::private('size', 'int');

        $grown = $uml->withAttribute($attr);

        self::assertSame([$attr], $grown->attributes());
        self::assertSame([], $uml->attributes());
        self::assertSame([], $grown->methods(), 'methods must stay empty through an attribute door');
        self::assertSame([], $grown->templateParams());
    }

    public function testMethodAppendOrderAndIsolation(): void
    {
        $uml = new UMLClass('c1', 'Widget');
        $render = ClassMember::public('render', 'string');
        $tick = ClassMember::public('tick');

        $grown = $uml->withMethod($render)->withMethod($tick);

        self::assertSame([$render, $tick], $grown->methods());
        self::assertSame([], $uml->methods(), 'the source class must not see chained appends');
        // Chained door preserves identity of earlier entries.
        self::assertSame($render, $grown->methods()[0]);
    }

    public function testTemplateParamAppendAndCarriedSlots(): void
    {
        $uml = new UMLClass('c1', 'Box', 'core', true, false);

        $grown = $uml->withTemplateParam('T')->withTemplateParam('U');

        self::assertSame(['T', 'U'], $grown->templateParams());
        self::assertSame([], $uml->templateParams());
        self::assertSame('c1', $grown->id);
        self::assertSame('Box', $grown->name);
        self::assertSame('core', $grown->package);
        self::assertTrue($grown->isAbstract, 'flags carry through every door');
    }
}

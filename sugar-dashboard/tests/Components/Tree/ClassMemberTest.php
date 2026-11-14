<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\ClassMember;
use SugarCraft\Dashboard\Components\Tree\Visibility;

/**
 * D-143: ClassDiagramTest paints members through the diagram and asserts
 * 'contains' fragments; the exact render grammar (marker ORDER after the
 * visibility symbol, the Δ glyph, and the empty-type omission) had no
 * byte-exact direct pin. This file pins the whole string shape.
 */
final class ClassMemberTest extends TestCase
{
    public function testVisibilityFactoriesSetTheMarkerWithoutFlags(): void
    {
        self::assertSame(Visibility::Public, ClassMember::public('age')->visibility);
        self::assertSame(Visibility::Private, ClassMember::private('id')->visibility);
        self::assertSame(Visibility::Protected, ClassMember::protected('token')->visibility);

        foreach ([ClassMember::public('a'), ClassMember::private('a'), ClassMember::protected('a')] as $m) {
            self::assertFalse($m->isStatic);
            self::assertFalse($m->isAbstract);
            self::assertSame('', $m->type);
        }
    }

    public function testRenderOmitsTheColonWhenTypeIsEmpty(): void
    {
        self::assertSame('+age', ClassMember::public('age')->render());
        self::assertSame('+age: int', ClassMember::public('age', 'int')->render());
        self::assertSame('-id: string', ClassMember::private('id', 'string')->render());
        self::assertSame('#token', ClassMember::protected('token')->render());
    }

    public function testStaticMarkerIsUnderscoreImmediatelyAfterVisibility(): void
    {
        $member = ClassMember::static(ClassMember::public('count', 'int'));

        self::assertTrue($member->isStatic);
        self::assertSame('+_count: int', $member->render());
    }

    public function testAbstractMarkerIsDeltaAfterTheUnderscoreSlot(): void
    {
        $plain = new ClassMember(Visibility::Protected, 'exec', '', false, true);
        self::assertSame('#Δexec', $plain->render());

        $both = new ClassMember(Visibility::Public, 'make', 'static', true, true);
        // Marker order is visibility, '_', 'Δ', name — the grammar the
        // UML legend prints.
        self::assertSame('+_Δmake: static', $both->render());
    }

    public function testStaticWrapperCarriesAllOtherSlotsIncludingAbstract(): void
    {
        $abstract = new ClassMember(Visibility::Private, 'spawn', 'self', false, true);
        $static = ClassMember::static($abstract);

        self::assertSame(Visibility::Private, $static->visibility);
        self::assertSame('spawn', $static->name);
        self::assertSame('self', $static->type);
        self::assertTrue($static->isAbstract, 'static() must not drop the abstract flag');
        self::assertSame('-_Δspawn: self', $static->render());
        // The wrapped original is untouched.
        self::assertFalse($abstract->isStatic);
    }
}

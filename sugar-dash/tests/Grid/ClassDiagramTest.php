<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\ClassDiagram;
use SugarCraft\Dash\Grid\UMLClass;
use SugarCraft\Dash\Grid\ClassMember;
use SugarCraft\Dash\Grid\ClassRelation;
use SugarCraft\Dash\Grid\Visibility;

final class ClassDiagramTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $diagram = ClassDiagram::new();
        $this->assertInstanceOf(ClassDiagram::class, $diagram);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->setSize(70, 20);
        $this->assertInstanceOf(\SugarCraft\Dash\Grid\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $diagram = ClassDiagram::new()->setSize(70, 20);
        $rendered = $diagram->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $diagram = ClassDiagram::new()->setSize(70, 20);
        $rendered = $diagram->render();
        $this->assertStringContainsString('─', $rendered);
    }

    public function testWithClass(): void
    {
        $diagram = ClassDiagram::new();
        $class = new UMLClass('user', 'User');
        $result = $diagram->withClass($class);
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testAddClass(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->addClass('person', 'Person');
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testAddAbstractClass(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->addClass('animal', 'Animal', true, false);
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testAddInterfaceClass(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->addClass('drawable', 'Drawable', false, true);
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testWithClasses(): void
    {
        $diagram = ClassDiagram::new();
        $classes = [
            'user' => new UMLClass('user', 'User'),
            'admin' => new UMLClass('admin', 'Admin'),
        ];
        $result = $diagram->withClasses($classes);
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testWithRelation(): void
    {
        $diagram = ClassDiagram::new();
        $relation = ClassRelation::association('user', 'order', 'places');
        $result = $diagram->withRelation($relation);
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testWithInheritance(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->withInheritance('admin', 'user');
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testWithRelations(): void
    {
        $diagram = ClassDiagram::new();
        $relations = [
            ClassRelation::association('user', 'order', 'places'),
            ClassRelation::inheritance('admin', 'user'),
        ];
        $result = $diagram->withRelations($relations);
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testWithShowVisibility(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->withShowVisibility(false);
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testWithShowTypes(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->withShowTypes(false);
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testWithShowPackages(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->withShowPackages(false);
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testWithStyle(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->withStyle('bold');
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testGetInnerSize(): void
    {
        $diagram = ClassDiagram::new()->setSize(70, 20);
        $size = $diagram->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(70, $size[0]);
        $this->assertEquals(20, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $diagram = ClassDiagram::new()->setSize(10, 5);
        $rendered = $diagram->render();
        $this->assertSame('', $rendered);
    }

    public function testWithClassColor(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->withClassColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testWithInterfaceColor(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->withInterfaceColor(\SugarCraft\Core\Util\Color::hex('#00FF00'));
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testWithAbstractColor(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->withAbstractColor(\SugarCraft\Core\Util\Color::hex('#0000FF'));
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testWithRelationColor(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->withRelationColor(\SugarCraft\Core\Util\Color::hex('#FFFF00'));
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testWithTextColor(): void
    {
        $diagram = ClassDiagram::new();
        $result = $diagram->withTextColor(\SugarCraft\Core\Util\Color::hex('#FF00FF'));
        $this->assertInstanceOf(ClassDiagram::class, $result);
    }

    public function testUMLClassWithAttribute(): void
    {
        $class = new UMLClass('user', 'User');
        $attr = ClassMember::public('name', 'string');
        $classWithAttr = $class->withAttribute($attr);
        $this->assertNotEmpty($classWithAttr->getAttributes());
    }

    public function testUMLClassWithMethod(): void
    {
        $class = new UMLClass('user', 'User');
        $method = ClassMember::public('getName', 'string');
        $classWithMethod = $class->withMethod($method);
        $this->assertNotEmpty($classWithMethod->getMethods());
    }

    public function testUMLClassWithTemplateParam(): void
    {
        $class = new UMLClass('pair', 'Pair');
        $classWithTemplate = $class->withTemplateParam('T');
        $this->assertContains('T', $classWithTemplate->getTemplateParams());
    }

    public function testClassMemberVisibilityHelpers(): void
    {
        $pub = ClassMember::public('name', 'string');
        $this->assertEquals(Visibility::Public, $pub->visibility);
        $this->assertEquals('name', $pub->name);
        $this->assertEquals('string', $pub->type);

        $priv = ClassMember::private('id', 'int');
        $this->assertEquals(Visibility::Private, $priv->visibility);

        $prot = ClassMember::protected('token', 'string');
        $this->assertEquals(Visibility::Protected, $prot->visibility);
    }

    public function testClassMemberRender(): void
    {
        $member = ClassMember::public('age', 'int');
        $rendered = $member->render();
        $this->assertStringContainsString('+', $rendered);
        $this->assertStringContainsString('age', $rendered);
        $this->assertStringContainsString('int', $rendered);
    }

    public function testClassRelationTypes(): void
    {
        $assoc = ClassRelation::association('a', 'b', 'uses');
        $this->assertEquals('association', $assoc->type);

        $inh = ClassRelation::inheritance('child', 'parent');
        $this->assertEquals('inheritance', $inh->type);

        $impl = ClassRelation::implementation('class', 'interface');
        $this->assertEquals('implementation', $impl->type);

        $agg = ClassRelation::aggregation('whole', 'part');
        $this->assertEquals('aggregation', $agg->type);

        $comp = ClassRelation::composition('container', 'item');
        $this->assertEquals('composition', $comp->type);

        $dep = ClassRelation::dependency('client', 'service', 'uses');
        $this->assertEquals('dependency', $dep->type);
    }
}

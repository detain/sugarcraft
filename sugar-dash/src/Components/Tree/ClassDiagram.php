<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Visibility modifier for class members.
 */
enum Visibility: string
{
    case Public = '+';
    case Private = '-';
    case Protected = '#';
    case Package = '~';
}

/**
 * UML class member (attribute or method).
 */
final class ClassMember
{
    public function __construct(
        public readonly Visibility $visibility,
        public readonly string $name,
        public readonly string $type = '',
        public readonly bool $isStatic = false,
        public readonly bool $isAbstract = false,
    ) {}

    /**
     * Create a public member.
     */
    public static function public(string $name, string $type = ''): self
    {
        return new self(Visibility::Public, $name, $type);
    }

    /**
     * Create a private member.
     */
    public static function private(string $name, string $type = ''): self
    {
        return new self(Visibility::Private, $name, $type);
    }

    /**
     * Create a protected member.
     */
    public static function protected(string $name, string $type = ''): self
    {
        return new self(Visibility::Protected, $name, $type);
    }

    /**
     * Create a static member.
     */
    public static function static(self $member): self
    {
        return new self(
            $member->visibility,
            $member->name,
            $member->type,
            true,
            $member->isAbstract,
        );
    }

    /**
     * Render the member as a string.
     */
    public function render(): string
    {
        $result = $this->visibility->value;

        if ($this->isStatic) {
            $result .= '_';
        }

        if ($this->isAbstract) {
            $result .= 'Δ';
        }

        $result .= $this->name;

        if ($this->type !== '') {
            $result .= ': ' . $this->type;
        }

        return $result;
    }
}

/**
 * A UML class in a class diagram.
 */
final class UMLClass
{
    /** @var list<ClassMember> */
    private array $attributes = [];

    /** @var list<ClassMember> */
    private array $methods = [];

    /** @var list<string> */
    private array $templateParams = [];

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $package = null,
        public readonly bool $isAbstract = false,
        public readonly bool $isInterface = false,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Add an attribute.
     */
    public function withAttribute(ClassMember $attribute): self
    {
        $clone = clone $this;
        $clone->attributes[] = $attribute;
        return $clone;
    }

    /**
     * Add a method.
     */
    public function withMethod(ClassMember $method): self
    {
        $clone = clone $this;
        $clone->methods[] = $method;
        return $clone;
    }

    /**
     * Add a template parameter.
     */
    public function withTemplateParam(string $param): self
    {
        $clone = clone $this;
        $clone->templateParams[] = $param;
        return $clone;
    }

    /**
     * Get all attributes.
     *
     * @return list<ClassMember>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get all methods.
     *
     * @return list<ClassMember>
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * Get all template parameters.
     *
     * @return list<string>
     */
    public function getTemplateParams(): array
    {
        return $this->templateParams;
    }
}

/**
 * A relationship between classes.
 */
final class ClassRelation
{
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $to,
        public readonly string $type = 'association',
        public readonly string $label = '',
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create an association relationship.
     */
    public static function association(string $from, string $to, string $label = ''): self
    {
        return new self(uniqid('', true), $from, $to, 'association', $label);
    }

    /**
     * Create an inheritance relationship.
     */
    public static function inheritance(string $from, string $to): self
    {
        return new self(uniqid('', true), $from, $to, 'inheritance', '');
    }

    /**
     * Create an implementation relationship.
     */
    public static function implementation(string $from, string $to): self
    {
        return new self(uniqid('', true), $from, $to, 'implementation', '');
    }

    /**
     * Create an aggregation relationship.
     */
    public static function aggregation(string $from, string $to, string $label = ''): self
    {
        return new self(uniqid('', true), $from, $to, 'aggregation', $label);
    }

    /**
     * Create a composition relationship.
     */
    public static function composition(string $from, string $to, string $label = ''): self
    {
        return new self(uniqid('', true), $from, $to, 'composition', $label);
    }

    /**
     * Create a dependency relationship.
     */
    public static function dependency(string $from, string $to, string $label = ''): self
    {
        return new self(uniqid('', true), $from, $to, 'dependency', $label);
    }
}

/**
 * A UML class diagram component for visualizing class structures.
 *
 * Features:
 * - Classes with name, attributes, and methods
 * - Abstract classes and interfaces
 * - Template/generic parameters
 * - Various relationship types (association, inheritance, etc.)
 * - Visibility modifiers
 * - Stereotypes
 *
 * Mirrors UML class diagram patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class ClassDiagram implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var array<string, UMLClass> */
    private array $classes = [];

    /** @var list<ClassRelation> */
    private array $relations = [];

    private bool $showVisibility = true;
    private bool $showTypes = true;
    private bool $showPackages = true;
    private string $style = 'rounded';

    public function __construct(
        private ?Color $classColor = null,
        private ?Color $interfaceColor = null,
        private ?Color $abstractColor = null,
        private ?Color $relationColor = null,
        private ?Color $textColor = null,
        private string $style_ = 'rounded',
    ) {}

    /**
     * Create a new class diagram with default styling.
     */
    public static function new(): self
    {
        return new self(
            classColor: Color::hex('#89B4FA'),
            interfaceColor: Color::hex('#A6E3A1'),
            abstractColor: Color::hex('#CBA6F7'),
            relationColor: Color::hex('#45475A'),
            textColor: Color::hex('#CDD6F4'),
            style_: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this class diagram.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Add a class to the diagram.
     */
    public function withClass(UMLClass $class): self
    {
        $clone = clone $this;
        $clone->classes[$class->id] = $class;
        return $clone;
    }

    /**
     * Add a class by parameters.
     */
    public function addClass(string $id, string $name, bool $isAbstract = false, bool $isInterface = false): self
    {
        return $this->withClass(new UMLClass($id, $name, null, $isAbstract, $isInterface));
    }

    /**
     * Set all classes at once.
     *
     * @param array<string, UMLClass> $classes
     */
    public function withClasses(array $classes): self
    {
        $clone = clone $this;
        $clone->classes = $classes;
        return $clone;
    }

    /**
     * Add a relation to the diagram.
     */
    public function withRelation(ClassRelation $relation): self
    {
        $clone = clone $this;
        $clone->relations[] = $relation;
        return $clone;
    }

    /**
     * Add an inheritance relation.
     */
    public function withInheritance(string $child, string $parent): self
    {
        return $this->withRelation(ClassRelation::inheritance($child, $parent));
    }

    /**
     * Set all relations at once.
     *
     * @param list<ClassRelation> $relations
     */
    public function withRelations(array $relations): self
    {
        $clone = clone $this;
        $clone->relations = $relations;
        return $clone;
    }

    /**
     * Show or hide visibility modifiers.
     */
    public function withShowVisibility(bool $show): self
    {
        $clone = clone $this;
        $clone->showVisibility = $show;
        return $clone;
    }

    /**
     * Show or hide type annotations.
     */
    public function withShowTypes(bool $show): self
    {
        $clone = clone $this;
        $clone->showTypes = $show;
        return $clone;
    }

    /**
     * Show or hide package names.
     */
    public function withShowPackages(bool $show): self
    {
        $clone = clone $this;
        $clone->showPackages = $show;
        return $clone;
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        $clone = clone $this;
        $clone->style = $style;
        return $clone;
    }

    /**
     * Render the class diagram as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 70;
        $useHeight = $this->height ?? 20;

        if ($useWidth < 25 || $useHeight < 10) {
            return '';
        }

        return $this->renderDiagram($useWidth, $useHeight);
    }

    /**
     * Render the complete class diagram.
     */
    private function renderDiagram(int $width, int $height): string
    {
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');

        $result = '';

        // Title
        $title = 'Class Diagram';
        $titleX = intval(($width - strlen($title)) / 2);
        $result .= $tl . str_repeat('─', $titleX - 1) . $title . str_repeat('─', $width - 2 - $titleX - strlen($title)) . $tr . "\n";

        // Calculate class positions (grid layout)
        $classCount = count($this->classes);
        $cols = max(1, intval(sqrt($classCount)));
        $cellWidth = intval(($width - 4) / $cols);
        $classWidth = max(15, $cellWidth - 2);

        $classPositions = [];
        $index = 0;

        foreach ($this->classes as $classId => $class) {
            $col = $index % $cols;
            $row = intval($index / $cols);

            $x = 2 + $col * $cellWidth;
            $y = 2 + $row * 8; // Each class box is ~8 rows tall

            $classPositions[$classId] = [
                'class' => $class,
                'x' => $x,
                'y' => $y,
                'width' => $classWidth,
            ];
            $index++;
        }

        // Draw class boxes
        foreach ($classPositions as $info) {
            $class = $info['class'];
            $x = $info['x'];
            $y = $info['y'];
            $classWidth = $info['width'];

            $classBox = $this->renderClassBox($class, $classWidth);
            $lines = explode("\n", $classBox);

            // Position within the width
            $result .= $v . str_repeat(' ', $width - 2) . $v . "\n";

            foreach ($lines as $line) {
                $padded = str_pad($line, $classWidth);
                $result .= $v . ' ' . mb_substr($padded, 0, $classWidth) . ' ' . $v . "\n";
            }
        }

        // Draw relations (simplified - just labels for now)
        if (!empty($this->relations) && $this->showVisibility) {
            $result .= $v . str_repeat(' ', $width - 2) . $v . "\n";
            foreach ($this->relations as $relation) {
                $relationStr = '  ── ' . $relation->type . ': ' . $relation->from . ' → ' . $relation->to;
                if ($relation->label !== '') {
                    $relationStr .= ' [' . $relation->label . ']';
                }
                $result .= $v . str_pad($relationStr, $width - 2) . $v . "\n";
            }
        }

        // Bottom border
        $result .= $bl . str_repeat('─', $width - 2) . $br;

        return $result;
    }

    /**
     * Render a single class box.
     */
    private function renderClassBox(UMLClass $class, int $width): string
    {
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');

        // Determine class color
        $classColor = $class->color ?? match (true) {
            $class->isInterface => $this->interfaceColor ?? Color::hex('#A6E3A1'),
            $class->isAbstract => $this->abstractColor ?? Color::hex('#CBA6F7'),
            default => $this->classColor ?? Color::hex('#89B4FA'),
        };

        $innerWidth = $width - 2;

        // Build class name line (possibly with stereotype)
        $nameLine = '';
        if ($class->isInterface) {
            $nameLine .= '«interface» ';
        } elseif ($class->isAbstract) {
            $nameLine .= '«abstract» ';
        }

        // Template parameters
        if (!empty($class->getTemplateParams())) {
            $nameLine .= '⟨' . implode(', ', $class->getTemplateParams()) . '⟩ ';
        }

        $nameLine .= $class->name;

        $result = '';

        // Top border
        if ($classColor !== null) {
            $result .= $classColor->toFg(ColorProfile::TrueColor);
        }
        $result .= '┌' . str_repeat('─', $innerWidth) . '┐';
        if ($classColor !== null) {
            $result .= Ansi::reset();
        }
        $result .= "\n";

        // Class name
        $result .= '│' . str_pad(mb_substr($nameLine, 0, $innerWidth), $innerWidth) . '│' . "\n";

        // Separator
        $result .= '├' . str_repeat('─', $innerWidth) . '┤' . "\n";

        // Attributes section
        if (empty($class->getAttributes())) {
            $result .= '│' . str_pad('(+ no attributes)', $innerWidth) . '│' . "\n";
        } else {
            foreach ($class->getAttributes() as $attr) {
                $attrStr = $this->showVisibility ? $attr->render() : $attr->name;
                $result .= '│' . str_pad(mb_substr($attrStr, 0, $innerWidth), $innerWidth) . '│' . "\n";
            }
        }

        // Separator
        $result .= '├' . str_repeat('─', $innerWidth) . '┤' . "\n";

        // Methods section
        if (empty($class->getMethods())) {
            $result .= '│' . str_pad('(+ no methods)', $innerWidth) . '│' . "\n";
        } else {
            foreach ($class->getMethods() as $method) {
                $methodStr = ($this->showVisibility ? $method->visibility->value : '') . $method->name . '()';
                if ($this->showTypes && $method->type !== '') {
                    $methodStr .= ': ' . $method->type;
                }
                $result .= '│' . str_pad(mb_substr($methodStr, 0, $innerWidth), $innerWidth) . '│' . "\n";
            }
        }

        // Bottom border
        if ($classColor !== null) {
            $result .= $classColor->toFg(ColorProfile::TrueColor);
        }
        $result .= '╰' . str_repeat('─', $innerWidth) . '╯';
        if ($classColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Get the style characters for the border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string}
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['╭', '╮', '╰', '╯', '─', '│'],
        };
    }

    /**
     * Calculate the natural dimensions of this class diagram.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $classCount = count($this->classes);
        $cols = max(1, intval(sqrt($classCount)));
        $rows = intval(($classCount + $cols - 1) / $cols);

        $width = $this->width ?? max(40, $cols * 25);
        $height = $this->height ?? max(15, $rows * 10 + 4);

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the class color.
     */
    public function withClassColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->classColor = $color;
        return $clone;
    }

    /**
     * Set the interface color.
     */
    public function withInterfaceColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->interfaceColor = $color;
        return $clone;
    }

    /**
     * Set the abstract class color.
     */
    public function withAbstractColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->abstractColor = $color;
        return $clone;
    }

    /**
     * Set the relation color.
     */
    public function withRelationColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->relationColor = $color;
        return $clone;
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->textColor = $color;
        return $clone;
    }

    /**
     * Set the border style.
     */
    public function withBorderStyle(string $style): self
    {
        $clone = clone $this;
        $clone->style = $style;
        return $clone;
    }
}
